<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingControlRepository;
use FurkiCycle\Rebooking\RebookingExportService;
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

    $search = trim((string) ($body['search'] ?? ''));
    $estado = trim((string) ($body['estado'] ?? 'todos'));
    $environment = $env->optional('FURK_ENVIRONMENT', 'production') ?? 'production';

    $gate = new RebookingGateRepository($env);
    $control = new RebookingControlRepository($env);
    $exporter = new RebookingExportService();

    $limit = RebookingExportService::EXCEL_MAX_ROWS;

    $toExportRow = static function (array $row): array {
        return [
            'nota' => $row['nota'] ?? null,
            'fil_agt' => $row['fil_agt'] ?? null,
            'num_file' => $row['num_file'] ?? null,
            'localizador' => $row['localizador'] ?? null,
            'tkt_num' => $row['tkt_num'] ?? null,
            'nom_pax' => $row['nom_pax'] ?? null,
            'cod_for' => $row['airline_code'] ?? $row['cod_for'] ?? null,
            'fan_for' => $row['fan_for'] ?? null,
            'data_nota' => $row['data_nota'] ?? null,
            'trecho' => $row['trecho'] ?? null,
            'produto' => $row['produto'] ?? null,
            'tipo_nota' => $row['tipo_nota'] ?? null,
            'tarifa_br' => $row['tarifa_br'] ?? null,
            'taxa_1' => $row['taxa_1'] ?? null,
            'taxa_2' => $row['taxa_2'] ?? null,
            'taxa_serv_br' => $row['taxa_serv_br'] ?? null,
            'room_tax' => $row['room_tax'] ?? null,
            'trans_fee' => $row['trans_fee'] ?? null,
            'receb_cli' => $row['receb_cli'] ?? null,
            'Total' => $row['Total'] ?? null,
            'vence_cli' => $row['vence_cli'] ?? null,
            'cancel' => $row['cancel'] ?? null,
            'faturado' => $row['faturado'] ?? null,
            'processing_status' => $row['processing_status'] ?? null,
        ];
    };

    $controlledNotas = $control->controlledNotas($environment);
    $rows = [];
    $total = 0;

    if ($estado === 'enviado') {
        $result = $control->page($environment, 1, $limit, $search);
        $total = (int) $result['total'];
        foreach ($result['rows'] as $row) {
            $rows[] = $toExportRow($row);
        }
    } elseif ($estado === 'no_enviado') {
        $result = $gate->page(1, $limit, $search, $controlledNotas === [] ? null : $controlledNotas, true);
        $rows = $result['rows'];
        $total = (int) $result['total'];
    } else {
        $gateResult = $gate->page(1, $limit, $search, $controlledNotas === [] ? null : $controlledNotas, true);
        $controlResult = $control->page($environment, 1, $limit, $search);
        $rows = $gateResult['rows'];
        foreach ($controlResult['rows'] as $row) {
            $rows[] = $toExportRow($row);
        }
        $total = (int) $gateResult['total'] + (int) $controlResult['total'];
    }

    $exporter->assertExportable($total);
    $export = $exporter->export($rows);

    http_response_code(200);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
    header('Cache-Control: no-store');
    echo $export['bytes'];
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível exportar os registros.');
}
