-- ============================================================
-- 004_furk_rebooking_control.sql
-- Tabela de controlo de Rebooking (MySQL de controlo).
--
-- ATENÇÃO: esta tabela JÁ EXISTE em produção (com registros reais).
-- Este arquivo é uma REFERENCIA do schema observado (INFORMATION_SCHEMA)
-- e usa CREATE TABLE IF NOT EXISTS: NÃO altera nem toca a tabela existente.
-- ============================================================

CREATE TABLE IF NOT EXISTS `furk_rebooking_control` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `environment` VARCHAR(20) NOT NULL,
    `fil_agt` VARCHAR(30) NULL,
    `nota` VARCHAR(50) NULL,
    `num_file` VARCHAR(50) NULL,
    `localizador` VARCHAR(20) NULL,
    `tkt_num` VARCHAR(30) NULL,
    `airline_code` CHAR(2) NULL,
    `iata_code` VARCHAR(20) NULL,
    `issue_channel_sent` VARCHAR(50) NULL,
    `issue_channel_returned` VARCHAR(50) NULL,
    `furk_rebooking_id` CHAR(36) NULL,
    `furk_status` VARCHAR(40) NULL,
    `furk_request_datetime` DATETIME(6) NULL,
    `http_status` SMALLINT UNSIGNED NULL,
    `processing_status` ENUM('PENDING','SENT','ACCEPTED','REJECTED','ERROR','CANCELLED') NOT NULL,
    `error_message` TEXT NULL,
    `request_payload` LONGTEXT NULL,
    `response_payload` LONGTEXT NULL,
    `idempotency_key` VARCHAR(160) NOT NULL,
    `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `last_checked_at` DATETIME(6) NULL,
    `next_check_at` DATETIME(6) NULL,
    `field_sources` LONGTEXT NULL,
    `validation_notes` LONGTEXT NULL,
    `created_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_furk_rebooking_id` (`furk_rebooking_id`),
    KEY `idx_environment` (`environment`),
    KEY `idx_fil_agt` (`fil_agt`),
    KEY `idx_localizador` (`localizador`),
    KEY `idx_furk_status` (`furk_status`),
    KEY `idx_processing_status` (`processing_status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice para o cruce visual (grid madre por `nota`).
-- Se a tabela já existe e não tem este índice, pode criarse manualmente:
-- CREATE INDEX idx_furk_rebooking_control_nota ON furk_rebooking_control (nota);