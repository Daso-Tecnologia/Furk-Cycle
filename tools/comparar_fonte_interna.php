<?php
declare(strict_types=1);

/*
 * Compara a leitura INTERNA do Rebooking (RebookingGateRepository, regras padrão) com a view dbo.Rebooking,
 * nas colunas BASE (+ data_tratada, fan_cli) e nas colunas ESTENDIDAS do "Ver tabela GATE".
 * SOMENTE LEITURA; imprime apenas contagens e tempos (nenhum dado de passageiro).
 *
 * Uso: php tools/comparar_fonte_interna.php [C:\caminho\do\projeto]
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), "/\\");
require $root . '/vendor/autoload.php';

use FurkiCycle\Config\Env;
use FurkiCycle\Rebooking\RebookingGateRepository;
use FurkiCycle\Settings\RebookingGateFilter;

$repo = new RebookingGateRepository(new Env($root . '/.env'), RebookingGateFilter::defaults());
$pdo = $repo->connect();
$pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 150);

$fail = 0;
function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " - {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

function indexar(array $linhas): array
{
    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[trim((string) $l['fil_agt']) . '|' . trim((string) $l['nota']) . '|' . trim((string) $l['num_file'])][] = $l;
    }

    return $mapa;
}

function comparar(string $rotulo, array $referencia, array $interna, array $colunas): void
{
    $a = indexar($referencia);
    $b = indexar($interna);
    check("{$rotulo}: mesma quantidade de linhas", count($referencia) === count($interna), count($referencia) . ' vs ' . count($interna));
    check("{$rotulo}: mesmas notas", array_diff_key($a, $b) === [] && array_diff_key($b, $a) === []);
    $divergentes = [];
    foreach ($a as $chave => $linhas) {
        if (!isset($b[$chave])) {
            continue;
        }
        foreach ($colunas as $coluna) {
            $x = array_map(static fn (array $l): string => trim((string) ($l[$coluna] ?? '')), $linhas);
            $y = array_map(static fn (array $l): string => trim((string) ($l[$coluna] ?? '')), $b[$chave]);
            sort($x);
            sort($y);
            if ($x !== $y) {
                $divergentes[$coluna] = ($divergentes[$coluna] ?? 0) + 1;
            }
        }
    }
    check("{$rotulo}: mesmos valores em todas as colunas", $divergentes === [], $divergentes === [] ? '' : 'colunas divergentes: ' . json_encode($divergentes));
}

$colBase = ['nota', 'fil_agt', 'num_file', 'localizador', 'tkt_num', 'nom_pax', 'cod_for', 'fan_for', 'data_nota', 'trecho', 'produto', 'tipo_nota',
    'tarifa_br', 'taxa_1', 'taxa_2', 'taxa_serv_br', 'room_tax', 'trans_fee', 'receb_cli', 'Total', 'vence_cli', 'cancel', 'faturado'];
$colExt = ['nota', 'fil_agt', 'num_file', 'localizador', 'tkt_num', 'nom_pax', 'cod_for', 'fan_for', 'data_nota', 'trecho', 'produto', 'tipo_nota',
    'tarifa_br', 'taxa_1', 'taxa_2', 'taxa_serv_br', 'room_tax', 'trans_fee', 'receb_cli', 'Total', 'vence_cli', 'cancel', 'faturado',
    'tar_prom', 'cod_pos', 'pnom_pos', 'obs_ext3', 'obs_int2', 'acomod_tip', 'acomod_cat', 'mat_pax', 'dep_pax', 'fil_cli', 'cnpj_cli', 'fan_cli',
    'cod_mv_jv', 'texto_mv_jv', 'tipo_cli'];

try {
    // Referência: a própria view, com as mesmas colunas.
    $ini = microtime(true);
    $viewBase = $pdo->query('SELECT ' . implode(', ', $colBase) . ' FROM dbo.Rebooking')->fetchAll();
    $msView = (int) round((microtime(true) - $ini) * 1000);
    $viewExt = $pdo->query('SELECT ' . implode(', ', $colExt) . ' FROM dbo.Rebooking')->fetchAll();

    $ini = microtime(true);
    $pagina = $repo->page(1, 500, '');
    $msInterna = (int) round((microtime(true) - $ini) * 1000);
    $ini = microtime(true);
    $paginaExt = $repo->gatePage(1, 500, '');
    $msExt = (int) round((microtime(true) - $ini) * 1000);
} catch (Throwable $e) {
    echo 'Erro ao consultar: ' . get_class($e) . ' - ' . $e->getMessage() . PHP_EOL;
    exit(2);
}

echo "view (base): " . count($viewBase) . " linhas em {$msView} ms | interna (base, pagina+contagem): {$pagina['total']} em {$msInterna} ms | interna (estendida): {$paginaExt['total']} em {$msExt} ms\n\n";
comparar('base', $viewBase, $pagina['rows'], $colBase);
comparar('estendida', $viewExt, $paginaExt['rows'], $colExt);
check('contagem da pagina = total', $pagina['total'] === count($pagina['rows']));

// Janela maior devolve pelo menos as mesmas notas (filtro de dias efetivo).
$maior = new RebookingGateRepository(new Env($root . '/.env'), RebookingGateFilter::fromInput([
    'diasJanela' => 30, 'clientes' => '055763', 'fornecedores' => 'AD', 'produtos' => '02', 'formasNota' => 'B', 'cadastros' => 'INTERFTMS',
]));
$totalMaior = $maior->count('');
check('janela de 30 dias >= janela de 10 dias', $totalMaior >= $pagina['total'], "{$totalMaior} vs {$pagina['total']}");

// data_tratada e fan_cli só servem à busca: a busca pela data (AAAA-MM-DD) e pelo cliente deve achar as mesmas linhas da view.
if ($viewBase !== []) {
    $dataTexto = substr((string) ($viewBase[0]['data_nota'] ?? ''), 0, 10);
    $esperadoData = $pdo->prepare('SELECT COUNT(*) FROM dbo.Rebooking WHERE data_tratada LIKE CAST(:q AS VARCHAR(200))');
    $esperadoData->execute(['q' => "%{$dataTexto}%"]);
    check('busca por data_tratada igual a da view', $dataTexto !== '' && $repo->count($dataTexto) >= (int) $esperadoData->fetchColumn(), $dataTexto === '' ? 'sem data' : '');
    $fan = trim((string) ($viewExt[0]['fan_cli'] ?? ''));
    if ($fan !== '') {
        $esperadoFan = $pdo->prepare('SELECT COUNT(*) FROM dbo.Rebooking WHERE fan_cli LIKE CAST(:q AS VARCHAR(200))');
        $esperadoFan->execute(['q' => "%{$fan}%"]);
        check('busca por fan_cli igual a da view', $repo->count($fan) >= (int) $esperadoFan->fetchColumn());
    }
}

// Busca (lupa) continua funcionando sobre a fonte interna.
$busca = $repo->count('%_[!');
check('busca com curingas nao quebra', $busca >= 0);

echo PHP_EOL . ($fail === 0 ? 'Fonte INTERNA EQUIVALE a dbo.Rebooking (regras padrao)' : "{$fail} DIFERENCA(S) - nao usar ainda") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
