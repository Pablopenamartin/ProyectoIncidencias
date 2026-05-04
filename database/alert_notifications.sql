CREATE TABLE alert_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    jira_key VARCHAR(20) NOT NULL,
    report_id BIGINT NOT NULL,

    notified_teams TINYINT(1) NOT NULL DEFAULT 0,
    notified_email TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_alert_notification (jira_key, report_id),

    CONSTRAINT fk_alert_notifications_report
        FOREIGN KEY (report_id)
        REFERENCES ai_reports(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
