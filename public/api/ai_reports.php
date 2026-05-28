<?php
/**
 * public/api/ai_reports.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para listar los informes visibles en la pestaña Informes.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa constants.php para constantes globales.
 * - Usa database.php para consultar la base de datos mediante PDO.
 * - Usa Utils.php para responder JSON de forma homogénea.
 * - Usa Auth.php para exigir acceso admin.
 *
 * TIPOS DE INFORME SOPORTADOS:
 * 1) Informes incidencia  -> ai_reports
 * 2) Informes 12H         -> ai_queue_reports
 * 3) Informes cierre      -> ai_closure_reports
 *
 * PROBLEMA CORREGIDO:
 * - Error MySQL:
 *   SQLSTATE[HY000]: General error: 1271 Illegal mix of collations for operation 'UNION'
 *
 * CAUSA:
 * - Las tablas ai_reports, ai_queue_reports y ai_closure_reports pueden tener
 *   columnas VARCHAR/TEXT con collations distintas.
 * - Al hacer UNION ALL, MySQL necesita que las columnas equivalentes tengan
 *   collations compatibles.
 *
 * SOLUCIÓN:
 * - Forzamos todas las columnas de texto del UNION a:
 *   utf8mb4_unicode_ci
 * - Para ello usamos:
 *   CONVERT(columna USING utf8mb4) COLLATE utf8mb4_unicode_ci
 *
 * FUNCIONES PRINCIPALES:
 * - GET:
 *   - devuelve listado unificado de todos los informes
 *   - añade report_type para distinguirlos en frontend:
 *     - incidencia
 *     - 12h
 *     - closure
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Solo administradores pueden consultar el listado de informes.
 */
auth_require_api_role('admin');

try {
    // ----------------------------------------------------------
    // 1) Validar método HTTP
    // ----------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.'
        ], 405);
    }

    // ----------------------------------------------------------
    // 2) Obtener conexión PDO
    // ----------------------------------------------------------
    $pdo = getPDO();

    // ----------------------------------------------------------
    // 3) Normalizar límite de resultados
    // ----------------------------------------------------------
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit = max(1, min(200, $limit));

    // ----------------------------------------------------------
    // 4) Consulta unificada de informes
    // ----------------------------------------------------------
    /**
     * IMPORTANTE:
     * En todos los SELECT del UNION normalizamos las columnas de texto con:
     *
     * CONVERT(campo USING utf8mb4) COLLATE utf8mb4_unicode_ci
     *
     * Esto evita el error:
     * Illegal mix of collations for operation 'UNION'
     */
    $sql = "
        (
            SELECT
                r.id,

                CONVERT(r.report_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS report_name,
                CONVERT(r.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
                'incidencia' COLLATE utf8mb4_unicode_ci AS report_type,

                CONVERT(r.provider USING utf8mb4) COLLATE utf8mb4_unicode_ci AS provider,
                CONVERT(r.model USING utf8mb4) COLLATE utf8mb4_unicode_ci AS model,

                r.total_issues_analyzed,
                r.total_critical_detected,

                CONVERT(r.trigger_source USING utf8mb4) COLLATE utf8mb4_unicode_ci AS trigger_source,
                r.sync_reference_time,
                CONVERT(r.error_message USING utf8mb4) COLLATE utf8mb4_unicode_ci AS error_message,

                r.started_at,
                r.completed_at,
                r.created_at
            FROM ai_reports r
        )

        UNION ALL

        (
            SELECT
                qr.id,

                CONVERT(qr.report_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS report_name,
                CONVERT(qr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
                '12h' COLLATE utf8mb4_unicode_ci AS report_type,

                'openai' COLLATE utf8mb4_unicode_ci AS provider,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS model,

                0 AS total_issues_analyzed,
                0 AS total_critical_detected,

                CONVERT(qr.trigger_source USING utf8mb4) COLLATE utf8mb4_unicode_ci AS trigger_source,
                NULL AS sync_reference_time,
                CONVERT(qr.error_message USING utf8mb4) COLLATE utf8mb4_unicode_ci AS error_message,

                qr.started_at,
                qr.completed_at,
                qr.created_at
            FROM ai_queue_reports qr
        )

        UNION ALL

        (
            SELECT
                cr.id,

                CONVERT(
                    CONCAT(
                        'Informe cierre ',
                        cr.jira_key,
                        ' · Rating ',
                        IFNULL(cr.rating, 'N/A'),
                        '/10'
                    )
                    USING utf8mb4
                ) COLLATE utf8mb4_unicode_ci AS report_name,

                CONVERT(cr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
                'closure' COLLATE utf8mb4_unicode_ci AS report_type,

                'openai' COLLATE utf8mb4_unicode_ci AS provider,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS model,

                0 AS total_issues_analyzed,
                IFNULL(cr.rating, 0) AS total_critical_detected,

                CONVERT(cr.trigger_source USING utf8mb4) COLLATE utf8mb4_unicode_ci AS trigger_source,
                NULL AS sync_reference_time,
                CONVERT(cr.error_message USING utf8mb4) COLLATE utf8mb4_unicode_ci AS error_message,

                cr.started_at,
                cr.completed_at,
                cr.created_at
            FROM ai_closure_reports cr
        )

        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ";

    // ----------------------------------------------------------
    // 5) Ejecutar consulta
    // ----------------------------------------------------------
    $rows = $pdo->query($sql)->fetchAll() ?: [];

    // ----------------------------------------------------------
    // 6) Responder JSON
    // ----------------------------------------------------------
    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows,
    ]);

} catch (Throwable $t) {
    // ----------------------------------------------------------
    // 7) Manejo de errores
    // ----------------------------------------------------------
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG
            ? $t->getMessage()
            : 'Error listando informes.'
    ], 500);
}