<?php
/**
 * public/api/whoami.php
 * -------------------------------------------------------
 * Muestra qué archivo estás ejecutando y, sobre todo,
 * qué variables de entorno (.env) están siendo leídas por el backend.
 * Requiere que app/config/constants.php cargue el .env desde la raíz del proyecto.
 */

require_once __DIR__ . '/../../app/config/constants.php'; // Carga .env y constantes

header('Content-Type: application/json; charset=utf-8');

// Intento de resolver la ruta esperada del .env de raíz
$basePath = defined('BASE_PATH') ? BASE_PATH : realpath(__DIR__ . '/../../');
$envPath  = $basePath ? ($basePath . DIRECTORY_SEPARATOR . '.env') : null;

// Construimos salida
echo json_encode([
    'file'                    => __FILE__,
    'base_path'               => $basePath,
    'expected_root_env_path'  => $envPath,
    // Variables críticas de Jira / BD: deben venir del .env de la RAÍZ, NO del /public
    'JIRA_USE_ATLASSIAN_API'  => getenv('JIRA_USE_ATLASSIAN_API') ?: null,
    'JIRA_CLOUD_ID'           => getenv('JIRA_CLOUD_ID') ?: null,
    'JIRA_SITE'               => getenv('JIRA_SITE') ?: null,
    'JIRA_EMAIL'              => getenv('JIRA_EMAIL') ?: null,
    'DB_NAME'                 => getenv('DB_NAME') ?: null
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);