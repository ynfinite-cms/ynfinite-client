<?php

namespace App\Domain\Request\Service;

use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Repository\RequestCacheRepository;

use Psr\Container\ContainerInterface;

use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use \Twig\Extension\DebugExtension;
use Cocur\Slugify\Bridge\Twig\SlugifyExtension;
use Cocur\Slugify\Slugify;



final class RenderSitemapService
{

    private $repository;
    public $settings;
    public $twig;

    public function __construct(ContainerInterface $container) {
        $this->settings = $container->get("settings");

        $loader = new FilesystemLoader([getcwd(). "/../src/" . $this->settings["ynfinite"]["templateDir"], getcwd() . '/../templates']);
        $this->twig = new Environment($loader, ['debug' => true, /* 'cache' => getcwd().'/../tmp/twig_cache', */]);
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addExtension(new DebugExtension());
        $this->twig->addExtension(new SlugifyExtension(Slugify::create()));
    }

    public function render($sitemap) {
        // Handle the wrapped structure from GetSitemapService
        if (isset($sitemap['isIndex']) && isset($sitemap['entries'])) {
            // Determine which template to use
            $template = $sitemap['isIndex'] 
                ? "yn/module/sitemap/sitemap_index.twig" 
                : "yn/module/sitemap/index.twig";
            
            $variableName = $sitemap['isIndex'] ? "sitemaps" : "sitemap";
            
            return $this->twig->render($template, [$variableName => $sitemap['entries']]);
        }

        // Fallback for flat array structure (if backend behavior changes)
        return $this->twig->render("yn/module/sitemap/index.twig", ["sitemap" => $sitemap]);
    }

}