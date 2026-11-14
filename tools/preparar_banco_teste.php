<?php
declare(strict_types=1);

/*
 * Cria o esquema (migrations database/001..011) num MySQL de TESTE.
 * Trava de segurança: o banco precisa ter "test" no nome e ser diferente do .env de produção.
 *
 * Uso:
 *   php tools/preparar_banco_teste.php                 (usa .env.test na raiz)
 *   php tools/preparar_banco_teste.php C:\caminho\.env.test
 *
 * O .env.test deve conter MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USERNAME, MYSQL_PASSWORD
 * (e, para os testes HTTP, JWT_SECRET e JWT_ISSUER). O banco (schema) precisa EXISTIR e estar vazio.
 * Esta pasta tools/ NÃO vai no deploy.zip.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n";

try {
    $pdo = MySqlConnection::create($env);
} catch (Throwable $erro) {
    fwrite(STDERR, 'Não foi possível conectar ao MySQL de teste: ' . get_class($erro) . "\n(confira as credenciais no .env.test e se o schema existe)\n");
    exit(2);
}

$arquivos = glob($root . '/database/*.sql') ?: [];
sort($arquivos);
$criadas = 0;
foreach ($arquivos as $arquivo) {
    $linhas = array_filter(
        preg_split('/\r?\n/', (string) file_get_contents($arquivo)) ?: [],
        static fn (string $linha): bool => !str_starts_with(ltrim($linha), '--'),
    );
    $comandos = preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $linhas)) ?: [];
    foreach ($comandos as $comando) {
        $comando = trim($comando);
        if ($comando === '') {
            continue;
        }
        try {
            $pdo->exec($comando);
            if (stripos($comando, 'CREATE TABLE') === 0) {
                $criadas++;
            }
        } catch (PDOException $erro) {
            // 1060 coluna duplicada (ALTER já refletido no CREATE), 1061 índice duplicado, 1050 tabela existe
            $codigo = (int) ($erro->errorInfo[1] ?? 0);
            if (in_array($codigo, [1060, 1061, 1050], true)) {
                echo '  (ignorado em ' . basename($arquivo) . ": erro MySQL {$codigo})\n";
                continue;
            }
            fwrite(STDERR, 'Falha em ' . basename($arquivo) . ': erro MySQL ' . $codigo . "\n");
            exit(1);
        }
    }
    echo '  ok ' . basename($arquivo) . "\n";
}

// A tabela `users` existe só na produção (não há migration no projeto). Para testar as telas/endpoints de
// usuários e o login no banco de TESTE, cria-se uma versão mínima, SEM dados (apenas para teste).
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT \'SUPERVISOR\',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL,
        updated_at DATETIME(6) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
);
echo "  ok users (tabela mínima, só para teste; sem dados)\n";

$tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Pronto. Tabelas no banco de teste: ' . count($tabelas) . "\n";
foreach ($tabelas as $tabela) {
    echo "  - {$tabela}\n";
}
