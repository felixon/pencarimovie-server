<?php

declare(strict_types=1);

$path = '/app/backend.php';
$text = file_get_contents($path);
if ($text === false) {
    throw new RuntimeException('Unable to read backend.php');
}

if (!str_contains($text, 'function fd_is_render_request(): bool')) {
    $marker = 'function fd_is_cloudflare_tunnel_request(): bool';
    $pos = strpos($text, $marker);
    if ($pos === false) {
        throw new RuntimeException('Cloudflare tunnel function marker not found');
    }

    $renderFn = <<<'PHP'
function fd_is_render_request(): bool
{
    // Render sets RENDER=true on deployed services.
    return strtolower((string) ($_ENV['RENDER'] ?? $_SERVER['RENDER'] ?? '')) === 'true'
        || strtolower((string) ($_ENV['RENDER_SERVICE_ID'] ?? '')) !== '';
}

PHP;

    $text = substr($text, 0, $pos) . $renderFn . substr($text, $pos);
}

$old = <<<'PHP'
    if (!in_array($path, $alwaysPublicApi, true)) {
        $allowViaTunnel = fd_is_cloudflare_tunnel_request() && in_array($path, $tunnelReadableApi, true);
        if (!$allowViaTunnel) {
            fd_require_local_request();
        }
    }
PHP;

$new = <<<'PHP'
    if (!in_array($path, $alwaysPublicApi, true)) {
        $allowViaTunnel =
            fd_is_cloudflare_tunnel_request()
            && in_array($path, $tunnelReadableApi, true);

        // Render-hosted dashboard authentication must be reachable
        // through the public Render URL. Keep all other protected
        // administrative endpoints local/tunnel-only.
        $allowRenderBotLogin =
            fd_is_render_request()
            && $path === '/api/botlogin'
            && $method === 'POST';

        if (!$allowViaTunnel && !$allowRenderBotLogin) {
            fd_require_local_request();
        }
    }
PHP;

if (!str_contains($text, $new)) {
    if (!str_contains($text, $old)) {
        throw new RuntimeException('API request guard block not found');
    }
    $text = str_replace($old, $new, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected one API guard block, found {$count}");
    }
}

if (file_put_contents($path, $text) === false) {
    throw new RuntimeException('Unable to write patched backend.php');
}

echo "Render bot-login patch applied successfully\n";
