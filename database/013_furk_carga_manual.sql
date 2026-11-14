-- Registro das LEITURAS MANUAIS em andamento (Sales, Status Dinâmicos, Créditos, Reembolsos): quem iniciou, de qual tela,
-- o período e o andamento, e o pedido de interrupção (somente ADMIN). Alimenta a faixa de aviso e o botão Interromper.
-- Executar no MySQL de controle. É SEGURO publicar o código antes: sem a tabela as leituras funcionam como antes
-- (sem andamento detalhado e sem Interromper). InnoDB. Linhas finalizadas com mais de 7 dias são apagadas sozinhas.

CREATE TABLE IF NOT EXISTS furk_carga_manual (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tela VARCHAR(30) NOT NULL,
    usuario_id BIGINT UNSIGNED NULL,
    usuario_nome VARCHAR(150) NULL,
    periodo_inicial DATE NULL,
    periodo_final DATE NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
    progresso TEXT NULL,
    resumo TEXT NULL,
    iniciado_em DATETIME NOT NULL,
    heartbeat DATETIME NOT NULL,
    finalizado_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_furk_carga_manual_estado (estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
