<?php
/**
 * public/api/ai_report_detail.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para obtener el detalle de informes IA y ejecutar
 * acciones manuales sobre ellos.
 *
 * TIPOS DE INFORME SOPORTADOS:
 * - incidencia:
 *   Lee de ai_reports + ai_report_issues.
 *
 * - 12h:
 *   Lee de ai_queue_reports.
 *
 * - closure:
 *   Lee de ai_closure_reports.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para restringir acceso a administradores.
 * - Usa Utils.php para respuestas JSON homogéneas.
 * - Usa database.php para conexión PDO.
 * - Usa AiReportModel.php para informes de incidencia.
 *
 * FUNCIONES PRINCIPALES:
 * - GET:
 *   - type=incidencia -> detalle desde ai_reports + ai_report_issues
 *   - type=12h        -> detalle desde ai_queue_reports
 *   - type=closure    -> detalle desde ai_closure_reports
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
 * QUÉ HACE:
 * - Lee php://input
 * - Decodifica JSON como array asociativo
 * - Devuelve array vacío si no hay JSON válido
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
 * normalizeReportType
 * --------------------------------------------------------------
 * Normaliza el tipo de informe recibido desde frontend.
 *
 * TIPOS VÁLIDOS:
 * - incidencia
 * - 12h
 * - closure
 *
 * @param string $type Tipo recibido
 * @return string Tipo normalizado
 */
function normalizeReportType(string $type): string
{
    $type = strtolower(trim($type));

    if (in_array($type, ['incidencia', '12h', 'closure'], true)) {
        return $type;
    }

    return 'incidencia';
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
 * getClosureReportById
 * --------------------------------------------------------------
 * Recupera el detalle completo de un informe de cierre.
 *
 * QUÉ DEVUELVE:
 * - id
 * - issue_id
 * - jira_key
 * - status
 * - rating
 * - report_summary
 * - report_text
 * - raw_response_json
 * - error_message
 * - fechas
 *
 * @param PDO $pdo Conexión PDO
 * @param int $reportId ID del informe de cierre
 * @return array|null
 */
function getClosureReportById(PDO $pdo, int $reportId): ?array
{
    $sql = "
        SELECT
            cr.id,

            CONCAT(
                'Informe cierre ',
                cr.jira_key,
                ' · Rating ',
                IFNULL(cr.rating, 'N/A'),
                '/10'
            ) AS report_name,

            cr.issue_id,
            cr.jira_key,
            cr.status,
            'closure' AS report_type,

            'openai' AS provider,
            NULL AS model,

            0 AS total_issues_analyzed,
            IFNULL(cr.rating, 0) AS total_critical_detected,

            cr.rating,
            cr.report_summary,
            cr.report_text,
            cr.raw_response_json,
            cr.error_message,
            cr.trigger_source,

            NULL AS sync_reference_time,

            cr.started_at,
            cr.completed_at,
            cr.created_at
        FROM ai_closure_reports cr
        WHERE cr.id = :id
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
 * NOTA:
 * - No borra error_message.
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

/**
 * markClosureReportCompletedManually
 * --------------------------------------------------------------
 * Marca manualmente un informe de cierre como completed.
 *
 * NOTA:
 * - No borra error_message.
 *
 * @param PDO $pdo Conexión PDO
 * @param int $reportId ID del informe de cierre
 * @return void
 */
function markClosureReportCompletedManually(PDO $pdo, int $reportId): void
{
    $sql = "
        UPDATE ai_closure_reports
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

    // ----------------------------------------------------------
    // GET -> obtener detalle del informe
    // ----------------------------------------------------------
    if ($method === 'GET') {
        $reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $type = normalizeReportType((string)($_GET['type'] ?? 'incidencia'));

        if ($reportId <= 0) {
            json_response([
                'ok'    => false,
                'error' => 'El parámetro id es obligatorio y debe ser mayor que 0.'
            ], 400);
        }

        // ------------------------------------------------------
        // Detalle informe 12H
        // ------------------------------------------------------
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

        // ------------------------------------------------------
        // Detalle informe de cierre
        // ------------------------------------------------------
        if ($type === 'closure') {
            $report = getClosureReportById($pdo, $reportId);

            if (!$report) {
                json_response([
                    'ok'    => false,
                    'error' => 'No existe el informe de cierre solicitado.'
                ], 404);
            }

            json_response([
                'ok'          => true,
                'report_type' => 'closure',
                'report'      => $report,
                'issues'      => [],
            ]);
        }

        // ------------------------------------------------------
        // Detalle informe incidencia
        // ------------------------------------------------------
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

    // ----------------------------------------------------------
    // POST -> acciones manuales
    // ----------------------------------------------------------
    if ($method === 'POST') {
        $data = readJsonBodyAiReportDetail();

        if (empty($data)) {
            json_response([
                'ok'    => false,
                'error' => 'Body JSON vacío o inválido.'
            ], 400);
        }

        $reportId = isset($data['id']) ? (int)$data['id'] : 0;
        $type = normalizeReportType((string)($data['type'] ?? 'incidencia'));
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

        // ------------------------------------------------------
        // Acción: marcar como completed
        // ------------------------------------------------------
        if ($action === 'mark_completed') {

            // --------------------------------------------------
            // Marcar informe 12H
            // --------------------------------------------------
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

            // --------------------------------------------------
            // Marcar informe de cierre
            // --------------------------------------------------
            if ($type === 'closure') {
                $report = getClosureReportById($pdo, $reportId);

                if (!$report) {
                    json_response([
                        'ok'    => false,
                        'error' => 'No existe el informe de cierre indicado.'
                    ], 404);
                }

                markClosureReportCompletedManually($pdo, $reportId);

                json_response([
                    'ok'      => true,
                    'message' => 'Informe de cierre marcado manualmente como completed.',
                    'report'  => getClosureReportById($pdo, $reportId),
                    'issues'  => [],
                ]);
            }

            // --------------------------------------------------
            // Marcar informe incidencia
            // --------------------------------------------------
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

    // ----------------------------------------------------------
    // Método no permitido
    // ----------------------------------------------------------
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