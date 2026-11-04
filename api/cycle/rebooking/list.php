<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingControlRepository;
use FurkiCycle\Rebooking\RebookingGateRepository;

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

    $page = max(1, (int) ($body['page'] ?? 1));
    $size = max(1, min(500, (int) ($body['size'] ?? 50)));
    $search = trim((string) ($body['search'] ?? ''));
    $estado = trim((string) ($body['estado'] ?? 'todos'));

    $environment = $env->optional('FURK_ENVIRONMENT', 'production') ?? 'production';
    $gate = new RebookingGateRepository($env);
    $control = new RebookingControlRepository($env);

    $rows = [];
    $total = 0;
    $origem = 'gate';

    $mapControlRow = static function (array $row): array {
        return [
            'nota' => $row['nota'] ?? null,
            'fil_agt' => $row['fil_agt'] ?? null,
            'num_file' => $row['num_file'] ?? null,
            'localizador' => $row['localizador'] ?? null,
            'tkt_num' => $row['tkt_num'] ?? null,
            'nom_pax' => null,
            'cod_for' => $row['airline_code'] ?? null,
            'fan_for' => null,
            'data_nota' => null,
            'trecho' => null,
            'produto' => null,
            'tipo_nota' => null,
            'tarifa_br' => null,
            'total' => null,
            'control' => $row,
        ];
    };

    $mapGateRow = static function (array $row, ?array $controlRow): array {
        return [
            'nota' => $row['nota'] ?? null,
            'fil_agt' => $row['fil_agt'] ?? null,
            'num_file' => $row['num_file'] ?? null,
            'localizador' => $row['localizador'] ?? null,
            'tkt_num' => $row['tkt_num'] ?? null,
            'nom_pax' => $row['nom_pax'] ?? null,
            'cod_for' => $row['cod_for'] ?? null,
            'fan_for' => $row['fan_for'] ?? null,
            'data_nota' => $row['data_nota'] ?? null,
            'trecho' => $row['trecho'] ?? null,
            'produto' => $row['produto'] ?? null,
            'tipo_nota' => $row['tipo_nota'] ?? null,
            'tarifa_br' => $row['tarifa_br'] ?? null,
            'total' => $row['Total'] ?? null,
            'control' => $controlRow,
        ];
    };

    if ($estado === 'enviado') {
        // Fonte da verdade dos envios: a tabela de controle (o GATE só mostra pendências atuais).
        $result = $control->page($environment, $page, $size, $search);
        $total = (int) $result['total'];
        $origem = 'control';
        foreach ($result['rows'] as $row) {
            $rows[] = $mapControlRow($row);
        }
    } elseif ($estado === 'no_enviado') {
        $controlledNotas = $control->controlledNotas($environment);
        $result = $gate->page($page, $size, $search, $controlledNotas === [] ? null : $controlledNotas, true);
        $total = (int) $result['total'];
        $origem = 'gate';
        foreach ($result['rows'] as $row) {
            $rows[] = $mapGateRow($row, null);
        }
    } else {
        // "todos" = consolidado: enviados (controle) + pendentes (GATE), sem duplicar.
        $controlledNotas = $control->controlledNotas($environment);
        $gateResult = $gate->page(1, 5000, $search, $controlledNotas === [] ? null : $controlledNotas, true);
        $controlResult = $control->page($environment, 1, 5000, $search);

        $merged = [];
        foreach ($gateResult['rows'] as $row) {
            $item = $mapGateRow($row, null);
            $item['_sort'] = (string) ($row['data_nota'] ?? '');
            $merged[] = $item;
        }
        foreach ($controlResult['rows'] as $row) {
            $item = $mapControlRow($row);
            $item['_sort'] = (string) ($row['furk_request_datetime'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
            $merged[] = $item;
        }
        usort($merged, static fn (array $a, array $b): int => strcmp((string) $b['_sort'], (string) $a['_sort']));

        $total = count($merged);
        $origem = 'consolidado';
        $offset = max(0, ($page - 1) * $size);
        foreach (array_slice($merged, $offset, $size) as $item) {
            unset($item['_sort']);
            $rows[] = $item;
        }
    }

    JsonResponse::send([
        'success' => true,
        'environment' => $environment,
        'origem' => $origem,
        'page' => $page,
        'size' => $size,
        'total' => $total,
        'rows' => $rows,
        'regras' => $gate->activeFilter()?->toArray(),
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível consultar o Gate. Verifique a VPN e tente novamente.');
}