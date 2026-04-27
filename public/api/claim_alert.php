<?php
/**
 * public/api/claim_alert.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Permite que el usuario logado se asigne una incidencia crítica.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para detectar el usuario logado.
 * - Usa JiraIssueMutationService.php para asignar la incidencia en Jira
 *   y actualizar la BBDD local.
 *
 * REGLA:
 * - admin y operador pueden usarlo
 * - la incidencia se asigna SIEMPRE al jira_account_id del usuario logado
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/JiraIssueMutationService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_api_role(['admin', 'operador']);

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

    $jiraKey = trim((string)($data['jira_key'] ?? ''));
    if ($jiraKey === '') {
        json_response([
            'ok'    => false,
            'error' => 'Falta jira_key.'
        ], 400);
    }

    $user = auth_user();
    if (!$user) {
        json_response([
            'ok'    => false,
            'error' => 'No autenticado.'
        ], 401);
    }

    $jiraAccountId = trim((string)($user['jira_account_id'] ?? ''));
    if ($jiraAccountId === '') {
        json_response([
            'ok'    => false,
            'error' => 'El usuario logado no tiene jira_account_id configurado.'
        ], 400);
    }

    $service = new JiraIssueMutationService();
    $result = $service->applyManualEdit($jiraKey, [
        'assignee_account_id' => $jiraAccountId
    ], 'app');

    json_response([
        'ok'      => true,
        'message' => 'Incidencia asignada correctamente.',
        'row'     => $result['row'] ?? null,
        'detail'  => $result['detail'] ?? null,
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error asignando la incidencia.'
    ], 500);
}
