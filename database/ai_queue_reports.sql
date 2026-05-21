CREATE TABLE ai_queue_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Identidad del informe
    report_name VARCHAR(255) NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',

    -- Tipo fijo
    report_type VARCHAR(20) NOT NULL DEFAULT '12h',

    -- Periodo del informe (clave)
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    period_label ENUM('morning','afternoon') NOT NULL,

    -- Origen
    trigger_source VARCHAR(50) NOT NULL DEFAULT 'scheduler', -- scheduler | manual_button

    -- Métricas globales
    total_open_start INT DEFAULT 0,
    total_open_end INT DEFAULT 0,
    total_incoming INT DEFAULT 0,
    total_resolved INT DEFAULT 0,
    total_unassigned INT DEFAULT 0,
    total_unassigned_critical INT DEFAULT 0,

    -- Tiempos
    avg_resolution_time_sec INT DEFAULT NULL,
    max_resolution_time_sec INT DEFAULT NULL,
    avg_time_to_assign_sec INT DEFAULT NULL,

    -- JSON con métricas extendidas
    metrics_json LONGTEXT NULL,

    -- IA
    report_summary TEXT NULL,
    report_text LONGTEXT NULL,
    prompt_used LONGTEXT NULL,
    raw_response_json LONGTEXT NULL,

    -- Errores
    error_message TEXT NULL,

    -- Trazabilidad
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
