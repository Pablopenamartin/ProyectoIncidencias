<?php
/**
 * public/api/generate_queue_report.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Endpoint para generar informes 12H de evolución de la cola.
 *
 * RELACIÓN:
 * - Usa QueueReportService.php
 *
 * MÉTODOS:
 * POST → genera informe 12H manual
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/QueueReportService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

function readJsonBodyQueue(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response([
            'ok' => false,
            'error' => 'Método no permitido'
        ], 405);
    }

    $service = new QueueReportService();

    $result = $service->generateReport('manual_button');

    json_response([
        'ok' => true,
        'message' => 'Informe 12H generado correctamente',
        'data' => $result
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Error generando informe 12H'
    ], 500);
}
