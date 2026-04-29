<?php
/**
 * public/api/admin_create_user.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Endpoint admin para crear usuarios en Jira Cloud y en la app local.
 *
 * ACCESO:
 * - solo admin
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para proteger acceso
 * - Usa JiraUserProvisionService.php para alta Jira + alta local
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/JiraUserProvisionService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.'
        ], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response([
            'ok'    => false,
            'error' => 'JSON inválido.'
        ], 400);
    }

    $service = new JiraUserProvisionService();
    $result = $service->registerUser([
        'username'     => $data['username'] ?? '',
        'password'     => $data['password'] ?? '',
        'display_name' => $data['display_name'] ?? '',
        'role'         => $data['role'] ?? '',
        'is_active'    => $data['is_active'] ?? 1,
    ]);

    json_response([
        'ok'      => true,
        'message' => 'Usuario creado correctamente en Jira y en la app.',
        'data'    => $result,
    ]);

} catch (InvalidArgumentException $e) {
    json_response([
        'ok'    => false,
        'error' => $e->getMessage()
    ], 400);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error creando el usuario.'
    ], 500);
}