<?php

declare(strict_types=1);

// Workers sao scripts de linha de comando: nunca devem executar via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/*
 * Furk Cycle — Dump da resposta bruta da API Dynamic Status
 *
 * Consulta a Furk (GET /v1/dynamicstatus) para o período informado e imprime a
 * resposta JSON CRUA (a mesma que o DynamicStatusImportService consome), para
 * conferirmos os campos reais retornados pela API.
 *
 * Read-only: NÃO grava nada no banco. Usa apenas as credenciais do .env.
 *
 * Uso:
 *   php worker/dump_furk_ticket.php [initialDate] [finalDate] [ticketNumber] [outputFile]
 *
 * Exemplo:
 *   php worker/dump_furk_ticket.php 2026-10-06 2026-10-06 GF2ZKL
 *
 * Saída:
 *   Grava o JSON em arquivo (por padrão em dump/dump_furk_ticket_<ini>_<fim>.json,
 *   na raiz do projeto) e também imprime no terminal. O arquivo fica FORA de
 *   public/, ou seja, não é acessível pela web.
 *
 *   Conteúdo do JSON:
 *     - parametros: datas e ticketNumber usados
 *     - meta: totalElements / totalPages / pages consultadas
 *     - chaves_resposta: as chaves do objeto raiz da API
 *     - estrutura_primeiro_item: o primeiro item (para ver os campos reais)
 *     - match: o(s) item(ns) que contêm o ticketNumber/locator informado
 */

use FurkiCycle\Config\Env;
use FurkiCycle\Furk\DynamicStatusClient;
use FurkiCycle\Furk\OAuthClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function out(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

function fail(array $data): never
{
    fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

/** Grava o dump em arquivo (fora de public/) e devolve o caminho absoluto. */
function writeDumpFile(array $data, ?string $outputFile, string $initial, string $final): string
{
    $dumpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dump';
    if ($outputFile === null || $outputFile === '') {
        $outputFile = 'dump_furk_ticket_' . $initial . '_' . $final . '.json';
    }
    $path = str_contains($outputFile, DIRECTORY_SEPARATOR) || str_contains($outputFile, '/')
        ? $outputFile
        : $dumpDir . DIRECTORY_SEPARATOR . $outputFile;

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Não foi possível serializar o dump para JSON.');
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException("Não foi possível gravar o arquivo de dump em: {$path}");
    }

    return $path;
}

/** Localiza um item da Furk que contenha o filtro no número/locator/status. */
function findMatches(array $items, string $filter): array
{
    $needle = strtoupper(trim($filter));
    if ($needle === '') {
        return $items;
    }

    $matches = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = $item['ticketID'] ?? $item;
        $candidates = [
            $id['ticketNumber'] ?? null,
            $id['locatorCode'] ?? null,
            $item['ticketStatus'] ?? null,
            $item['ndcOrderId'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && strtoupper($candidate) === $needle) {
                $matches[] = $item;
                break;
            }
        }
    }

    return $matches;
}

try {
    $env = new Env();
    $initial = $argv[1] ?? '2026-10-06';
    $final = $argv[2] ?? $initial;
    $filter = $argv[3] ?? 'GF2ZKL';
    $outputFile = $argv[4] ?? null;

    $scope = $env->required('FURK_DYNAMIC_STATUS_SCOPE');
    $oauth = new OAuthClient($env, $scope);
    $client = new DynamicStatusClient($env, $oauth);

    $pageSize = $env->int('FURK_DYNAMIC_STATUS_PAGE_SIZE', 100);
    $maxPages = $env->int('FURK_DYNAMIC_STATUS_MAX_PAGES', 1000);

    $all = [];
    $expected = 0;
    $pages = 0;
    $page = 1;
    $totalPages = 1;

    do {
        $response = $client->list($initial, $final, $page, $pageSize);
        $items = $response['data'] ?? $response['content'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }
        $expected = max($expected, (int) ($response['totalElements'] ?? 0));
        $totalPages = max($page, (int) ($response['totalPages'] ?? ($items === [] ? $page : $page + 1)));
        $pages = $page;
        $page++;
    } while ($page <= $totalPages && $page <= $maxPages);

    $result = [
        'parametros' => [
            'initialDate' => $initial,
            'finalDate' => $final,
            'ticketNumber' => $filter,
        ],
        'meta' => [
            'totalElements' => $expected,
            'totalPages' => $totalPages,
            'pages_consultadas' => $pages,
            'itens_coletados' => count($all),
        ],
        'chaves_resposta' => array_keys($response),
        'estrutura_primeiro_item' => $all[0] ?? null,
        'match' => findMatches($all, $filter),
    ];

    $path = writeDumpFile($result, $outputFile, $initial, $final);

    echo 'DUMP GRAVADO EM: ' . $path . PHP_EOL . PHP_EOL;
    out($result);
} catch (\Throwable $error) {
    fail(['success' => false, 'message' => $error->getMessage()]);
}
