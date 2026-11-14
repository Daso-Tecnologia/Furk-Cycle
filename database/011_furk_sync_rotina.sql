-- Executar no MySQL de controle antes da primeira execução da
-- Sincronização Consolidada Contínua Recursiva.
-- InnoDB é obrigatório para consistência nas atualizações de estado do job.
--
-- Regras de negócio (16/10/2026):
--   * data_base       = sempre a DATA ATUAL (primeiro disparo); vedada a alteração.
--   * frequencia_horas= 1 a 5 horas (o worker converte para minutos na pausa).
--   * dias_recuo      = 1 a 5 dias (janela: [hoje - recuo, hoje - 1], sempre em D-1).

CREATE TABLE IF NOT EXISTS furk_sync_rotina (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    data_base DATE NULL,
    frequencia_horas INT UNSIGNED NULL,
    dias_recuo INT UNSIGNED NULL,
    estado ENUM('IDLE','RUNNING','STOP_REQUESTED','ERROR') NOT NULL DEFAULT 'IDLE',
    heartbeat DATETIME NULL,
    proxima_execucao DATETIME NULL,
    ultimo_resumo JSON NULL,
    duracao_media_s FLOAT UNSIGNED NULL,
    instancia VARCHAR(64) NULL,
    tabela_atual VARCHAR(32) NULL,
    janela_inicial DATE NULL,
    janela_final DATE NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
