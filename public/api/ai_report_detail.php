<?php
/**
 * public/api/ai_report_detail.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para trabajar con un informe IA concreto.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/helpers/Auth.php para restringir acceso a administradores.
 * - Usa app/helpers/Utils.php para respuestas JSON homogéneas.
 * - Usa app/models/AiReportModel.php para:
 *   - leer el informe por ID
 *   - leer sus incidencias
 *   - marcarlo manualmente como completed
 *
 * FUNCIONES PRINCIPALES:
 * - GET:
 *   - devuelve cabecera del informe
 *   - devuelve incidencias analizadas del informe
 *
 * - POST:
 *   - permite ejecutar acciones sobre el informe
 *   - action = mark_completed
 *
 * NOTA:
 * - El cambio manual a completed NO borra error_message,
 *   para mantener trazabilidad del error original.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/models/AiReportModel.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

/**
 * readJsonBodyAiReportDetail
 * --------------------------------------------------------------
 * Lee el body JSON de la petición y lo devuelve como array.
 *
 * @return array
 */
function readJsonBodyAiReportDetail(): array
{
    $raw = file_get_contents('php://input') ?: '';

    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);

    return is_array($json) ? $json : [];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $model = new AiReportModel();

    //----------------------------------------------------------
    // GET -> obtener detalle del informe
    //----------------------------------------------------------
    if ($method === 'GET') {
        $reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($reportId <= 0) {
            json_response([
                'ok'    => false,
                'error' => 'El parámetro id es obligatorio y debe ser mayor que 0.'
            ], 400);
        }

        $report = $model->getReportById($reportId);

        if (!$report) {
            json_response([
                'ok'    => false,
                'error' => 'No existe el informe solicitado.'
            ], 404);
        }

        $issues = $model->getReportIssues($reportId);

        json_response([
            'ok'     => true,
            'report' => $report,
            'issues' => $issues,
        ]);
    }

    //----------------------------------------------------------
    // POST -> acciones sobre el informe
    //----------------------------------------------------------
    if ($method === 'POST') {
        $data = readJsonBodyAiReportDetail();

        if (empty($data)) {
            json_response([
                'ok'    => false,
                'error' => 'Body JSON vacío o inválido.'
            ], 400);
        }

        $reportId = isset($data['id']) ? (int)$data['id'] : 0;
        $action   = trim((string)($data['action'] ?? ''));

        if ($reportId <= 0) {
            json_response([
                'ok'    => false,
                'error' => 'El campo id es obligatorio y debe ser mayor que 0.'
            ], 400);
        }

        if ($action === '') {
            json_response([
                'ok'    => false,
                'error' => 'El campo action es obligatorio.'
            ], 400);
        }

        $report = $model->getReportById($reportId);

        if (!$report) {
            json_response([
                'ok'    => false,
                'error' => 'No existe el informe indicado.'
            ], 404);
        }

        //------------------------------------------------------
        // Acción: marcar manualmente como completed
        //------------------------------------------------------
        if ($action === 'mark_completed') {
            $model->markCompletedManually($reportId);

            $updatedReport = $model->getReportById($reportId);
            $issues = $model->getReportIssues($reportId);

            json_response([
                'ok'      => true,
                'message' => 'Informe marcado manualmente como completed.',
                'report'  => $updatedReport,
                'issues'  => $issues,
            ]);
        }

        //------------------------------------------------------
        // Acción no soportada
        //------------------------------------------------------
        json_response([
            'ok'    => false,
            'error' => 'Acción no soportada.'
        ], 400);
    }

    //----------------------------------------------------------
    // Método no permitido
    //----------------------------------------------------------
    json_response([
        'ok'    => false,
        'error' => 'Método no permitido.'
    ], 405);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error procesando el detalle del informe.'
    ], 500);
}
