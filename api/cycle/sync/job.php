<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ImportGuard;
use FurkiCycle\Sync\SyncJobRepository;
use FurkiCycle\Sync\SyncJobService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Nome de quem iniciou (para o aviso "iniciada por X"); qualquer falha devolve null, nunca bloqueia o início. */
function userName(PDO $connection, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $statement = $connection->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $name = $statement->fetchColumn();

        return is_string($name) && $name !== '' ? $name : null;
    } catch (\Throwable) {
        return null;
    }
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $connection = MySqlConnection::create($env);
    $jobRepository = new SyncJobRepository($connection);
    $service = new SyncJobService($env, $connection, $jobRepository);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null;
        JsonResponse::send(['success' => true, 'job' => $service->status($id)]);
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

    // Iniciar a Sincronização Consolidada: bloqueado com a Recursiva habilitada ou outra carga em andamento (409).
    if ($action === 'start') {
        (new ImportGuard($env))->requireCargaPermitida($claims, ['furk_sync_job']);
    }

    // Interromper: somente administradores (como na Recursiva). Não passa pelo ImportGuard: parar é sempre permitido.
    if ($action === 'stop') {
        (new JwtGuard())->requireRole($claims, ['ADMIN']);
        JsonResponse::send(['success' => true, 'job' => $service->stop()]);
    }

    $result = match ($action) {
        'start' => $service->start(
            (string) ($body['initialDate'] ?? ''),
            (string) ($body['finalDate'] ?? ''),
            userName($connection, (int) ($claims['sub'] ?? 0)),
        ),
        default => throw new RuntimeException('Ação inválida. Use start ou stop.'),
    };

    JsonResponse::send(['success' => true, 'job' => $result['job']]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a operação da sincronização.');
}
