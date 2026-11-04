-- Executar no MySQL de controle antes da primeira sincronização.
-- InnoDB é obrigatório para que cada página seja gravada dentro de uma transação.

CREATE TABLE IF NOT EXISTS furkapi_creditos_elegiveis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    airline_code VARCHAR(10) NULL,
    ticket_number VARCHAR(50) NOT NULL,
    locator_code VARCHAR(50) NULL,
    ndc_order_id VARCHAR(100) NULL,
    transaction_type VARCHAR(20) NULL,
    issue_date DATE NULL,
    passenger_name VARCHAR(255) NULL,
    passenger_first_name VARCHAR(100) NULL,
    passenger_last_name VARCHAR(100) NULL,
    passenger_type VARCHAR(50) NULL,
    fare_value DECIMAL(15,2) NULL,
    boarding_fee_value DECIMAL(15,2) NULL,
    tax_du_value DECIMAL(15,2) NULL,
    taxes_value DECIMAL(15,2) NULL,
    incentive_value DECIMAL(15,2) NULL,
    commission_value DECIMAL(15,2) NULL,
    total_value DECIMAL(15,2) NULL,
    estimated_credit DECIMAL(15,2) NULL,
    estimated_change_fee DECIMAL(15,2) NULL,
    applied_credit DECIMAL(15,2) NULL,
    paid_amount DECIMAL(15,2) NULL,
    applied_change_fee DECIMAL(15,2) NULL,
    expiration_date DATE NULL,
    reissued_ticket JSON NULL,
    updated_at DATETIME NULL,
    last_import DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_ticket_number (ticket_number),
    KEY idx_credit_airline_code (airline_code),
    KEY idx_credit_issue_date (issue_date),
    KEY idx_credit_expiration_date (expiration_date),
    KEY idx_credit_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se a tabela já existir como MyISAM, valide um backup e depois execute:
-- ALTER TABLE furkapi_creditos_elegiveis ENGINE = InnoDB;
-- Para versões de MySQL sem suporte ao tipo JSON, altere reissued_ticket para LONGTEXT NULL.
