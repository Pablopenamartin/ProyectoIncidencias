<?php
/**
 * app/models/AiReportModel.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Modelo encargado de persistir:
 *  - informes IA (ai_reports)
 *  - detalle por incidencia (ai_report_issues)
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usado por app/services/AiAnalysisService.php
 * - Reutiliza la conexión PDO global (database.php)
 */

require_once __DIR__ . '/../config/database.php';

class AiReportModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * Crea un informe en estado PENDING antes de llamar a OpenAI.
     * Esto garantiza trazabilidad aunque la IA falle.
     */
    public function createPendingReport(array $data): int
    {
        $sql = "
            INSERT INTO ai_reports (
                report_name,
                status,
                provider,
                model,
                prompt_general_used,
                def_incidencia_critica_used,
                total_issues_analyzed,
                trigger_source,
                sync_reference_time,
                started_at,
                created_at
            ) VALUES (
                :report_name,
                'pending',
                :provider,
                :model,
                :prompt_general_used,
                :def_incidencia_critica_used,
                :total_issues_analyzed,
                :trigger_source,
                :sync_reference_time,
                :started_at,
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':report_name'                 => $data['report_name'],
            ':provider'                    => $data['provider'],
            ':model'                       => $data['model'],
            ':prompt_general_used'         => $data['prompt_general_used'],
            ':def_incidencia_critica_used' => $data['def_incidencia_critica_used'],
            ':total_issues_analyzed'       => $data['total_issues_analyzed'],
            ':trigger_source'              => $data['trigger_source'],
            ':sync_reference_time'         => $data['sync_reference_time'],
            ':started_at'                  => $data['started_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Marca el informe como COMPLETED y guarda resultados finales.
     */
    public function markCompleted(int $reportId, array $data): void
    {
        $sql = "
            UPDATE ai_reports SET
                report_name = :report_name,
                status = 'completed',
                total_critical_detected = :total_critical_detected,
                report_summary = :report_summary,
                report_text = :report_text,
                raw_response_json = :raw_response_json,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':report_name'             => $data['report_name'],
            ':total_critical_detected' => $data['total_critical_detected'],
            ':report_summary'          => $data['report_summary'],
            ':report_text'             => $data['report_text'],
            ':raw_response_json'       => $data['raw_response_json'],
            ':id'                      => $reportId,
        ]);
    }

    /**
     * Marca el informe como FAILED y guarda el error.
     */
    public function markFailed(int $reportId, string $error): void
    {
        $sql = "
            UPDATE ai_reports SET
                status = 'failed',
                error_message = :error,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':error' => $error,
            ':id'    => $reportId,
        ]);
    }

    /**
     * Guarda el análisis IA por incidencia.
     */
    public function saveIssueAnalyses(int $reportId, array $issues): void
    {
        $sql = "
            INSERT INTO ai_report_issues (
                report_id,
                jira_key,
                summary,
                current_status,
                current_priority,
                is_critical,
                critical_reason,
                recommended_action,
                analysis_text,
                score,
                created_at
            ) VALUES (
                :report_id,
                :jira_key,
                :summary,
                :current_status,
                :current_priority,
                :is_critical,
                :critical_reason,
                :recommended_action,
                :analysis_text,
                :score,
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();

        try {
            foreach ($issues as $i) {
                $st->execute([
                    ':report_id'          => $reportId,
                    ':jira_key'           => $i['jira_key'],
                    ':summary'            => $i['summary'],
                    ':current_status'     => $i['current_status'],
                    ':current_priority'   => $i['current_priority'],
                    ':is_critical'        => $i['is_critical'] ? 1 : 0,
                    ':critical_reason'    => $i['critical_reason'],
                    ':recommended_action' => $i['recommended_action'],
                    ':analysis_text'      => $i['analysis_text'],
                    ':score'              => $i['score'],
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    /**
     * getReportsList
     * --------------------------------------------------------------
     * Devuelve el listado de informes IA ordenados por fecha descendente.
     *
     * QUÉ HACE:
     * - Consulta ai_reports
     * - Devuelve solo columnas útiles para el listado
     * - Ordena por created_at DESC y id DESC
     *
     * @param int $limit Límite máximo de filas
     * @return array
     */
    public function getReportsList(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT
                id,
                report_name,
                status,
                provider,
                model,
                total_issues_analyzed,
                total_critical_detected,
                trigger_source,
                sync_reference_time,
                started_at,
                completed_at,
                created_at
            FROM ai_reports
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * getReportById
     * --------------------------------------------------------------
     * Devuelve la cabecera completa de un informe concreto.
     *
     * QUÉ HACE:
     * - Busca en ai_reports por id
     * - Devuelve toda la información principal del informe
     *
     * @param int $reportId ID del informe
     * @return array|null
     */
    public function getReportById(int $reportId): ?array
    {
        $sql = "
            SELECT
                id,
                report_name,
                status,
                provider,
                model,
                prompt_general_used,
                def_incidencia_critica_used,
                total_issues_analyzed,
                total_critical_detected,
                report_summary,
                report_text,
                raw_response_json,
                trigger_source,
                sync_reference_time,
                error_message,
                started_at,
                completed_at,
                created_at
            FROM ai_reports
            WHERE id = :id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $reportId]);
        $row = $st->fetch();

        return $row ?: null;
    }

    /**
     * getReportIssues
     * --------------------------------------------------------------
     * Devuelve todas las incidencias analizadas dentro de un informe.
     *
     * QUÉ HACE:
     * - Consulta ai_report_issues por report_id
     * - Ordena primero críticas y luego por jira_key
     *
     * @param int $reportId ID del informe
     * @return array
     */
    public function getReportIssues(int $reportId): array
    {
        $sql = "
            SELECT
                id,
                report_id,
                jira_key,
                summary,
                current_status,
                current_priority,
                is_critical,
                critical_reason,
                recommended_action,
                analysis_text,
                score,
                created_at
            FROM ai_report_issues
            WHERE report_id = :report_id
            ORDER BY is_critical DESC, jira_key ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':report_id' => $reportId]);

        return $st->fetchAll() ?: [];
    }

    /**
     * getLatestCriticalUnassignedAlerts
     * --------------------------------------------------------------
     * Devuelve solo las últimas incidencias críticas sin asignar.
     *
     * REGLA DE NEGOCIO:
     * - Se toma la última evaluación IA por jira_key
     * - Solo se incluyen las marcadas como críticas
     * - Solo se incluyen incidencias visibles actualmente
     * - Solo se incluyen incidencias sin asignar actualmente
     *
     * CÓMO FUNCIONA:
     * - latest: obtiene el último report_id por jira_key
     * - ari: recupera el análisis IA de esa última evaluación
     * - i: cruza con la tabla issues para leer el estado actual real
     * - ar: recupera datos del informe origen
     *
     * @param int $limit Límite máximo de alertas
     * @return array
     */
    public function getLatestCriticalUnassignedAlerts(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $sql = "
            SELECT
                ari.id,
                ari.report_id,
                ari.jira_key,
                i.summary,
                i.status_name AS current_status,
                i.priority_name AS current_priority,
                i.prioridad_nivel,
                ari.is_critical,
                ari.critical_reason,
                ari.recommended_action,
                ari.analysis_text,
                ari.score,
                ar.report_name,
                ar.created_at AS report_created_at
            FROM ai_report_issues ari

            INNER JOIN (
                SELECT
                    jira_key,
                    MAX(report_id) AS last_report_id
                FROM ai_report_issues
                GROUP BY jira_key
            ) latest
                ON latest.jira_key = ari.jira_key
               AND latest.last_report_id = ari.report_id

            INNER JOIN ai_reports ar
                ON ar.id = ari.report_id

            
            INNER JOIN issues i
                ON i.jira_key COLLATE utf8mb4_unicode_ci = ari.jira_key COLLATE utf8mb4_unicode_ci


            WHERE
                ari.is_critical = 1
                AND i.visible = 1
                AND (
                    i.assignee_account_id IS NULL
                    OR i.assignee_account_id = ''
                )

            ORDER BY
                ar.created_at DESC,
                ari.score DESC,
                ari.jira_key ASC

            LIMIT {$limit}
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }


}