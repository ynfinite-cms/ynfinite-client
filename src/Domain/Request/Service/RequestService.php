<?php

namespace App\Domain\Request\Service;

use Exception;
use SlimSession\Helper as SessionHelper;
use Psr\Container\ContainerInterface;

use App\Domain\Request\Utils\CurlHandler;

class RequestService {

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

        // GET forms (filter/search) don't use PoW — the client sets proofenHash=true
        // as a pass-through signal. Skip all PoW validation for them.
        if (($body['method'] ?? '') === 'get') {
            return true;
        }

        if (empty($body['hasProof']) || empty($body['proofenHash'])) {
            throw new Exception("Something went wrong with your submission — please reload the page and try again.");
        }

        // Forms with noBotProtection are exempt from PoW — but we verify this
        // via a server-signed token, not by trusting the client-submitted value.
        // A bot that just sends proofenHash=true can no longer skip validation.
        if ($this->isNoBotProtectionForm($body)) {
            return true;
        }

        $hash       = strtolower((string) $body['proofenHash']);
        $difficulty = 5; // must match startProofOfWork() difficulty

        // 1. Format: must be a valid 64-char SHA-256 hex string.
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new Exception("Something went wrong — please reload the page and try again.");
        }

        // 2. Difficulty: first `difficulty` chars must all be the same hex digit.
        if (!preg_match('/^(.)\\1{' . ($difficulty - 1) . '}/', substr($hash, 0, $difficulty))) {
            throw new Exception("Something went wrong — please reload the page and try again.");
        }

        // 3. Full re-derivation: re-compute SHA256(previousHash + timestamp + formId + nonce)
        //    and verify it equals the submitted hash. This closes the gap where a bot could
        //    pre-mine hashes that satisfy the pattern but are not tied to this submission.
        $nonce        = $body['proofenNonce']        ?? null;
        $previousHash = $body['proofenPreviousHash'] ?? null;
        $timestamp    = $body['proofenTimestamp']    ?? null;
        $formId       = $body['formId']              ?? '';

        // Validate formId early — must be a 24-character hex MongoDB ObjectId.
        // Rejects array injection (formId[]=...) and path traversal / injection strings
        // before they can reach the API or the PoW re-derivation.
        if (!is_string($formId) || !preg_match('/^[0-9a-f]{24}$/i', $formId)) {
            throw new Exception("Something went wrong — please reload the page and try again.");
        }

        if ($nonce !== null && $nonce !== '' &&
            $previousHash !== null &&
            $timestamp !== null && $timestamp !== '') {

            // Freshness check: reject tokens older than 30 minutes.
            // Gives users enough time to read/fill out complex forms without making
            // it so loose that replay attacks become feasible.
            // JS Date.now() is in milliseconds; PHP time() is in seconds.
            $ageMs = abs((int) round(microtime(true) * 1000) - (int) $timestamp);
            if ($ageMs > 30 * 60 * 1000) {
                throw new Exception("That took a while — please reload the page and try again.");
            }

            // Re-derive the hash exactly as the Web Worker does:
            // SHA256(previousHash + timestamp + formId + nonce)
            // All values are concatenated as strings (JS coerces numbers to strings).
            $expected = hash('sha256', $previousHash . $timestamp . $formId . $nonce);

            if (!hash_equals($expected, $hash)) {
                throw new Exception("Something went wrong — please reload the page and try again.");
            }

            // Single-use enforcement: reject any hash that was already accepted
            // within the freshness window. This prevents the same mined token
            // from being reused across multiple submissions (e.g. via "new form").
            $usedKey  = 'yn_used_pow_hashes';
            $nowMs    = (int) round(microtime(true) * 1000);
            $maxAgeMs = 30 * 60 * 1000;

            if (!isset($_SESSION[$usedKey]) || !is_array($_SESSION[$usedKey])) {
                $_SESSION[$usedKey] = [];
            }

            // Prune entries older than the freshness window.
            $_SESSION[$usedKey] = array_filter(
                $_SESSION[$usedKey],
                fn(int $ts) => ($nowMs - $ts) < $maxAgeMs
            );

            if (isset($_SESSION[$usedKey][$hash])) {
                throw new Exception("Looks like this form was already submitted — please reload the page to try again.");
            }

            $_SESSION[$usedKey][$hash] = $nowMs;
        }

        return true;
    }

    /**
     * Verifies the server-signed yn_pow_token and returns true when the form
     * is configured with noBotProtection (i.e. PoW should be skipped).
     * Falls back to false when the token is absent or tampered with.
     */
    private function isNoBotProtectionForm(array $body): bool
    {
        $tokenRaw = $body['yn_pow_token'] ?? '';
        if (empty($tokenRaw)) {
            return false;
        }

        $decoded = json_decode(base64_decode($tokenRaw), true);
        if (!is_array($decoded) || empty($decoded['p']) || empty($decoded['s'])) {
            return false;
        }

        $secret   = $this->settings['auth']['api_key'] ?? '';
        $expected = hash_hmac('sha256', $decoded['p'], $secret);

        if (!hash_equals($expected, (string) $decoded['s'])) {
            return false;
        }

        $payload = json_decode($decoded['p'], true);
        return !empty($payload['noBotProtection']);
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