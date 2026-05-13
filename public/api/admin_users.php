<?php
/**
 * public/api/admin_users.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint admin para listar usuarios locales de la aplicación.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para proteger acceso solo admin.
 * - Usa database.php para consultar la tabla users.
 * - Será consumido por public/admin_users_page.php
 *
 * FUNCIONES PRINCIPALES:
 * - GET -> devuelve listado de usuarios locales
 * - Además devuelve, si existe:
 *   - la incidencia visible más reciente asignada al usuario
 *   - su clave Jira
 *   - su resumen
 *   - su enlace a Jira
 *
 * NOTA:
 * - Para relacionar usuario -> incidencia se usa:
 *   users.jira_account_id = issues.assignee_account_id
 * - Solo se considera la incidencia visible más reciente del usuario.
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
     * Recuperamos todos los usuarios y, adicionalmente, la incidencia
     * visible más reciente asignada a cada uno.
     *
     * IMPORTANTE:
     * - Usamos comparación BINARY para evitar conflictos de collation
     *   entre users.jira_account_id e issues.assignee_account_id.
     */
    $sql = "
        SELECT
            u.id,
            u.username,
            u.display_name,
            u.role,
            u.jira_account_id,
            u.phone_number,
            u.phone_notifications_enabled,
            u.is_active,
            u.created_at,
            u.updated_at,

            (
                SELECT i.jira_key
                FROM issues i
                WHERE BINARY i.assignee_account_id = BINARY u.jira_account_id
                  AND i.visible = 1
                ORDER BY i.updated_at DESC, i.jira_key ASC
                LIMIT 1
            ) AS current_issue_key,

            (
                SELECT i.summary
                FROM issues i
                WHERE BINARY i.assignee_account_id = BINARY u.jira_account_id
                  AND i.visible = 1
                ORDER BY i.updated_at DESC, i.jira_key ASC
                LIMIT 1
            ) AS current_issue_summary

        FROM users u
        ORDER BY u.id DESC
    ";

    $rows = $pdo->query($sql)->fetchAll() ?: [];

    /**
     * Añadimos la URL a Jira si hay incidencia asignada.
     */
    $jiraBrowseBase = rtrim((string)JIRA_SITE, '/') . '/browse/';

    foreach ($rows as &$row) {
        $jiraKey = trim((string)($row['current_issue_key'] ?? ''));

        $row['current_issue_url'] = $jiraKey !== ''
            ? $jiraBrowseBase . rawurlencode($jiraKey)
            : null;
    }
    unset($row);

    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error listando usuarios.'
    ], 500);
}
