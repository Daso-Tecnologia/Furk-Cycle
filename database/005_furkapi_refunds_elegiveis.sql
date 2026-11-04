-- Executar no MySQL de controle antes da primeira sincronização de reembolsos.
-- InnoDB é obrigatório para que cada página seja gravada dentro de uma transação.
-- A tabela já existe em produção (exemplo_furk_cycle_api); este script serve
-- como referência (IF NOT EXISTS) e não sobrescreve a base existente.

CREATE TABLE IF NOT EXISTS furkapi_refunds_elegiveis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    airline_code VARCHAR(10) NULL,
    ticket_number VARCHAR(50) NOT NULL,
    locator_code VARCHAR(50) NULL,
    ndc_order_id VARCHAR(100) NULL,
    transaction_type VARCHAR(20) NULL,
    issue_date DATE NULL,
    estimated_refund_value VARCHAR(50) NULL,
    estimated_credit VARCHAR(50) NULL,
    refund_paid_value VARCHAR(50) NULL,
    refund_origin VARCHAR(50) NULL,
    refund_request_status VARCHAR(50) NULL,
    refund_payment_type VARCHAR(50) NULL,
    refund_billing_reference VARCHAR(100) NULL,
    refund_requested_date DATE NULL,
    refund_billing_date DATE NULL,
    updated_at DATETIME NULL,
    last_import DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refund_ticket_number (ticket_number),
    KEY idx_refund_airline_code (airline_code),
    KEY idx_refund_issue_date (issue_date),
    KEY idx_refund_request_status (refund_request_status),
    KEY idx_refund_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observação: os campos de valor são VARCHAR porque a Furk devolve valores
-- formatados (ex.: "-30,65"), preservando o formato original de ponta a ponta.
-- A regra de idempotência é UNIQUE(ticket_number), conforme o processo original.
