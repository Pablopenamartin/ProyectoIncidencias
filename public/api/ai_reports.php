<?php
/**
 * public/api/ai_reports.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para listar informes IA generados.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/models/AiReportModel.php
 * - Será consumido por la futura pantalla "Informes"
 *
 * FUNCIONES PRINCIPALES:
 * - GET -> devuelve listado de informes ordenados por fecha descendente
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/models/AiReportModel.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'GET') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.',
        ], 405);
    }

    // Límite opcional para el listado
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $model = new AiReportModel();
    $rows = $model->getReportsList($limit);

    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows,
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error obteniendo el listado de informes IA.',
    ], 500);
}