CREATE TABLE IF NOT EXISTS tn_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    daily_limit INT UNSIGNED DEFAULT NULL,
    monthly_limit INT UNSIGNED DEFAULT NULL,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_projects_slug (slug),
    KEY idx_tn_projects_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_sms_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    recipient_raw VARCHAR(50) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    message VARCHAR(160) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'sms',
    status ENUM('queued', 'processing', 'sent', 'failed', 'blocked') NOT NULL DEFAULT 'queued',
    provider VARCHAR(50) NOT NULL DEFAULT 'mock',
    provider_message_id VARCHAR(120) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    idempotency_key VARCHAR(80) DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    meta_json JSON DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_sms_messages_project_id (project_id),
    KEY idx_tn_sms_messages_status (status),
    KEY idx_tn_sms_messages_created_at (created_at),
    KEY idx_tn_sms_messages_phone (phone),
    UNIQUE KEY uq_tn_sms_messages_idempotency (project_id, idempotency_key),
    CONSTRAINT fk_tn_sms_messages_project
        FOREIGN KEY (project_id) REFERENCES tn_projects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_sms_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    sms_message_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    details_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_sms_logs_sms_message_id (sms_message_id),
    KEY idx_tn_sms_logs_project_id (project_id),
    CONSTRAINT fk_tn_sms_logs_project
        FOREIGN KEY (project_id) REFERENCES tn_projects (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_sms_logs_message
        FOREIGN KEY (sms_message_id) REFERENCES tn_sms_messages (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_sms_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_sms_templates_project (project_id),
    CONSTRAINT fk_tn_sms_templates_project
        FOREIGN KEY (project_id) REFERENCES tn_projects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_optouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone VARCHAR(20) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_optouts_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projeto de teste opcional:
-- INSERT INTO tn_projects (name, slug, api_key_hash, active, daily_limit, monthly_limit, max_attempts)
-- VALUES ('Projeto Teste', 'projeto-teste', '$2y$10$troque-por-um-hash-valido', 1, NULL, NULL, 3);
