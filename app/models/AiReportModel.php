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


}