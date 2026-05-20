<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use App\Domain\Request\Service\RequestService;

use App\Domain\Request\Utils\CurlHandler;

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

        // --- Layer 1: CSRF double-submit cookie ---
        $csrfError = $this->validateCsrfToken($request);
        if ($csrfError !== null) {
            $this->logBlockedRequest($request, 'csrf');
            return $csrfError;
        }

        // --- Layer 2: Soft Origin header check ---
        $originError = $this->validateOrigin($request);
        if ($originError !== null) {
            $this->logBlockedRequest($request, 'origin');
            return $originError;
        }

        // --- Layer 3: Session-based rate limiting ---
        $rateLimitError = $this->checkRateLimit($request);
        if ($rateLimitError !== null) {
            $this->logBlockedRequest($request, 'rate_limit');
            return $rateLimitError;
        }

        // Check the form's own method field (sent by JS as 'post'/'get'),
        // NOT $postBody['method'] which is always "POST" (the HTTP method).
        $parsedBody = $request->getParsedBody();
        $formMethod = strtolower(is_array($parsedBody) ? ($parsedBody['method'] ?? 'post') : 'post');

        if ($formMethod === 'post' && !$this->securityCheck($request)) {
            $this->logBlockedRequest($request, 'honeypot');
            return $this->securityError;
        }

        if ($formMethod === 'post' && !$this->validateRequiredFields($request)) {
            return array(
                "type"    => 'error',
                'message' => 'Please fill in all required fields.'
            );
        }
        
        if ($formMethod === 'post') {
            try {
                $this->checkPostProof($request);
            } catch (\Exception $e) {
                $this->logBlockedRequest($request, 'pow');
                return [
                    'type'    => 'error',
                    'message' => 'Security check failed. Please reload the page and try again.',
                ];
            }
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

    /**
     * Appends a JSON-encoded audit line to the security log file.
     * Each line captures the timestamp, client IP, formId, and the guard
     * that rejected the request.
     */
    protected function logBlockedRequest(ServerRequestInterface $request, string $reason): void
    {
        // Respect FORM_SECURITY_LOG env var (default: enabled).
        // Set FORM_SECURITY_LOG=false in .env to disable audit logging.
        $raw = $_ENV['FORM_SECURITY_LOG'] ?? 'true';
        if (!$raw || $raw === 'false') {
            return;
        }

        $body   = $request->getParsedBody();
        $formId = is_array($body) ? ($body['formId'] ?? '') : '';
        $params = $request->getServerParams();
        $ip     = $params['REMOTE_ADDR'] ?? '';

        $entry = json_encode([
            'ts'     => date('c'),
            'ip'     => $ip,
            'formId' => $formId,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES);

        $written = @error_log($entry . PHP_EOL, 3, $this->getLogPath());
        if ($written === false) {
            // Fallback: write to PHP system error log so the entry is never silently lost
            error_log('[ynfinite-form-security] ' . $entry);
        }
    }

    /**
     * Returns the path to the security audit log file.
     * Overrideable in tests to avoid real disk I/O.
     */
    protected function getLogPath(): string
    {
        // SendFormService lives at src/Domain/Request/Service/ — 4 levels up = project root
        return dirname(__DIR__, 4) . '/tmp/form_security.log';
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
                "rendered" => "The form has no proof that is was sent by a human. Sorry for you inconvenience."
            );
               
            return false;
        }
        return true;
    }

    /**
     * Session-based synchronizer token validation (CSRF).
     *
     * `_yn_csrf_token(form)` in Twig generates a unique token per form per page
     * load, stores it in $_SESSION['_yn_csrf'][$formId], and renders it as a
     * hidden `<input name="_csrf_token">` automatically — no manual template work.
     * JS submits it via FormData without any extra code.
     *
     * Why this is unforgeable:
     *   - The token is random and lives only in the server-side session.
     *   - A bot cannot compute or guess a valid token without a real page load.
     *   - Each page load overwrites the stored token, so old tokens are rejected.
     *   - The token differs per form (keyed by formId) and per reload.
     *
     * Returns an error array on failure, null on pass.
     */
    protected function validateCsrfToken(ServerRequestInterface $request): ?array
    {
        $body      = $request->getParsedBody();
        $formId    = is_array($body) ? ($body['formId'] ?? '') : '';
        $submitted = is_array($body) ? ($body['_csrf_token'] ?? '') : '';
        $stored    = $_SESSION['_yn_csrf'][$formId] ?? '';

        if ($submitted === '' || $stored === '' || !hash_equals($stored, $submitted)) {
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