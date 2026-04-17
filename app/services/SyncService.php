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
 *
 * IMPORTANTE:
 * - Se capturan los estados previos ANTES del UPSERT
 * - issue_timeline solo registra cambios reales en esta sync
 * - La sync manual se mantiene como reconciliación
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/jira.php';
require_once __DIR__ . '/../helpers/Utils.php';
require_once __DIR__ . '/../models/IssueModel.php';
require_once __DIR__ . '/JiraService.php';
require_once __DIR__ . '/SnapshotService.php';
require_once __DIR__ . '/IssueTimelineService.php';

class SyncService
{
    /**
     * Ejecuta la sincronización Jira → BD
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
        // 9) Respuesta final
        //------------------------------------------------------
        return [
            'ok'               => true,
            'jql'              => $jql,
            'fetched'          => $totalFetched,
            'inserted'         => $totalInserted,
            'snapshot_id'      => $snapshotId,
            'timeline_inserted'=> $timelineInserted,
            'last_sync_time'   => $now
        ];
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
