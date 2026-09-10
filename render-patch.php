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

// Render-safe shortcode resolution helper. The normal resolver races all bots
// through curl_multi. That is useful locally, but on Render the sequential HTTP
// path is more reliable and gives the WordPress/Telegram relay enough time to
// finish resolving the file.
if (!str_contains($text, 'function fd_resolve_shortcode_render_safe(string $shortCode')) {
    $marker = 'function fd_resolve_shortcode_concurrent(string $shortCode, array $candidateBots = []): array';
    $pos = strpos($text, $marker);
    if ($pos === false) {
        throw new RuntimeException('Shortcode concurrent resolver marker not found');
    }

    $renderResolver = <<<'PHP'
function fd_resolve_shortcode_render_safe(string $shortCode, array $candidateBots = []): array
{
    $shortCode = trim($shortCode);
    if ($shortCode === '') {
        return ['ok' => 0, 'message' => 'short_code is required.'];
    }

    if ($candidateBots === []) {
        $activeBotId = trim((string) fd_get_bot_id());
        if ($activeBotId !== '') {
            $candidateBots[] = $activeBotId;
        }

        foreach (fd_get_bot_pool() as $poolBot) {
            $poolId = trim((string) ($poolBot['bot_id'] ?? ''));
            if ($poolId !== '' && !in_array($poolId, $candidateBots, true)) {
                $candidateBots[] = $poolId;
            }
        }
    }

    $candidateBots = array_values(array_unique(array_filter(array_map('strval', $candidateBots))));
    if ($candidateBots === []) {
        return ['ok' => 0, 'message' => 'No active bots available for resolution.'];
    }

    $lastError = null;
    foreach ($candidateBots as $botId) {
        $cached = fd_resolve_shortcode_cached($shortCode, $botId);
        if ($cached !== null) {
            if (empty($cached['bot_id'])) {
                $cached['bot_id'] = $botId;
            }
            return $cached;
        }

        $url = FD_WP_API_BASE . '/resolve-file';
        $params = [
            'short_code' => $shortCode,
            'bot_id' => $botId,
        ];

        // Render needs more time than the normal 5-second resolver because
        // WordPress may need to ask Telegram for the file before responding.
        $result = fd_http_json($url, $params, 'GET', 20);

        if (!empty($result['file_id_mt']) || !empty($result['file_id'])) {
            $result['ok'] = 1;
            if (empty($result['bot_id'])) {
                $result['bot_id'] = $botId;
            }
            fd_save_resolve_cache($shortCode, $botId, $result);
            fd_log('render-safe shortcode resolved', [
                'short_code' => $shortCode,
                'bot_id' => $botId,
            ]);
            return $result;
        }

        $lastError = is_array($result) ? $result : ['ok' => 0, 'message' => 'Invalid resolver response.'];
        fd_log('render-safe shortcode attempt failed', [
            'short_code' => $shortCode,
            'bot_id' => $botId,
            'message' => (string) ($lastError['message'] ?? 'Unknown resolver error'),
        ]);
    }

    return $lastError ?: [
        'ok' => 0,
        'message' => 'Failed to resolve short code across all available bots.',
    ];
}

PHP;

    $text = substr($text, 0, $pos) . $renderResolver . substr($text, $pos);
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

// The public Render file-detail resolver should avoid curl_multi. Keep the
// normal concurrent resolver unchanged for local installs and downloads.
$oldPublicResolve = <<<'PHP'
        // Use concurrent multi-bot resolution across all pool bots for instantaneous resolution
        // Pass empty candidate bots so it queries all pool bots + active bot simultaneously
        $result = fd_resolve_shortcode_concurrent($shortCode);
PHP;

$newPublicResolve = <<<'PHP'
        // Render uses a sequential resolver with a longer upstream timeout;
        // local installations keep the normal concurrent resolver.
        $result = fd_is_render_request()
            ? fd_resolve_shortcode_render_safe($shortCode)
            : fd_resolve_shortcode_concurrent($shortCode);
PHP;

if (!str_contains($text, $newPublicResolve)) {
    if (!str_contains($text, $oldPublicResolve)) {
        throw new RuntimeException('Public shortcode resolver call block not found');
    }
    $text = str_replace($oldPublicResolve, $newPublicResolve, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected one public resolver call block, found {$count}");
    }
}

// The standalone server is not loaded inside WordPress, so this WordPress
// helper may not exist. Keep the existing country-header behavior without
// taking a dependency on WordPress functions.
$oldSanitize = "sanitize_text_field(\$_SERVER['HTTP_CF_IPCOUNTRY'])";
$newSanitize = "preg_replace('/[^A-Za-z0-9_-]/', '', (string) \$_SERVER['HTTP_CF_IPCOUNTRY'])";
if (str_contains($text, $oldSanitize)) {
    $text = str_replace($oldSanitize, $newSanitize, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected one sanitize_text_field call, found {$count}");
    }
}

// Fix route initialization order. /configure checks $addonPath before it is
// normalized, which produces a warning on the standalone FrankenPHP server.
$oldAddonOrder = <<<'PHP'
    // Handle Stremio's standard /configure route -> redirects directly to dashboard with #configure
    if ($path === '/configure' || $path === '/configure/' || $addonPath === '/configure' || $addonPath === '/configure/') {
        header('Location: /#configure', true, 302);
        exit;
    }

    // Normalize path by stripping /nuvio or /stremio prefix if present so internal matching is uniform
    $addonPath = preg_replace('#^/(nuvio|stremio)#', '', $path);
PHP;

$newAddonOrder = <<<'PHP'
    // Normalize path by stripping /nuvio or /stremio prefix if present so internal matching is uniform
    $addonPath = preg_replace('#^/(nuvio|stremio)#', '', $path);
    if ($addonPath === '') {
        $addonPath = '/';
    }

    // Handle Stremio's standard /configure route -> redirects directly to dashboard with #configure
    if ($path === '/configure' || $path === '/configure/' || $addonPath === '/configure' || $addonPath === '/configure/') {
        header('Location: /#configure', true, 302);
        exit;
    }
PHP;

if (str_contains($text, $oldAddonOrder)) {
    $text = str_replace($oldAddonOrder, $newAddonOrder, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected one addon path block, found {$count}");
    }
}

if (file_put_contents($path, $text) === false) {
    throw new RuntimeException('Unable to write patched backend.php');
}

echo "Render bot-login, shortcode-resolution, and standalone compatibility patches applied successfully\n";
