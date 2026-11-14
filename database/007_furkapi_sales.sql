-- Executar no MySQL de controle antes da primeira sincronização de vendas.
-- InnoDB é obrigatório para que cada página seja gravada dentro de uma transação.

CREATE TABLE IF NOT EXISTS furkapi_sales (
    airline_code VARCHAR(10) NOT NULL,
    ticket_number VARCHAR(32) NOT NULL,
    original_ticket_number VARCHAR(32) NULL,
    locator_code VARCHAR(32) NULL,
    ndc_order_id VARCHAR(100) NULL,
    transaction_type VARCHAR(30) NULL,
    issue_date DATE NULL,
    ticket_status VARCHAR(40) NULL,
    issue_channel VARCHAR(40) NULL,
    iata_code VARCHAR(30) NULL,
    operational_unit VARCHAR(80) NULL,
    issuer_user VARCHAR(100) NULL,
    tour_code VARCHAR(100) NULL,
    passenger_first_name VARCHAR(120) NULL,
    passenger_last_name VARCHAR(120) NULL,
    passenger_type VARCHAR(20) NULL,
    total_fare_value DECIMAL(15,2) NULL,
    total_boarding_fee_value DECIMAL(15,2) NULL,
    total_tax_du_value DECIMAL(15,2) NULL,
    total_taxes_value DECIMAL(15,2) NULL,
    total_incentive_value DECIMAL(15,2) NULL,
    total_commission_value DECIMAL(15,2) NULL,
    total_value DECIMAL(15,2) NULL,
    updated_at DATETIME NULL,
    last_synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (airline_code, ticket_number),
    KEY idx_furkapi_sales_issue_date (issue_date),
    KEY idx_furkapi_sales_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Idempotência: PRIMARY KEY (airline_code, ticket_number) — o upsert
-- (INSERT ... ON DUPLICATE KEY UPDATE) nunca grava a mesma venda duas vezes.
-- Os arrays 'segments' e 'payments' da Furk não são gravados nesta tabela.
