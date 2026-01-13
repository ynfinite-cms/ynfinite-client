<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use App\Domain\Request\Service\RequestService;

use App\Domain\Request\Utils\CurlHandler;

final class GdprUpdateService extends RequestService
{
    private $repository;
    public $settings;

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

        $response = $this->request(trim($path), $this->settings["services"]["gdpr_update"], $postBody, $jsonResponse);

        return $response;
    }
}