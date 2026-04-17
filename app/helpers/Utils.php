<?php
/**
 * app/helpers/Utils.php
 * ---------------------
 * Utilidades mínimas comunes: salida JSON, CORS, saneo, etc.
 */

require_once __DIR__ . '/../config/constants.php'; // Asegura env/constantes cargadas

/**
 * Emite cabeceras CORS de acuerdo a ALLOWED_ORIGINS (del .env).
 * Úsalo al principio de endpoints públicos.
 */
function send_cors_headers(): void
{
    $origin = ALLOWED_ORIGINS ?: '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Vary: Origin');

    // Si es preflight OPTIONS, devolvemos 204 y salimos
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Emite una respuesta JSON estándar (aplica códigos HTTP).
 */
function json_response($payload, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Convierte fecha MySQL (Y-m-d H:i:s) a formato legible (local TZ).
 */
function human_datetime(?string $mysqlDatetime): ?string
{
    if (!$mysqlDatetime) return null;
    try {
        $dt = new DateTime($mysqlDatetime);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $mysqlDatetime;
    }
}

/**
 * Convierte MySQL datetime a formato JQL "Y/m/d H:i" (sin zona explícita).
 * Jira acepta "yyyy/MM/dd HH:mm" para operadores como updated >= "....".
 */
function mysql_to_jql_datetime(?string $mysqlDatetime): ?string
{
    if (!$mysqlDatetime) return null;
    try {
        $dt = new DateTime($mysqlDatetime);
        return $dt->format('Y/m/d H:i'); // formato recomendado para JQL simple
    } catch (Throwable) {
        return null;
    }
}