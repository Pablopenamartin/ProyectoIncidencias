<?php
/**
 * app/services/SyncService.php
 * -------------------------------------------------------
 * Servicio de sincronización Jira → BBDD
 *
 * FUNCIONES PRINCIPALES:
 * ✅ JQL incremental con ventana de seguridad (-2 min)
 * ✅ Full Sync opcional
 * ✅ Paginación Jira → UPSERT en tabla issues
 * ✅ Actualiza issues_last_sync en sync_metadata
 * ✅ Genera snapshot agregado en snapshots
 * ✅ Registra cambios reales de estado en issue_timeline
 * ✅ Detecta incidencias borradas forzosamente de Jira
 * ✅ Genera automáticamente informes de cierre cuando una incidencia pasa a cerrada
 *
 * IMPORTANTE:
 * - Se capturan los estados previos ANTES del UPSERT
 * - issue_timeline solo registra cambios reales en esta sync
 * - La sync manual se mantiene como reconciliación
 * - Los informes de cierre se generan solo cuando se detecta un cierre nuevo
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/jira.php';
require_once __DIR__ . '/../helpers/Utils.php';
require_once __DIR__ . '/../models/IssueModel.php';
require_once __DIR__ . '/JiraService.php';
require_once __DIR__ . '/SnapshotService.php';
require_once __DIR__ . '/IssueTimelineService.php';
require_once __DIR__ . '/ClosureReportService.php';

class SyncService
{
    /**
     * Ejecuta la sincronización Jira → BD.
     *
     * @param bool $full Si es true, ignora incremental y hace FULL SYNC
     * @return array
     */
    public function runSync(bool $full = false): array
    {
        $model = new IssueModel();

        //------------------------------------------------------
        // 1) Obtener última marca de sincronización
        //------------------------------------------------------
        $lastSync = $model->getLastSyncTime();

        // FULL SYNC ignora incremental
        if ($full === true) {
            $lastSync = null;
        }

        //------------------------------------------------------
        // 2) Construcción del JQL incremental seguro
        //------------------------------------------------------
        $jql = $this->buildIncrementalJql($lastSync);

        //------------------------------------------------------
        // 3) Campos que queremos traer de Jira
        //------------------------------------------------------
        $fields = [
            'id',
            'key',
            'summary',
            'status',
            'assignee',
            'updated',
            'created',
            'project',
            'priority',
            'customfield_10041', // Urgency
            'customfield_10004', // Impact
        ];

        //------------------------------------------------------
        // 4) Capturar estados previos ANTES del UPSERT
        //------------------------------------------------------
        // Esto es clave para detectar cambios reales de estado
        // en ESTA sync, y no comparar contra issues ya actualizada.
        //------------------------------------------------------
        $prevStates = $model->getCurrentIssueStates();

        //------------------------------------------------------
        // 4.b) Capturar filas visibles completas ANTES del UPSERT
        //------------------------------------------------------
        // Esto sirve para detectar incidencias que desaparecen
        // de Jira y luego registrar el evento en issue_timeline.
        //------------------------------------------------------
        $prevVisibleRows = $this->getCurrentVisibleIssueRows();

        //------------------------------------------------------
        // 5) Ejecutar paginación Jira → UPSERT en tabla issues
        //------------------------------------------------------
        $jira = new JiraService();

        $totalFetched  = 0;
        $totalInserted = 0;

        $onChunk = function(array $chunk, int $startAt, int $fetched)
            use ($model, &$totalFetched, &$totalInserted)
        {
            // UPSERT batch en tabla issues
            $inserted = $model->upsertBatchFromJiraIssues($chunk);

            $totalFetched  += count($chunk);
            $totalInserted += $inserted;
        };

        // Ejecutar paginación completa
        $jira->paginateAll($jql, 100, $fields, $onChunk);

        //------------------------------------------------------
        // 6) Guardar marca de sync
        //------------------------------------------------------
        $now = date('Y-m-d H:i:s');
        $model->setLastSyncTime($now);

        //------------------------------------------------------
        // 6.b) Reconciliación de borrados forzados en Jira
        //------------------------------------------------------
        // Obtenemos la lista REAL actual de claves en Jira y
        // comparamos contra lo que estaba visible en local antes
        // de la sync. Si una visible local ya no existe en Jira,
        // la marcamos como cerrada/completada en issues.
        //------------------------------------------------------
        $jiraCurrentKeys = $this->fetchAllCurrentJiraKeys();

        $deletedIssues = [];

        foreach ($prevVisibleRows as $jiraKey => $issueRow) {
            if (!isset($jiraCurrentKeys[$jiraKey])) {
                $deletedIssues[$jiraKey] = $issueRow;
            }
        }

        $deletedClosed = $this->markIssuesDeletedInJiraAsClosed(array_keys($deletedIssues));

        //------------------------------------------------------
        // 7) Crear snapshot agregado (tabla snapshots)
        //------------------------------------------------------
        $snap = new SnapshotService();
        $snapshotId = $snap->createSnapshot();

        //------------------------------------------------------
        // 8) Registrar transiciones reales de estado
        //------------------------------------------------------
        // Usamos:
        // - $now como snapshot_time / momento de la sync
        // - $prevStates capturado ANTES del upsert
        //
        // snapshot_id NO se usa aquí porque el método nuevo trabaja
        // por eventos reales, no por snapshot completo.
        //------------------------------------------------------
        $timeline = new IssueTimelineService();
        $timelineInserted = $timeline->storeSnapshotStateIfStatusChanged(
            $now,
            $prevStates
        );

        //------------------------------------------------------
        // 8.b) Registrar en timeline las incidencias borradas
        //------------------------------------------------------
        // Estas incidencias se marcaron como Completado porque
        // estaban visibles en local pero ya no existen en Jira.
        //------------------------------------------------------
        $timelineDeletedInserted = 0;

        if (!empty($deletedIssues)) {
            $timelineDeletedInserted = $timeline->storeDeletedIssuesAsClosed(
                $now,
                array_values($deletedIssues)
            );
        }

        //------------------------------------------------------
        // 8.c) Generar informes de cierre automáticos
        //------------------------------------------------------
        // REGLA:
        // - Solo se generan para incidencias que en ESTA sync
        //   han pasado de no cerradas a cerradas.
        // - También cubre cierres por borrado forzado en Jira.
        // - Si OpenAI falla, NO rompemos la sincronización.
        //------------------------------------------------------
        $closureReportStats = $this->generateClosureReportsForNewlyClosedIssues($prevStates);

        //------------------------------------------------------
        // 9) Respuesta final
        //------------------------------------------------------
        return [
            'ok'                        => true,
            'jql'                       => $jql,
            'fetched'                   => $totalFetched,
            'inserted'                  => $totalInserted,
            'deleted_closed'            => $deletedClosed,
            'snapshot_id'               => $snapshotId,
            'timeline_inserted'         => $timelineInserted,
            'timeline_deleted'          => $timelineDeletedInserted,
            'closure_reports_generated' => $closureReportStats['generated'],
            'closure_reports_failed'    => $closureReportStats['failed'],
            'last_sync_time'            => $now
        ];
    }

    /**
     * getCurrentVisibleIssueRows()
     * -------------------------------------------------------
     * Devuelve el estado actual completo de las incidencias visibles
     * ANTES de la sync.
     *
     * QUÉ HACE:
     * - Lee de la tabla issues solo las visibles
     * - Devuelve un mapa indexado por jira_key
     * - Sirve para detectar incidencias que desaparecen de Jira
     *   y para construir el evento posterior en issue_timeline
     *
     * @return array mapa jira_key => fila issue
     */
    private function getCurrentVisibleIssueRows(): array
    {
        $pdo = $this->createPdo();

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
                assignee_display_name
            FROM issues
            WHERE visible = 1
        ";

        $rows = $pdo->query($sql)->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['jira_key']] = $row;
        }

        return $map;
    }

    /**
     * fetchAllCurrentJiraKeys()
     * -------------------------------------------------------
     * Obtiene TODAS las claves Jira actualmente existentes en el proyecto.
     *
     * IMPORTANTE:
     * - NO usa el JQL incremental
     * - usa el JQL base del proyecto
     * - solo pide el campo key
     *
     * Esto es necesario porque una incidencia borrada no aparecerá nunca
     * en el incremental, así que hay que reconciliar contra la lista real
     * actual del proyecto en Jira.
     *
     * @return array mapa jira_key => true
     */
    private function fetchAllCurrentJiraKeys(): array
    {
        $jira = new JiraService();

        // Para reconciliación queremos el proyecto completo actual,
        // no solo lo tocado desde lastSync.
        $jql = $this->buildIncrementalJql(null);

        $fields = ['key'];

        $keys = [];

        $onChunk = function(array $chunk) use (&$keys) {
            foreach ($chunk as $issue) {
                $key = $issue['key'] ?? null;
                if ($key) {
                    $keys[$key] = true;
                }
            }
        };

        $jira->paginateAll($jql, 100, $fields, $onChunk);

        return $keys;
    }

    /**
     * markIssuesDeletedInJiraAsClosed()
     * -------------------------------------------------------
     * Marca como cerradas en la tabla issues las incidencias que han
     * desaparecido de Jira.
     *
     * QUÉ HACE:
     * - status_name = Completado
     * - estado_categoria = cerrado_unificado
     * - visible = 0
     *
     * @param array $jiraKeys Lista de claves Jira a cerrar
     * @return int Número de filas afectadas
     */
    private function markIssuesDeletedInJiraAsClosed(array $jiraKeys): int
    {
        if (empty($jiraKeys)) {
            return 0;
        }

        $pdo = $this->createPdo();

        $placeholders = implode(',', array_fill(0, count($jiraKeys), '?'));

        $sql = "
            UPDATE issues
            SET
                status_name = 'Completado',
                estado_categoria = 'cerrado_unificado',
                visible = 0
            WHERE jira_key IN ($placeholders)
              AND visible = 1
        ";

        $st = $pdo->prepare($sql);
        $st->execute(array_values($jiraKeys));

        return $st->rowCount();
    }

    /**
     * generateClosureReportsForNewlyClosedIssues()
     * -------------------------------------------------------
     * Genera automáticamente informes de cierre para incidencias
     * que han pasado a cerradas en ESTA sincronización.
     *
     * REGLA:
     * - Busca incidencias actualmente cerradas.
     * - Compara contra el estado previo capturado antes del UPSERT.
     * - Solo genera informe si:
     *   1) antes NO estaba cerrada
     *   2) ahora SÍ está cerrada
     *   3) no existe ya un informe de cierre para esa jira_key
     *
     * IMPORTANTE:
     * - Si falla OpenAI o el servicio de cierre, no se rompe la sync.
     * - Devuelve contadores para trazabilidad.
     *
     * @param array $prevStates Mapa jira_key => status_name antes de la sync
     * @return array
     */
    private function generateClosureReportsForNewlyClosedIssues(array $prevStates): array
    {
        $pdo = $this->createPdo();

        $sql = "
            SELECT
                id,
                jira_key,
                summary,
                status_name,
                estado_categoria,
                visible,
                updated_at
            FROM issues
            WHERE
                estado_categoria = 'cerrado_unificado'
                OR LOWER(status_name) IN (
                    'completado',
                    'completed',
                    'closed',
                    'done',
                    'resolved',
                    'resuelto',
                    'cancelled',
                    'canceled'
                )
            ORDER BY updated_at DESC
        ";

        $closedIssues = $pdo->query($sql)->fetchAll() ?: [];

        if (empty($closedIssues)) {
            return [
                'generated' => 0,
                'failed'    => 0,
            ];
        }

        $closureService = new ClosureReportService($pdo);

        $generated = 0;
        $failed = 0;

        foreach ($closedIssues as $issue) {
            $jiraKey = (string)($issue['jira_key'] ?? '');
            $issueId = (int)($issue['id'] ?? 0);

            if ($jiraKey === '' || $issueId <= 0) {
                continue;
            }

            /**
             * Estado anterior capturado antes de la sync.
             * Si no existía antes, no generamos automáticamente para evitar
             * crear informes de cierre de incidencias históricas importadas.
             */
            $previousStatus = $prevStates[$jiraKey] ?? null;

            if ($previousStatus === null) {
                continue;
            }

            /**
             * Solo nos interesan las que antes NO estaban cerradas.
             */
            if ($this->isClosedStatus($previousStatus)) {
                continue;
            }

            /**
             * Seguridad extra:
             * Si ya existe informe de cierre para esta incidencia, no duplicamos.
             */
            if ($this->hasClosureReportForJiraKey($jiraKey)) {
                continue;
            }

            try {
                $closureService->generateReport($issueId, 'auto_sync');
                $generated++;
            } catch (Throwable $t) {
                /**
                 * No lanzamos excepción.
                 * La sincronización de Jira no debe fallar porque falle la IA.
                 */
                $failed++;
            }
        }

        return [
            'generated' => $generated,
            'failed'    => $failed,
        ];
    }

    /**
     * hasClosureReportForJiraKey()
     * -------------------------------------------------------
     * Comprueba si ya existe un informe de cierre para una incidencia.
     *
     * QUÉ EVITA:
     * - Duplicar informes de cierre en cada sync.
     *
     * @param string $jiraKey Clave Jira
     * @return bool
     */
    private function hasClosureReportForJiraKey(string $jiraKey): bool
    {
        $pdo = $this->createPdo();

        $sql = "
            SELECT id
            FROM ai_closure_reports
            WHERE jira_key COLLATE utf8mb4_unicode_ci = :jira_key COLLATE utf8mb4_unicode_ci
            LIMIT 1
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':jira_key' => $jiraKey,
        ]);

        return (bool)$st->fetchColumn();
    }

    /**
     * isClosedStatus()
     * -------------------------------------------------------
     * Determina si un estado debe considerarse cerrado.
     *
     * @param string|null $status Estado Jira
     * @return bool
     */
    private function isClosedStatus(?string $status): bool
    {
        $s = strtolower(trim((string)$status));

        if ($s === '') {
            return false;
        }

        return in_array($s, [
            'completado',
            'completed',
            'closed',
            'done',
            'resolved',
            'resuelto',
            'cancelled',
            'canceled',
            'cancelado',
        ], true);
    }

    /**
     * createPdo()
     * -------------------------------------------------------
     * Crea una conexión PDO local para métodos internos.
     *
     * NOTA:
     * - Se mantiene este helper porque esta clase ya usaba conexiones
     *   locales en varios métodos.
     *
     * @return PDO
     */
    private function createPdo(): PDO
    {
        return new PDO(
            sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                env('DB_HOST'),
                env('DB_PORT'),
                env('DB_NAME')
            ),
            env('DB_USER'),
            env('DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * buildIncrementalJql()
     * -------------------------------------------------------
     * Construye un JQL incremental seguro:
     * - Si hay lastSync → usa updated >= con ventana -2 min
     * - Si NO hay lastSync → full sync con JIRA_JQL_BASE
     * - Siempre garantiza ORDER BY updated DESC
     *
     * @param string|null $lastSync
     * @return string
     */
    private function buildIncrementalJql(?string $lastSync): string
    {
        //------------------------------------------------------
        // CASO 1 → Incremental
        //------------------------------------------------------
        if ($lastSync) {
            // Ventana de -2 minutos para evitar perder updates
            $ts = strtotime($lastSync . ' UTC -2 minutes');

            // Formato requerido por Jira
            $jqlDate = date('Y/m/d H:i', $ts);

            // Base desde constants o fallback por proyecto
            $jqlBase = (JIRA_JQL_BASE && trim(JIRA_JQL_BASE) !== '')
                ? trim(JIRA_JQL_BASE)
                : ('project = ' . (JIRA_PROJECT_KEY ?? ''));

            // Detectar ORDER BY ya existente
            $pattern   = '/\s+order\s+by\s+/i';
            $hasOrder  = preg_match($pattern, $jqlBase) === 1;
            $orderExpr = '';
            $left      = $jqlBase;

            if ($hasOrder) {
                $parts = preg_split($pattern, $jqlBase, 2);
                $left = trim($parts[0]);
                $orderExpr = 'ORDER BY ' . trim($parts[1]);
            }

            // Añadir condición incremental
            $left .= ' AND updated >= "' . $jqlDate . '"';

            if ($orderExpr === '') {
                $orderExpr = 'ORDER BY updated DESC';
            }

            return $left . ' ' . $orderExpr;
        }

        //------------------------------------------------------
        // CASO 2 → Full sync con JQL base
        //------------------------------------------------------
        if (JIRA_JQL_BASE && trim(JIRA_JQL_BASE) !== '') {
            $base = trim(JIRA_JQL_BASE);

            if (stripos($base, 'order by') === false) {
                $base .= ' ORDER BY updated DESC';
            }

            return $base;
        }

        //------------------------------------------------------
        // CASO 3 → Full sync por proyecto
        //------------------------------------------------------
        $key = JIRA_PROJECT_KEY ?? '';
        return "project = $key ORDER BY updated DESC";
    }
}