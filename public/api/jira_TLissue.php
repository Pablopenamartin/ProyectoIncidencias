<?php
/**
 * public/api/jira_TLissue.php
 * -------------------------------------------------------
 * Endpoint para obtener el detalle de UNA incidencia Jira
 * desde la vista de Timeline.
 *
 * - NO lista incidencias
 * - NO pagina
 * - NO expone credenciales
 *
 * Entrada:
 *   GET ?key=PTL-3
 *
 * Salida (JSON):
 *   Información básica + URL web al ticket Jira
 */

require_once __DIR__ . '/../../app/services/JiraService.php';
require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * 1️⃣ Validar parámetro obligatorio
 */
$key = $_GET['key'] ?? null;

if (!$key) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Missing key parameter'
    ]);
    exit;
}

try {
    /**
     * 2️⃣ Instanciar JiraService
     *     (usa exactamente la implementación que YA tienes)
     */
    $jira = new JiraService();

    /**
     * 3️⃣ Buscar la incidencia usando JQL
     *     Jira permite recuperar un issue concreto con:
     *       key = PTL-3
     */
    $response = $jira->searchIssues(
        'key = ' . $key,
        1,      // máximo 1 resultado
        0,
        [
            // Campos que necesitamos para el detalle
            'key',
            'summary',
            'status',
            'priority',
            'assignee',
            'description'
        ]
    );

    $issues = $response['issues'] ?? [];

    if (count($issues) === 0) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Issue not found'
        ]);
        exit;
    }

    /**
     * 4️⃣ Extraer datos relevantes
     */
    $issue  = $issues[0];
    $fields = $issue['fields'] ?? [];

    // ✅ Construir URL web CORRECTA del ticket Jira
    // Usamos la base configurada del Jira real (UI), NO la API
    $jiraWebUrl = rtrim(env('JIRA_BASE_URL'), '/') . '/browse/' . $issue['key'];

    /**
     * 6️⃣ Preparar respuesta limpia para el frontend
     */
    echo json_encode([
        'ok'          => true,
        'key'         => $issue['key'],
        'summary'     => $fields['summary'] ?? '',

        // Descripción: formato Atlassian Doc → texto plano (primer bloque)
        'description' =>
            $fields['description']['content'][0]['content'][0]['text']
            ?? '',

        'status'    => $fields['status']['name'] ?? '',
        'priority'  => $fields['priority']['name'] ?? '',
        'assignee'  => $fields['assignee']['displayName'] ?? '—',
        'url'       => rtrim(env('JIRA_SITE'), '/') . '/browse/' . $issue['key']
    ]);

} catch (Throwable $e) {
    /**
     * 7️⃣ Manejo de errores
     */
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}
