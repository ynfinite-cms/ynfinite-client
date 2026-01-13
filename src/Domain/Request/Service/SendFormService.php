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
        $postBody["referer"] = $_SERVER['HTTP_REFERER'];
        
        if($postBody['method'] === "post" && !$this->securityCheck($request)) {
            return $this->securityError;
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
            if(isset($body["loginToken"]) && strlen($body["loginToken"] > 0)){
                $_SESSION["loginToken"] = $body["loginToken"];
            };
            return array("type" => 'redirect', "statusCode" => $statusCode, "url" => $body["url"]);
        } else {
            // Error
            return array("type" => 'error', 'message' => $body["message"]);
        }
    }

    private function securityCheck($request) 
    {
        $body = $request->getParsedBody();
        if($body["yn_confirm_email"] !== "my@email.com") {
            $this->securityError = array(
                "success" => false,
                "rendered" => "The form has no proof that is was sent by a human. Sorry for you inconvenience."
            );
               
            return false;
        }
        return true;
    }
}