<?php
/**
 * public/api/jira_update_issue.php
 * -------------------------------------------------------
 * Endpoint de edición manual desde la app.
 *
 * GET  ?key=LIP-17  -> devuelve contexto para poblar el modal
 * POST JSON         -> aplica cambios en Jira y actualiza BBDD local
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/services/JiraIssueMutationService.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $service = new JiraIssueMutationService();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $key = trim((string)($_GET['key'] ?? ''));
        if ($key === '') {
            respond(['ok' => false, 'error' => 'Falta la clave Jira.'], 400);
        }

        $context = $service->getEditContext($key);
        respond(['ok' => true] + $context);
    }

    if ($method !== 'POST') {
        respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'JSON inválido.'], 400);
    }

    $jiraKey = trim((string)($data['jira_key'] ?? ''));
    if ($jiraKey === '') {
        respond(['ok' => false, 'error' => 'Falta jira_key.'], 400);
    }

    $changes = [
        'summary'             => $data['summary'] ?? null,
        'priority_level'      => $data['priority_level'] ?? null,
        'assignee_account_id' => $data['assignee_account_id'] ?? null,
        'transition_id'       => $data['transition_id'] ?? null,
    ];

    $result = $service->applyManualEdit($jiraKey, $changes, 'app');

    respond([
        'ok'       => true,
        'message'  => 'Incidencia actualizada correctamente.',
        'row'      => $result['row'],
        'detail'   => $result['detail'],
        'jira_url' => $result['jira_url'],
    ]);

} catch (Throwable $t) {
    respond([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error actualizando la incidencia en Jira.',
    ], 500);
}
