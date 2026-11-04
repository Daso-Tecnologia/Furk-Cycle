-- Executar no MySQL de controle ANTES de usar a Sincronização Consolidada Contínua.
-- InnoDB é obrigatório para consistência do cursor (ponto de retomada) e do log.

-- ---------------------------------------------------------------------------
-- Job da varredura (período longo, processado em blocos com cursor)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS furk_sync_continuo_job (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    initial_date DATE NOT NULL,
    final_date DATE NOT NULL,
    cursor_date DATE NOT NULL,
    block_days INT UNSIGNED NOT NULL DEFAULT 5,
    bloco_inicio DATE NULL,
    bloco_fim DATE NULL,
    tabela_atual VARCHAR(32) NULL,
    estado ENUM('QUEUED','RUNNING','PAUSED','STOP_REQUESTED','COMPLETED','ERROR') NOT NULL DEFAULT 'QUEUED',
    progresso JSON NULL,
    resumo JSON NULL,
    rotinas_pausadas JSON NULL,
    retentar TINYINT(1) NOT NULL DEFAULT 0,
    erro TEXT NULL,
    heartbeat DATETIME NULL,
    instancia VARCHAR(64) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_furk_sync_continuo_job_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Log de evolução (um registro por bloco × tabela)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS furk_sync_continuo_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    bloco_inicio DATE NULL,
    bloco_fim DATE NULL,
    tabela VARCHAR(32) NOT NULL,
    status ENUM('OK','ERRO') NOT NULL,
    resumo JSON NULL,
    erro TEXT NULL,
    duracao_s FLOAT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_furk_sync_continuo_log_job (job_id, id),
    KEY idx_furk_sync_continuo_log_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
