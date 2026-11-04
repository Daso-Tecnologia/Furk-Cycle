<?php
declare(strict_types=1);

/*
 * Compara uma view candidata (ex.: dbo.RebookingApi) com a dbo.Rebooking atual, nas colunas BASE.
 * SOMENTE LEITURA; imprime apenas contagens e tempos (nenhum dado de passageiro).
 *
 * Uso: php tools/comparar_view_gate.php dbo.RebookingApi [C:\caminho\do\projeto]
 */

$candidata = $argv[1] ?? '';
$root = rtrim($argv[2] ?? dirname(__DIR__), "/\\");
if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $candidata) !== 1) {
    fwrite(STDERR, "Uso: php tools/comparar_view_gate.php <schema.view>\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

use FurkiCycle\Config\Env;
use FurkiCycle\Rebooking\RebookingGateRepository;

$repo = new RebookingGateRepository(new Env($root . '/.env'));
$pdo = $repo->connect();
$pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 120);

$colunas = 'nota, fil_agt, num_file, localizador, tkt_num, nom_pax, cod_for, fan_for, data_nota, trecho, produto, tipo_nota, tarifa_br, taxa_1, taxa_2, taxa_serv_br, room_tax, trans_fee, receb_cli, Total, vence_cli, cancel, faturado, data_tratada, fan_cli';
$fail = 0;
function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " — {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

function carregar(PDO $pdo, string $view, string $colunas): array
{
    $ini = microtime(true);
    $linhas = $pdo->query("SELECT {$colunas} FROM {$view}")->fetchAll();
    $ms = (int) round((microtime(true) - $ini) * 1000);
    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[trim((string) $l['fil_agt']) . '|' . trim((string) $l['nota']) . '|' . trim((string) $l['num_file'])][] = $l;
    }

    return [$mapa, count($linhas), $ms];
}

try {
    [$original, $nOriginal, $msOriginal] = carregar($pdo, 'dbo.Rebooking', $colunas);
} catch (Throwable $e) {
    echo 'Não foi possível ler dbo.Rebooking (as colunas data_tratada/fan_cli existem nela?): ' . get_class($e) . PHP_EOL;
    exit(2);
}
try {
    [$nova, $nNova, $msNova] = carregar($pdo, $candidata, $colunas);
} catch (Throwable $e) {
    echo "Não foi possível ler {$candidata} — a view já foi criada? (" . get_class($e) . ")\n";
    exit(2);
}

echo "dbo.Rebooking: {$nOriginal} linhas em {$msOriginal} ms | {$candidata}: {$nNova} linhas em {$msNova} ms\n\n";
check('mesma quantidade de linhas', $nOriginal === $nNova);
$soOriginal = array_diff_key($original, $nova);
$soNova = array_diff_key($nova, $original);
check('mesmas notas (nenhuma só na original)', $soOriginal === [], count($soOriginal) . ' só na original');
check('mesmas notas (nenhuma só na candidata)', $soNova === [], count($soNova) . ' só na candidata');

$divergentes = 0;
$duplicadas = 0;
foreach ($original as $chave => $linhas) {
    if (count($linhas) > 1) {
        $duplicadas++;
    }
    if (!isset($nova[$chave])) {
        continue;
    }
    foreach ($colunas === '' ? [] : array_map('trim', explode(',', $colunas)) as $coluna) {
        $a = array_map(static fn (array $l): string => trim((string) ($l[$coluna] ?? '')), $linhas);
        $b = array_map(static fn (array $l): string => trim((string) ($l[$coluna] ?? '')), $nova[$chave]);
        sort($a);
        sort($b);
        if ($a !== $b) {
            $divergentes++;
            break;
        }
    }
}
check('mesmos valores em todas as colunas das notas em comum', $divergentes === 0, "{$divergentes} nota(s) com valor diferente");
echo "      notas com linhas duplicadas na original (efeito de JOIN 1:N): {$duplicadas}\n";
check("{$candidata} é mais rápida que dbo.Rebooking", $msNova < $msOriginal, "{$msNova} ms vs {$msOriginal} ms (a original pode estar com cache quente)");

echo PHP_EOL . ($fail === 0 ? "{$candidata} EQUIVALE a dbo.Rebooking nas colunas usadas pelo sistema" : "{$fail} DIFERENÇA(S) — não usar ainda") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
