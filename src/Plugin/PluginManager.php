<?php

namespace App\Plugin;

final class PluginManager
{
    private array $hooks;

    public function __construct()
    {
        $hooksFile = dirname(__DIR__, 2) . '/plugins/hooks.php';
        $this->hooks = is_file($hooksFile) ? require $hooksFile : [];
    }

    public function run(string $name, array $context): mixed
    {
        $middleware = $this->hooks[$name] ?? [];
        $next = static fn(array $context): mixed => null;

        foreach (array_reverse($middleware) as $plugin) {
            if (!is_callable($plugin)) {
                throw new \UnexpectedValueException("Plugin registered for {$name} is not callable.");
            }

            $current = $plugin;
            $next = static fn(array $context): mixed => $current($context, $next);
        }

        return $next($context);
    }
}
