<?php
/**
 * public/api/alerts.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para obtener las alertas críticas sin asignar.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/models/AiReportModel.php
 * - Usa app/helpers/Auth.php para proteger acceso por rol
 *
 * FUNCIONES PRINCIPALES:
 * - GET -> devuelve últimas incidencias críticas sin asignar
 *
 * ACCESO:
 * - admin
 * - operador
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/models/AiReportModel.php';

header('Content-Type: application/json; charset=utf-8');

// Permitimos acceso tanto a admin como a operador.
auth_require_api_role(['admin', 'operador']);

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'GET') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.',
        ], 405);
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $model = new AiReportModel();
    $rows = $model->getLatestCriticalUnassignedAlerts($limit);

    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows,
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error obteniendo las alertas críticas.',
    ], 500);
}