-- Executar no MySQL de controle antes da primeira importação de Status Dinâmicos.
-- As tabelas podem já existir no servidor; este arquivo serve de referência do schema.
-- InnoDB é obrigatório para que cada parte seja gravada dentro de uma transação.

CREATE TABLE IF NOT EXISTS furkapi_bilhetes_aereos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    airline_code VARCHAR(10) NULL,
    ticket_number VARCHAR(50) NOT NULL,
    locator_code VARCHAR(50) NULL,
    ndc_order_id VARCHAR(100) NULL,
    transaction_type VARCHAR(20) NULL,
    issue_date DATE NULL,
    ticket_status VARCHAR(50) NULL,
    updated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bilhete_airline_code (airline_code),
    KEY idx_bilhete_issue_date (issue_date),
    KEY idx_bilhete_ticket_status (ticket_status),
    KEY idx_bilhete_airline_ticket (airline_code, ticket_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS furkapi_bilhetes_segmentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_number VARCHAR(50) NOT NULL,
    segment_order INT NOT NULL,
    fare_basis VARCHAR(50) NULL,
    fare_class VARCHAR(10) NULL,
    departure_date DATETIME NULL,
    arrival_date DATETIME NULL,
    origin VARCHAR(10) NULL,
    destination VARCHAR(10) NULL,
    flight_number VARCHAR(20) NULL,
    flight_airline_code VARCHAR(10) NULL,
    flight_status VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_segmento_ticket_number (ticket_number),
    KEY idx_segmento_origin_destination (origin, destination),
    KEY idx_segmento_departure_date (departure_date),
    KEY idx_segmento_flight_status (flight_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para versões de MySQL com chaves únicas por bilhete, reforce a idempotência:
-- ALTER TABLE furkapi_bilhetes_aereos ADD UNIQUE KEY uq_bilhete_ticket_number (ticket_number);
-- ALTER TABLE furkapi_bilhetes_segmentos ADD UNIQUE KEY uq_segmento_ticket_order (ticket_number, segment_order);