ALTER TABLE shops ADD COLUMN intasend_wallet_id VARCHAR(40) NULL;

CREATE TABLE IF NOT EXISTS intasend_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    reference VARCHAR(60) NOT NULL UNIQUE,
    invoice_id VARCHAR(60) NOT NULL,
    amount_minor INT NOT NULL,
    currency VARCHAR(10) NOT NULL,
    wallet_id VARCHAR(40) NOT NULL,
    status ENUM('initialized', 'verified_success', 'verified_failed') NOT NULL DEFAULT 'initialized',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    INDEX (client_id),
    INDEX (invoice_id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS intasend_webhook_events (
    event_key VARCHAR(190) NOT NULL PRIMARY KEY,
    state VARCHAR(40) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('received', 'processing', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    attempts INT NOT NULL DEFAULT 1,
    last_error VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX (reference),
    INDEX (status, updated_at)
);
