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
            $body = json_decode(file_get_contents('php://input'));
        }

        if(!$body["hasProof"] || !$body["proofenHash"]) {
            throw new Exception("The form has no proof that is was sent by a human. Sorry for you inconvenience.");
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