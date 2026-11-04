<?php
declare(strict_types=1);

/*
 * Validacao das BUSCAS do GATE (LIKE ... ESCAPE '!' + NOT IN/IN) contra o SQL Server real.
 * SOMENTE LEITURA e SOMENTE CONTAGENS: nao imprime dados de passageiros (LGPD).
 * Criado em 22/10/2026 porque o teste offline do formato do SQL NAO pegou o bug do
 * ESCAPE '\' (HY093): so o SQL Server real expoe esse tipo de problema.
 *
 * Uso:
 *   php tools/validar_gate_buscas.php
 *   php tools/validar_gate_buscas.php C:\xampp\htdocs\furk-cycle-novo
 *
 * Requer acesso ao GATE (VPN) e vendor/ + .env na raiz indicada. Esta pasta tools/ NAO vai no deploy.zip.
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), "/\\");
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php nao encontrado em {$root}\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

use FurkiCycle\Config\Env;
use FurkiCycle\Rebooking\RebookingGateRepository;

$repo = new RebookingGateRepository(new Env($root . '/.env'));
$fail = 0;
function t(string $name, callable $fn): void
{
    global $fail;
    try {
        echo 'OK    ' . $name . ' -> ' . $fn() . PHP_EOL;
    } catch (Throwable $e) {
        $fail++;
        echo 'FALHA ' . $name . ' -> ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    }
}

t('total sem busca', fn () => $repo->count(''));
t("busca texto simples 'a'", fn () => $repo->count('a'));
t("busca numerica '0'", fn () => $repo->count('0'));
t("busca com espaco 'jose maria'", fn () => $repo->count('jose maria'));
t("busca com aspas simples \"o'neil\"", fn () => $repo->count("o'neil"));
t("busca com '!' literal", fn () => $repo->count('a!b'));
t("busca com '[' ']' '%' '_'", fn () => $repo->count('[a]%_'));
t('busca longa (200 chars)', fn () => $repo->count(str_repeat('x', 200)));
t('page com busca + exclusao (NOT IN)', fn () => count($repo->page(1, 5, 'a', ['000001', '000002'], true)['rows']) . ' linhas');
t('page com busca + inclusao (IN)', fn () => count($repo->page(1, 5, 'a', ['000001', '000002'], false)['rows']) . ' linhas');
t('page sem busca + exclusao de varias notas', fn () => $repo->page(1, 50, '', ['x1', 'x2', 'x3'], true)['total'] . ' total');
t('gatePage com busca', fn () => count($repo->gatePage(1, 5, 'a')['rows']) . ' linhas');
t('gatePage pagina 2 tamanho 10', fn () => count($repo->gatePage(2, 10, '')['rows']) . ' linhas');

// Controle positivo do ESCAPE: com ESCAPE, '_' literal so casa onde ha um underscore de verdade;
// sem escape o curinga casaria quase tudo.
$pdo = $repo->connect();
$any = (int) $pdo->query("SELECT COUNT(*) FROM dbo.Rebooking WHERE nota LIKE '%_%'")->fetchColumn();
$lit = (int) $pdo->query("SELECT COUNT(*) FROM dbo.Rebooking WHERE nota LIKE '%!_%' ESCAPE '!'")->fetchColumn();
echo "controle: LIKE '%_%' (curinga) = {$any} | LIKE '%!_%' ESCAPE '!' (literal) = {$lit}" . PHP_EOL;
$ok = $any > $lit || $any === 0;
echo ($ok ? 'OK    ' : 'FALHA ') . "ESCAPE distingue o '_' literal do curinga" . PHP_EOL;
if (!$ok) {
    $fail++;
}

echo PHP_EOL . ($fail === 0 ? 'TODAS AS BUSCAS OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
