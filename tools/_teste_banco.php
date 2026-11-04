<?php
declare(strict_types=1);

/*
 * Utilitário comum das ferramentas de TESTE com banco (não é executado sozinho).
 * Garante que o teste só roda contra um MySQL de TESTE, nunca contra a produção.
 *
 * O arquivo de configuração de teste (por padrão `.env.test` na raiz do projeto) é criado PELO
 * USUÁRIO, com as credenciais do MySQL de teste. Ele NUNCA deve ser enviado em chat, commitado
 * ou incluído no deploy.zip (a pasta raiz não vai no pacote; `.env.test` também está no .gitignore).
 */

use FurkiCycle\Config\Env;

/**
 * @return array{0: Env, 1: string} o Env de teste e o nome do banco (para exibir)
 */
function carregarEnvTeste(string $root, ?string $arquivo = null): array
{
    $arquivo ??= $root . '/.env.test';
    if (!is_file($arquivo)) {
        fwrite(STDERR, "Arquivo de teste não encontrado: {$arquivo}\nCrie-o com as variáveis MYSQL_* do banco de TESTE (veja docs/ai/analise-concorrencia-rotinas.md §9).\n");
        exit(2);
    }

    foreach (['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USERNAME', 'MYSQL_PASSWORD'] as $variavel) {
        if (getenv($variavel) !== false) {
            fwrite(STDERR, "A variável de ambiente real {$variavel} está definida; ela tem prioridade sobre o arquivo e poderia apontar para outro banco. Remova-a e rode de novo.\n");
            exit(2);
        }
    }

    $teste = new Env($arquivo);
    $banco = $teste->required('MYSQL_DATABASE');
    if (stripos($banco, 'test') === false) {
        fwrite(STDERR, "Por segurança, o nome do banco de teste deve conter \"test\" (ex.: furk_cycle_test).\n");
        exit(2);
    }

    // Os nomes de GET_LOCK valem para o SERVIDOR MySQL inteiro (não por banco). Se o banco de teste
    // estiver no mesmo servidor da produção (comum em cPanel), os testes precisam de um prefixo
    // próprio nos locks, senão poderiam interferir nos locks REAIS das rotinas de produção.
    $prefixo = (string) $teste->optional('LOCK_PREFIX', '');
    if ($prefixo === '' || stripos($prefixo, 'test') === false) {
        fwrite(STDERR, "Defina LOCK_PREFIX no .env.test com um prefixo que contenha \"test\" (ex.: LOCK_PREFIX=test_). Sem ele, os testes poderiam colidir com os locks reais da produção.\n");
        exit(2);
    }

    $producao = $root . '/.env';
    if (is_file($producao)) {
        // Compara com a PRIMEIRA ocorrência de MYSQL_* no .env (o bloco de produção). O Env usa a
        // ÚLTIMA ocorrência; se alguém colar o bloco de teste dentro do .env, ela já não é a produção.
        [$prod, $repeticoes] = primeirasOcorrencias($producao, ['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE']);
        $repetidas = array_keys(array_filter($repeticoes, static fn (int $n): bool => $n > 1));
        if ($repetidas !== []) {
            fwrite(STDERR, 'ATENÇÃO: o .env define mais de uma vez: ' . implode(', ', $repetidas)
                . ". O Env usa a ÚLTIMA ocorrência (provavelmente a do teste), então o PHP local aponta para o banco de TESTE. Remova o bloco de teste do .env.\n");
        }
        $mesmo = ($prod['MYSQL_HOST'] ?? null) === $teste->optional('MYSQL_HOST')
            && (string) ($prod['MYSQL_PORT'] ?? '3306') === (string) $teste->optional('MYSQL_PORT', '3306')
            && ($prod['MYSQL_DATABASE'] ?? null) === $banco;
        if ($mesmo) {
            fwrite(STDERR, "O banco de teste é IGUAL ao banco do .env de produção. Abortado.\n");
            exit(2);
        }
    }

    return [$teste, $banco];
}

/**
 * Primeira ocorrência (e contagem) das chaves pedidas num arquivo .env — em memória, sem imprimir valores.
 *
 * @param array<int, string> $chaves
 * @return array{0: array<string, string>, 1: array<string, int>}
 */
function primeirasOcorrencias(string $arquivo, array $chaves): array
{
    $primeiras = [];
    $contagem = array_fill_keys($chaves, 0);
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        if (!in_array($chave, $chaves, true)) {
            continue;
        }
        $contagem[$chave]++;
        if (!isset($primeiras[$chave])) {
            $valor = trim($valor);
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[strlen($valor) - 1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            $primeiras[$chave] = $valor;
        }
    }

    return [$primeiras, $contagem];
}
