<?php
declare(strict_types=1);

/**
 * public/index.php
 * Entrada única da API (roteador mínimo)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Caminho puro (sem querystring)
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Normaliza barras e remove trailing slash (exceto raiz)
$path = preg_replace('~/{2,}~', '/', $path) ?? $path;
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

// Healthcheck simples
if ($path === '/health' || $path === '/v1/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok']);
    exit;
}

$routes = [

    'GET' => [
        '/v1/nfe/consultar_padrao' => __DIR__ . '/v1/nfe/consultar_padrao.php',
        '/v1/nfe/status_padrao'    => __DIR__ . '/v1/nfe/status_padrao.php',
    ],

    'POST' => [
        '/v1/nfe/emitir_padrao'  => __DIR__ . '/v1/nfe/emitir_padrao.php',
        '/v1/nfe/cce_padrao' => __DIR__ . '/v1/nfe/cce_padrao.php',
        '/v1/nfe/cancelar_padrao' => __DIR__ . '/v1/nfe/cancelar_padrao.php',
        '/v1/nfe/inutilizar_padrao' => __DIR__ . '/v1/nfe/inutilizar_padrao.php',

    ],
];

$target = $routes[$method][$path] ?? null;

if (!$target || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'  => 'Not Found',
        'method' => $method,
        'path'   => $path,
    ]);
    exit;
}

require $target;
