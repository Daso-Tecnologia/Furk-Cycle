<?php
declare(strict_types=1);

/*
 * Diagnóstico do MySQL de TESTE antes de criar as tabelas: conexão, versão, TLS, privilégios
 * e recursos que a Fase 1 usa (GET_LOCK/IS_FREE_LOCK, JSON). NÃO cria as tabelas do projeto
 * (usa uma tabela temporária de prova que é apagada). Só imprime fatos NÃO secretos: nunca
 * host, usuário, senha nem mensagens de erro do servidor (podem conter usuário/IP).
 *
 * Uso: php tools/testar_conexao_teste.php [C:\caminho\.env.test]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n\n";

$fail = 0;
function item(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " — {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

// ---- 1. conexão ----
$inicio = microtime(true);
try {
    $pdo = MySqlConnection::create($env);
} catch (Throwable $erro) {
    $codigo = $erro instanceof PDOException ? (int) ($erro->errorInfo[1] ?? 0) : 0;
    $dica = match (true) {
        $codigo === 1045 => 'usuário ou senha incorretos',
        in_array($codigo, [2002, 2003, 2013], true) => 'host/porta inacessível: libere o seu IP em cPanel → Remote MySQL® (conta pessoal) e confira firewall/VPN',
        $codigo === 1044 => 'o usuário não tem privilégio neste banco (cPanel → "Add User To Database")',
        $codigo === 1049 => 'o banco não existe (confira o nome, com o prefixo da conta cPanel)',
        $codigo === 3159 => 'o servidor exige TLS/SSL (avise o agente para adicionar a opção de SSL para os testes)',
        default => 'erro de conexão (código ' . $codigo . ')',
    };
    item('conexão', false, $dica);
    echo "\nNão foi possível continuar.\n";
    exit(1);
}
$ms = (int) round((microtime(true) - $inicio) * 1000);
item('conexão', true, "{$ms} ms");

// ---- 2. versão, banco, TLS ----
$versao = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$atual = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$cipher = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
$tls = is_array($cipher) && ($cipher['Value'] ?? '') !== '';
echo "      versão do servidor: {$versao}\n";
echo '      conexão com TLS: ' . ($tls ? 'sim' : 'não') . "\n";
item('banco selecionado é o de teste', $atual === $banco);

$maria = stripos($versao, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)/', $versao, $m);
$maior = (int) ($m[1] ?? 0);
$menor = (int) ($m[2] ?? 0);
$versaoOk = $maria ? ($maior > 10 || ($maior === 10 && $menor >= 2)) : ($maior > 5 || ($maior === 5 && $menor >= 7));
item('versão suportada (MySQL ≥ 5.7 ou MariaDB ≥ 10.2)', $versaoOk);

// ---- 3. banco vazio? ----
$tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo '      tabelas existentes no banco de teste: ' . count($tabelas) . "\n";
item('banco de teste vazio (ou só com as tabelas do projeto)', count($tabelas) === 0 || count(array_filter($tabelas, static fn (string $t): bool => !str_starts_with($t, 'furk'))) === 0);

// ---- 4. privilégios (sem imprimir nomes de outros bancos) ----
$grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
$globais = 0;
$outrosBancos = [];
$noBancoTeste = false;
foreach ($grants as $grant) {
    $grant = (string) $grant;
    if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/i', $grant, $g) !== 1) {
        continue;
    }
    $privilegios = strtoupper(trim($g[1]));
    $alvo = str_replace(['`', '\\'], '', trim($g[2]));
    if ($alvo === '*.*') {
        if ($privilegios !== 'USAGE') {
            $globais++;
        }
        continue;
    }
    [$bd] = explode('.', $alvo, 2);
    if (strcasecmp($bd, $banco) === 0 || strcasecmp(str_replace('_', '', $bd), str_replace('_', '', $banco)) === 0) {
        $noBancoTeste = $noBancoTeste || str_contains($privilegios, 'ALL PRIVILEGES') || (str_contains($privilegios, 'CREATE') && str_contains($privilegios, 'INSERT') && str_contains($privilegios, 'ALTER'));
    } else {
        $outrosBancos[$bd] = true;
    }
}
item('usuário tem privilégios completos no banco de teste', $noBancoTeste);
item('usuário NÃO tem privilégios globais (*.*)', $globais === 0, $globais === 0 ? '' : 'há privilégios globais — prefira um usuário só do banco de teste');
item('usuário NÃO enxerga outros bancos', $outrosBancos === [], $outrosBancos === [] ? '' : count($outrosBancos) . ' outro(s) banco(s) concedido(s) — remova em cPanel → MySQL Databases');

// ---- 5. recursos usados pela Fase 1 ----
$prefixo = (string) $env->required('LOCK_PREFIX');
$nomeLock = $prefixo . 'probe_diagnostico';
$obtido = $pdo->query("SELECT GET_LOCK('{$nomeLock}', 0)")->fetchColumn();
$outra = MySqlConnection::create($env);
$emUso = $outra->query("SELECT IS_FREE_LOCK('{$nomeLock}')")->fetchColumn();
$pdo->query("SELECT RELEASE_LOCK('{$nomeLock}')")->fetchColumn();
$livre = $outra->query("SELECT IS_FREE_LOCK('{$nomeLock}')")->fetchColumn();
item('GET_LOCK / IS_FREE_LOCK / RELEASE_LOCK entre sessões', (int) $obtido === 1 && (int) $emUso === 0 && (int) $livre === 1);

try {
    $json = (int) $pdo->query("SELECT JSON_VALID('{\"a\":1}')")->fetchColumn();
    item('funções JSON', $json === 1);
} catch (PDOException) {
    item('funções JSON', false);
}

// ---- 6. escrita real (tabela de prova, apagada ao final) ----
$probe = '_probe_diagnostico_teste';
try {
    $pdo->exec("DROP TABLE IF EXISTS {$probe}");
    $pdo->exec("CREATE TABLE {$probe} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, estado ENUM('IDLE','RUNNING') NOT NULL DEFAULT 'IDLE', dados JSON NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO {$probe} (estado, dados) VALUES ('RUNNING', '{\"ok\":true}')");
    $linhas = (int) $pdo->query("SELECT COUNT(*) FROM {$probe}")->fetchColumn();
    $pdo->exec("ALTER TABLE {$probe} ADD COLUMN extra INT NULL");
    $pdo->exec("TRUNCATE TABLE {$probe}");
    $pdo->exec("DROP TABLE {$probe}");
    item('CREATE / INSERT / ALTER / TRUNCATE / DROP (tabela de prova InnoDB com JSON e ENUM)', $linhas === 1);
} catch (PDOException $erro) {
    item('CREATE / INSERT / ALTER / TRUNCATE / DROP', false, 'erro MySQL ' . (int) ($erro->errorInfo[1] ?? 0));
    try {
        $pdo->exec("DROP TABLE IF EXISTS {$probe}");
    } catch (PDOException) {
    }
}

echo "\n" . ($fail === 0 ? 'BANCO DE TESTE PRONTO. Próximo: php tools\\preparar_banco_teste.php' : "{$fail} PONTO(S) A CORRIGIR antes de criar as tabelas.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
