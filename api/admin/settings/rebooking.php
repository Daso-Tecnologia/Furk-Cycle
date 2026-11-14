<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Settings\RebookingGateFilter;
use FurkiCycle\Settings\SettingsRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// GET: qualquer usuário autenticado vê as regras ativas e o histórico. POST: somente ADMIN altera.
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $guard = new JwtGuard();
    $claims = $guard->requireAuthenticatedUser($env);
    $repository = new SettingsRepository($env);
    $changes = [];

    if ($method === 'POST') {
        $guard->requireRole($claims, ['ADMIN']);

        $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
        }

        if (($body['restaurarPadrao'] ?? false) === true) {
            $filter = RebookingGateFilter::defaults();
        } else {
            $filter = RebookingGateFilter::fromInput($body);
        }
        $changes = $repository->saveRebookingGateFilter($filter, (int) ($claims['sub'] ?? 0));
    }

    JsonResponse::send([
        'success' => true,
        'message' => $method === 'POST'
            ? ($changes === [] ? 'Nenhuma alteração: as regras já estavam assim.' : 'Regras do Rebooking salvas. Valem a partir da próxima consulta.')
            : null,
        'filter' => $repository->rebookingGateFilter()->toArray(),
        'defaults' => RebookingGateFilter::defaults()->toArray(),
        'limits' => [
            'minDias' => RebookingGateFilter::MIN_DIAS,
            'maxDias' => RebookingGateFilter::MAX_DIAS,
            'maxItens' => RebookingGateFilter::MAX_ITENS,
        ],
        'history' => $repository->history(15),
        'canEdit' => ($claims['role'] ?? null) === 'ADMIN',
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível processar as configurações.');
}
