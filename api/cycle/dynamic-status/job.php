<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\DynamicStatus\DynamicStatusJobRepository;
use FurkiCycle\DynamicStatus\DynamicStatusJobService;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ImportGuard;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $connection = MySqlConnection::create($env);
    $jobRepository = new DynamicStatusJobRepository($connection);
    $service = new DynamicStatusJobService($env, $connection, $jobRepository);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        JsonResponse::send(['success' => true, 'job' => $service->status()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $action = $body['action'] ?? null;

    // Iniciar uma rotina de carga: bloqueado com a Recursiva habilitada ou outra carga em andamento (409).
    if ($action === 'start') {
        (new ImportGuard($env))->requireCargaPermitida($claims, ['furk_dynamic_status_job']);
    }

    $result = match ($action) {
        'configure' => $service->configure(
            (string) ($body['dataBase'] ?? ''),
            (int) ($body['frequenciaMin'] ?? 0),
            (int) ($body['diasRecuo'] ?? 0),
            (int) ($body['diasFinal'] ?? 1),
        ),
        'start' => $service->start(),
        'stop' => $service->stop(),
        default => throw new RuntimeException('Ação inválida. Use configure, start ou stop.'),
    };

    JsonResponse::send(['success' => true, 'job' => $result]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a operação da rotina. Consulte os logs do servidor.');
}