<?php
/**
 * public/api/generate_closure_report.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Genera informe de calidad de cierre para una incidencia.
 *
 * MÉTODO:
 * POST
 *
 * BODY:
 * {
 *   "issue_id": 123
 * }
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/ClosureReportService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

function readJson(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    $data = readJson();

    $issueId = isset($data['issue_id']) ? (int)$data['issue_id'] : 0;

    if ($issueId <= 0) {
        json_response(['ok' => false, 'error' => 'issue_id obligatorio'], 400);
    }

    $service = new ClosureReportService();

    $result = $service->generateReport($issueId, 'manual');

    json_response([
        'ok' => true,
        'message' => 'Informe de cierre generado',
        'data' => $result
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Error generando informe de cierre'
    ], 500);
}