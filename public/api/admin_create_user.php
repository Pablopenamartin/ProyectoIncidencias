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
 *
 * CAMPOS ESPERADOS:
 * - username
 * - password
 * - display_name
 * - role
 * - is_active
 * - phone_number
 * - phone_notifications_enabled
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

/**
 * isValidInternationalPhone
 * --------------------------------------------------------------
 * Valida un teléfono en formato internacional.
 *
 * FORMATO ESPERADO:
 * - +34600111222
 *
 * @param string|null $phone
 * @return bool
 */
function isValidInternationalPhone(?string $phone): bool
{
    $phone = trim((string)$phone);

    if ($phone === '') {
        return false;
    }

    return preg_match('/^\+[1-9]\d{6,14}$/', $phone) === 1;
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

    //----------------------------------------------------------
    // 1) Normalizar campos
    //----------------------------------------------------------
    $username                  = trim((string)($data['username'] ?? ''));
    $password                  = (string)($data['password'] ?? '');
    $displayName               = trim((string)($data['display_name'] ?? ''));
    $role                      = trim((string)($data['role'] ?? ''));
    $isActive                  = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $phoneNumber               = trim((string)($data['phone_number'] ?? ''));
    $phoneNotificationsEnabled = isset($data['phone_notifications_enabled'])
        ? (int)$data['phone_notifications_enabled']
        : 0;

    //----------------------------------------------------------
    // 2) Validaciones rápidas de entrada
    //----------------------------------------------------------
    if ($username === '' || $password === '' || $displayName === '' || $role === '') {
        json_response([
            'ok'    => false,
            'error' => 'Faltan campos obligatorios.'
        ], 400);
    }

    if (!in_array($role, ['admin', 'operador'], true)) {
        json_response([
            'ok'    => false,
            'error' => 'El rol debe ser admin u operador.'
        ], 400);
    }

    if (!in_array($isActive, [0, 1], true)) {
        json_response([
            'ok'    => false,
            'error' => 'is_active debe valer 0 o 1.'
        ], 400);
    }

    if (!in_array($phoneNotificationsEnabled, [0, 1], true)) {
        json_response([
            'ok'    => false,
            'error' => 'phone_notifications_enabled debe valer 0 o 1.'
        ], 400);
    }

    // Si el usuario tendrá llamadas automáticas, el teléfono es obligatorio
    if ($phoneNotificationsEnabled === 1 && !isValidInternationalPhone($phoneNumber)) {
        json_response([
            'ok'    => false,
            'error' => 'Si las llamadas están habilitadas, el teléfono debe estar en formato internacional válido (por ejemplo, +34600111222).'
        ], 400);
    }

    //----------------------------------------------------------
    // 3) Ejecutar alta completa
    //----------------------------------------------------------
    $service = new JiraUserProvisionService();

    $result = $service->registerUser([
        'username'                    => $username,
        'password'                    => $password,
        'display_name'                => $displayName,
        'role'                        => $role,
        'is_active'                   => $isActive,
        'phone_number'                => $phoneNumber !== '' ? $phoneNumber : null,
        'phone_notifications_enabled' => $phoneNotificationsEnabled,
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