-- ============================================================================
-- Email Marketing System - Complete Database Schema
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Kompatibel mit MySQL 8.0+ und MariaDB 10.6+
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. CONTACTS - Zentrale Kontakt-Tabelle
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    company VARCHAR(150),

    -- Status & Lifecycle
    status ENUM('unconfirmed', 'active', 'unsubscribed', 'bounced', 'complained', 'suppressed')
        NOT NULL DEFAULT 'unconfirmed',

    -- Double Opt-in (DSGVO)
    confirmation_token VARCHAR(64),
    confirmed_at DATETIME,
    unsubscribed_at DATETIME,
    unsubscribe_token VARCHAR(64) UNIQUE,

    -- Engagement Tracking
    last_opened_at DATETIME,
    last_clicked_at DATETIME,
    open_count INT DEFAULT 0,
    click_count INT DEFAULT 0,
    engagement_tier ENUM('hot', 'warm', 'cold') DEFAULT 'cold',

    -- Herkunft
    source VARCHAR(100) DEFAULT 'manual',
    import_id BIGINT NULL,

    -- Custom Fields (flexibel, JSON)
    custom_fields JSON,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email),
    KEY idx_status (status),
    KEY idx_engagement_tier (engagement_tier),
    KEY idx_unsubscribe_token (unsubscribe_token),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. TAGS - Kernparadigma des Systems
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('newsletter', 'segment', 'engagement', 'status', 'campaign', 'custom')
        NOT NULL DEFAULT 'custom',
    description VARCHAR(255),
    color VARCHAR(7) DEFAULT '#6c757d',
    is_system BOOLEAN DEFAULT FALSE, -- systemseitig verwaltete Tags (z.B. status:hard-bounce)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_tag_name (name),
    KEY idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. CONTACT_TAGS - Many-to-Many Zuordnung + Lifecycle-Tracking
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_tags (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT NOT NULL,
    tag_id BIGINT NOT NULL,

    added_by ENUM('manual', 'automation', 'import', 'api') DEFAULT 'manual',
    added_by_user VARCHAR(150),
    added_reason VARCHAR(255),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    removed_at DATETIME NULL,
    removed_by ENUM('manual', 'automation', 'import', 'api') NULL,
    removed_reason VARCHAR(255),

    UNIQUE KEY uniq_active_tag (contact_id, tag_id, removed_at),
    KEY idx_contact (contact_id),
    KEY idx_tag (tag_id),

    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. TAG_RULES - Auto-Tagging / Trigger-Regeln
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tag_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    trigger_event ENUM(
        'email_opened', 'email_not_opened_days', 'email_clicked',
        'unsubscribed', 'hard_bounce', 'soft_bounce', 'complained',
        'confirmed_opt_in', 'imported', 'manual'
    ) NOT NULL,
    trigger_params JSON, -- z.B. {"days": 90}
    action_add_tags JSON,    -- Array von tag_ids
    action_remove_tags JSON, -- Array von tag_ids
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_trigger (trigger_event),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. TEMPLATES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    subject VARCHAR(255),
    preview_text VARCHAR(255),
    body_html LONGTEXT,
    body_text TEXT,
    variables JSON, -- verfuegbare {{platzhalter}}
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. EMAIL_ACCOUNTS - Absender-/SMTP-Konten
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(150) NOT NULL,
    reply_to VARCHAR(255),
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    smtp_username VARCHAR(255),
    smtp_password_encrypted TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. CAMPAIGNS - Kampagnen (auch fuer Quick-Send Newsletter genutzt)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    type ENUM('newsletter', 'sequence', 'automation') DEFAULT 'newsletter',
    status ENUM('draft', 'scheduled', 'sending', 'sent', 'paused', 'cancelled')
        NOT NULL DEFAULT 'draft',

    template_id BIGINT,
    email_account_id BIGINT,

    subject VARCHAR(255),
    preview_text VARCHAR(255),

    -- Tag-basierte Empfaenger-Auswahl
    -- {"include": ["tag:x AND tag:y"], "exclude": ["tag:z"]}
    audience_rule JSON,
    audience_count INT DEFAULT 0,

    -- Versand-Optionen
    scheduled_at DATETIME NULL,
    send_rate_per_minute INT DEFAULT 50,
    use_engagement_tiers BOOLEAN DEFAULT TRUE,
    test_email_sent_to VARCHAR(255),

    started_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_by VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_status (status),
    KEY idx_scheduled (scheduled_at),

    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
    FOREIGN KEY (email_account_id) REFERENCES email_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. CAMPAIGN_STEPS - fuer Kampagnen-Sequenzen mit Delays
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaign_steps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT NOT NULL,
    step_order INT NOT NULL DEFAULT 1,
    template_id BIGINT,
    delay_minutes INT DEFAULT 0, -- Delay relativ zum vorherigen Step
    subject VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_campaign (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. EMAIL_QUEUE - Versand-Queue (Kern der Architektur)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    contact_id BIGINT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT,
    body_text TEXT,
    template_id INT,
    sender_email VARCHAR(255),

    -- Status Tracking
    status ENUM('pending', 'processing', 'sent', 'failed', 'bounced', 'complained') DEFAULT 'pending',
    attempt_count INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    next_retry DATETIME,
    sent_at DATETIME,
    error_message TEXT,

    -- Engagement-Tier fuer priorisierten Versand
    engagement_tier ENUM('hot', 'warm', 'cold') DEFAULT 'cold',

    -- Tracking IDs fuer Analytics
    tracking_id VARCHAR(64) UNIQUE,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- KRITISCHE INDIZES (Performance!)
    KEY idx_status_retry (status, next_retry),
    KEY idx_campaign (campaign_id),
    KEY idx_created (created_at),
    KEY idx_email (recipient_email),
    KEY idx_tracking (tracking_id),
    KEY idx_tier_status (engagement_tier, status),

    FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. EMAIL_QUEUE_LOG - Debugging & Monitoring
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT NOT NULL,
    event ENUM('queued', 'started', 'sent', 'failed', 'bounced', 'retrying', 'complained') NOT NULL,
    details JSON,
    error_code VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_queue (queue_id),
    KEY idx_event (event),
    KEY idx_created (created_at),

    FOREIGN KEY(queue_id) REFERENCES email_queue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. EMAIL_EVENTS - Opens/Clicks/Unsubscribes pro Email (fuer Statistiken)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT NOT NULL,
    campaign_id BIGINT NOT NULL,
    contact_id BIGINT NULL,
    event_type ENUM('open', 'click', 'unsubscribe', 'bounce', 'complaint') NOT NULL,
    url VARCHAR(500) NULL, -- bei 'click'
    user_agent VARCHAR(255),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_queue (queue_id),
    KEY idx_campaign_event (campaign_id, event_type),
    KEY idx_contact (contact_id),
    KEY idx_created (created_at),

    FOREIGN KEY (queue_id) REFERENCES email_queue(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. SUPPRESSION_LIST - Hard Bounces, Complaints, Unsubscribes (nie wieder kontaktieren)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppression_list (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    reason ENUM('hard_bounce', 'complained', 'unsubscribed', 'manual', 'invalid_syntax') NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email),
    KEY idx_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. CONSENT_LOG - DSGVO Double-Opt-in / Widerspruch Nachweise
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consent_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT NOT NULL,
    action ENUM('opt_in_requested', 'opt_in_confirmed', 'opt_out', 'consent_withdrawn') NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    source VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_contact (contact_id),
    KEY idx_action (action),

    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. AUDIT_LOG - Wer / Wann / Was (24 Monate Aufbewahrung)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor VARCHAR(150) NOT NULL DEFAULT 'system',
    action VARCHAR(150) NOT NULL,
    entity_type VARCHAR(100),
    entity_id BIGINT,
    details JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_actor (actor),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. CONTACT_IMPORTS - CSV Import Historie
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_imports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255),
    total_rows INT DEFAULT 0,
    imported_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    error_count INT DEFAULT 0,
    column_mapping JSON,
    applied_tags JSON,
    status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
    created_by VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,

    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 16. WEBHOOKS - Ausgehende Webhook-Konfiguration
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhooks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    url VARCHAR(500) NOT NULL,
    events JSON, -- ["email.sent", "email.opened", "contact.unsubscribed", ...]
    secret VARCHAR(64),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 17. API_TOKENS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    permissions JSON,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,

    UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 18. SETTINGS - Key/Value Systemeinstellungen (Impressum, Datenschutz, etc.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(150) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- Seed: System-Tags (aus DEVELOPMENT_1.md Tag-Katalog)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO tags (name, type, description, is_system) VALUES
    ('newsletter-subscriber', 'newsletter', 'Newsletter abonniert', TRUE),
    ('newsletter-active', 'newsletter', 'In den letzten 30 Tagen aktiv', TRUE),
    ('newsletter-cold', 'newsletter', 'Inaktiv > 90 Tage', TRUE),
    ('newsletter-unsubscribed', 'newsletter', 'Abgemeldet', TRUE),
    ('engaged:high', 'engagement', '>5 Oeffnungen/Monat', TRUE),
    ('engaged:medium', 'engagement', '2-5 Oeffnungen/Monat', TRUE),
    ('engaged:low', 'engagement', '<2 Oeffnungen/Monat', TRUE),
    ('engaged:unengaged', 'engagement', 'Nie geoeffnet', TRUE),
    ('status:hard-bounce', 'status', 'Hard Bounce (Invalid)', TRUE),
    ('status:soft-bounce', 'status', 'Soft Bounce (Temporarily Rejected)', TRUE),
    ('status:complained', 'status', 'Spam-Beschwerde', TRUE),
    ('status:unconfirmed', 'status', 'Double Opt-in nicht bestaetigt', TRUE),
    ('status:suppressed', 'status', 'Auf Suppression List', TRUE);

-- ----------------------------------------------------------------------------
-- Seed: Default Settings
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('double_opt_in', 'true'),
    ('audit_log_retention_months', '24'),
    ('impressum_url', ''),
    ('datenschutz_url', '');
