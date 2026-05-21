<?php
/**
 * public/api/ai_reports.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para listar los informes visibles en la pestaña Informes.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para exigir acceso admin.
 * - Usa database.php para consultar la base de datos.
 * - Devuelve informes de dos tipos:
 *   1) Informes incidencia (tabla ai_reports)
 *   2) Informes 12H        (tabla ai_queue_reports)
 *
 * FUNCIONES PRINCIPALES:
 * - GET -> devuelve el listado unificado de informes
 * - Añade report_type para que el frontend pueda distinguir:
 *   - incidencia
 *   - 12h
 *
 * NOTA IMPORTANTE:
 * - Este endpoint NO devuelve el detalle completo del informe.
 * - Solo devuelve el listado resumido para la vista principal.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.'
        ], 405);
    }

    $pdo = getPDO();

    /**
     * Límite máximo de filas.
     * Se normaliza para evitar valores absurdos.
     */
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit = max(1, min(200, $limit));

    /**
     * Consulta unificada:
     * - ai_reports       -> informes de incidencia
     * - ai_queue_reports -> informes 12H
     *
     * QUÉ HACE:
     * - normaliza ambos tipos a una estructura común
     * - añade report_type
     * - ordena por fecha de creación descendente
     *
     * NOTA:
     * - En informes 12H no existen campos como total_issues_analyzed o
     *   total_critical_detected de forma natural, así que se rellenan a 0
     *   para mantener compatibilidad con la UI actual.
     */
    $sql = "
        (
            SELECT
                r.id,
                r.report_name,
                r.status,
                'incidencia' AS report_type,
                r.provider,
                r.model,
                r.total_issues_analyzed,
                r.total_critical_detected,
                r.trigger_source,
                r.sync_reference_time,
                r.error_message,
                r.started_at,
                r.completed_at,
                r.created_at
            FROM ai_reports r
        )

        UNION ALL

        (
            SELECT
                qr.id,
                qr.report_name,
                qr.status,
                '12h' AS report_type,
                'openai' AS provider,
                NULL AS model,
                0 AS total_issues_analyzed,
                0 AS total_critical_detected,
                qr.trigger_source,
                NULL AS sync_reference_time,
                qr.error_message,
                qr.started_at,
                qr.completed_at,
                qr.created_at
            FROM ai_queue_reports qr
        )

        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ";

    $rows = $pdo->query($sql)->fetchAll() ?: [];

    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows,
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error listando informes.'
    ], 500);
}