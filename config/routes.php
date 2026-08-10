<?php

use Slim\App;

return function (App $app) {
    $app->get('/yn-updater/update', \App\Action\UpdateToVersionAction::class);
    $app->post('/yn-cache/invalidate', \App\Action\InvalidateCacheAction::class);
    $app->get('/yn-cache/invalidateAll', \App\Action\InvalidateAllCacheAction::class);

    $app->get("/yn-gdpr/request", \App\Action\GdprRequestAction::class);

    $app->get('/sitemap.xml', \App\Action\GetSitemapAction::class);
    $app->get('/{lang:.*}/sitemap.xml', \App\Action\GetSitemapAction::class);
    $app->get('/robots.txt', \App\Action\GetRobotsTxtAction::class);
    
    $app->post('/yn-form/send', \App\Action\SendFormAction::class);
    $app->post('/yn-gdpr/request', \App\Action\GdprRequestAction::class);
    $app->post('/yn-gdpr/update', \App\Action\GdprUpdateAction::class);
    
    $app->post('/yn-api/content', \App\Action\ApiGetContentAction::class);

    $app->get('/yn-api/cache', \App\Action\GetApiCacheAction::class);
    $app->post('/yn-api/cache', \App\Action\SetApiCacheAction::class);

    $app->get('[/{params:.*}]', \App\Action\RenderPageAction::class);
    $app->post('[/{params:.*}]', \App\Action\RenderPageAction::class);
};