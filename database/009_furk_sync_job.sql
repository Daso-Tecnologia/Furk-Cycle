-- Executar no MySQL de controle antes de usar a Sincronização Consolidada.
-- InnoDB é obrigatório para consistência nas atualizações de estado do job.

CREATE TABLE IF NOT EXISTS furk_sync_job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    initial_date DATE NULL,
    final_date DATE NULL,
    estado ENUM('QUEUED','RUNNING','COMPLETED','ERROR','STOP_REQUESTED') NOT NULL DEFAULT 'QUEUED',
    progresso JSON NULL,
    resumo JSON NULL,
    erro TEXT NULL,
    heartbeat DATETIME NULL,
    instancia VARCHAR(64) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
