<?php

declare(strict_types=1);

/*
 * Furk Rebooking — callback PHP 8.1 para XAMPP/Apache
 *
 * Este arquivo trata somente o callback:
 * 1. valida x-callback-token;
 * 2. consulta GET /v1/rebooking/{id};
 * 3. atualiza a mesma linha em furk_rebooking_control;
 * 4. responde HTTP 200 somente após o UPDATE.
 *
 * As credenciais ficam fora do htdocs em:
 * C:/xampp/secure/furk-webhook-config.php
 */

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function load_config(): array
{
    $path = 'C:/xampp/secure/furk-webhook-config.php';
    if (!is_file($path)) {
        respond([
            'success' => false,
            'stage' => 'configuration',
            'message' => 'Arquivo de configuração não encontrado.',
            'expected_path' => $path,
        ], 500);
    }

    $config = require $path;
    if (!is_array($config)) {
        respond([
            'success' => false,
            'stage' => 'configuration',
            'message' => 'O arquivo de configuração não retornou uma configuração válida.',
        ], 500);
    }
    return $config;
}

function config_required(array $config, string $group, string $key): string
{
    $value = $config[$group][$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        respond([
            'success' => false,
            'stage' => 'configuration',
            'message' => "A configuração {$group}.{$key} não foi preenchida.",
        ], 500);
    }
    return trim($value);
}

function config_optional(array $config, string $group, string $key, string $default): string
{
    $value = $config[$group][$key] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) return trim((string) $_SERVER[$serverKey]);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) return trim((string) $value);
        }
    }
    return '';
}

function http_json(string $url, string $method, array $headers, ?string $body = null): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão HTTP.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($handle, $options);

    $responseBody = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($responseBody === false) {
        throw new RuntimeException($error !== '' ? $error : 'Falha de comunicação HTTP.');
    }

    try {
        $data = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $data = null;
    }

    return [
        'status' => $status,
        'body' => (string) $responseBody,
        'data' => is_array($data) ? $data : null,
    ];
}

function mysql_connection(array $config): PDO
{
    $mysql = $config['mysql'] ?? [];
    $host = config_required($config, 'mysql', 'host');
    $database = config_required($config, 'mysql', 'database');
    $username = config_required($config, 'mysql', 'username');
    $password = config_required($config, 'mysql', 'password');
    $port = isset($mysql['port']) && is_numeric($mysql['port']) ? (int) $mysql['port'] : 3306;
    $charset = config_optional($config, 'mysql', 'charset', 'utf8mb4');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    try {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $error) {
        throw new RuntimeException('Não foi possível conectar ao MySQL. Verifique host, porta, banco, usuário e senha.');
    }
}

function json_text(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function processing_status(?string $status): string
{
    if ($status === 'REBOOKED') return 'ACCEPTED';
    if (in_array($status, ['NOT_REBOOKED', 'ERROR_REBOOKING', 'REBOOKING_DENIED', 'EXPIRED', 'CANCELLATION_DENIED'], true)) return 'REJECTED';
    return 'SENT';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['success' => false, 'message' => 'Este endpoint aceita somente requisições POST.'], 405);
}

if (!function_exists('curl_init')) {
    respond(['success' => false, 'stage' => 'runtime', 'message' => 'A extensão cURL não está habilitada no PHP.'], 500);
}
if (!class_exists(PDO::class) || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    respond(['success' => false, 'stage' => 'runtime', 'message' => 'A extensão pdo_mysql não está habilitada no PHP do Apache.'], 500);
}

$config = load_config();
$expectedToken = config_required($config, 'furk', 'webhook_token');
$receivedToken = request_header('x-callback-token');
$rebookingId = request_header('x-rebooking-id');

if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    respond(['success' => false, 'message' => 'Token de callback inválido.'], 401);
}
if ($rebookingId === '') {
    respond(['success' => false, 'message' => 'O header x-rebooking-id não foi informado.'], 400);
}

$rawBody = file_get_contents('php://input') ?: '';
try {
    $callbackBody = $rawBody === '' ? [] : json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(['success' => false, 'message' => 'O corpo da requisição deve ser um JSON válido.'], 400);
}
if (!is_array($callbackBody)) {
    respond(['success' => false, 'message' => 'O corpo da requisição deve ser um objeto JSON.'], 400);
}

