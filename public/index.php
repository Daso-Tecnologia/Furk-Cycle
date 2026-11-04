<?php

declare(strict_types=1);

// 1. AUTOLOAD DO COMPOSER
$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo '<h1>Erro de Configuração</h1>';
    echo '<p>O arquivo de autoload do Composer não foi encontrado. Por favor, rode <code>composer install</code> na raiz do projeto.</p>';
    exit;
}

require_once $autoloadPath;

// 2. EXIBIÇÃO DE ERROS (condicionada ao ambiente)
$env = new FurkiCycle\Config\Env();
$appDebug = strtolower((string) $env->optional('APP_DEBUG', 'false')) === 'true';

if ($appDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// 3. CAPTURA DA REQUISIÇÃO
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 3.1 BASE PATH (aplicação servida sob /furk-cycle)
// Remove o prefixo do subcaminho para que as rotas absolutas (/login, /api/*) funcionem.
$basePath = '/furk-cycle';
if (!is_string($uri)) {
    $uri = '/';
}
if ($basePath !== '' && strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}
if ($uri === '') {
    $uri = '/';
}
if ($appDebug) {
    error_log("DEBUG: uri=$uri method=$method");
}

// 4. CABEÇALHOS DE SEGURANÇA E CORS
// O front é servido pela mesma origem da API, então CORS fica DESLIGADO por padrão.
// Para liberar uma origem específica, defina CORS_ALLOWED_ORIGINS (lista separada por vírgula).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) $env->optional('CORS_ALLOWED_ORIGINS', ''))));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Vary: Origin');
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Resolve a rota /api/... para um arquivo dentro de api/, ou null.
 * Rejeita qualquer caminho que escape de api/ (ex.: /api/../src/...), pois o arquivo
 * resolvido é incluído e executado.
 */
function resolveApiFile(string $uri): ?string
{
    if (str_contains($uri, "\0") || str_contains($uri, '..') || str_contains($uri, '\\')) {
        return null;
    }
    if (!str_ends_with($uri, '.php')) {
        return null;
    }

    $apiRoot = realpath(dirname(__DIR__) . '/api');
    $file = realpath(dirname(__DIR__) . $uri);
    if ($apiRoot === false || $file === false || !is_file($file)) {
        return null;
    }
    if (strncasecmp($file, $apiRoot . DIRECTORY_SEPARATOR, strlen($apiRoot) + 1) !== 0) {
        return null;
    }

    return $file;
}

// =========================================================================
// 5. ROTAS DO SISTEMA ( MAPEAMENTO DOS MÓDULOS DO MENU )
// =========================================================================

// MÓDULO: API routes — encaminha para arquivos em /api/
if (strpos($uri, '/api/') === 0) {
    $apiFile = resolveApiFile($uri);
    if ($apiFile !== null) {
        require_once $apiFile;
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not Found', 'message' => 'Rota da API não encontrada.']);
    exit;
}

// MÓDULOS: telas HTML. Para adicionar uma tela nova, acrescente uma linha aqui.
$pages = [
    '/login' => 'login.html',
    '/' => 'creditos-elegiveis.html',
    '/index.php' => 'creditos-elegiveis.html',
    '/creditos-elegiveis.html' => 'creditos-elegiveis.html',
    '/consulta-localizador.html' => 'consulta-localizador.html',
    '/status-dinamicos-leitura.html' => 'status-dinamicos-leitura.html',
    '/rotina-continua.html' => 'rotina-continua.html',
    '/reembolsos-elegiveis.html' => 'reembolsos-elegiveis.html',
    '/sales-leitura.html' => 'sales-leitura.html',
    '/sales-rotina-continua.html' => 'sales-rotina-continua.html',
    '/sync-consolidada.html' => 'sync-consolidada.html',
    '/sync-continua.html' => 'sync-continua.html',
    '/sync-rotina.html' => 'sync-rotina.html',
    '/rebookings.html' => 'rebookings.html',
    '/webhook.html' => 'webhook.html',
    '/configuracoes.html' => 'configuracoes.html',
    '/auditoria.html' => 'auditoria.html',
    '/usuarios.html' => 'usuarios.html',
    '/guia.html' => 'guia.html',
];

if (isset($pages[$uri])) {
    $publicFile = __DIR__ . '/' . $pages[$uri];
    if (!is_file($publicFile)) {
        http_response_code(404);
        echo 'Página não encontrada.';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicFile);
    exit;
}

// MÓDULO: Status da API / Healthcheck (sem versão do PHP nem detalhes do servidor)
if ($uri === '/status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'online', 'api_name' => 'Furk Cycle API']);
    exit;
}

// =========================================================================
// 6. ROTA NÃO ENCONTRADA (Fallback 404)
// =========================================================================
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'Not Found',
    'message' => 'A rota solicitada não foi encontrada neste servidor.',
]);
