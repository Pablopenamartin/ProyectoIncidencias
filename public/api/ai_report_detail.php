<?php
/**
 * public/api/ai_report_detail.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para obtener detalle de informes IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para restringir acceso a administradores.
 * - Usa Utils.php para respuestas JSON homogéneas.
 * - Usa AiReportModel.php para informes de incidencia.
 * - Usa database.php para consultar informes 12H en ai_queue_reports.
 *
 * FUNCIONES PRINCIPALES:
 * - GET:
 *   - type=incidencia -> detalle desde ai_reports + ai_report_issues
 *   - type=12h        -> detalle desde ai_queue_reports
 *
 * - POST:
 *   - action=mark_completed
 *   - permite marcar manualmente como completed informes failed
 *
 * NOTA:
 * - No borra error_message para mantener trazabilidad.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/models/AiReportModel.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

/**
 * readJsonBodyAiReportDetail
 * --------------------------------------------------------------
 * Lee el body JSON de una petición POST.
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

/**
 * getQueueReportById
 * --------------------------------------------------------------
 * Recupera la cabecera completa de un informe 12H.
 *
 * @param PDO $pdo Conexión PDO
 * @param int $reportId ID del informe 12H
 * @return array|null
 */
function getQueueReportById(PDO $pdo, int $reportId): ?array
{
    $sql = "
        SELECT
            id,
            report_name,
            status,
            report_type,
            period_start,
            period_end,
            period_label,
            trigger_source,
            total_open_start,
            total_open_end,
            total_incoming,
            total_resolved,
            total_unassigned,
            total_unassigned_critical,
            avg_resolution_time_sec,
            max_resolution_time_sec,
            avg_time_to_assign_sec,
            metrics_json,
            report_summary,
            report_text,
            prompt_used,
            raw_response_json,
            error_message,
            started_at,
            completed_at,
            created_at
        FROM ai_queue_reports
        WHERE id = :id
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id' => $reportId
    ]);

    $row = $st->fetch();

    return $row ?: null;
}

/**
 * markQueueReportCompletedManually
 * --------------------------------------------------------------
 * Marca manualmente un informe 12H como completed.
 *
 * @param PDO $pdo Conexión PDO
 * @param int $reportId ID del informe 12H
 * @return void
 */
function markQueueReportCompletedManually(PDO $pdo, int $reportId): void
{
    $sql = "
        UPDATE ai_queue_reports
        SET
            status = 'completed',
            completed_at = NOW()
        WHERE id = :id
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id' => $reportId
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = getPDO();
    $model = new AiReportModel();

    //----------------------------------------------------------
    // GET -> obtener detalle
    //----------------------------------------------------------
    if ($method === 'GET') {
        $reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $type = strtolower(trim((string)($_GET['type'] ?? 'incidencia')));

        if ($reportId <= 0) {
            json_response([
                'ok'    => false,
                'error' => 'El parámetro id es obligatorio y debe ser mayor que 0.'
            ], 400);
        }

        //------------------------------------------------------
        // Detalle informe 12H
        //------------------------------------------------------
        if ($type === '12h') {
            $report = getQueueReportById($pdo, $reportId);

            if (!$report) {
                json_response([
                    'ok'    => false,
                    'error' => 'No existe el informe 12H solicitado.'
                ], 404);
            }

            json_response([
                'ok'          => true,
                'report_type' => '12h',
                'report'      => $report,
                'issues'      => [],
            ]);
        }

        //------------------------------------------------------
        // Detalle informe incidencia
        //------------------------------------------------------
        $report = $model->getReportById($reportId);

        if (!$report) {
            json_response([
                'ok'    => false,
                'error' => 'No existe el informe solicitado.'
            ], 404);
        }

        $issues = $model->getReportIssues($reportId);

        json_response([
            'ok'          => true,
            'report_type' => 'incidencia',
            'report'      => $report,
            'issues'      => $issues,
        ]);
    }

    //----------------------------------------------------------
    // POST -> acciones manuales
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
        $type = strtolower(trim((string)($data['type'] ?? 'incidencia')));
        $action = trim((string)($data['action'] ?? ''));

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

        //------------------------------------------------------
        // Marcar como completed
        //------------------------------------------------------
        if ($action === 'mark_completed') {
            if ($type === '12h') {
                $report = getQueueReportById($pdo, $reportId);

                if (!$report) {
                    json_response([
                        'ok'    => false,
                        'error' => 'No existe el informe 12H indicado.'
                    ], 404);
                }

                markQueueReportCompletedManually($pdo, $reportId);

                json_response([
                    'ok'      => true,
                    'message' => 'Informe 12H marcado manualmente como completed.',
                    'report'  => getQueueReportById($pdo, $reportId),
                    'issues'  => [],
                ]);
            }

            $report = $model->getReportById($reportId);

            if (!$report) {
                json_response([
                    'ok'    => false,
                    'error' => 'No existe el informe indicado.'
                ], 404);
            }

            $model->markCompletedManually($reportId);

            json_response([
                'ok'      => true,
                'message' => 'Informe marcado manualmente como completed.',
                'report'  => $model->getReportById($reportId),
                'issues'  => $model->getReportIssues($reportId),
            ]);
        }

        json_response([
            'ok'    => false,
            'error' => 'Acción no soportada.'
        ], 400);
    }

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