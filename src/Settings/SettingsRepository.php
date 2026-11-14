<?php

declare(strict_types=1);

namespace FurkiCycle\Settings;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use PDO;
use PDOException;

/**
 * Leitura/gravação das configurações editáveis (tabelas furk_configuracao e furk_configuracao_historico).
 * Sem a tabela (migration 012 ainda não executada), a leitura devolve os PADRÕES: é seguro publicar o código antes.
 */
final class SettingsRepository
{
    private ?PDO $connection = null;

    public function __construct(private readonly Env $env)
    {
    }

    /** Filtro atual do Rebooking (padrões onde nada foi gravado). */
    public function rebookingGateFilter(): RebookingGateFilter
    {
        try {
            $statement = $this->pdo()->query("SELECT chave, valor FROM furk_configuracao WHERE chave LIKE 'rebooking.gate.%'");
            $rows = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                return RebookingGateFilter::defaults();
            }
            throw $error;
        }

        /** @var array<string, string> $rows */
        return RebookingGateFilter::fromStorage($rows);
    }

    /**
     * Grava o novo filtro. Só registra (valor + histórico) o que de fato mudou.
     *
     * @return array<int, array{chave: string, de: string, para: string}> alterações aplicadas
     */
    public function saveRebookingGateFilter(RebookingGateFilter $novo, int $userId): array
    {
        $pdo = $this->pdo();
        $nome = $this->userName($userId);
        $atual = $this->rebookingGateFilter()->toStorage();
        $alteracoes = [];

        $pdo->beginTransaction();
        try {
            foreach ($novo->toStorage() as $chave => $valor) {
                $antes = $atual[$chave] ?? '';
                if ($antes === $valor) {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO furk_configuracao (chave, valor, atualizado_por, atualizado_por_nome, atualizado_em)
                     VALUES (:chave, :valor, :uid, :nome, NOW())
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por),
                                             atualizado_por_nome = VALUES(atualizado_por_nome), atualizado_em = VALUES(atualizado_em)',
                )->execute(['chave' => $chave, 'valor' => $valor, 'uid' => $userId > 0 ? $userId : null, 'nome' => $nome]);

                $pdo->prepare(
                    'INSERT INTO furk_configuracao_historico (chave, valor_anterior, valor_novo, alterado_por, alterado_por_nome, alterado_em)
                     VALUES (:chave, :antes, :valor, :uid, :nome, NOW())',
                )->execute(['chave' => $chave, 'antes' => $antes, 'valor' => $valor, 'uid' => $userId > 0 ? $userId : null, 'nome' => $nome]);

                $alteracoes[] = ['chave' => $chave, 'de' => $antes, 'para' => $valor];
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $alteracoes;
    }

    /**
     * Últimas alterações (mais recentes primeiro).
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $statement = $this->pdo()->query(
                "SELECT chave, valor_anterior, valor_novo, alterado_por_nome, alterado_em
                 FROM furk_configuracao_historico WHERE chave LIKE 'rebooking.gate.%' ORDER BY id DESC LIMIT {$limit}",
            );

            return $statement->fetchAll();
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                return [];
            }
            throw $error;
        }
    }

    private function userName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $statement = $this->pdo()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $nome = $statement->fetchColumn();
        } catch (PDOException) {
            return null;
        }

        return is_string($nome) && $nome !== '' ? substr($nome, 0, 150) : null;
    }

    private function pdo(): PDO
    {
        return $this->connection ??= MySqlConnection::create($this->env);
    }
}
