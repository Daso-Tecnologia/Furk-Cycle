<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingControlRepository;
use FurkiCycle\Webhook\WebhookExportService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }
    $search = trim((string) ($body['search'] ?? ''));

    $environment = $env->optional('FURK_ENVIRONMENT', 'production') ?? 'production';
    $repository = new RebookingControlRepository($env);
    $rows = $repository->webhookEventsAll($environment, $search);

    $exporter = new WebhookExportService();
    $exporter->assertExportable(count($rows));
    $file = $exporter->export($rows);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('Content-Length: ' . strlen($file['bytes']));
    header('Cache-Control: no-store');
    echo $file['bytes'];
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível gerar a planilha de eventos.');
}
