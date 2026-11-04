<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\RebookingClient;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingControlRepository;
use FurkiCycle\Rebooking\RebookingGateRepository;
use FurkiCycle\Rebooking\RebookingSendService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

// Lote pode ter várias notas com pausa entre POSTs: não deixar o servidor matar
// a requisição no meio (o que deixaria linhas presas em PENDING) nem abortar se o
// navegador desconectar.
set_time_limit(0);
ignore_user_abort(true);

try {
    $env = new Env();
    $guard = new JwtGuard();
    $claims = $guard->requireAuthenticatedUser($env);
    $guard->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $notas = $body['notas'] ?? null;
    if (!is_array($notas) || $notas === []) {
        throw new RuntimeException('Selecione pelo menos uma nota para processar.');
    }

    $clean = [];
    foreach ($notas as $item) {
        if (is_string($item) && trim($item) !== '') {
            $clean[] = trim($item);
        }
    }
    $notas = array_values(array_unique($clean));
    if ($notas === []) {
        throw new RuntimeException('Selecione pelo menos uma nota para processar.');
    }

    // Cada nota faz várias consultas ao GATE e um POST à Furk; um lote grande estoura o
    // timeout do servidor web (60 s) com o envio pela metade. A tela divide a seleção
    // em partes menores; este teto protege chamadas diretas à API.
    $maxNotas = 20;
    if (count($notas) > $maxNotas) {
        throw new RuntimeException("Envie no máximo {$maxNotas} notas por requisição.");
    }

    $confirmation = trim((string) ($body['confirmation'] ?? ''));
    $send = $confirmation === 'ENVIAR REBOOKING';

    $gate = new RebookingGateRepository($env);
    $control = new RebookingControlRepository($env);
    $client = new RebookingClient($env);
    $service = new RebookingSendService($env, $gate, $control, $client);
    $result = $service->process($notas, $send);

    JsonResponse::send(['success' => $result['success'], 'mode' => $result['mode'], 'summary' => $result['summary'], 'results' => $result['results']]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir o processamento de Rebooking.');
}