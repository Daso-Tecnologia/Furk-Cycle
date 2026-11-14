<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use PDO;
use PDOException;

/**
 * Registro das leituras manuais em andamento (tabela furk_carga_manual, migration 013).
 *
 * Serve para (1) a faixa de aviso dizer quem iniciou, de qual tela, o período e o andamento, e (2) o ADMIN poder
 * INTERROMPER: a leitura roda numa requisição web e confere, entre páginas, se foi pedida a parada.
 * Tolerante à ausência da tabela: sem ela tudo vira "sem registro" e as leituras funcionam como antes.
 */
final class ManualLoadRegistry
{
    /** Uma linha RUNNING sem batimento há mais que isso é de uma requisição que morreu: não conta como em andamento. */
    private const STALE_SECONDS = 180;
    private const PURGE_DAYS = 7;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function begin(string $tela, ?int $userId, ?string $userName, string $initial, string $final): ?int
    {
        try {
            $this->connection->exec(
                'DELETE FROM furk_carga_manual WHERE finalizado_em IS NOT NULL AND finalizado_em < ' . $this->connection->quote(date('Y-m-d H:i:s', time() - self::PURGE_DAYS * 86400)),
            );
            // Linhas "RUNNING" órfãs (requisição que morreu) deixam de poluir o registro.
            $this->connection->exec(
                "UPDATE furk_carga_manual SET estado = 'ERROR', finalizado_em = " . $this->connection->quote(date('Y-m-d H:i:s'))
                . " WHERE estado IN ('RUNNING','STOP_REQUESTED') AND heartbeat < " . $this->connection->quote(date('Y-m-d H:i:s', time() - self::STALE_SECONDS)),
            );

            $now = date('Y-m-d H:i:s');
            $statement = $this->connection->prepare(
                'INSERT INTO furk_carga_manual (tela, usuario_id, usuario_nome, periodo_inicial, periodo_final, estado, iniciado_em, heartbeat)
                 VALUES (:tela, :uid, :nome, :ini, :fim, \'RUNNING\', :agora, :agora2)',
            );
            $statement->execute([
                'tela' => $tela,
                'uid' => $userId !== null && $userId > 0 ? $userId : null,
                'nome' => $userName !== null ? mb_substr($userName, 0, 150) : null,
                'ini' => $initial,
                'fim' => $final,
                'agora' => $now,
                'agora2' => $now,
            ]);

            return (int) $this->connection->lastInsertId();
        } catch (PDOException $error) {
            return $this->semTabela($error) ? null : $this->falha($error);
        }
    }

    /** @param array<string, mixed> $progress */
    public function touch(int $id, array $progress): void
    {
        try {
            $this->connection->prepare('UPDATE furk_carga_manual SET heartbeat = :h, progresso = :p WHERE id = :id')->execute([
                'h' => date('Y-m-d H:i:s'),
                'p' => json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'id' => $id,
            ]);
        } catch (PDOException $error) {
            $this->semTabela($error) || $this->falha($error);
        }
    }

    public function stopRequested(int $id): bool
    {
        try {
            $statement = $this->connection->prepare('SELECT estado FROM furk_carga_manual WHERE id = :id');
            $statement->execute(['id' => $id]);

            return (string) $statement->fetchColumn() === 'STOP_REQUESTED';
        } catch (PDOException $error) {
            return $this->semTabela($error) ? false : (bool) $this->falha($error);
        }
    }

    /** @param array<string, mixed> $summary */
    public function finish(int $id, string $estado, array $summary = []): void
    {
        try {
            $this->connection->prepare('UPDATE furk_carga_manual SET estado = :e, resumo = :r, finalizado_em = :f, heartbeat = :f2 WHERE id = :id')->execute([
                'e' => $estado,
                'r' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'f' => date('Y-m-d H:i:s'),
                'f2' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]);
        } catch (PDOException $error) {
            $this->semTabela($error) || $this->falha($error);
        }
    }

    /**
     * Pede a interrupção da leitura em andamento. Só Sales e Status Dinâmicos podem parar no meio sem deixar o período
     * incompleto (Créditos e Reembolsos apagam o período antes de regravar).
     *
     * @return array<string, mixed>|null a linha marcada, ou null se não há leitura em andamento
     */
    public function requestStop(): ?array
    {
        $active = $this->active();
        if ($active === null) {
            return null;
        }
        if (!in_array((string) $active['tela'], ['sales', 'status'], true)) {
            return $active + ['nao_interrompivel' => true];
        }
        try {
            $this->connection->prepare("UPDATE furk_carga_manual SET estado = 'STOP_REQUESTED' WHERE id = :id AND estado = 'RUNNING'")->execute(['id' => $active['id']]);
        } catch (PDOException $error) {
            $this->semTabela($error) || $this->falha($error);
        }

        return $active;
    }

    /** @return array<string, mixed>|null a leitura manual em andamento (batimento recente), se houver */
    public function active(): ?array
    {
        try {
            $statement = $this->connection->prepare(
                "SELECT * FROM furk_carga_manual WHERE estado IN ('RUNNING','STOP_REQUESTED') AND heartbeat >= :limite ORDER BY id DESC LIMIT 1",
            );
            $statement->execute(['limite' => date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (PDOException $error) {
            return $this->semTabela($error) ? null : $this->falha($error);
        }
    }

    private function semTabela(PDOException $error): bool
    {
        return (string) $error->getCode() === '42S02';
    }

    /** @return never */
    private function falha(PDOException $error): mixed
    {
        throw $error;
    }
}
