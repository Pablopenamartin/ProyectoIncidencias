<?php
/**
 * public/api/admin_create_user.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint admin para crear usuarios desde la aplicación.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/helpers/Auth.php para restringir acceso a administradores.
 * - Usa app/helpers/Utils.php para responder en JSON de forma homogénea.
 * - Usa app/services/JiraUserProvisionService.php para ejecutar el alta completa.
 *
 * FUNCIONES PRINCIPALES:
 * - Acepta una petición POST con datos del usuario.
 * - Llama al servicio de provisión de usuario en Jira + BBDD local.
 * - Devuelve respuesta JSON con resultado o error.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/JiraUserProvisionService.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Solo los administradores pueden crear usuarios.
 */
auth_require_api_role('admin');

/**
 * readJsonBody
 * --------------------------------------------------------------
 * Lee el body JSON de la petición y lo devuelve como array.
 *
 * @return array
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

try {
    /**
     * Solo aceptamos POST.
     */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.'
        ], 405);
    }

    $data = readJsonBody();

    if (empty($data)) {
        json_response([
            'ok'    => false,
            'error' => 'Body JSON vacío o inválido.'
        ], 400);
    }

    /**
     * Ejecutar alta completa:
     * - Jira
     * - recuperación accountId
     * - guardado local
     */
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
        'message' => 'Usuario creado correctamente.',
        'data'    => $result,
    ]);

} catch (InvalidArgumentException $e) {
    json_response([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error creando el usuario.',
    ], 500);
}
