<?php

class IssueModel
{
    private PDO $pdo;
    private array $statusLevelCache = [];

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
            return;
        }

        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            env('DB_HOST'),
            env('DB_PORT'),
            env('DB_NAME')
        );

        $this->pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /* ============================================================
     * SYNC METADATA
     * ============================================================
     */

    public function getLastSyncTime(): ?string
    {
        $sql = "SELECT value FROM sync_metadata WHERE name='issues_last_sync' LIMIT 1";
        $v = $this->pdo->query($sql)->fetchColumn();
        return $v !== false ? $v : null;
    }

    public function setLastSyncTime(string $now): void
    {
        $sql = "
            INSERT INTO sync_metadata (name,value,updated_at)
            VALUES ('issues_last_sync', :v, NOW())
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':v', $now);
        $st->execute();
    }
        /**
     * Devuelve un mapa jira_key => status_name
     * representando el estado ANTES de la sync.
     *
     * Se usa para detectar transiciones reales
     * en la misma ejecución de SyncService.
     */
    public function getCurrentIssueStates(): array
    {
        $sql = "
            SELECT jira_key, status_name
            FROM issues
        ";

        $rows = $this->pdo->query($sql)->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['jira_key']] = $row['status_name'];
        }

        return $map;
    }


    /* ============================================================
     * MAPEOS DE ESTADO
     * ============================================================
     */

    /**
     * Mapea el estado de Jira a la categoría de negocio
     * usada por snapshots, dashboard e index
     */
    private function mapEstadoCategoria(string $statusName): string
    {
        $s = strtolower(trim($statusName));

        return match ($s) {
            'open', 'abierta' => 'esperando_ayuda',
            'escalated' => 'escalated',
            'work in progress', 'in progress', 'en curso', 'investigar' => 'en_curso',
            'pending', 'pendiente' => 'pending',
            'waiting for approval' => 'waiting_approval',
            'waiting for customer', 'esperando por el cliente' => 'waiting_customer',

            // ✅ TODOS LOS ESTADOS DE CIERRE
            'done', 'hecho', 'completed', 'completado',
            'cancelled', 'canceled', 'cancelado',
            'resolved', 'resuelto', 'closed' => 'cerrado_unificado',

            default => 'other',
        };
    }

    /**
     * Nivel numérico del estado (desde status_map)
     */
    private function resolveStatusLevel(?string $name): int
    {
        if (!$name) return 3;

        $key = strtolower(trim($name));

        if (isset($this->statusLevelCache[$key])) {
            return $this->statusLevelCache[$key];
        }

        $st = $this->pdo->prepare(
            "SELECT estado_nivel FROM status_map WHERE jira_status_name = :n LIMIT 1"
        );
        $st->bindValue(':n', $name);
        $st->execute();

        $lvl = $st->fetchColumn();
        $lvl = $lvl !== false ? (int)$lvl : 3;

        $this->statusLevelCache[$key] = $lvl;
        return $lvl;
    }

    /* ============================================================
     * UPSERT PRINCIPAL
     * ============================================================
     */

    public function upsertBatchFromJiraIssues(array $issues): int
    {
        if (empty($issues)) return 0;

        $sql = "
            INSERT INTO issues (
                jira_id, jira_key, summary,
                project_id, project_key, project_name,
                status_id, status_name, estado_categoria, estado_nivel,
                priority_id, priority_name, prioridad_nivel,
                assignee_account_id, assignee_display_name,
                urgency_name, impact_name,
                updated_at, created_at, last_synced_at, visible
            ) VALUES (
                :jira_id, :jira_key, :summary,
                :project_id, :project_key, :project_name,
                :status_id, :status_name, :estado_categoria, :estado_nivel,
                :priority_id, :priority_name, :prioridad_nivel,
                :assignee_account_id, :assignee_display_name,
                :urgency_name, :impact_name,
                :updated_at, :created_at, NOW(), :visible
            )
            ON DUPLICATE KEY UPDATE
                summary               = VALUES(summary),
                project_id            = VALUES(project_id),
                project_key           = VALUES(project_key),
                project_name          = VALUES(project_name),
                status_id             = VALUES(status_id),
                status_name           = VALUES(status_name),
                estado_categoria      = VALUES(estado_categoria),
                estado_nivel          = VALUES(estado_nivel),
                priority_id           = VALUES(priority_id),
                priority_name         = VALUES(priority_name),
                prioridad_nivel       = VALUES(prioridad_nivel),
                assignee_account_id   = VALUES(assignee_account_id),
                assignee_display_name = VALUES(assignee_display_name),
                urgency_name          = VALUES(urgency_name),
                impact_name           = VALUES(impact_name),
                updated_at            = VALUES(updated_at),
                last_synced_at        = NOW(),
                visible               = VALUES(visible)
        ";

        $st = $this->pdo->prepare($sql);
        $affected = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($issues as $issue) {

                $jiraId  = (string)($issue['id'] ?? '');
                $jiraKey = (string)($issue['key'] ?? '');
                $fields  = (array)($issue['fields'] ?? []);

                $summary = $this->truncate($fields['summary'] ?? '', 1000);

                $project = (array)($fields['project'] ?? []);
                $projectId   = $project['id']   ?? null;
                $projectKey  = $project['key']  ?? null;
                $projectName = $project['name'] ?? null;

                $status = (array)($fields['status'] ?? []);
                $statusId   = $status['id']   ?? null;
                $statusName = $status['name'] ?? '';

                $estadoCategoria = $this->mapEstadoCategoria($statusName);
                $estadoNivel     = $this->resolveStatusLevel($statusName);

                $priority = (array)($fields['priority'] ?? []);
                $priorityId     = (int)($priority['id'] ?? 0);
                $priorityName   = (string)($priority['name'] ?? '');
                $prioridadNivel = $priorityId;

                $assignee = (array)($fields['assignee'] ?? []);
                $assigneeAccountId   = $assignee['accountId'] ?? null;
                $assigneeDisplayName = $assignee['displayName'] ?? null;

                $urgency = $this->safeValue($fields['customfield_10041']['value'] ?? null);
                $impact  = $this->safeValue($fields['customfield_10004']['value'] ?? null);

                $updatedAt = $this->jiraToMysqlDatetime($fields['updated'] ?? null);
                $createdAt = $this->jiraToMysqlDatetime($fields['created'] ?? null);

                /* ====================================================
                 * REGLA DE VISIBILIDAD (CLAVE DEL COMPORTAMIENTO)
                 * ====================================================
                 */

                $visible = 1;

                if ($estadoCategoria === 'cerrado_unificado') {
                    $chk = $this->pdo->prepare("
                        SELECT estado_categoria, visible
                        FROM issues
                        WHERE jira_key = :k
                        LIMIT 1
                    ");
                    $chk->bindValue(':k', $jiraKey);
                    $chk->execute();
                    $prev = $chk->fetch();

                    // ✅ Si ya estaba cerrada en la sync anterior → ocultar
                    if ($prev && $prev['estado_categoria'] === 'cerrado_unificado') {
                        $visible = 0;
                    }
                }

                /* ====================================================
                 * BIND
                 * ====================================================
                 */

                $st->bindValue(':jira_id',  $jiraId);
                $st->bindValue(':jira_key', $jiraKey);
                $st->bindValue(':summary',  $summary);

                $st->bindValue(':project_id',   $projectId);
                $st->bindValue(':project_key',  $projectKey);
                $st->bindValue(':project_name', $projectName);

                $st->bindValue(':status_id',        $statusId);
                $st->bindValue(':status_name',      $statusName);
                $st->bindValue(':estado_categoria', $estadoCategoria);
                $st->bindValue(':estado_nivel',     $estadoNivel);

                $st->bindValue(':priority_id',     $priorityId);
                $st->bindValue(':priority_name',   $priorityName);
                $st->bindValue(':prioridad_nivel', $prioridadNivel);

                $st->bindValue(':assignee_account_id',   $assigneeAccountId);
                $st->bindValue(':assignee_display_name', $assigneeDisplayName);

                $st->bindValue(':urgency_name', $urgency);
                $st->bindValue(':impact_name',  $impact);

                $st->bindValue(':updated_at', $updatedAt);
                $st->bindValue(':created_at', $createdAt);
                $st->bindValue(':visible',    $visible, PDO::PARAM_INT);

                $st->execute();
                $affected += $st->rowCount();
            }

            $this->pdo->commit();

        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }

        return $affected;
    }

    /* ============================================================
     * HELPERS
     * ============================================================
     */

    private function jiraToMysqlDatetime(?string $ts): ?string
    {
        if (!$ts) return null;
        try {
            $dt = new DateTime($ts);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max);
    }

    private function safeValue($v): ?string
    {
        return is_scalar($v) ? (string)$v : null;
    }
}