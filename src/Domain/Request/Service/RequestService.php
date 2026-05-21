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

        if(!$body["hasProof"] || !$body["proofenHash"]) {
            throw new Exception("The form has no proof that is was sent by a human. Sorry for you inconvenience.");
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
            throw new Exception("The form has no proof that it was sent by a human. Sorry for the inconvenience.");
        }

        // Validate SHA-256 hex format.
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new Exception("The form has no proof that it was sent by a human. Sorry for the inconvenience.");
        }

        // Verify CSRF-tied PoW: recompute SHA256(csrfToken + prevHash + timestamp + formId + nonce)
        // and confirm it matches the submitted hash and meets the difficulty prefix.
        $nonce     = $submittedNonce;
        $prevHash  = (string) ($body['proofenPrevHash']  ?? '');
        $timestamp = (string) ($body['proofenTimestamp'] ?? '');

        if ($nonce === '') {
            throw new Exception("The form proof could not be verified. Sorry for the inconvenience.");
        }

        $expected = hash('sha256', $csrfToken . $prevHash . $timestamp . $formId . $nonce);
        if (!hash_equals($expected, $hash)) {
            throw new Exception("PoW verification failed.");
        }

        // Difficulty: prefix must be '00000' or '11111' (difficulty=5, chances=1 in worker.js).
        $prefix = substr($hash, 0, 5);
        if ($prefix !== '00000' && $prefix !== '11111') {
            throw new Exception("PoW difficulty not met.");
        }

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
     * If an Origin header is present it must match the server's own host.
     * Absent Origin is allowed (covers unusual proxies / browser configs).
     * Returns an error array on failure, null on pass.
     */
    protected function validateOrigin(\Psr\Http\Message\ServerRequestInterface $request): ?array
    {
        $originHeader = $request->getHeaderLine('Origin');
        if ($originHeader === '') {
            return null; // no Origin → allow (rely on CSRF as primary guard)
        }

        $originHost = parse_url($originHeader, PHP_URL_HOST);
        $serverParams = $request->getServerParams();
        $serverHost = $serverParams['HTTP_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $request->getUri()->getHost();

        // Strip port from server host for comparison
        $serverHost = explode(':', $serverHost)[0];

        if ($originHost === null || strtolower($originHost) !== strtolower($serverHost)) {
            return [
                'type'    => 'error',
                'message' => 'Request origin not allowed.',
            ];
        }

        return null;
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