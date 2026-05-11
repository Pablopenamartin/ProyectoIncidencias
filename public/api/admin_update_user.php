<?php
/**
 * public/api/admin_update_user.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint admin para actualizar datos administrativos de usuarios.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para exigir rol admin.
 * - Usa Utils.php para responder JSON homogéneo.
 * - Usa UserModel.php para:
 *   - buscar usuario por ID
 *   - contar admins activos
 *   - contar usuarios activos con llamadas habilitadas
 *   - actualizar datos administrativos
 *
 * QUÉ PERMITE EDITAR:
 * - display_name
 * - role
 * - is_active
 * - phone_number
 * - phone_notifications_enabled
 *
 * REGLAS DE NEGOCIO:
 * - no puedes desactivarte a ti mismo
 * - siempre debe quedar al menos 1 admin activo
 * - siempre debe quedar al menos 1 usuario activo con llamadas habilitadas
 * - si un usuario tiene llamadas habilitadas, debe tener teléfono válido
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/models/UserModel.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role('admin');

/**
 * Lee el body JSON.
 */
function readJsonBodyAdminUpdateUser(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

/**
 * Valida un teléfono básico en formato internacional.
 * Ejemplo esperado: +34600111222
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
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.'
        ], 405);
    }

    $data = readJsonBodyAdminUpdateUser();

    if (empty($data)) {
        json_response([
            'ok'    => false,
            'error' => 'Body JSON vacío o inválido.'
        ], 400);
    }

    //----------------------------------------------------------
    // 1) Normalizar entrada
    //----------------------------------------------------------
    $userId                    = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $displayName               = trim((string)($data['display_name'] ?? ''));
    $role                      = trim((string)($data['role'] ?? ''));
    $isActive                  = isset($data['is_active']) ? (int)$data['is_active'] : -1;
    $phoneNumber               = trim((string)($data['phone_number'] ?? ''));
    $phoneNotificationsEnabled = isset($data['phone_notifications_enabled'])
        ? (int)$data['phone_notifications_enabled']
        : 0;

    //----------------------------------------------------------
    // 2) Validaciones básicas
    //----------------------------------------------------------
    if ($userId <= 0) {
        json_response([
            'ok'    => false,
            'error' => 'user_id es obligatorio y debe ser mayor que 0.'
        ], 400);
    }

    if ($displayName === '') {
        json_response([
            'ok'    => false,
            'error' => 'El nombre visible es obligatorio.'
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

    // Si recibe llamadas, el teléfono es obligatorio y debe ser válido
    if ($phoneNotificationsEnabled === 1 && !isValidInternationalPhone($phoneNumber)) {
        json_response([
            'ok'    => false,
            'error' => 'Si las llamadas están habilitadas, el teléfono debe estar en formato internacional válido (por ejemplo, +34600111222).'
        ], 400);
    }

    //----------------------------------------------------------
    // 3) Cargar usuario actual
    //----------------------------------------------------------
    $model = new UserModel();
    $targetUser = $model->findById($userId);

    if (!$targetUser) {
        json_response([
            'ok'    => false,
            'error' => 'El usuario indicado no existe.'
        ], 404);
    }

    //----------------------------------------------------------
    // 4) Usuario autenticado actual
    //----------------------------------------------------------
    $authUser = auth_user();

    if (!$authUser) {
        json_response([
            'ok'    => false,
            'error' => 'No autenticado.'
        ], 401);
    }

    $currentAuthUserId = (int)($authUser['id'] ?? 0);
    $isSelfEdit = $currentAuthUserId === (int)$targetUser['id'];

    //----------------------------------------------------------
    // 5) Regla: no puedes desactivarte a ti mismo
    //----------------------------------------------------------
    if ($isSelfEdit && $isActive === 0) {
        json_response([
            'ok'    => false,
            'error' => 'No puedes desactivarte a ti mismo.'
        ], 400);
    }

    //----------------------------------------------------------
    // 6) Regla: siempre debe quedar al menos 1 admin activo
    //----------------------------------------------------------
    $targetWasActiveAdmin = (
        ($targetUser['role'] ?? '') === 'admin'
        && (int)($targetUser['is_active'] ?? 0) === 1
    );

    $targetWillBeActiveAdmin = (
        $role === 'admin'
        && $isActive === 1
    );

    if ($targetWasActiveAdmin && !$targetWillBeActiveAdmin) {
        $otherActiveAdmins = $model->countActiveAdmins((int)$targetUser['id']);

        if ($otherActiveAdmins < 1) {
            json_response([
                'ok'    => false,
                'error' => 'Debe existir al menos un administrador activo.'
            ], 400);
        }
    }

    //----------------------------------------------------------
    // 7) Regla: siempre debe quedar al menos 1 usuario activo
    //    con llamadas habilitadas
    //----------------------------------------------------------
    $targetHadPhoneCalls = (
        (int)($targetUser['is_active'] ?? 0) === 1
        && (int)($targetUser['phone_notifications_enabled'] ?? 0) === 1
    );

    $targetWillHavePhoneCalls = (
        $isActive === 1
        && $phoneNotificationsEnabled === 1
    );

    if ($targetHadPhoneCalls && !$targetWillHavePhoneCalls) {
        $otherCallableUsers = $model->countUsersWithPhoneNotificationsEnabled((int)$targetUser['id']);

        if ($otherCallableUsers < 1) {
            json_response([
                'ok'    => false,
                'error' => 'Debe existir al menos un usuario activo con llamadas habilitadas.'
            ], 400);
        }
    }

    //----------------------------------------------------------
    // 8) Actualizar usuario
    //----------------------------------------------------------
    $ok = $model->updateUserAdminData(
        $userId,
        $displayName,
        $role,
        $isActive,
        $phoneNumber !== '' ? $phoneNumber : null,
        $phoneNotificationsEnabled
    );

    if (!$ok) {
        json_response([
            'ok'    => false,
            'error' => 'No se pudo actualizar el usuario.'
        ], 500);
    }

    //----------------------------------------------------------
    // 9) Cargar usuario actualizado
    //----------------------------------------------------------
    $updatedUser = $model->findById($userId);

    json_response([
        'ok'      => true,
        'message' => 'Usuario actualizado correctamente.',
        'data'    => [
            'id'                          => (int)$updatedUser['id'],
            'username'                    => (string)$updatedUser['username'],
            'display_name'                => (string)$updatedUser['display_name'],
            'role'                        => (string)$updatedUser['role'],
            'jira_account_id'             => (string)($updatedUser['jira_account_id'] ?? ''),
            'phone_number'                => (string)($updatedUser['phone_number'] ?? ''),
            'phone_notifications_enabled' => (int)($updatedUser['phone_notifications_enabled'] ?? 0),
            'is_active'                   => (int)$updatedUser['is_active'],
            'created_at'                  => (string)($updatedUser['created_at'] ?? ''),
            'updated_at'                  => (string)($updatedUser['updated_at'] ?? ''),
        ],
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error actualizando el usuario.'
    ], 500);
}
