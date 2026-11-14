<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ImportGuard;
use FurkiCycle\Sync\SyncContinuoRepository;
use FurkiCycle\Sync\SyncContinuoService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Rota somente POST (todas as ações, inclusive a leitura de status).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $connection = MySqlConnection::create($env);
    $repository = new SyncContinuoRepository($connection);
    $service = new SyncContinuoService($env, $connection, $repository);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $action = $body['action'] ?? null;

    // Iniciar/retomar/reprocessar a varredura: bloqueado com a Recursiva habilitada ou outra carga em
    // andamento (409). Ela já pausa as rotinas de Status e Sales por desenho, então estas são ignoradas.
    if (in_array($action, ['start', 'resume', 'retryFailed'], true)) {
        (new ImportGuard($env))->requireCargaPermitida(
            $claims,
            ['furk_dynamic_status_job', 'furk_sales_job', 'furk_sync_continuo_job'],
        );
    }

    $id = isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null;

    $job = match ($action) {
        'status' => $service->status($id),
        'start' => $service->start(
            (string) ($body['initialDate'] ?? ''),
            (string) ($body['finalDate'] ?? ''),
            (int) ($body['blockDays'] ?? 5),
        )['job'],
        'stop' => $service->stop(),
        'resume' => $service->resume(),
        'retryFailed' => $service->requestRetryFailed(),
        default => throw new RuntimeException('Ação inválida. Use status, start, stop, resume ou retryFailed.'),
    };

    JsonResponse::send(['success' => true, 'job' => $job]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a operação da sincronização contínua.');
}
