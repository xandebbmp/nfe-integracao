<?php
declare(strict_types=1);

function nfe_config_path(): string
{
    return dirname(__DIR__, 2) . '/config/nfe.php';
}

function nfe_load_config(): array
{
    $configFile = nfe_config_path();
    if (!is_file($configFile)) {
        return [];
    }

    $cfg = require $configFile;
    return is_array($cfg) ? $cfg : [];
}

function nfe_request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return '';
}

function nfe_configured_api_token(array $cfg): string
{
    foreach (['apiToken', 'api_token', 'nfe_api_token'] as $key) {
        $token = trim((string)($cfg[$key] ?? ''));
        if ($token !== '') {
            return $token;
        }
    }

    return '';
}

function nfe_request_has_valid_token(array $cfg): bool
{
    $expected = nfe_configured_api_token($cfg);
    if ($expected === '') {
        return false;
    }

    $received = nfe_request_header('X-NFE-API-TOKEN');
    return $received !== '' && hash_equals($expected, $received);
}

function nfe_require_api_token(?array $cfg = null): array
{
    $cfg = $cfg ?? nfe_load_config();
    $expected = nfe_configured_api_token($cfg);
    if ($expected === '') {
        return $cfg;
    }

    if (!nfe_request_has_valid_token($cfg)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'Token de API ausente ou invalido.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    return $cfg;
}

function nfe_is_local_request(): bool
{
    $addr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($addr, ['', '127.0.0.1', '::1'], true);
}

function nfe_debug_raw_enabled(?array $cfg = null): bool
{
    $cfg = $cfg ?? nfe_load_config();
    if (empty($cfg['debugRawOutput'])) {
        return false;
    }

    if (nfe_configured_api_token($cfg) !== '') {
        return nfe_request_has_valid_token($cfg);
    }

    return nfe_is_local_request();
}

function nfe_maybe_raw(array $raw, ?array $cfg = null): ?array
{
    return nfe_debug_raw_enabled($cfg) ? $raw : null;
}
