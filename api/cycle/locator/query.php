<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\DynamicStatusClient;
use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Locator\LocatorLookupService;

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

    $initialIssueDate = $body['initialIssueDate'] ?? null;
    $finalIssueDate = $body['finalIssueDate'] ?? null;
    $locator = $body['locator'] ?? null;

    if (!is_string($initialIssueDate) || !is_string($finalIssueDate)) {
        throw new RuntimeException('Informe a data inicial e a data final.');
    }
    if (!is_string($locator)) {
        throw new RuntimeException('Informe o localizador a consultar.');
    }

    $scope = $env->required('FURK_DYNAMIC_STATUS_SCOPE');
    $oauth = new OAuthClient($env, $scope);
    $client = new DynamicStatusClient($env, $oauth);
    $service = new LocatorLookupService($env, $client);
    $result = $service->lookup($initialIssueDate, $finalIssueDate, $locator);

    JsonResponse::send(['success' => true, 'result' => $result]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a consulta. Consulte os logs do servidor.');
}