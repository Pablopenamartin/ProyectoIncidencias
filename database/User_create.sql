CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,

    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(150) NOT NULL,

    role ENUM('admin', 'operador') NOT NULL,
    jira_account_id VARCHAR(100) NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--------------------
INSERT INTO users (
    username,
    password_hash,
    display_name,
    role,
    jira_account_id,
    is_active
) VALUES
(
    'admin@dxc.com', //correo de la empresa
    '$2y$10$CXkdmeDPK.zLwSlZ3ZhXSOzeUrqa3Fiqdd54sX/u36HogIghRTb5u',
    'Administrador',
    'admin',
    '712020:xxxxxxxx-admin',
    1
),
(
    'operador1@dxc.com', 
    '$2y$10$YTpQBHbdFzYQVTbZL9v2betVIWFVqIQ9KKi1JWFgbS2LdSmIA5fYK',
    'Operador 1',
    'operador',
    '712020:xxxxxxxx-operador1',
    1
);