try {
    $clientId = config_required($config, 'furk', 'client_id');
    $clientSecret = config_required($config, 'furk', 'client_secret');
    $apiKey = config_required($config, 'furk', 'api_key');
    $tokenUrl = config_optional($config, 'furk', 'token_url', 'https://auth-api.furk.tech/oauth2/token');
    $rebookingUrl = config_optional($config, 'furk', 'rebooking_url', 'https://rebooking-api.furk.tech/v1/rebooking');
    $scope = config_optional($config, 'furk', 'scope', 'furkcycle-api-modules/rebooking');

    $tokenResponse = http_json($tokenUrl, 'POST', ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => $scope,
    ], '', '&', PHP_QUERY_RFC3986));
    $accessToken = is_array($tokenResponse['data']) ? ($tokenResponse['data']['access_token'] ?? null) : null;
    if ($tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300 || !is_string($accessToken) || $accessToken === '') {
        respond(['success' => false, 'stage' => 'oauth', 'message' => 'A autenticação OAuth na Furk falhou.', 'furk_http_status' => $tokenResponse['status']], 502);
    }

    $url = rtrim($rebookingUrl, '/') . '/' . rawurlencode($rebookingId);
    $getResponse = http_json($url, 'GET', [
        'Authorization: Bearer ' . $accessToken,
        'x-api-key: ' . $apiKey,
        'Accept: application/json',
    ]);
    if ($getResponse['status'] < 200 || $getResponse['status'] >= 300 || !is_array($getResponse['data'])) {
        respond(['success' => false, 'stage' => 'furk_query', 'message' => 'A consulta do Rebooking na Furk falhou.', 'rebooking_id' => $rebookingId, 'furk_http_status' => $getResponse['status']], 502);
    }

    $control = mysql_connection($config);
    $select = $control->prepare('SELECT id, response_payload, validation_notes FROM furk_rebooking_control WHERE furk_rebooking_id = :rebooking_id LIMIT 1');
    $select->execute(['rebooking_id' => $rebookingId]);
    $existing = $select->fetch();
    if ($existing === false) {
        respond(['success' => false, 'stage' => 'mysql_lookup', 'message' => 'O Rebooking não existe em furk_rebooking_control.', 'rebooking_id' => $rebookingId], 404);
    }

    $responseData = $getResponse['data'];
    $rebooking = is_array($responseData['data']['rebooking'] ?? null) ? $responseData['data']['rebooking'] : [];
    $requestedItem = is_array($responseData['data']['requestedItem'] ?? null) ? $responseData['data']['requestedItem'] : [];
    $furkStatus = is_string($rebooking['status'] ?? null) ? $rebooking['status'] : null;
    $oldResponse = json_decode((string) ($existing['response_payload'] ?? ''), true);
    $oldNotes = json_decode((string) ($existing['validation_notes'] ?? ''), true);
    if (!is_array($oldResponse)) $oldResponse = [];
    if (!is_array($oldNotes)) $oldNotes = [];

    $combinedResponse = [
        'initial_post_response' => $oldResponse['initial_post_response'] ?? $oldResponse,
        'webhook_callback' => $callbackBody,
        'status_query_response' => $responseData,
    ];
    $combinedNotes = array_merge($oldNotes, [
        'last_return_source' => 'Furk webhook + GET /v1/rebooking/{rebookingId}',
        'last_webhook_status' => $rebooking['webhookStatus'] ?? null,
        'last_webhook_datetime' => $rebooking['webhookDatetime'] ?? null,
        'callback_processed_at' => gmdate('c'),
    ]);
    $finalStatuses = ['REBOOKED', 'NOT_REBOOKED', 'ERROR_REBOOKING', 'REBOOKING_DENIED', 'EXPIRED', 'CANCELLATION_CONFIRMED', 'CANCELLATION_DENIED', 'PNR_CANCELLED'];
    $nextCheck = in_array($furkStatus, $finalStatuses, true) ? null : gmdate('Y-m-d H:i:s', time() + 300);

    $update = $control->prepare('UPDATE furk_rebooking_control SET furk_status=:furk_status, issue_channel_returned=:issue_channel_returned, http_status=:http_status, processing_status=:processing_status, error_message=NULL, last_checked_at=NOW(6), next_check_at=:next_check_at, response_payload=:response_payload, validation_notes=:validation_notes WHERE id=:id');
    $update->execute([
        'furk_status' => $furkStatus,
        'issue_channel_returned' => $requestedItem['issueChannel'] ?? null,
        'http_status' => $getResponse['status'],
        'processing_status' => processing_status($furkStatus),
        'next_check_at' => $nextCheck,
        'response_payload' => json_text($combinedResponse),
        'validation_notes' => json_text($combinedNotes),
        'id' => (int) $existing['id'],
    ]);

    respond([
        'success' => true,
        'message' => 'Callback processado e controle atualizado.',
        'rebooking_id' => $rebookingId,
        'control_id' => (int) $existing['id'],
        'furk_status' => $furkStatus,
        'processing_status' => processing_status($furkStatus),
        'furk_http_status' => $getResponse['status'],
    ], 200);
} catch (Throwable $error) {
    error_log('furk-webhook: ' . $error->getMessage());
    respond(['success' => false, 'stage' => 'processing', 'message' => $error->getMessage()], 500);
}
