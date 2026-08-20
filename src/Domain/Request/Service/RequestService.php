<?php

namespace App\Domain\Request\Service;

use Exception;
use SlimSession\Helper as SessionHelper;
use Psr\Container\ContainerInterface;

use App\Domain\Request\Utils\CurlHandler;
use App\Utils\FormSecurityLog;

class RequestService {

    /**
     * Hard limit on proof timestamp age/skew, enforced from day one. 24h is a
     * zero-false-positive bound: the proof is hashed over the CSRF cookie,
     * which only lives 24h, so no legitimate visitor can hold a proof older
     * than that - only stockpiled/farmed proofs (or absurd clocks) fail it.
     */
    protected const POW_MAX_AGE_WINDOW = 24 * 60 * 60;
    /**
     * Telemetry-only freshness window: proofs older/newer than this are logged
     * as "pow_stale_softlog" but always allowed. Pure data for the security
     * log (client clock skew distribution) - never a rejection, never needs
     * manual action.
     */
    protected const POW_FRESHNESS_WINDOW = 30 * 60;
    /** How long a used proof hash stays blocked (also the prune horizon). */
    protected const POW_REPLAY_WINDOW = 30 * 60;
    /** Hard cap on remembered proof hashes per session. */
    protected const POW_REPLAY_MAX_ENTRIES = 50;

    private $repository;
    public $settings;
    public $session;
    public $curlHandler;

    public function __construct(SessionHelper $session, ContainerInterface $container) {
        $this->session = $session;

        $this->settings = $container->get("settings")["ynfinite"];

        $cookieArray = array();
        foreach ($_COOKIE as $key => $cookie) {
            $cookieArray[] = $key . "=" . $cookie;
        }

        $this->curlHandler = new CurlHandler($this->settings);

        $this->curlHandler->addHeader("ynfinite-api-key", $this->settings["auth"]["api_key"]);
        $this->curlHandler->addHeader("ynfinite-service-id", $this->settings["auth"]["service_id"]);
    }

    protected function getFiles($request) {
        $uploadFields = $request->getUploadedFiles();
        $files = array();

        foreach ($uploadFields["fields"] ?? [] as $key => $file) {
            if (is_array($file)) {
                foreach ($file as $i => $part) {
                    if($part->getFilePath()) {
                        $files["files.".$key.'['.$i.']'] = curl_file_create($part->getFilePath(), $part->getClientMediaType(), $part->getClientFilename());
                    }
                }
            } else {
                if($file->getFilePath()) {
                    $files["files.".$key] = curl_file_create($file->getFilePath(), $file->getClientMediaType(), $file->getClientFilename());
                }
            }
        }        

        return $files;
    }

    protected function checkPostProof($request) {
        $body = $request->getParsedBody();
        
        if(!$body) {
            $body = json_decode(file_get_contents('php://input'), true);
        }

        // hasProof arrives as a *string* from FormData ('true' / 'false'), and the
        // string 'false' is truthy in PHP - so a plain !$body['hasProof'] check
        // never fired. Accept only an explicit positive value.
        $hasProof = $body["hasProof"] ?? null;
        $hasProofValid = $hasProof === true
            || (is_string($hasProof) && in_array(strtolower($hasProof), ['true', '1'], true))
            || $hasProof === 1;

        if (!$hasProofValid || empty($body["proofenHash"]) || !is_string($body["proofenHash"])) {
            throw new PowException("pow_missing", "The form has no proof that it was sent by a human.");
        }

        $hash      = (string) $body["proofenHash"];
        $csrfToken = $request->getCookieParams()['ynfinite-csrf-protection'] ?? '';
        $formId    = (string) ($body['formId'] ?? '');

        // noBotProtection POST forms submit SHA256(csrfToken + formId + 'nobot') with no nonce,
        // plus a server-signed yn_nobot_token (HMAC-SHA256(formId:nobot, api_key)).
        // Both must be present and valid — this prevents bots from faking the nobot path
        // on regular PoW forms (which never have yn_nobot_token in their HTML).
        $noBotSentinel  = hash('sha256', $csrfToken . $formId . 'nobot');
        $submittedNonce = (string) ($body['proofenNonce'] ?? '');
        $noBotToken     = (string) ($body['yn_nobot_token'] ?? '');
        // Token proves formId was rendered by this server. CSRF binding is already provided
        // by proofenHash (SHA256(csrfToken + formId + 'nobot')), so no CSRF in this HMAC.
        $expectedNoBotToken = hash_hmac('sha256', $formId . ':nobot:', $this->settings['auth']['api_key'] ?? '');
        if ($submittedNonce === ''
            && hash_equals($noBotSentinel, $hash)
            && hash_equals($expectedNoBotToken, $noBotToken)
        ) {
            return true;
        }

        // Reject the old forgeable 'true' sentinel.
        if ($hash === 'true') {
            throw new PowException("pow_forged_sentinel", "The form has no proof that it was sent by a human.");
        }

        // Validate SHA-256 hex format.
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new PowException("pow_bad_format", "The form has no proof that it was sent by a human.");
        }

