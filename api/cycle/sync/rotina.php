<?php

declare(strict_types=1);

use FurkiCycle\Auth\AuthorizationException;
use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ImportGuard;
use FurkiCycle\Sync\SyncRotinaRepository;
use FurkiCycle\Sync\SyncRotinaService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $connection = MySqlConnection::create($env);
    $jobRepository = new SyncRotinaRepository($connection);
    $service = new SyncRotinaService($env, $connection, $jobRepository);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        JsonResponse::send(['success' => true, 'job' => $service->status()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    // Ver o status é livre para ADMIN/SUPERVISOR (GET acima); configurar, iniciar ou interromper
    // a Recursiva é exclusivo de administradores.
    if (($claims['role'] ?? null) !== 'ADMIN') {
        throw new AuthorizationException('Somente administradores podem configurar, iniciar ou interromper a Sincronização Consolidada Contínua Recursiva.');
    }

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $action = $body['action'] ?? null;

    // Iniciar a Recursiva: nenhuma outra rotina/carga pode estar em andamento (409).
    if ($action === 'start') {
        (new ImportGuard($env))->requireRecursivaPodeIniciar();
    }

    $result = match ($action) {
        'configure' => $service->configure(
            (int) ($body['frequenciaHoras'] ?? 0),
            (int) ($body['diasRecuo'] ?? 0),
        ),
        'start' => $service->start(),
        'stop' => $service->stop(),
        default => throw new RuntimeException('Ação inválida. Use configure, start ou stop.'),
    };

    JsonResponse::send(['success' => true, 'job' => $result]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a operação da rotina. Consulte os logs do servidor.');
}
