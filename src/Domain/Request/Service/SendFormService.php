<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use App\Domain\Request\Service\RequestService;

use App\Domain\Request\Utils\CurlHandler;
use App\Middleware\CsrfCookieMiddleware;
use App\Utils\Cache\StaticCache;
use App\Utils\FormSecurityLog;

class SendFormService extends RequestService
{
    /** Max accepted submissions per form inside RATE_LIMIT_WINDOW. */
    private const RATE_LIMIT_MAX    = 10;
    /** Sliding window for the rate limit, in seconds. */
    private const RATE_LIMIT_WINDOW = 15 * 60;
    /** Max hard-rejected attempts per form inside RATE_LIMIT_WINDOW. */
    private const REJECT_LIMIT_MAX  = 20;
    /** Grace period after a deploy in which transition soft-allows apply, seconds. */
    private const DEPLOY_GRACE_WINDOW = 3600;
    /** Minimum seconds between a visitor's first request and a form POST. */
    private const DWELL_MIN_SECONDS = 5;

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

        // Verify the server-signed method token so that tampering with the
        // form's method attribute (e.g. post → get via DevTools) is detected.
        // Missing token = page was cached before this feature; allow but log.
        // Mismatched token = tampering; block.
        $methodError = $this->validateFormMethod($request, $formMethod);
        if ($methodError !== null) {
            return $methodError;
        }

