

CREATE TABLE ai_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,

    prompt_general LONGTEXT NOT NULL,
    def_incidencia_critica LONGTEXT NOT NULL,

    language VARCHAR(10) NOT NULL DEFAULT 'es',
    provider VARCHAR(50) NOT NULL DEFAULT 'openai',
    model VARCHAR(50) NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--------------------------------------------------------------------
CREATE TABLE ai_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    report_name VARCHAR(255) NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',

    provider VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,

    prompt_general_used LONGTEXT NOT NULL,
    def_incidencia_critica_used LONGTEXT NOT NULL,

    total_issues_analyzed INT NOT NULL DEFAULT 0,
    total_critical_detected INT NOT NULL DEFAULT 0,

    report_summary TEXT,
    report_text LONGTEXT,
    raw_response_json LONGTEXT,

    trigger_source VARCHAR(50) NOT NULL,
    sync_reference_time DATETIME,

    error_message TEXT,

    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
----------------------------------------------------------------------

CREATE TABLE ai_report_issues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    report_id BIGINT NOT NULL,
    jira_key VARCHAR(20) NOT NULL,

    summary VARCHAR(255),
    current_status VARCHAR(100),
    current_priority VARCHAR(50),

    is_critical TINYINT(1) NOT NULL DEFAULT 0,
    critical_reason TEXT,
    recommended_action TEXT,
    analysis_text LONGTEXT,
    score DECIMAL(5,2),

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ai_report
        FOREIGN KEY (report_id)
        REFERENCES ai_reports(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;