<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use App\Domain\Request\Service\RequestService;

use App\Domain\Request\Utils\CurlHandler;
use App\Utils\FormSecurityLog;

class SendFormService extends RequestService
{
    private $repository;
    public $settings;
    public $securityError;

    public function __construct(SessionHelper $session, RequestCacheRepository $repository, ContainerInterface $container) {
        parent::__construct($session, $container);
        $this->repository = $repository;
    }

    public function sendForm(ServerRequestInterface $request, $postData)
    {
        $jsonResponse = true;
        $path = $request->getUri()->getPath();
    
        $postBody = $this->getBody($request);

        FormSecurityLog::cleanup();

        // Check the form's own method field (sent by JS as 'post'/'get'),
        // NOT the HTTP method which is always POST for this endpoint.
        $parsedBody = $request->getParsedBody();
        $formMethod = strtolower(is_array($parsedBody) ? ($parsedBody['method'] ?? 'post') : 'post');

        // --- Layer 1: CSRF double-submit cookie (all forms) ---
        // JS sends _csrf_token unconditionally for every form type, including filter
        // forms (method=get). Validating here closes the bypass where a bot could
        // send method=get in the POST body to skip all security layers.
        // Logging is handled inside validateCsrfToken() with a specific sub-reason.
        $csrfError = $this->validateCsrfToken($request);
        if ($csrfError !== null) {
            return $csrfError;
        }

        // Layers 2–6 apply only to contact forms (method: post).
        // Filter forms (method: get) are already gated by CSRF above.
        if ($formMethod === 'post') {
            // --- Layer 2: Soft Origin header check ---
            $originError = $this->validateOrigin($request);
            if ($originError !== null) {
                FormSecurityLog::write($request, 'origin');
                return $originError;
            }

            // --- Layer 3: Session-based rate limiting ---
            $rateLimitError = $this->checkRateLimit($request);
            if ($rateLimitError !== null) {
                FormSecurityLog::write($request, 'rate_limit');
                return $rateLimitError;
            }
            
            // --- Layer 4: Honeypot ---
            if (!$this->securityCheck($request)) {
                FormSecurityLog::write($request, 'honeypot');
                return $this->securityError;
            }

            // --- Layer 5: Required fields ---
            if (!$this->validateRequiredFields($request)) {
                return array(
                    "type"    => 'error',
                    'message' => 'Please fill in all required fields.'
                );
            }

            // --- Layer 6: Proof-of-Work ---
            try {
                $this->checkPostProof($request);
            } catch (\Exception $e) {
                FormSecurityLog::write($request, 'pow');
                return [
                    'type'    => 'error',
                    'message' => 'Security check failed. Please reload the page and try again.',
                ];
            }

            FormSecurityLog::write($request, 'allowed');
        }

        $response = $this->request(trim($path), $this->settings["services"]["form"], $postBody, $jsonResponse);
        $statusCode = $response["statusCode"];
        $body = $response["body"];

        if(in_array($statusCode, [200, 201, 206], true)){
            // Page render
            $body["type"] = 'page';
            return $body;
        }
        else if(in_array($statusCode, [301, 302, 307, 308], true)){
            // Redirect
            if(isset($body["loginToken"]) && strlen($body["loginToken"]) > 0){
                $_SESSION["loginToken"] = $body["loginToken"];
                setcookie('loginToken', $body["loginToken"], time() + (86400 * 30), "/");
            };
            return array("type" => 'redirect', "statusCode" => $statusCode, "url" => $body["url"]);
        } else {
            // Error
            return array("type" => 'error', 'message' => $body["message"]);
        }
    }

    /**
     * Verifies the server-signed required-fields token and checks that every
     * field it lists is present and non-empty in the submitted POST data.
     */
    private function validateRequiredFields(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        $token = $body['yn_required_fields_token'] ?? '';

        if (empty($token)) {
            return false;
        }

        $raw = base64_decode($token, true);
        if ($raw === false) {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['p'], $decoded['s'])) {
            return false;
        }

        $secret      = $this->settings['auth']['api_key'] ?? '';
        $expectedSig = hash_hmac('sha256', $decoded['p'], $secret);

        if (!hash_equals($expectedSig, $decoded['s'])) {
            return false;
        }

        $payload = json_decode($decoded['p'], true);
        if (!is_array($payload) || !array_key_exists('fields', $payload) || !is_array($payload['fields'])) {
            return false;
        }

