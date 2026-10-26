<?php

namespace App\Plugin;

trait RunsPlugins
{
    private ?PluginManager $pluginManager = null;

    protected function runPluginHook(string $name, array $context): mixed
    {
        $this->pluginManager ??= new PluginManager();
        return $this->pluginManager->run($name, $context);
    }
}