        // Verify CSRF-tied PoW: recompute SHA256(csrfToken + prevHash + timestamp + formId + nonce)
        // and confirm it matches the submitted hash and meets the difficulty prefix.
        $nonce     = $submittedNonce;
        $prevHash  = (string) ($body['proofenPrevHash']  ?? '');
        $timestamp = (string) ($body['proofenTimestamp'] ?? '');

        if ($nonce === '') {
            throw new PowException("pow_no_nonce", "The form proof could not be verified.");
        }

        $expected = hash('sha256', $csrfToken . $prevHash . $timestamp . $formId . $nonce);
        if (!hash_equals($expected, $hash)) {
            throw new PowException("pow_mismatch", "PoW verification failed.");
        }

        // Difficulty: prefix must be '00000' or '11111' (difficulty=5, chances=1 in worker.js).
        $prefix = substr($hash, 0, 5);
        if ($prefix !== '00000' && $prefix !== '11111') {
            throw new PowException("pow_difficulty", "PoW difficulty not met.");
        }

        // --- Freshness ---
        // proofenTimestamp is the client's Date.now() in ms and is bound into
        // the verified hash above, so it cannot be altered without redoing the
        // PoW. Two windows: a 24h HARD bound (cannot false-positive - the CSRF
        // cookie the proof binds only lives 24h) and a 30min telemetry-only
        // window that just logs client clock skew. Nothing here ever needs a
        // manual flag flip.
        $tsMs       = is_numeric($timestamp) ? (float) $timestamp : 0.0;
        $ageSeconds = abs(microtime(true) * 1000 - $tsMs) / 1000;
        if ($tsMs <= 0 || $ageSeconds > self::POW_MAX_AGE_WINDOW) {
            throw new PowException("pow_stale", "PoW timestamp too old.");
        }
        if ($ageSeconds > self::POW_FRESHNESS_WINDOW) {
            FormSecurityLog::write($request, 'pow_stale_softlog');
        }

        // --- Single-use proofs (nonce path only) ---
        // The nobot sentinel above is constant per cookie+form by design and is
        // therefore excluded. Safe for humans: the client re-arms a fresh proof
        // right after every POST, so a legitimate second submit never reuses one.
        $now  = time();
        $used = $_SESSION['ynform_used_proofs'] ?? [];
        if (!is_array($used)) {
            $used = [];
        }
        $used = array_filter($used, fn($t) => is_int($t) && ($now - $t) <= self::POW_REPLAY_WINDOW);

        if (isset($used[$hash])) {
            $_SESSION['ynform_used_proofs'] = $used;
            throw new PowException("pow_replay", "PoW proof was already used.");
        }

        if (count($used) >= self::POW_REPLAY_MAX_ENTRIES) {
            asort($used);
            $used = array_slice($used, -(self::POW_REPLAY_MAX_ENTRIES - 1), null, true);
        }
        $used[$hash] = $now;
        $_SESSION['ynform_used_proofs'] = $used;

