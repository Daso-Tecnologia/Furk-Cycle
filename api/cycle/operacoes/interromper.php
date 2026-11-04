<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ManualLoadRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    // Somente administradores interrompem (como a Recursiva e a Consolidada). Parar nunca passa pelo ImportGuard.
    (new JwtGuard())->requireRole($claims, ['ADMIN']);

    $active = (new ManualLoadRegistry(MySqlConnection::create($env)))->requestStop();
    if ($active === null) {
        throw new RuntimeException('Não há leitura manual em andamento para interromper.');
    }
    if (($active['nao_interrompivel'] ?? false) === true) {
        // Conflito com o estado do sistema (HTTP 409): a leitura é rápida e apaga o período antes de regravar.
        throw new \FurkiCycle\Http\ConflictException('Esta leitura (Créditos ou Reembolsos) termina em poucos segundos e não pode ser interrompida no meio, para não deixar o período incompleto. Aguarde terminar.');
    }

    JsonResponse::send([
        'success' => true,
        'message' => 'Interrupção solicitada. A leitura para ao fim da página em andamento e informa até onde foi.',
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível interromper a leitura.');
}
