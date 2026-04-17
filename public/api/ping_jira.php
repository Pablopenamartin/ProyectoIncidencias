<?php
/**
 * public/api/ping_jira.php
 * ------------------------
 * Prueba de conectividad contra Jira Cloud.
 * - Carga entorno y helpers
 * - Permite pasar una JQL por querystring (?jql=...)
 * - Ejecuta POST /rest/api/3/search con JiraService
 * - Devuelve un JSON con: site, project, jql utilizada, total e (1) muestra
 *
 * Requisitos previos:
 *   - app/config/constants.php  (carga .env correctamente)
 *   - app/config/jira.php       (helpers de URL/cabeceras/body)
 *   - app/services/JiraService.php
 *
 * Uso:
 *   GET /api/ping_jira.php
 *   GET /api/ping_jira.php?jql=project%20=%20ABC%20ORDER%20BY%20updated%20DESC
 */

header('Content-Type: application/json; charset=utf-8');

// 1) Carga de entorno y dependencias
require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/config/jira.php';
require_once __DIR__ . '/../../app/services/JiraService.php';

// (Opcional) si quieres CORS también aquí, descomenta las dos líneas siguientes:
// require_once __DIR__ . '/../../app/helpers/Utils.php';
// send_cors_headers();

// [ADD] --- MODO INSPECCIÓN POR ISSUE (?key=...) ----------------------------
// Permite inspeccionar un issue concreto y descubrir los IDs reales de campos
// de JSM (p. ej., Urgency/Impact) usando /issue/{key}?expand=names,schema.
//
// Uso: GET /api/ping_jira.php?key=LIP-6
$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
if ($key !== '') {
    try {
        // Construye URL y cabeceras seguras
        $url     = jira_endpoint('/issue/' . rawurlencode($key)) . '?expand=names,schema';
        $headers = jira_headers();

        // cURL simple con TLS verificado
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errn = curl_errno($ch);
        $err  = curl_error($ch);
        unset($ch); // En PHP 8+, curl_close es no-op; destruir handle con unset

        if ($errn !== 0) {
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'mode'  => 'issue',
                'error' => APP_DEBUG ? $err : 'Error de conexión a Jira'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $json = json_decode($body, true);
        if ($http < 200 || $http >= 300 || !is_array($json)) {
            http_response_code($http ?: 500);
            echo json_encode([
                'ok'    => false,
                'mode'  => 'issue',
                'error' => APP_DEBUG ? $body : 'Error obteniendo el issue de Jira'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Respuesta reducida y útil para diagnosticar campos custom
        echo json_encode([
            'ok'     => true,
            'mode'   => 'issue',
            'url'    => $url,
            // En 'names' verás: customfield_XXXXX => "Urgency", "Impact", etc.
            'names'  => $json['names']  ?? null,
            // En 'fields.customfield_XXXXX' verás el valor real (value/name)
            'fields' => $json['fields'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $t) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'mode'  => 'issue',
            'error' => APP_DEBUG ? $t->getMessage() : 'Error inesperado leyendo el issue'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
// ---------------------------------------------------------------------------

// 2) Construcción de la JQL a usar
$jql = isset($_GET['jql']) ? trim((string)$_GET['jql']) : '';
if ($jql === '') {
    // Usa la JQL base del .env si existe; si no, cae a project = <KEY>
    $jql = (defined('JIRA_JQL_BASE') && JIRA_JQL_BASE)
         ? JIRA_JQL_BASE
         : ('project = ' . (defined('JIRA_PROJECT_KEY') ? JIRA_PROJECT_KEY : '') . ' ORDER BY updated DESC');
}

// 3) Campos mínimos para validar estructura sin descargar payload pesado
// [MOD] --- CAMPOS FLEXIBLES PARA SEARCH ------------------------------------
// Soporta ?fields=*all ó lista separada por comas (?fields=id,key,summary,...)
// Si no se especifica, usa un set ligero por defecto.
$fieldsParam = isset($_GET['fields']) ? trim((string)$_GET['fields']) : '';
if ($fieldsParam === '*all') {
    $fields = ['*all']; // JiraService compondrá fields=*all
} elseif ($fieldsParam !== '') {
    $fields = array_values(array_filter(array_map('trim', explode(',', $fieldsParam))));
} else {
    // Por defecto: payload mínimo
    $fields = ['id','key','summary','updated'];
}
// ---------------------------------------------------------------------------

try {
    // 4) Invoca Jira con nuestro servicio
    $svc   = new JiraService();
    $data  = $svc->searchIssues($jql, 1, 0, $fields);

    // 5) Extrae una muestra (primer issue) si hay resultados
    $sample = [];
    if (!empty($data['issues']) && is_array($data['issues'])) {
        $sample = array_slice($data['issues'], 0, 1);
    }

    // 6) Respuesta OK
    echo json_encode([
        'ok'      => true,
        'site'    => (defined('JIRA_SITE') ? JIRA_SITE : null),
        'project' => (defined('JIRA_PROJECT_KEY') ? JIRA_PROJECT_KEY : null),
        'jql'     => $jql,
        'total'   => $data['total'] ?? null,
        'sample'  => $sample
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $t) {
    // 7) Errores legibles (JiraService lanza mensajes claros si APP_DEBUG=true)
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error al contactar con Jira.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