        // Verify the token belongs to the form being submitted
        $submittedFormId = $body['formId'] ?? '';
        if (!empty($payload['formId']) && $payload['formId'] !== $submittedFormId) {
            return false;
        }

        $fields = $body['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }
        foreach ($payload['fields'] as $alias) {
            $value = $fields[$alias] ?? '';
            if (is_array($value)) {
                $filled = array_filter($value, fn($v) => trim((string)$v) !== '');
                if (empty($filled)) {
                    return false;
                }
            } elseif (trim((string)$value) === '') {
                return false;
            }
        }

        return true;
    }

    protected function securityCheck($request) 
    {
        $body = $request->getParsedBody();

        // The expected honeypot value is the per-session CSRF cookie, set by JS
        // in checkHoneypot() before submit. A bot posting without a browser session
        // will not know the current cookie value.
        $expectedHoneypot = $request->getCookieParams()['ynfinite-csrf-protection'] ?? '';

        if (empty($expectedHoneypot) || $body["yn_confirm_email"] !== $expectedHoneypot) {
            $this->securityError = array(
                "type"    => 'error',
                "success" => false,
                "message" => 'Security check failed.',
                "rendered" => "The form has no proof that it was sent by a human. Sorry for the inconvenience."
            );
               
            return false;
        }
        return true;
    }

    /**
     * Double-submit cookie CSRF validation.
     *
     * PHP always sets the `ynfinite-csrf-protection` cookie: CsrfCookieMiddleware
     * handles PHP-rendered pages; CsrfCookieMiddleware::ensureCookie() handles
     * static-cache hits in index.php. Before submitting, JS copies the cookie
     * value into the hidden `_csrf_token` input.
     *
     * Why this is unforgeable:
     *   - A cross-origin attacker cannot read the victim's cookie (same-origin policy).
     *   - SameSite=Lax prevents the cookie from being sent with cross-site POST requests.
     *   - Matching cookie and body field cannot be forged without cookie read access.
     *
     * Returns an error array on failure, null on pass.
     */
    protected function validateCsrfToken(ServerRequestInterface $request): ?array
    {
        $body        = $request->getParsedBody();
        $submitted   = is_array($body) ? ($body['_csrf_token'] ?? '') : '';
        $cookieToken = $request->getCookieParams()['ynfinite-csrf-protection'] ?? '';

        if ($submitted === '' || $cookieToken === '' || !hash_equals($cookieToken, $submitted)) {
            // Log a specific sub-reason so production logs can distinguish the
            // three failure modes without changing the user-facing message.
            if ($submitted === '' && $cookieToken === '') {
                $subReason = 'csrf_both_missing';
            } elseif ($submitted === '') {
                $subReason = 'csrf_no_token';
            } elseif ($cookieToken === '') {
                $subReason = 'csrf_no_cookie';
            } else {
                $subReason = 'csrf_mismatch';
            }
            FormSecurityLog::write($request, $subReason);
            return [
                'type'    => 'error',
                'message' => 'Invalid or missing CSRF token. Please reload the page and try again.',
            ];
        }

        return null;
    }

    /**
     * Session-based rate limiting.
     *
     * Allows up to 10 submissions per form (identified by formId) within a
     * 15-minute sliding window.  Catches both page-reload spam and repeated
     * "New Form" → submit cycles.
     *
     * Returns an error array when the limit is exceeded, null otherwise.
     */
    protected function checkRateLimit(ServerRequestInterface $request): ?array
    {
        $body   = $request->getParsedBody();
        $formId = is_array($body) ? ($body['formId'] ?? 'unknown') : 'unknown';

        $windowSeconds = 15 * 60; // 15 minutes
        $maxSubmits    = 10;
        $now           = time();

        if (!isset($_SESSION['ynform_ratelimit'])) {
            $_SESSION['ynform_ratelimit'] = [];
        }

        $entry = $_SESSION['ynform_ratelimit'][$formId] ?? ['count' => 0, 'window_start' => $now];

        // Reset window if it has expired
        if (($now - $entry['window_start']) > $windowSeconds) {
            $entry = ['count' => 0, 'window_start' => $now];
        }

        if ($entry['count'] >= $maxSubmits) {
            return [
                'type'    => 'error',
                'message' => 'Too many form submissions. Please wait a few minutes and try again.',
            ];
        }

        $entry['count']++;
        $_SESSION['ynform_ratelimit'][$formId] = $entry;

        return null;
    }
}