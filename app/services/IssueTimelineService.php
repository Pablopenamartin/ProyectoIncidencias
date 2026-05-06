<?php
/**
 * IssueTimelineService.php
 * -------------------------------------------------------
 * Servicio encargado de registrar histórico de incidencias
 * en la tabla issue_timeline.
 *
 * NUEVO MODELO:
 * ✅ Compatible con snapshots legacy
 * ✅ Preparado para eventos webhook
 * ✅ Guarda trazabilidad por tipo de evento
 * ✅ Guarda origen (jira / app / ai / system)
 * ✅ Preparado para webhook_identifier y correlation_id
 *
 * NOTA:
 * - storeSnapshotState() mantiene compatibilidad con snapshots antiguos
 * - storeSnapshotStateIfStatusChanged() mantiene la lógica actual de sync
 * - insertTimelineEvent() deja preparada la base para webhooks futuros
 */

require_once __DIR__ . '/../config/constants.php';

class IssueTimelineService
{
    /**
     * Conexión PDO a la base de datos.
     */
    private PDO $pdo;

    /**
     * Constructor.
     * Permite inyectar PDO (tests) o crear conexión nueva.
     */
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

        $this->pdo = new PDO(
            $dsn,
            env('DB_USER'),
            env('DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * -----------------------------------------------------------------
     * SQL BASE DE INSERCIÓN
     * -----------------------------------------------------------------
     * Unifica todas las inserciones en issue_timeline con el nuevo esquema.
     *
     * IMPORTANTE:
     * - snapshot_id puede ser NULL (eventos webhook / eventos futuros)
     * - webhook_identifier y correlation_id pueden ser NULL
     * - event_type y source son obligatorios
     */
    private function getInsertSql(): string
    {
        return "
            INSERT INTO issue_timeline (
                snapshot_id,
                snapshot_time,
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
                event_type,
                source,
                webhook_identifier,
                correlation_id
            ) VALUES (
                :snapshot_id,
                :snapshot_time,
                :jira_key,
                :summary,
                :status_id,
                :status_name,
                :estado_categoria,
                :priority_id,
                :priority_name,
                :prioridad_nivel,
                :assignee_account_id,
                :assignee_display_name,
                :event_type,
                :source,
                :webhook_identifier,
                :correlation_id
            )
        ";
    }

    /**
     * -----------------------------------------------------------------
     * HELPER INTERNO DE INSERCIÓN
     * -----------------------------------------------------------------
     * Inserta una fila en issue_timeline usando el esquema final.
     *
     * @param PDOStatement $stmt              Statement preparado
     * @param array        $issue             Datos actuales de la incidencia
     * @param int|null     $snapshotId        ID snapshot si existe (legacy/sync)
     * @param string       $snapshotTime      Fecha/hora del evento
     * @param string       $eventType         Tipo de evento (status_change, etc.)
     * @param string       $source            Origen (jira/app/ai/system)
     * @param string|null  $webhookIdentifier Identificador de webhook Jira
     * @param string|null  $correlationId     Correlación app/IA/Jira
     * @return void
     */
    private function insertTimelineEvent(
        PDOStatement $stmt,
        array $issue,
        ?int $snapshotId,
        string $snapshotTime,
        string $eventType,
        string $source,
        ?string $webhookIdentifier = null,
        ?string $correlationId = null
    ): void {
        $stmt->bindValue(':snapshot_id', $snapshotId, $snapshotId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_time', $snapshotTime);

        $stmt->bindValue(':jira_key', $issue['jira_key'] ?? '');
        $stmt->bindValue(':summary', $issue['summary'] ?? '');

        $stmt->bindValue(':status_id', $issue['status_id'] ?? null);
        $stmt->bindValue(':status_name', $issue['status_name'] ?? '');

        $stmt->bindValue(':estado_categoria', $issue['estado_categoria'] ?? 'other');

        $stmt->bindValue(':priority_id', $issue['priority_id'] ?? null);
        $stmt->bindValue(':priority_name', $issue['priority_name'] ?? null);

        $stmt->bindValue(
            ':prioridad_nivel',
            isset($issue['prioridad_nivel']) ? (int)$issue['prioridad_nivel'] : null,
            isset($issue['prioridad_nivel']) ? PDO::PARAM_INT : PDO::PARAM_NULL
        );

        $stmt->bindValue(':assignee_account_id', $issue['assignee_account_id'] ?? null);
        $stmt->bindValue(':assignee_display_name', $issue['assignee_display_name'] ?? null);

        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':source', $source);

        $stmt->bindValue(':webhook_identifier', $webhookIdentifier);
        $stmt->bindValue(':correlation_id', $correlationId);

        $stmt->execute();
    }

    /**
     * -----------------------------------------------------------------
     * SNAPSHOT LEGACY COMPLETO
     * -----------------------------------------------------------------
     * Mantiene compatibilidad con el modelo histórico antiguo.
     *
     * Usa source = system
     * Usa event_type = snapshot_sync
     *
     * @param int    $snapshotId   ID del snapshot recién creado
     * @param string $snapshotTime Fecha/hora exacta del snapshot
     * @return int   Número de filas insertadas
     */
    public function storeSnapshotState(int $snapshotId, string $snapshotTime): int
    {
        /**
         * 1️⃣ Obtener estado actual de las incidencias visibles
         *
         * Mantenemos visible = 1 por compatibilidad con el comportamiento
         * histórico del dashboard/index actual.
         */
        $sqlIssues = "
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
                assignee_display_name
            FROM issues
            WHERE visible = 1
        ";

        $issues = $this->pdo->query($sqlIssues)->fetchAll();
        if (!$issues) {
            return 0;
        }

        $stmt = $this->pdo->prepare($this->getInsertSql());
        $inserted = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($issues as $issue) {
                $this->insertTimelineEvent(
                    $stmt,
                    $issue,
                    $snapshotId,
                    $snapshotTime,
                    'snapshot_sync',
                    'system'
                );
                $inserted++;
            }

            $this->pdo->commit();
            return $inserted;

        } catch (Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    /**
     * -----------------------------------------------------------------
     * DETECCIÓN DE CAMBIO REAL DE ESTADO EN LA SYNC
     * -----------------------------------------------------------------
     * Registra SOLO si el estado Jira ha cambiado en ESTA sync.
     *
     * IMPORTANTE:
     * - Usa prevStates capturado ANTES del upsert en SyncService
     * - No usa issue_timeline como referencia de estado previo
     * - No genera ruido si el estado no cambia
     *
     * @param string $snapshotTime Fecha/hora del evento/sync
     * @param array  $prevStates   Mapa jira_key => status_name ANTES de la sync
     * @return int   Número de transiciones reales detectadas
     */
    public function storeSnapshotStateIfStatusChanged(
        string $snapshotTime,
        array $prevStates
    ): int {
        /**
         * 1️⃣ Estado actual DESPUÉS del upsert
         *
         * Mantenemos visible = 1 para respetar el comportamiento actual:
         * - cierre reciente todavía visible en primera sync
         * - cierre antiguo ya no visible
         *
         * Más adelante, en el modo webhook, esto se podrá desacoplar.
         */
        $sqlIssues = "
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
                assignee_display_name
            FROM issues
            WHERE visible = 1
        ";

        $issues = $this->pdo->query($sqlIssues)->fetchAll();
        if (!$issues) {
            return 0;
        }

        $stmt = $this->pdo->prepare($this->getInsertSql());
        $inserted = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($issues as $issue) {

                $jiraKey   = $issue['jira_key'];
                $newStatus = $issue['status_name'] ?? null;

                /**
                 * Estado ANTES de la sync
                 */
                $prevStatus = $prevStates[$jiraKey] ?? null;

                /**
                 * ✅ Insertar SOLO si el estado cambió en esta sync
                 */
                if ($prevStatus !== $newStatus) {
                    $this->insertTimelineEvent(
                        $stmt,
                        $issue,
                        null,                  // snapshot_id nullable en el modelo nuevo
                        $snapshotTime,
                        'status_change',
                        'system'
                    );
                    $inserted++;
                }
            }

            $this->pdo->commit();
            return $inserted;

        } catch (Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    /**
     * -----------------------------------------------------------------
     * storeDeletedIssuesAsClosed
     * -----------------------------------------------------------------
     * Registra en issue_timeline las incidencias que han desaparecido de Jira
     * y que el sistema ha marcado como cerradas/completadas en la tabla issues.
     *
     * QUÉ HACE:
     * - recibe las filas visibles capturadas ANTES de la sync
     * - crea un evento timeline por cada incidencia borrada en Jira
     * - fuerza el estado final a:
     *   - status_name = Completado
     *   - estado_categoria = cerrado_unificado
     * - usa:
     *   - event_type = status_change
     *   - source = system
     *
     * IMPORTANTE:
     * - snapshot_id se guarda como NULL porque este evento no depende
     *   del snapshot agregado, sino de una reconciliación de borrado
     * - el método no consulta la tabla issues, trabaja con la foto previa
     *   recibida desde SyncService
     *
     * @param string $snapshotTime Fecha/hora de la sync
     * @param array  $deletedIssues Filas previas de incidencias borradas en Jira
     * @return int Número de eventos insertados
     */
    public function storeDeletedIssuesAsClosed(
        string $snapshotTime,
        array $deletedIssues
    ): int {
        if (empty($deletedIssues)) {
            return 0;
        }

        $stmt = $this->pdo->prepare($this->getInsertSql());
        $inserted = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($deletedIssues as $issue) {
                /**
                 * Construimos una copia del issue previo y forzamos
                 * el estado final que queremos reflejar en timeline.
                 */
                $timelineIssue = $issue;

                $timelineIssue['status_name'] = 'Completado';
                $timelineIssue['estado_categoria'] = 'cerrado_unificado';

                $this->insertTimelineEvent(
                    $stmt,
                    $timelineIssue,
                    null,              // snapshot_id nullable en el modelo nuevo
                    $snapshotTime,
                    'status_change',   // mismo tipo de evento que un cierre/cambio de estado
                    'system'           // lo hace el sistema por reconciliación
                );

                $inserted++;
            }

            $this->pdo->commit();
            return $inserted;

        } catch (Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }


    /**
     * -----------------------------------------------------------------
     * PREPARADO PARA WEBHOOKS FUTUROS
     * -----------------------------------------------------------------
     * Inserta un evento puntual ya enriquecido por otro servicio.
     *
     * Este método NO se usa todavía, pero deja preparada la clase
     * para el siguiente paso:
     * - Jira webhook
     * - source = jira / app / ai
     * - webhook_identifier
     * - correlation_id
     *
     * @param array       $issue              Datos del issue ya normalizados
     * @param string      $snapshotTime       Fecha/hora del evento
     * @param string      $eventType          Tipo de evento
     * @param string      $source             Fuente del cambio
     * @param string|null $webhookIdentifier  Identificador único del webhook
     * @param string|null $correlationId      Correlación app/IA/Jira
     * @param int|null    $snapshotId         Solo si el evento nace de snapshot
     * @return void
     */
    public function appendEvent(
        array $issue,
        string $snapshotTime,
        string $eventType,
        string $source,
        ?string $webhookIdentifier = null,
        ?string $correlationId = null,
        ?int $snapshotId = null
    ): void {
        $stmt = $this->pdo->prepare($this->getInsertSql());

        $this->insertTimelineEvent(
            $stmt,
            $issue,
            $snapshotId,
            $snapshotTime,
            $eventType,
            $source,
            $webhookIdentifier,
            $correlationId
        );
    }
}
