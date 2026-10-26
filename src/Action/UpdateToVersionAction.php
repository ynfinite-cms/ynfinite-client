<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Service\GetRobotsTxtService;
use App\Plugin\RunsPlugins;

use SlimSession\Helper as SessionHelper;


require_once(__DIR__."/../../config/updater.php");
require_once(__DIR__."/../Updater/updater_functions.php");

final class UpdateToVersionAction
{
    use RunsPlugins;
    public $getRobotsTxtService;

    public function __construct(GetRobotsTxtService $getRobotsTxtService) {
        $this->getRobotsTxtService = $getRobotsTxtService;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        
        $data = (array)$request->getServerParams();
        $query = array();
        parse_str($data["QUERY_STRING"], $query);

        $updaterSettingsNotifications = ausCheckSettings();
        if (!empty($updaterSettingsNotifications)) //invalid settings
        {
            // Build the HTTP response
            $response->getBody()->write("Invalid settings, contact script developer");
            return $response
                ->withHeader('Content-Type','text/plain')
                ->withStatus(201);
        }

        $context = ['request' => $request, 'query' => $query, 'operation' => 'updater.check'];
        $pluginResult = $this->runPluginHook('updater.before_check', $context);
        if (is_string($pluginResult)) {
            $response->getBody()->write($pluginResult);
            return $response->withHeader('Content-Type', 'text/plain')->withStatus(200);
        }

        $version = $query["version"]; // if version is set, try to update to this version

        $version_notifications_array = ausGetVersion(); //get data of latest available version
        if ($version_notifications_array['notification_case']=="notification_operation_ok") //'notification_operation_ok' case returned - operation succeeded
        {
            $result = $version_notifications_array['notification_data']['product_title']." version ".$version_notifications_array['notification_data']['version_number']." details parsed.";
        }
        else //Other case returned - operation failed
        {
            $result = "Version details could not be parsed because of this reason: ".$version_notifications_array['notification_text'];
        }

        $context['result'] = $result;
        $pluginResult = $this->runPluginHook('updater.after_check', $context);
        if (is_string($pluginResult)) {
            $result = $pluginResult;
        }

        $response->getBody()->write($result);
            return $response
                ->withHeader('Content-Type','text/plain')
                ->withStatus(201);
    }
}
