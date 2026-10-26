# Customer plugins

This directory holds customer-specific extensions. The shared application code lives in `src/`; plugin implementations and their registration live here so they can remain customer-owned when shared `src/` code is updated.

## How the plugin system works

`src/Plugin/PluginManager.php` loads `plugins/hooks.php` once for each manager instance. That file returns an associative array whose keys are named extension points and whose values are ordered arrays of middleware callables. If `plugins/hooks.php` does not exist, the manager has no registered hooks and calls simply continue.

Each middleware receives the current context and a continuation:

```php
function (array $context, callable $next): mixed {
    // Inspect the context, then let the remaining middleware run.
    return $next($context);
}
```

Call `$next($context)` to let the next middleware run. Return a non-null value instead to stop the chain and hand that value back to the controller/service. `null` means “continue”; middleware are run in the order listed in `hooks.php`. The context is passed by value, so changing it only affects downstream middleware; use a non-null return to replace the operation result. Plugins are trusted PHP code and run with the same privileges as the application.

`src/Plugin/RunsPlugins.php` is a trait used by actions and services. It provides the protected helper:

```php
$result = $this->runPluginHook('some.extension_point', [
    'request' => $request,
    'operation' => 'example.operation',
]);
```

The extension-point call site determines the meaning and required type of a non-null result. Keep contexts small, document each new hook, and return a value matching the consumer's contract.

## Register middleware

Create or edit `plugins/hooks.php`:

```php
<?php

require_once __DIR__ . '/ExampleMiddleware.php';

return [
    'page.after_render' => [
        new ExampleMiddleware(),
    ],
];
```

Implement a callable class or use a closure. This middleware example changes rendered HTML:

```php
<?php

final class ExampleMiddleware
{
    public function __invoke(array $context, callable $next): mixed
    {
        $html = $context['result'] ?? null;
        $nextResult = $next($context);
        if (is_string($nextResult)) {
            $html = $nextResult;
        }

        if (!is_string($html)) {
            return $html;
        }

        return str_replace('</body>', '<!-- customer addition --></body>', $html);
    }
}
```

Put secrets in environment variables, not in `hooks.php` or plugin source. Treat request values as untrusted input and validate them before use.

## Current extension points

| Hook | Context | Non-null return |
| --- | --- | --- |
| `form.before_forward` | `request`, `postBody`, `operation` | Regular form service result, e.g. `['type' => 'error', 'message' => '...']` to reject. |
| `form.after_forward` | Same, plus `result` | Replacement service result with `statusCode` and `body`. |
| `gdpr.request.before_forward`, `gdpr.update.before_forward` | `request`, `postBody`, `operation` | `['response' => '...']` to short-circuit. |
| `gdpr.request.after_forward`, `gdpr.update.after_forward` | Same, plus `result` | Replacement service result with `body` and `statusCode`. |
| `content.before_fetch`, `content.after_fetch` | `request`, `postData`, `operation`; after also has `result` | Content payload to use instead of the service result. |
| `page.before_fetch`, `page.after_fetch` | `request`, `operation`; after also has `result` | Page data to use instead of the service result. |
| `page.before_render`, `page.after_render` | `request`, `templates`, `data`, `operation`; after also has `result` | Rendered HTML string. |
| `robots.before_generate`, `robots.after_generate` | `request`, `operation`; after also has `result` | `robots.txt` content string. |
| `sitemap.before_fetch` | `request`, `operation` | Sitemap data to use instead of fetching it. |
| `sitemap.after_render` | `request`, `operation`, `sitemap`, `result` | Replacement XML string. |
| `cache.invalidate.before`, `cache.invalidate.after` | `request`, `cacheKeys`, `operation`; after also has `result` | Boolean result to skip or replace invalidation. |
| `cache.invalidate_all.before`, `cache.invalidate_all.after` | `request`, `operation`; after also has `result` | Boolean result to skip or replace invalidation. |
| `updater.before_check`, `updater.after_check` | `request`, `query`, `operation`; after also has `result` | Plain-text response string. |

On “before” hooks, returning a replacement value skips the operation; on “after” hooks, a replacement value substitutes for its result. Returning `null` (usually by returning `$next($context)`) preserves normal behavior.

## Adding an extension point

When a customer needs to customize a new shared-code operation:

1. Add a stable, operation-specific hook name at the appropriate point in the shared `src/` controller/service. For example, use `account.before_update` immediately before forwarding or persisting an account update.
2. Pass a small context array containing the request and only the operation data needed by extensions.
3. Define what a non-null return means and which type/shape is valid. Before-hooks commonly accept a replacement result to skip the operation; after-hooks commonly accept a replacement result.
4. Add the hook and its context/result contract to this README.
5. Register customer middleware for the hook name in `plugins/hooks.php`.

Example in a class using `RunsPlugins`:

```php
$context = [
    'request' => $request,
    'payload' => $payload,
    'operation' => 'account.update',
];
$pluginResult = $this->runPluginHook('account.before_update', $context);
if ($pluginResult !== null) {
    return $pluginResult;
}

$result = $this->updateAccount($payload);
```

For a class that does not use the trait, instantiate `App\Plugin\PluginManager` and call `run($hookName, $context)` directly. A new hook requires a change in the shared `src/` repository; plugin implementations and registrations stay in the customer repository and survive shared-code updates.
