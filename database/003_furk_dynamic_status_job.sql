-- Executar no MySQL de controle antes da primeira execução da Rotina Contínua.
-- InnoDB é obrigatório para consistência nas atualizações de estado do job.

CREATE TABLE IF NOT EXISTS furk_dynamic_status_job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    data_base DATE NULL,
    frequencia_min INT UNSIGNED NULL,
    dias_recuo INT UNSIGNED NULL,
    dias_final INT UNSIGNED NOT NULL DEFAULT 1,
    estado ENUM('IDLE','RUNNING','STOP_REQUESTED','ERROR') NOT NULL DEFAULT 'IDLE',
    heartbeat DATETIME NULL,
    proxima_execucao DATETIME NULL,
    ultimo_resumo JSON NULL,
    duracao_media_s FLOAT UNSIGNED NULL,
    instancia VARCHAR(64) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;