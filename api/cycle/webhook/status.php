<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\RebookingClient;
use FurkiCycle\Http\JsonResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $client = new RebookingClient($env);
    $data = $client->webhookStatus();

    $webhook = is_array($data['data']['webhook'] ?? null) ? $data['data']['webhook'] : [];

    JsonResponse::send([
        'success' => true,
        'registered' => ($webhook['status'] ?? 'NOT_REGISTERED') === 'REGISTERED',
        'status' => $webhook['status'] ?? null,
        'registeredUrl' => $webhook['registeredUrl'] ?? null,
        'notificationEmails' => $webhook['notificationEmails'] ?? null,
        'ticketOriginalStatus' => $webhook['ticketOriginalStatus'] ?? null,
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível consultar o status do webhook.');
}
