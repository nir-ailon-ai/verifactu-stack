-- Audit log for AI-extracted incoming invoice imports.
-- Run once per install:
--   docker compose exec db mariadb -ufsuser -p"$(grep MARIADB_PASSWORD ~/verifactu-stack/.env | cut -d= -f2)" facturascripts < incoming/setup-sidecar.sql

CREATE TABLE IF NOT EXISTS incoming_invoice_imports (
    id              INT             NOT NULL AUTO_INCREMENT,
    pdf_path        VARCHAR(500)    NOT NULL,
    pdf_hash        VARCHAR(64)     NOT NULL,
    idfactura       INT             NULL,
    empresa_nif     VARCHAR(30)     NULL,
    supplier_nif    VARCHAR(30)     NULL,
    invoice_number  VARCHAR(100)    NULL,
    status          ENUM('imported','error') NOT NULL DEFAULT 'error',
    confidence      ENUM('high','medium','low') NULL,
    error_message   TEXT            NULL,
    claude_raw      LONGTEXT        NULL,
    created_at      DATETIME        NOT NULL DEFAULT NOW(),
    PRIMARY KEY (id),
    UNIQUE  KEY uq_pdf_hash  (pdf_hash),
    KEY     idx_status       (status),
    KEY     idx_supplier_nif (supplier_nif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
