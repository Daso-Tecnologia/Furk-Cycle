<?php
declare(strict_types=1);

/*
 * Router do servidor embutido do PHP (somente desenvolvimento):
 *   php -S 0.0.0.0:8000 -t public public/router.php
 *
 * Arquivos estáticos reais dentro de public/ são servidos pelo próprio servidor;
 * todo o resto (HTML, /login, /status e /api/*) segue para o front controller
 * index.php, que é a única implementação de roteamento (e a única que valida o
 * caminho de /api/*).
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';

$publicRoot = realpath(__DIR__);
$candidate = realpath(__DIR__ . $uri);

if (
    $publicRoot !== false
    && $candidate !== false
    && is_file($candidate)
    && strncasecmp($candidate, $publicRoot . DIRECTORY_SEPARATOR, strlen($publicRoot) + 1) === 0
    && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php'
) {
    return false;
}

require __DIR__ . '/index.php';
