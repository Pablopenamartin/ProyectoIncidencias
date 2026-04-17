content = r'''<?php
/**
 * public/api/jira_webhook.php
 * -------------------------------------------------------
 * Endpoint HTTP para recibir webhooks de Jira Cloud.
 *
 * OBJETIVO ACTUAL:
 * ✅ Recibir eventos jira:issue_created y jira:issue_updated
 * ✅ Actualizar la tabla issues reutilizando IssueModel
 * ✅ Registrar en issue_timeline una fila por cada cambio relevante:
 *    - issue_created
 *    - summary_change
 *    - status_change
 *    - priority_change
 *    - assignee_change
 * ✅ Deduplicar reintentos usando X-Atlassian-Webhook-Identifier
 * ✅ Dejar preparada la base para source / correlation_id
 *
 * NOTA IMPORTANTE:
 * - Por ahora source se resolverá como 'jira' salvo que se envíe una pista
 *   explícita en el payload (campo no estándar) o se implemente más adelante
 *   la correlación App/IA con issue properties.
 * - No se implementa todavía la parte IA → App → Jira.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/models/IssueModel.php';
require_once __DIR__ . '/../../app/services/IssueTimelineService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // --------------------------------------------------
    // 1) SOLO POST
    // --------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido'
        ], 405);
    }

    // --------------------------------------------------
    // 2) LEER PAYLOAD RAW + HEADERS
    // --------------------------------------------------
    $rawBody = file_get_contents('php://input') ?: '';

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = array_change_key_case($headers, CASE_LOWER);

    $webhookIdentifier = $headers['x-atlassian-webhook-identifier'] ?? null;
    $webhookRetry      = isset($headers['x-atlassian-webhook-retry'])
        ? (int)$headers['x-atlassian-webhook-retry']
        : 0;

    if ($rawBody === '') {
        json_response([
            'ok'    => false,
            'error' => 'Body vacío'
        ], 400);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        json_response([
            'ok'    => false,
            'error' => 'JSON inválido'
        ], 400);
    }

    // --------------------------------------------------
    // 3) VALIDAR EVENTO SOPORTADO
    // --------------------------------------------------
    $webhookEvent = (string)($payload['webhookEvent'] ?? '');

    if (!in_array($webhookEvent, ['jira:issue_created', 'jira:issue_updated'], true)) {
        json_response([
            'ok'             => true,
            'ignored'        => true,
            'reason'         => 'Evento no soportado todavía',
            'webhook_event'  => $webhookEvent,
            'webhook_retry'  => $webhookRetry,
            'webhook_id'     => $webhookIdentifier,
        ], 200);
    }

    if (empty($payload['issue']) || !is_array($payload['issue'])) {
        json_response([
            'ok'    => false,
            'error' => 'Payload sin issue'
        ], 400);
    }

    $issuePayload = $payload['issue'];
    $jiraKey = (string)($issuePayload['key'] ?? '');

    if ($jiraKey === '') {
        json_response([
            'ok'    => false,
            'error' => 'Issue sin jira_key'
        ], 400);
    }

    // --------------------------------------------------
    // 4) CONEXIÓN PDO DIRECTA PARA LEER ESTADO ANTES/DESPUÉS
    // --------------------------------------------------
    $pdo = buildPdo();

    // Estado previo actual en issues ANTES del upsert del webhook
    $previousIssue = getIssueRowByKey($pdo, $jiraKey);

    // --------------------------------------------------
    // 5) UPSERT EN issues REUTILIZANDO IssueModel
    // --------------------------------------------------
    // Reutilizamos el modelo actual porque el payload issue de Jira
    // mantiene estructura compatible con fields/status/priority/etc.
    $model = new IssueModel($pdo);
    $model->upsertBatchFromJiraIssues([$issuePayload]);

    // Estado actual DESPUÉS del upsert
    $currentIssue = getIssueRowByKey($pdo, $jiraKey);
    if (!$currentIssue) {
        json_response([
            'ok'    => false,
            'error' => 'No se pudo recuperar el issue tras el upsert'
        ], 500);
    }

    // --------------------------------------------------
    // 6) RESOLVER SOURCE / CORRELATION_ID
    // --------------------------------------------------
    // Por ahora:
    // - si no hay señal explícita -> jira
    // - se deja preparado para más adelante usar correlación App/IA
    $source = resolveSource($payload);
    $correlationId = resolveCorrelationId($payload);

    // --------------------------------------------------
    // 7) CALCULAR EVENTOS A REGISTRAR EN issue_timeline
    // --------------------------------------------------
    $eventTime = resolveEventTime($payload, $currentIssue);
    $eventsToInsert = [];

    if ($webhookEvent === 'jira:issue_created') {
        // Issue nueva: registramos una fila base de creación
        $eventsToInsert[] = 'issue_created';
    }

    if ($webhookEvent === 'jira:issue_updated') {
        $eventsToInsert = array_merge(
            $eventsToInsert,
            detectRelevantChangesFromPayload($payload, $previousIssue, $currentIssue)
        );
    }

    // Si por algún motivo no detectamos nada relevante, no insertamos timeline
    $eventsToInsert = array_values(array_unique($eventsToInsert));

    // --------------------------------------------------
    // 8) INSERTAR UNA FILA POR CADA CAMBIO RELEVANTE
    // --------------------------------------------------
    $timeline = new IssueTimelineService($pdo);
    $insertedEvents = 0;
    $duplicatesIgnored = 0;

    foreach ($eventsToInsert as $eventType) {
        try {
            $timeline->appendEvent(
                $currentIssue,
                $eventTime,
                $eventType,
                $source,
                $webhookIdentifier,
                $correlationId,
                null // webhook => sin snapshot_id
            );
            $insertedEvents++;

        } catch (PDOException $e) {
            // Dedupe por unique(jira_key, webhook_identifier, event_type)
            if ((int)$e->getCode() === 23000) {
                $duplicatesIgnored++;
                continue;
            }
            throw $e;
        }
    }

    // --------------------------------------------------
    // 9) RESPUESTA OK
    // --------------------------------------------------
    json_response([
        'ok'                  => true,
        'jira_key'            => $jiraKey,
        'webhook_event'       => $webhookEvent,
        'event_time'          => $eventTime,
        'source'              => $source,
        'correlation_id'      => $correlationId,
        'webhook_identifier'  => $webhookIdentifier,
        'webhook_retry'       => $webhookRetry,
        'timeline_inserted'   => $insertedEvents,
        'duplicates_ignored'  => $duplicatesIgnored,
        'events'              => $eventsToInsert,
    ], 200);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error procesando webhook Jira'
    ], 500);
}

/* =========================================================
   HELPERS
========================================================= */

/**
 * Construye PDO estándar.
 */
function buildPdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST'),
        env('DB_PORT'),
        env('DB_NAME')
    );

    return new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * Recupera la fila actual de issues por jira_key.
 */
function getIssueRowByKey(PDO $pdo, string $jiraKey): ?array
{
    $sql = "
        SELECT
            jira_key,
            summary,
            status_id,
            status_name,
            estado_categoria,
            priority_id,
            priority_name,
            prioridad_nivel,
            assignee_account_id,
            assignee_display_name,
            updated_at
        FROM issues
        WHERE jira_key = :jira_key
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':jira_key' => $jiraKey]);
    $row = $st->fetch();

    return $row ?: null;
}

/**
 * Intenta resolver la fuente funcional del cambio.
 *
 * De momento:
 * - Si el payload incluye metadata.source y es válida, se usa
 * - Si no, por defecto 'jira'
 *
 * Más adelante aquí conectaremos la correlación App/IA.
 */
function resolveSource(array $payload): string
{
    $source = $payload['metadata']['source'] ?? null;
    $allowed = ['jira', 'app', 'ai', 'system'];

    if (is_string($source) && in_array($source, $allowed, true)) {
        return $source;
    }

    return 'jira';
}

/**
 * Intenta resolver correlation_id si viene embebido en metadata.
 *
 * Más adelante esto se conectará con issue properties o con el
 * flujo IA → App → Jira.
 */
function resolveCorrelationId(array $payload): ?string
{
    $cid = $payload['metadata']['correlation_id'] ?? null;
    return is_string($cid) && $cid !== '' ? $cid : null;
}

/**
 * Resuelve la fecha/hora del evento.
 *
 * Prioridad:
 * 1) timestamp del webhook si existe
 * 2) updated del issue actual en BBDD
 * 3) NOW del servidor
 */
function resolveEventTime(array $payload, array $currentIssue): string
{
    if (!empty($payload['timestamp']) && is_numeric($payload['timestamp'])) {
        return gmdate('Y-m-d H:i:s', (int) floor(((int)$payload['timestamp']) / 1000));
    }

    if (!empty($currentIssue['updated_at'])) {
        return (string)$currentIssue['updated_at'];
    }

    return date('Y-m-d H:i:s');
}

/**
 * Detecta cambios relevantes para el timeline.
 *
 * REGISTRA:
 * - summary_change
 * - status_change
 * - priority_change
 * - assignee_change
 *
 * Estrategia:
 * 1) Si Jira manda changelog.items, lo usamos (preferido)
 * 2) Si no, comparamos previousIssue vs currentIssue
 */
function detectRelevantChangesFromPayload(
    array $payload,
    ?array $previousIssue,
    array $currentIssue
): array {
    $events = [];

    $items = $payload['changelog']['items'] ?? null;
    if (is_array($items) && !empty($items)) {
        foreach ($items as $item) {
            $field = strtolower(trim((string)($item['field'] ?? '')));

            switch ($field) {
                case 'summary':
                    $events[] = 'summary_change';
                    break;

                case 'status':
                    $events[] = 'status_change';
                    break;

                case 'priority':
                    $events[] = 'priority_change';
                    break;

                case 'assignee':
                    $events[] = 'assignee_change';
                    break;
            }
        }

        return $events;
    }

    // Fallback si no hay changelog en el payload
    $prev = $previousIssue ?? [];

    if (($prev['summary'] ?? null) !== ($currentIssue['summary'] ?? null)) {
        $events[] = 'summary_change';
    }

    if (($prev['status_name'] ?? null) !== ($currentIssue['status_name'] ?? null)) {
        $events[] = 'status_change';
    }

    if (($prev['priority_name'] ?? null) !== ($currentIssue['priority_name'] ?? null)
        || (int)($prev['prioridad_nivel'] ?? 0) !== (int)($currentIssue['prioridad_nivel'] ?? 0)
    ) {
        $events[] = 'priority_change';
    }

    if (($prev['assignee_account_id'] ?? null) !== ($currentIssue['assignee_account_id'] ?? null)
        || ($prev['assignee_display_name'] ?? null) !== ($currentIssue['assignee_display_name'] ?? null)
    ) {
        $events[] = 'assignee_change';
    }

    return $events;
}