        return true;
    }

    protected function validateReferer($referer, $fallback) {
        if (empty($referer)) {
            return $fallback;
        }

        // Check if referer is a valid URI with http or https schema
        if (filter_var($referer, FILTER_VALIDATE_URL) && preg_match('/^https?:\\/\\//i', $referer)) {
            return $referer;
        }

        // Fallback to the constructed URL if referer is invalid
        return $fallback;
    }

    /**
     * Soft Origin header validation.
     *
     * If an Origin header is present it must match one of the hosts this
     * request could legitimately have been sent to. We accept a *set* of
     * candidate hosts instead of only HTTP_HOST, because behind a reverse
     * proxy / load balancer the Host header the app sees is frequently not
     * the host the browser used (Host rewriting, internal service names,
     * container hostnames). Comparing against HTTP_HOST alone rejected 100%
     * of submissions in those setups.
     *
     * X-Forwarded-Host is only honoured when the request reached us from a
     * private/loopback peer, i.e. from a proxy sitting in front of this app.
     * The header is client supplied: taken unconditionally, a bot could send
     * "Origin: https://evil.example" plus "X-Forwarded-Host: evil.example" and
     * always pass, which made this layer a no-op. If a deployment terminates
     * in front of the app on a *public* address (CDN, external load balancer)
     * and rewrites Host, list the public hostnames in the YN_ALLOWED_HOSTS env
     * variable (comma separated) instead.
     *
     * Allowed through without a match:
     *   - no Origin header at all (older browsers, some proxies strip it)
     *   - Origin: null (sandboxed iframes, some privacy extensions,
     *     redirect-initiated POSTs)
     * CSRF (double-submit cookie) remains the primary guard in those cases.
     *
     * Returns an error array on failure, null on pass.
     */
    protected function validateOrigin(\Psr\Http\Message\ServerRequestInterface $request): ?array
    {
        $originHeader = trim($request->getHeaderLine('Origin'));

        // No Origin, or an opaque origin → allow (rely on CSRF as primary guard).
        if ($originHeader === '' || strtolower($originHeader) === 'null') {
            return null;
        }

        $originHost = parse_url($originHeader, PHP_URL_HOST);
        if ($originHost === null || $originHost === false) {
            return ['type' => 'error', 'message' => 'Request origin not allowed.'];
        }

        $serverParams = $request->getServerParams();

        // Every host this request could legitimately carry.
        $candidates = [
            $request->getUri()->getHost(),
            $serverParams['HTTP_HOST'] ?? '',
            $serverParams['SERVER_NAME'] ?? '',
            $_SERVER['HTTP_HOST'] ?? '',
            $_ENV['YN_ALLOWED_HOSTS'] ?? $_SERVER['YN_ALLOWED_HOSTS'] ?? '',
        ];

        // Behind a local reverse proxy the browser's host only survives in
        // X-Forwarded-Host (may be a comma separated list when proxies are
        // chained). Trust it only when the connection itself came from a
        // proxy, never from an arbitrary internet client.
        if (self::isProxyPeer($serverParams['REMOTE_ADDR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $candidates[] = $serverParams['HTTP_X_FORWARDED_HOST'] ?? '';
            $candidates[] = $request->getHeaderLine('X-Forwarded-Host');
        }

        $normalize = static function ($host): string {
            $host = strtolower(trim((string) $host));
            // strip credentials/path leftovers and the port
            $host = explode('/', $host)[0];
            $host = explode(':', $host)[0];
            return $host;
        };

        $allowed = [];
        foreach ($candidates as $candidate) {
            foreach (explode(',', (string) $candidate) as $part) {
                $host = $normalize($part);
                if ($host !== '') {
                    $allowed[$host] = true;
                }
            }
        }

        if (isset($allowed[$normalize($originHost)])) {
            return null;
        }

        return [
            'type'    => 'error',
            'message' => 'Request origin not allowed.',
        ];
    }

    /**
     * True when the TCP peer is a reverse proxy running next to this app
     * (loopback, RFC1918, unique-local IPv6, or a unix socket / CLI request
     * where REMOTE_ADDR is empty). Only such peers may rewrite the Host.
     */
    protected static function isProxyPeer(string $remoteAddr): bool
    {
        $remoteAddr = trim($remoteAddr);

        if ($remoteAddr === '') {
            return true;
        }

        // Not a routable public address -> local network / proxy.
        return filter_var(
            $remoteAddr,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    protected function getBody($request) {
        $files = $this->getFiles($request);
        $body = $request->getParsedBody();

        if(!$body) {
            $body = json_decode(file_get_contents('php://input'));
        }

        $url = $request->getUri()->getScheme()."://".$request->getUri()->getHost().$request->getUri()->getPath();
        if($request->getUri()->getQuery()) {
            $url .= "?".$request->getUri()->getQuery();
        }

        $ip = $request->getHeader('X-Forwarded-For');

        if (empty($ip)) {
            $ip = $request->getHeader('Client-Ip'); 
        }

        if (empty($ip)) {
            $ip = $request->getServerParams()['REMOTE_ADDR'];
        }

        if (is_array($ip)) {
            $ip = $ip[0];
        }

        $referer = array_key_exists("HTTP_REFERER", $_SERVER) ? $_SERVER['HTTP_REFERER'] : $url;
        $referer = $this->validateReferer($referer, $url);

        $postBody = array_merge(array(
            "method" => $request->getMethod(),
            "url" => $url,
            "ip" => $ip,
            "session" => json_encode($_SESSION),
            "referer" => $referer
        ), $files);

        if($body) {
            $postBody["body"] = json_encode($body);
        }

        return $postBody;
    }

    protected function request($path, $service, $body = array(), $json = true)
    {
        $this->curlHandler->setUrl($service, $path);
        
        $response = $this->curlHandler->exec($body);  
        $body = $response["body"];
        $statusCode = $response["statusCode"];
        if ($json) {
            $body = json_decode($body, true);
        }

        return array("body" => $body, "statusCode" => $statusCode);
    }
}