        // Layers 2–6 apply only to contact forms (method: post).
        // Filter forms (method: get) are already gated by CSRF above.
        if ($formMethod === 'post') {
            // --- Layer 2a: rejected-attempt lockout ---
            // The accepted-submission limit below deliberately ignores rejected
            // attempts, which left bots free to hammer the endpoint (and fill the
            // security log) without ever burning a slot. Hard rejections are
            // counted separately via recordRejected(); soft-allows and CSRF
            // failures (stale-cache humans) are not.
            $rejectError = $this->checkRejectLimit($request);
            if ($rejectError !== null) {
                return $rejectError;
            }

            // --- Layer 2b: Soft Origin header check ---
            $originError = $this->validateOrigin($request);
            if ($originError !== null) {
                FormSecurityLog::write($request, 'origin');
                $this->recordRejected($request);
                return $originError;
            }

            // --- Layer 3: Session-based rate limiting ---
            $rateLimitError = $this->checkRateLimit($request);
            if ($rateLimitError !== null) {
                FormSecurityLog::write($request, 'rate_limit');
                return $rateLimitError;
            }

            // --- Layer 3b: Minimum dwell time ---
            $dwellError = $this->validateDwellTime($request);
            if ($dwellError !== null) {
                return $dwellError;
            }

            // --- Layer 4: Honeypot ---
            if (!$this->securityCheck($request)) {
                FormSecurityLog::write($request, 'honeypot');
                $this->recordRejected($request);
                return $this->securityError;
            }

            // --- Layer 5: Required fields ---
            $requiredFieldsFailure = $this->validateRequiredFields($request);
            if ($requiredFieldsFailure !== null) {
                FormSecurityLog::write($request, $requiredFieldsFailure);
                $this->recordRejected($request);
                return array(
                    "type"    => 'error',
                    'message' => 'Please fill in all required fields.'
                );
            }

            // --- Layer 6: Proof-of-Work ---
            try {
                $this->checkPostProof($request);
            } catch (PowException $e) {
                FormSecurityLog::write($request, $e->reason);
                $this->recordRejected($request);
                return [
                    'type'    => 'error',
                    'message' => 'Security check failed. Please reload the page and try again.',
                ];
            } catch (\Exception $e) {
                FormSecurityLog::write($request, 'pow');
                $this->recordRejected($request);
                return [
                    'type'    => 'error',
                    'message' => 'Security check failed. Please reload the page and try again.',
                ];
            }

            // Only accepted submissions count against the rate limit.
            $this->recordSubmission($request);

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
     *
     * Returns null on pass, or the log reason on failure.
     */
    private function validateRequiredFields(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $token = is_array($body) ? ($body['yn_required_fields_token'] ?? '') : '';

        if (empty($token)) {
            // No token means the page HTML predates the last deploy (open tab or
            // just-invalidated static cache). Deploy-time cache invalidation
            // (StaticCache::buildStamp) guarantees all freshly served HTML has
            // the token, so this soft-allow is only needed for pre-deploy
            // visitors and closes automatically after the grace window - a bot
            // simply omitting the token gets no permanent bypass.
            if ($this->inDeployGraceWindow()) {
                FormSecurityLog::write($request, 'required_fields_token_missing_grace');
                return null;
            }
            return 'required_fields_token_missing';
        }

        $raw = base64_decode($token, true);
        if ($raw === false) {
            return 'required_fields_token_tampered';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['p'], $decoded['s'])) {
            return 'required_fields_token_tampered';
        }

        $secret      = $this->settings['auth']['api_key'] ?? '';
        $expectedSig = hash_hmac('sha256', $decoded['p'], $secret);

        if (!hash_equals($expectedSig, $decoded['s'])) {
            return 'required_fields_token_tampered';
        }

        $payload = json_decode($decoded['p'], true);
        if (!is_array($payload) || !array_key_exists('fields', $payload) || !is_array($payload['fields'])) {
            return 'required_fields_token_tampered';
        }

        // Verify the token belongs to the form being submitted
        $submittedFormId = $body['formId'] ?? '';
        if (!empty($payload['formId']) && $payload['formId'] !== $submittedFormId) {
            return 'required_fields_token_tampered';
        }

        $fields = $body['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        // Required *file* inputs are rendered as fields[alias] too, but a file
        // never appears in the parsed body - it lands in the uploaded files.
        // Without this, every form with a required upload was rejected.
        $uploadedFields = $request->getUploadedFiles()['fields'] ?? [];

        foreach ($payload['fields'] as $alias) {
            if ($this->hasUploadedFile($uploadedFields[$alias] ?? null)) {
                continue;
            }

            $value = $fields[$alias] ?? '';
            if (is_array($value)) {
                $filled = array_filter($value, fn($v) => is_scalar($v) && trim((string)$v) !== '');
                if (empty($filled)) {
                    return 'required_fields';
                }
            } elseif (!is_scalar($value) || trim((string)$value) === '') {
                return 'required_fields';
            }
        }

        return null;
    }

    /**
     * True when the given uploaded-files entry (a single UploadedFileInterface or
     * an array of them, for `multiple` inputs) contains at least one real upload.
     */
    private function hasUploadedFile($entry): bool
    {
        if ($entry === null) {
            return false;
        }

        if (is_array($entry)) {
            foreach ($entry as $part) {
                if ($this->hasUploadedFile($part)) {
                    return true;
                }
            }
            return false;
        }

        if (!$entry instanceof \Psr\Http\Message\UploadedFileInterface) {
            return false;
        }

        return $entry->getError() === UPLOAD_ERR_OK && (int) $entry->getSize() > 0;
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
     * Verifies the server-signed form method token.
     *
     * At render time PHP signs {formId, method} with an HMAC and embeds it as
     * `yn_form_method_token`. On submission the signed method must match the
     * `method` field in the POST body, preventing a DevTools or bot bypass
     * where method is flipped from 'post' to 'get' to skip security layers.
     *
     * Missing token (pre-feature static cache) → log + allow (soft enforcement).
     * Invalid signature or method mismatch → block.
     *
     * Returns an error array on failure, null on pass.
     */
    protected function validateFormMethod(ServerRequestInterface $request, string $formMethod): ?array
    {
        $body  = $request->getParsedBody();
        $token = is_array($body) ? ($body['yn_form_method_token'] ?? '') : '';

        if ($token === '') {
            // Missing token = the page HTML predates the last deploy. Allowed
            // only inside the deploy grace window (pre-deploy open tabs); after
            // it every freshly served page carries the token, so a missing one
            // means a bot stripping fields - reject.
            if ($this->inDeployGraceWindow()) {
                FormSecurityLog::write($request, 'method_token_missing_grace');
                return null;
            }
            FormSecurityLog::write($request, 'method_token_missing');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        $raw = base64_decode($token, true);
        if ($raw === false) {
            FormSecurityLog::write($request, 'method_tampered');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['p'], $decoded['s'])) {
            FormSecurityLog::write($request, 'method_tampered');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        $secret      = $this->settings['auth']['api_key'] ?? '';
        $expectedSig = hash_hmac('sha256', $decoded['p'], $secret);
        if (!hash_equals($expectedSig, $decoded['s'])) {
            FormSecurityLog::write($request, 'method_tampered');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        $payload = json_decode($decoded['p'], true);
        if (!is_array($payload) || !isset($payload['method'])) {
            FormSecurityLog::write($request, 'method_tampered');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        if (strtolower($payload['method']) !== $formMethod) {
            FormSecurityLog::write($request, 'method_tampered');
            $this->recordRejected($request);
            return ['type' => 'error', 'message' => 'Security check failed. Please reload the page and try again.'];
        }

        return null;
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
     * Session-based rate limiting - read-only check.
     *
     * Allows up to RATE_LIMIT_MAX *accepted* submissions per form (identified by
     * formId) within a 15-minute sliding window. Catches both page-reload spam
     * and repeated "New Form" → submit cycles.
     *
     * The counter is deliberately NOT incremented here: it used to be, which
     * meant every submission rejected by a later layer (honeypot, required
     * fields, proof-of-work) still burned a slot. A legitimate visitor who hit
     * e.g. the proof-of-work race a few times could lock themselves out for 15
     * minutes without ever having submitted anything. recordSubmission() is
     * called once all layers have passed.
     *
     * Returns an error array when the limit is exceeded, null otherwise.
     */
    protected function checkRateLimit(ServerRequestInterface $request): ?array
    {
        $entry = $this->rateLimitEntry($request);

        if ($entry['count'] >= self::RATE_LIMIT_MAX) {
            return [
                'type'    => 'error',
                'message' => 'Too many form submissions. Please wait a few minutes and try again.',
            ];
        }

        return null;
    }

    /**
     * Counts one accepted submission against the rate limit window.
     */
    protected function recordSubmission(ServerRequestInterface $request): void
    {
        $entry = $this->rateLimitEntry($request);
        $entry['count']++;
        $_SESSION['ynform_ratelimit'][$this->rateLimitKey($request)] = $entry;
    }

    /**
     * Current (window-adjusted) rate limit entry for the submitted form.
     */
    private function rateLimitEntry(ServerRequestInterface $request): array
    {
        $now = time();

        if (!isset($_SESSION['ynform_ratelimit']) || !is_array($_SESSION['ynform_ratelimit'])) {
            $_SESSION['ynform_ratelimit'] = [];
        }

        $key   = $this->rateLimitKey($request);
        $entry = $_SESSION['ynform_ratelimit'][$key] ?? ['count' => 0, 'window_start' => $now];

        // Reset window if it has expired
        if (!is_array($entry) || ($now - ($entry['window_start'] ?? 0)) > self::RATE_LIMIT_WINDOW) {
            $entry = ['count' => 0, 'window_start' => $now];
        }

        return $entry;
    }

    /**
     * True within DEPLOY_GRACE_WINDOW after the last deploy (build marker
     * mtime, see StaticCache::buildStamp). Transition soft-allows for visitors
     * who loaded a page before the deploy are only honoured inside this window,
     * so they never become a permanent bot bypass. Each deploy re-opens exactly
     * one window for its own pre-deploy visitors.
     */
    private function inDeployGraceWindow(): bool
    {
        $stamp = StaticCache::buildStamp();

        return $stamp > 0 && time() < $stamp + self::DEPLOY_GRACE_WINDOW;
    }

    /**
     * Minimum dwell time between the visitor's first request and a form POST.
     *
     * The signed issue-timestamp cookie is minted by CsrfCookieMiddleware on
     * every response (including static-cache hits via ensureCookie()), so after
     * the grace window any legitimate submitter necessarily carries it - a POST
     * without it means no prior GET since the deploy. DWELL_MIN_SECONDS counts
     * from the visitor's FIRST page view ever (the timestamp survives refreshes),
     * so humans are effectively never affected while curl-style bots that GET
     * and instantly POST are.
     *
     * Returns an error array on failure, null on pass.
     */
    protected function validateDwellTime(ServerRequestInterface $request): ?array
    {
        $error = [
            'type'    => 'error',
            'message' => 'Security check failed. Please reload the page and try again.',
        ];

        $cookie = $request->getCookieParams()[CsrfCookieMiddleware::TS_COOKIE_NAME] ?? '';

        if ($cookie === '') {
            if ($this->inDeployGraceWindow()) {
                FormSecurityLog::write($request, 'dwell_cookie_missing_grace');
                return null;
            }
            FormSecurityLog::write($request, 'dwell_cookie_missing');
            $this->recordRejected($request);
            return $error;
        }

        $issuedAt = CsrfCookieMiddleware::validateTsCookie($cookie);
        if ($issuedAt === null) {
            FormSecurityLog::write($request, 'dwell_tampered');
            $this->recordRejected($request);
            return $error;
        }

        if (time() - $issuedAt < self::DWELL_MIN_SECONDS) {
            FormSecurityLog::write($request, 'dwell_too_fast');
            $this->recordRejected($request);
            return $error;
        }

        return null;
    }

    /**
     * Lockout for hard-rejected attempts (mirrors the accepted-submission
     * limiter). Returns an error array when the limit is exceeded, null
     * otherwise.
     */
    protected function checkRejectLimit(ServerRequestInterface $request): ?array
    {
        $entry = $this->rejectedEntry($request);

        if ($entry['count'] >= self::REJECT_LIMIT_MAX) {
            FormSecurityLog::write($request, 'rate_limit_rejected');
            return [
                'type'    => 'error',
                'message' => 'Too many form submissions. Please wait a few minutes and try again.',
            ];
        }

        return null;
    }

    /**
     * Counts one hard-rejected attempt (origin, honeypot, required fields,
     * tampered tokens, PoW, dwell). Soft-allows and CSRF failures are
     * deliberately not counted - those are where stale-cache humans land.
     */
    protected function recordRejected(ServerRequestInterface $request): void
    {
        $entry = $this->rejectedEntry($request);
        $entry['count']++;
        $_SESSION['ynform_ratelimit_rejected'][$this->rateLimitKey($request)] = $entry;
    }

    /**
     * Current (window-adjusted) rejected-attempt entry for the submitted form.
     */
    private function rejectedEntry(ServerRequestInterface $request): array
    {
        $now = time();

        if (!isset($_SESSION['ynform_ratelimit_rejected']) || !is_array($_SESSION['ynform_ratelimit_rejected'])) {
            $_SESSION['ynform_ratelimit_rejected'] = [];
        }

        $key   = $this->rateLimitKey($request);
        $entry = $_SESSION['ynform_ratelimit_rejected'][$key] ?? ['count' => 0, 'window_start' => $now];

        if (!is_array($entry) || ($now - ($entry['window_start'] ?? 0)) > self::RATE_LIMIT_WINDOW) {
            $entry = ['count' => 0, 'window_start' => $now];
        }

        return $entry;
    }

    private function rateLimitKey(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        $formId = is_array($body) ? ($body['formId'] ?? 'unknown') : 'unknown';

        return is_scalar($formId) ? (string) $formId : 'unknown';
    }
}