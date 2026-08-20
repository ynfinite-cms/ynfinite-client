<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Service\RequestPageService;
use App\Domain\Request\Service\RenderPageService;
use App\Domain\Request\Service\CacheService;
use SlimSession\Helper as SessionHelper;

use App\Exception\YnfiniteException;
use Exception;

final class RenderPageAction
{
    public $requestPageService;
    public $renderPageService;
    public $cacheService;

    public function __construct(RequestPageService $requestPageService, RenderPageService $renderPageService, CacheService $cacheService) {
        $this->requestPageService = $requestPageService;
        $this->renderPageService = $renderPageService;
        $this->cacheService = $cacheService;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        try {
            if(!$this->requestPageService->isValidUrl()){
                http_response_code(410);
                die();
            }

            if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] == '/logout') {
                $expires = time() - 3600;
                if (isset($_COOKIE['leadGroupIds'])) {
                    setcookie('leadGroupIds', '', $expires, '/');
                    unset($_COOKIE['leadGroupIds']);
                }
                if (isset($_COOKIE['loginToken'])) {
                    setcookie('loginToken', '', $expires, '/');
                    unset($_COOKIE['loginToken']);
                }
                setcookie('ynfinite-session', '', $expires, '/');
                unset($_COOKIE['ynfinite-session']);
                header('Location: /');
                die();
            }
            
            $formRequest = $request->getParsedBody();
            if($formRequest && $formRequest["method"] == "post" && !isset($formRequest["hasProof"])){
                $this->securityError = array(
                    "success" => false,
                    "rendered" => "The form has no proof that it was sent by a human. Sorry for the inconvenience."
                );
            }
            
            $data = $this->requestPageService->getPage($request);

            if (isset($data['type']) && in_array($data['type'], ['maintenance', 'offline'], true)) {
                $maintenanceStatus = isset($data['statusCode']) ? (int) $data['statusCode'] : 503;
                $maintenanceMessage = $data['message'] ?? 'Service temporarily unavailable';
                $lang = $this->resolveRequestLanguage();
                $rendered = $this->renderSystemTemplate('maintenance.twig', [
                    'message'  => $maintenanceMessage,
                    'statusCode' => $maintenanceStatus,
                    'headline' => $this->getMaintenanceTranslation('yn_maintenanceHeadline', $lang, "We'll be back shortly."),
                    'badge'    => $this->getMaintenanceTranslation('yn_maintenanceBadge', $lang, 'Maintenance in progress'),
                ]);

                if ($rendered !== null) {
                    $response->getBody()->write($rendered);
                } else {
                    $response->getBody()->write($maintenanceMessage);
                }

                return $response->withStatus($maintenanceStatus);
            }
            
            if ((isset($data['type']) && $data['type'] === 'error') || !isset($data['type'])) {
                $errorStatus = isset($data['statusCode']) ? (int) $data['statusCode'] : 500;
                $errorMessage = $data['message'] ?? 'An unexpected error occurred.';
                $rendered = $this->renderSystemTemplate('error.twig', [
                    'message' => $errorMessage,
                    'statusCode' => $errorStatus,
                ]);

                if ($rendered !== null) {
                    $response->getBody()->write($rendered);
                    return $response->withStatus($errorStatus);
                }

                $response->getBody()->write('An error occurred: ' . $errorMessage);
                return $response->withStatus($errorStatus);
            }

            if (isset($data['type']) && $data['type'] === 'redirect') {
                return $response->withHeader('Location', $data['url'])->withStatus($data['statusCode']);
            }

            if (isset($data['type']) && $data['type'] === '404') {
                $renderedTemplate = $this->renderPageService->render($data["templates"], $data["data"]);
                $response->getBody()->write($renderedTemplate);
                return $response->withStatus(404);
            }   

            if (is_array($data)) {
                if (isset($data['data']['leadGroupIds'])) {
                    $leadGroupIds = $data['data']['leadGroupIds'];
                    setcookie('leadGroupIds', $leadGroupIds, time() + (86400 * 30), "/");
                    $_COOKIE['leadGroupIds'] = $leadGroupIds;
                } elseif (isset($data['data']['user']) && !isset($_COOKIE['leadGroupIds'])) {
                    setcookie('leadGroupIds', 'empty', time() + (86400 * 30), "/");
                    $_COOKIE['leadGroupIds'] = 'empty';
                }
                if (isset($_COOKIE["loginToken"])) {
                    $_SESSION["loginToken"] = $_COOKIE["loginToken"];
                }
                if (!isset($data["data"]["errors"]) && isset($_POST['fields'])
                    && isset($_POST['fields']['general_e-mail'])
                    && isset($_POST['fields']['general_password'])) {
                    return $response->withHeader('Location', $_SERVER['REQUEST_URI'])->withStatus(302);
                }
                $renderedTemplate = $this->renderPageService->render($data["templates"], $data["data"]);
                if($data["data"]["page"]["type"] !== "404") {
                    $this->cacheService->createCache("PAGE", $renderedTemplate);
                }

                $response->getBody()->write($renderedTemplate);
                return $response->withStatus(200);
            } else {
                $response->getBody()->write($data);
                return $response->withStatus(200);
            }
        }
        catch (YnfiniteException $e) {
            return $this->handleException($e, $response);
        }

        // This should never be reached due to explicit returns above
        return $response->withStatus(500);
    }

    private function resolveRequestLanguage(): string
    {
        // Try to extract a 2-letter language prefix from the URL path (e.g. /de/... or /en/...)
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        if (preg_match('#^/([a-z]{2})(/|$)#', $path, $m)) {
            return $m[1];
        }

        // Fall back to the first language in the Accept-Language header
        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($acceptLang && preg_match('/^([a-z]{2})/i', $acceptLang, $m)) {
            return strtolower($m[1]);
        }

        return 'de';
    }

    private function getMaintenanceTranslation(string $key, string $lang, string $fallback): string
    {
        $localeDir = __DIR__ . '/../locale/';

        foreach ([$lang, 'de', 'en'] as $try) {
            $file = $localeDir . 'lang_' . $try . '.ini';
            if (file_exists($file)) {
                $strings = parse_ini_file($file);
                if ($strings !== false && isset($strings[$key])) {
                    return $strings[$key];
                }
            }
        }

        return $fallback;
    }

    private function renderSystemTemplate(string $templateName, array $context): ?string
    {
        $templatePath = $this->resolveSystemTemplatePath($templateName);

        if ($templatePath === null) {
            return null;
        }

        $loader = new \Twig\Loader\FilesystemLoader(dirname($templatePath));
        $twig = new \Twig\Environment($loader);

        return $twig->render(basename($templatePath), $context);
    }

    private function resolveSystemTemplatePath(string $templateName): ?string
    {
        $candidates = [
            getcwd() . '/../templates/' . $templateName,
            getcwd() . '/../templates/yn/' . $templateName,
            __DIR__ . '/../templates/yn/' . $templateName,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function handleException($e, $response) {
        $error = [];
        $error["message"] = $e->getMessage();
        $error["code"] = $e->getCode();
        $error["trace"] = $e->getTraceAsString();

        $response = $response->withStatus($error["code"]);

        switch ($e->getRenderType()) {
            case "error":
                $response->withHeader('Content-Type', 'text/html');
                return $response;
                break;
            case "template":
                if ($e->getRedirect() != null) {
                    return $response->withRedirect($e->getRedirect(), 301);
                }

                $renderedTemplate = $this->renderPageService->render($e->getTemplates(), $e->getData());
                $response->getBody()->write($renderedTemplate);

                return $response;
                break;
        }
    }
}