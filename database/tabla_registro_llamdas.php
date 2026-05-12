CREATE TABLE alert_phone_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    jira_key VARCHAR(50) NOT NULL,
    report_id BIGINT NOT NULL,
    user_id INT NOT NULL,
    phone_number VARCHAR(30) NOT NULL,

    call_sent TINYINT(1) NOT NULL DEFAULT 0,
    call_error TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_alert_phone_notification (jira_key, report_id, user_id, phone_number),

    CONSTRAINT fk_alert_phone_notifications_report
        FOREIGN KEY (report_id)
        REFERENCES ai_reports(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_alert_phone_notifications_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;