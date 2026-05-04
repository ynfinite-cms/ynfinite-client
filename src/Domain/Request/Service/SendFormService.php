<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use App\Domain\Request\Service\RequestService;

use App\Domain\Request\Utils\CurlHandler;

final class SendFormService extends RequestService
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
        
        if($postBody['method'] === "post" && !$this->securityCheck($request)) {
            return $this->securityError;
        }

        if($postBody['method'] === "post" && !$this->validateRequiredFields($request)) {
            return array(
                "type"    => 'error',
                'message' => 'Please fill in all required fields.'
            );
        }
        
        $this->checkPostProof($request);

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
     * If no token is present the check is skipped (backwards-compatible with
     * old cached pages that were rendered without the token).
     */
    private function validateRequiredFields(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        $token = $body['yn_required_fields_token'] ?? '';

        if (empty($token)) {
            return true;
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
        if (!is_array($payload) || empty($payload['fields'])) {
            return false;
        }

        // Verify the token belongs to the form being submitted
        $submittedFormId = $body['formId'] ?? '';
        if (!empty($payload['formId']) && $payload['formId'] !== $submittedFormId) {
            return false;
        }

        $fields = $body['fields'] ?? [];
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

    private function securityCheck($request) 
    {
        $body = $request->getParsedBody();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $expected = $_SESSION['yn_honeypot_token'] ?? null;

        // If no session token exists the page was rendered without one (e.g. old
        // cached page). Fall back to blocking anything that does not look like a
        // browser-filled empty field rather than silently accepting.
        if (empty($expected)) {
            $this->securityError = array(
                "success" => false,
                "rendered" => "Session expired. Please reload the page and try again."
            );
            return false;
        }

        if (!hash_equals($expected, (string)($body['yn_confirm_email'] ?? ''))) {
            $this->securityError = array(
                "success" => false,
                "rendered" => "The form has no proof that it was sent by a human. Sorry for the inconvenience."
            );
            return false;
        }
        return true;
    }
}