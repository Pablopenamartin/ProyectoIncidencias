<?php
/**
 * app/services/JiraIssueMutationService.php
 * -------------------------------------------------------
 * Servicio encargado de modificar incidencias en Jira Cloud
 * desde la app y reflejar el cambio inmediatamente en la BBDD local.
 *
 * Capacidades:
 * ✅ Editar summary
 * ✅ Editar prioridad
 * ✅ Editar asignado
 * ✅ Obtener transiciones disponibles
 * ✅ Cambiar estado mediante transición Jira
 * ✅ Actualizar issues localmente tras OK en Jira
 * ✅ Insertar eventos en issue_timeline con source = app / ai
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/jira.php';
require_once __DIR__ . '/../models/IssueModel.php';
require_once __DIR__ . '/IssueTimelineService.php';

class JiraIssueMutationService
{
    private PDO $pdo;
    private IssueModel $issueModel;
    private IssueTimelineService $timelineService;

    /**
     * Caché local de prioridades Jira devueltas por la API.
     *
     * @var array<int, array<string,mixed>>|null
     */
    private ?array $jiraPrioritiesCache = null;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('DB_HOST'),
                env('DB_PORT'),
                env('DB_NAME')
            );

            $this->pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        $this->issueModel      = new IssueModel($this->pdo);
        $this->timelineService = new IssueTimelineService($this->pdo);
    }

    /* ============================================================
     * API PÚBLICA
     * ============================================================ */

    /**
     * Devuelve los datos necesarios para poblar el modal de edición.
     */
    public function getEditContext(string $jiraKey): array
    {
        $issue = $this->getLocalIssueByKey($jiraKey);
        if (!$issue) {
            throw new RuntimeException('La incidencia no existe en la BBDD local.');
        }

        return [
            'issue' => [
                'jira_key'              => $issue['jira_key'],
                'summary'               => $issue['summary'],
                'status_name'           => $issue['status_name'],
                'priority_id'           => $issue['priority_id'],
                'priority_name'         => $issue['priority_name'],
                'prioridad_nivel'       => (int)($issue['prioridad_nivel'] ?? 0),
                'assignee_account_id'   => $issue['assignee_account_id'],
                'assignee_display_name' => $issue['assignee_display_name'],
                'jira_url'              => $this->buildJiraBrowseUrl($jiraKey),
            ],
            'priority_options' => $this->getUiPriorityOptions(),
            'assignee_options' => $this->getLocalAssigneeOptions(),
            'status_options'   => $this->getAvailableTransitions($jiraKey),
        ];
    }

    /**
     * Aplica una edición manual desde la app.
     *
     * @param string      $jiraKey        Clave Jira de la incidencia
     * @param array       $changes        Cambios solicitados por el modal
     * @param string      $source         Origen funcional (app / ai)
     * @param string|null $correlationId  Correlación opcional
     * @return array                     Datos actualizados para refrescar la UI
     */
    public function applyManualEdit(
        string $jiraKey,
        array $changes,
        string $source = 'app',
        ?string $correlationId = null
    ): array {
        $before = $this->getLocalIssueByKey($jiraKey);
        if (!$before) {
            throw new RuntimeException('La incidencia no existe en la BBDD local.');
        }

        // --------------------------------------------------------
        // 1) Construir payload de edición de campos
        // --------------------------------------------------------
        $jiraFields = [];

        if (array_key_exists('summary', $changes)) {
            $summary = trim((string)$changes['summary']);
            if ($summary === '') {
                throw new RuntimeException('El título no puede estar vacío.');
            }
            if ($summary !== (string)$before['summary']) {
                $jiraFields['summary'] = $summary;
            }
        }

        if (array_key_exists('priority_level', $changes) && $changes['priority_level'] !== null && $changes['priority_level'] !== '') {
            $priorityLevel = (int)$changes['priority_level'];
            $jiraPriorityId = $this->resolveJiraPriorityIdFromLevel($priorityLevel);

            if ($jiraPriorityId !== null && (string)$jiraPriorityId !== (string)($before['priority_id'] ?? '')) {
                $jiraFields['priority'] = ['id' => (string)$jiraPriorityId];
            }
        }

        if (array_key_exists('assignee_account_id', $changes)) {
            $assigneeAccountId = $changes['assignee_account_id'];

            if ($assigneeAccountId === '' || $assigneeAccountId === null) {
                if (!empty($before['assignee_account_id'])) {
                    $jiraFields['assignee'] = null;
                }
            } else {
                $assigneeAccountId = (string)$assigneeAccountId;
                if ($assigneeAccountId !== (string)($before['assignee_account_id'] ?? '')) {
                    $jiraFields['assignee'] = ['accountId' => $assigneeAccountId];
                }
            }
        }

        // --------------------------------------------------------
        // 2) Aplicar edición de campos si hay cambios
        // --------------------------------------------------------
        if (!empty($jiraFields)) {
            $this->request('PUT', '/issue/' . rawurlencode($jiraKey), [
                'fields' => $jiraFields,
            ]);
        }

        // --------------------------------------------------------
        // 3) Aplicar transición de estado si procede
        // --------------------------------------------------------
        $transitionId = $changes['transition_id'] ?? null;
        if (is_string($transitionId) && trim($transitionId) !== '') {
            $this->request('POST', '/issue/' . rawurlencode($jiraKey) . '/transitions', [
                'transition' => ['id' => trim($transitionId)],
            ]);
        }

        // --------------------------------------------------------
        // 4) Traer issue fresca desde Jira y reflejar localmente
        // --------------------------------------------------------
        $jiraIssuePayload = $this->fetchIssueForSync($jiraKey);
        $this->issueModel->upsertBatchFromJiraIssues([$jiraIssuePayload]);

        $after = $this->getLocalIssueByKey($jiraKey);
        if (!$after) {
            throw new RuntimeException('No se pudo refrescar la incidencia tras modificar Jira.');
        }

        // --------------------------------------------------------
        // 5) Insertar histórico por cada cambio real
        // --------------------------------------------------------
        $eventTime = date('Y-m-d H:i:s');

        if (($before['summary'] ?? null) !== ($after['summary'] ?? null)) {
            $this->timelineService->appendEvent($after, $eventTime, 'summary_change', $source, null, $correlationId);
        }

        if (($before['priority_name'] ?? null) !== ($after['priority_name'] ?? null)
            || (int)($before['prioridad_nivel'] ?? 0) !== (int)($after['prioridad_nivel'] ?? 0)
        ) {
            $this->timelineService->appendEvent($after, $eventTime, 'priority_change', $source, null, $correlationId);
        }

        if (($before['assignee_account_id'] ?? null) !== ($after['assignee_account_id'] ?? null)
            || ($before['assignee_display_name'] ?? null) !== ($after['assignee_display_name'] ?? null)
        ) {
            $this->timelineService->appendEvent($after, $eventTime, 'assignee_change', $source, null, $correlationId);
        }

        if (($before['status_name'] ?? null) !== ($after['status_name'] ?? null)) {
            $this->timelineService->appendEvent($after, $eventTime, 'status_change', $source, null, $correlationId);
        }

        return [
            'row'      => $this->buildUiRow($after),
            'detail'   => $this->buildDetailPayload($after),
            'jira_url' => $this->buildJiraBrowseUrl($jiraKey),
        ];
    }

    /**
     * Método preparado para la IA.
     *
     * De momento la IA solo cambia prioridad.
     */
    public function applyAiPriorityDecision(string $jiraKey, int $priorityLevel, ?string $correlationId = null): array
    {
        return $this->applyManualEdit($jiraKey, [
            'priority_level' => $priorityLevel,
        ], 'ai', $correlationId);
    }

    /**
     * Devuelve las transiciones disponibles para una incidencia.
     * Se devuelve id + nombre del estado destino para mostrar en UI.
     */
    public function getAvailableTransitions(string $jiraKey): array
    {
        $response = $this->request('GET', '/issue/' . rawurlencode($jiraKey) . '/transitions');
        $items = $response['transitions'] ?? [];

        $out = [];
        foreach ($items as $t) {
            $out[] = [
                'id'          => (string)($t['id'] ?? ''),
                'name'        => (string)($t['name'] ?? ''),
                'status_name' => (string)($t['to']['name'] ?? ''),
            ];
        }

        return $out;
    }

    /* ============================================================
     * HELPERS DE BBDD LOCAL
     * ============================================================ */

    private function getLocalIssueByKey(string $jiraKey): ?array
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
                created_at,
                updated_at
            FROM issues
            WHERE jira_key = :jira_key
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':jira_key' => $jiraKey]);
        $row = $st->fetch();

        return $row ?: null;
    }

    /**
     * Opciones de asignado basadas en incidencias ya existentes en BBDD local.
     */
    private function getLocalAssigneeOptions(): array
    {
        $sql = "
            SELECT DISTINCT
                assignee_account_id,
                assignee_display_name
            FROM issues
            WHERE assignee_account_id IS NOT NULL
              AND assignee_display_name IS NOT NULL
              AND assignee_account_id <> ''
            ORDER BY assignee_display_name ASC
        ";

        $rows = $this->pdo->query($sql)->fetchAll();
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'account_id'   => $row['assignee_account_id'],
                'display_name' => $row['assignee_display_name'],
            ];
        }

        return $out;
    }

    /**
     * Opciones de prioridad visibles en el modal.
     */
    private function getUiPriorityOptions(): array
    {
        return [
            ['level' => 1, 'label' => 'P1 – Critical', 'jira_name' => 'Highest'],
            ['level' => 2, 'label' => 'P2 – High',     'jira_name' => 'High'],
            ['level' => 3, 'label' => 'P3 – Medium',   'jira_name' => 'Medium'],
            ['level' => 4, 'label' => 'P4 – Low',      'jira_name' => 'Low'],
            ['level' => 5, 'label' => 'P5 – Lowest',   'jira_name' => 'Lowest'],
        ];
    }

    /**
     * Construye la fila que necesita index.php para refrescar solo esa incidencia.
     */
    private function buildUiRow(array $issue): array
    {
        return [
            'jira_key'              => $issue['jira_key'],
            'summary'               => $issue['summary'],
            'status'                => $issue['status_name'],
            'priority'              => $issue['priority_name'],
            'assigned'              => !empty($issue['assignee_account_id']),
            'assigned_display_name' => $issue['assignee_display_name'] ?: 'Pendiente',
            'created_at'            => $issue['created_at'],
            'jira_url'              => $this->buildJiraBrowseUrl($issue['jira_key']),
        ];
    }

    /**
     * Construye la carga útil para el detalle de timeline_page.
     */
    private function buildDetailPayload(array $issue): array
    {
        return [
            'key'        => $issue['jira_key'],
            'summary'    => $issue['summary'],
            'status'     => $issue['status_name'],
            'priority'   => $issue['priority_name'],
            'assignee'   => $issue['assignee_display_name'] ?: '—',
            'description'=> null,
            'url'        => $this->buildJiraBrowseUrl($issue['jira_key']),
        ];
    }

    private function buildJiraBrowseUrl(string $jiraKey): string
    {
        return rtrim(jira_site(), '/') . '/browse/' . rawurlencode($jiraKey);
    }

    /* ============================================================
     * HELPERS JIRA CLOUD
     * ============================================================ */

    /**
     * Recupera la incidencia en formato compatible con IssueModel::upsertBatchFromJiraIssues().
     */
    private function fetchIssueForSync(string $jiraKey): array
    {
        return $this->request('GET', '/issue/' . rawurlencode($jiraKey), null, [
            'fields' => implode(',', [
                'id',
                'key',
                'summary',
                'status',
                'assignee',
                'updated',
                'created',
                'project',
                'priority',
                'customfield_10041',
                'customfield_10004',
            ]),
        ]);
    }

    /**
     * Resuelve el priority_id real de Jira a partir del nivel interno 1..5.
     */
    private function resolveJiraPriorityIdFromLevel(int $priorityLevel): ?string
    {
        $st = $this->pdo->prepare('SELECT jira_priority_name FROM priority_map WHERE prioridad_nivel = :lvl LIMIT 1');
        $st->execute([':lvl' => $priorityLevel]);
        $jiraPriorityName = $st->fetchColumn();

        if ($jiraPriorityName === false) {
            throw new RuntimeException('No existe mapeo local de prioridad para el nivel indicado.');
        }

        $jiraPriorityName = (string)$jiraPriorityName;
        foreach ($this->getJiraPriorities() as $priority) {
            if ((string)($priority['name'] ?? '') === $jiraPriorityName) {
                return (string)($priority['id'] ?? '');
            }
        }

        throw new RuntimeException('No se pudo resolver el priority_id real en Jira para la prioridad seleccionada.');
    }

    private function getJiraPriorities(): array
    {
        if ($this->jiraPrioritiesCache !== null) {
            return $this->jiraPrioritiesCache;
        }

        $response = $this->request('GET', '/priority');
        $this->jiraPrioritiesCache = is_array($response) ? $response : [];
        return $this->jiraPrioritiesCache;
    }

    /**
     * Wrapper de llamada HTTP a Jira Cloud.
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = jira_endpoint($path);
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = jira_headers();
        $headers[] = 'Content-Type: application/json';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch);


        if ($errno !== 0) {
            throw new RuntimeException('Error de conexión a Jira: ' . $error);
        }

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Jira respondió con error HTTP ' . $http . ': ' . (string)$raw);
        }

        if ($raw === '' || $raw === false) {
            return [];
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
