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
 *
 * FUNCIONES PRINCIPALES:
 * - GET -> devuelve listado de usuarios locales
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

    $sql = "
        SELECT
            id,
            username,
            display_name,
            role,
            jira_account_id,
            is_active,
            created_at,
            updated_at
        FROM users
        ORDER BY id DESC
    ";

    $rows = $pdo->query($sql)->fetchAll();

    json_response([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows ?: []
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error listando usuarios.'
    ], 500);
}
