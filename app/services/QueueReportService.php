<?php
/**
 * app/services/QueueReportService.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Servicio encargado de generar informes de evolución de la cola (12H).
 *
 * RESPONSABILIDADES:
 * - Determinar el bloque temporal (mañana / tarde)
 * - Calcular métricas de la cola de incidencias
 * - Comparar con el bloque anterior
 * - Preparar datos para análisis IA
 * - Guardar informe en ai_queue_reports
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa database.php para PDO
 *
 * NOTA:
 * - No integra todavía OpenAI (fase siguiente)
 */

require_once __DIR__ . '/../config/database.php';

class QueueReportService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * generateReport
     * ------------------------------------------------------
     * Genera el informe de 12h (modo manual o scheduler)
     *
     * @param string $triggerSource scheduler | manual_button
     * @return array
     */
    public function generateReport(string $triggerSource = 'manual_button'): array
    {
        $period = $this->getClosedPeriod();

        $reportId = $this->createPendingReport($period, $triggerSource);

        try {
            $metrics = $this->calculateMetrics($period);
            $previous = $this->getPreviousPeriodMetrics($period);

            $metricsComparison = $this->compareWithPrevious($metrics, $previous);

            // TODO: aquí irá la IA
            $payload = $this->buildAiPayload($period, $metrics, $metricsComparison);

            $prompt = $this->buildPrompt($payload);

            $aiResult = $this->callOpenAI($prompt);

            $reportText = $aiResult['text'];

            // Sacamos resumen (primer bloque)
            $reportSummary = mb_substr($reportText, 0, 800);


            $this->markCompleted($reportId, [
                'report_summary' => $reportSummary,
                'report_text'    => $reportText,
                'metrics'        => $metrics,
            ]);

            return [
                'report_id' => $reportId,
                'period'    => $period,
                'metrics'   => $metrics,
            ];

        } catch (Throwable $e) {
            $this->markFailed($reportId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * getClosedPeriod
     * ------------------------------------------------------
     * Determina el último bloque cerrado (mañana o tarde)
     */
    private function getClosedPeriod(): array
    {
        $now = new DateTime();

        $hour = (int)$now->format('H');
        $date = $now->format('Y-m-d');

        if ($hour >= 12) {
            // generar mañana (00:00 - 11:59)
            return [
                'label' => 'morning',
                'start' => $date . ' 00:00:00',
                'end'   => $date . ' 11:59:59',
                'display' => 'Mañana'
            ];
        }

        // generar tarde del día anterior
        $yesterday = (new DateTime('-1 day'))->format('Y-m-d');

        return [
            'label' => 'afternoon',
            'start' => $yesterday . ' 12:00:00',
            'end'   => $yesterday . ' 23:59:59',
            'display' => 'Tarde'
        ];
    }

        /**
     * calculateMetrics
     * ------------------------------------------------------
     * Calcula las métricas base del informe 12H.
     *
     * QUÉ HACE:
     * - Calcula abiertas al inicio del bloque
     * - Calcula abiertas al final del bloque
     * - Calcula incidencias entrantes en el periodo
     * - Calcula incidencias resueltas/cerradas en el periodo
     * - Calcula incidencias actualmente sin asignar
     *
     * NOTA:
     * - Para abiertas inicio/fin se intenta usar snapshots.
     * - Si no hay snapshot previo, se usa una aproximación desde issues.
     *
     * @param array $period Periodo cerrado del informe
     * @return array Métricas calculadas
     */
    private function calculateMetrics(array $period): array
    {
        $start = $period['start'];
        $end   = $period['end'];

        return [
            'open_start' => $this->countOpenAt($start),
            'open_end'   => $this->countOpenAt($end),
            'incoming'   => $this->countIncoming($start, $end),
            'resolved'   => $this->countResolved($start, $end),
            'unassigned' => $this->countUnassigned($end),
        ];
    }

    /**
     * Eventos básicos
     */

    private function countIncoming(string $start, string $end): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM issues
            WHERE created_at BETWEEN :start AND :end
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':start' => $start, ':end' => $end]);

        return (int)$st->fetchColumn();
    }

        /**
     * countResolved
     * ------------------------------------------------------
     * Cuenta incidencias cerradas/resueltas durante el periodo.
     *
     * QUÉ HACE:
     * - Usa issue_timeline
     * - Busca eventos reales de cambio de estado
     * - Cuenta incidencias cuyo estado final sea cerrado/completado
     *
     * IMPORTANTE:
     * - No usa resolved_at porque no existe en issues.
     * - No usa to_status porque no existe en issue_timeline.
     * - Usa snapshot_time como fecha del evento.
     *
     * @param string $start Inicio del periodo
     * @param string $end Fin del periodo
     * @return int Número de incidencias resueltas/cerradas
     */
    private function countResolved(string $start, string $end): int
    {
        $sql = "
            SELECT COUNT(DISTINCT jira_key)
            FROM issue_timeline
            WHERE event_type = 'status_change'
            AND snapshot_time BETWEEN :start AND :end
            AND (
                estado_categoria = 'cerrado_unificado'
                OR LOWER(status_name) IN (
                    'completado',
                    'completed',
                    'closed',
                    'done',
                    'resuelto',
                    'resolved',
                    'cancelled',
                    'canceled'
                )
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':start' => $start,
            ':end'   => $end,
        ]);

        return (int)$st->fetchColumn();
    }

        /**
     * countOpenAt
     * ------------------------------------------------------
     * Calcula cuántas incidencias abiertas había en un momento dado.
     *
     * QUÉ HACE:
     * 1. Intenta obtener el dato desde snapshots:
     *    - busca el último snapshot anterior o igual al momento
     *    - usa total_abiertas
     *
     * 2. Si no hay snapshot disponible:
     *    - usa una aproximación desde issues
     *
     * IMPORTANTE:
     * - No usa resolved_at porque esa columna no existe.
     * - Para datos históricos reales, snapshots es la fuente más fiable
     *   que ya tienes en el sistema.
     *
     * @param string $moment Fecha/hora de referencia
     * @return int Total de incidencias abiertas
     */
    private function countOpenAt(string $moment): int
    {
        // ------------------------------------------------------
        // 1) Intentar usar snapshots como fuente histórica
        // ------------------------------------------------------
        $sqlSnapshot = "
            SELECT total_abiertas
            FROM snapshots
            WHERE created_at <= :moment
            ORDER BY created_at DESC
            LIMIT 1
        ";

        $stSnapshot = $this->pdo->prepare($sqlSnapshot);
        $stSnapshot->execute([
            ':moment' => $moment,
        ]);

        $snapshotValue = $stSnapshot->fetchColumn();

        if ($snapshotValue !== false && $snapshotValue !== null) {
            return (int)$snapshotValue;
        }

        // ------------------------------------------------------
        // 2) Fallback: aproximación desde issues actuales
        // ------------------------------------------------------
        $sqlIssues = "
            SELECT COUNT(*)
            FROM issues
            WHERE visible = 1
            AND created_at <= :moment
            AND (
                estado_categoria IS NULL
                OR estado_categoria <> 'cerrado_unificado'
            )
        ";

        $stIssues = $this->pdo->prepare($sqlIssues);
        $stIssues->execute([
            ':moment' => $moment,
        ]);

        return (int)$stIssues->fetchColumn();
    }

        /**
     * countUnassigned
     * ------------------------------------------------------
     * Cuenta incidencias actualmente visibles y sin asignar.
     *
     * QUÉ HACE:
     * - Usa issues
     * - Cuenta solo incidencias visibles
     * - Excluye cerradas unificadas
     * - Cuenta las que no tienen assignee_account_id
     *
     * IMPORTANTE:
     * - No usa resolved_at porque no existe.
     * - Es una métrica de estado actual aproximado al cierre del periodo.
     *
     * @param string $moment Momento de referencia, reservado para evolución futura
     * @return int Total de incidencias sin asignar
     */
    private function countUnassigned(string $moment): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM issues
            WHERE visible = 1
            AND (
                assignee_account_id IS NULL
                OR assignee_account_id = ''
            )
            AND (
                estado_categoria IS NULL
                OR estado_categoria <> 'cerrado_unificado'
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute();

        return (int)$st->fetchColumn();
    }

    /**
     * Comparativa con bloque anterior
     */
    private function compareWithPrevious(array $current, ?array $previous): array
    {
        if (!$previous) {
            return [];
        }

        $result = [];

        foreach ($current as $key => $value) {
            $prev = $previous[$key] ?? 0;

            if ($prev == 0) {
                $result[$key] = null;
                continue;
            }

            $result[$key] = ($value - $prev) / $prev;
        }

        return $result;
    }

    /**
     * Obtener métricas anterior
     */
    private function getPreviousPeriodMetrics(array $period): ?array
    {
        $sql = "
            SELECT metrics_json
            FROM ai_queue_reports
            WHERE period_end < :start
              AND status = 'completed'
            ORDER BY period_end DESC
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':start' => $period['start']]);

        $row = $st->fetch();

        if (!$row) return null;

        return json_decode($row['metrics_json'], true);
    }

    /**
     * DB
     */
    private function createPendingReport(array $period, string $source): int
    {
        $sql = "
            INSERT INTO ai_queue_reports (
                report_name,
                status,
                period_start,
                period_end,
                period_label,
                trigger_source,
                started_at
            ) VALUES (
                :name,
                'pending',
                :start,
                :end,
                :label,
                :source,
                NOW()
            )
        ";

        $name = "Informe 12H " . substr($period['start'], 0, 10) . " " . $period['display'];

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':name'   => $name,
            ':start'  => $period['start'],
            ':end'    => $period['end'],
            ':label'  => $period['label'],
            ':source' => $source,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

        /**
     * markCompleted
     * ------------------------------------------------------
     * Marca el informe 12H como completed y guarda:
     * - resumen
     * - texto completo
     * - métricas JSON
     * - métricas principales en columnas específicas
     *
     * @param int $id ID del informe
     * @param array $data Datos finales del informe
     * @return void
     */
    private function markCompleted(int $id, array $data): void
    {
        $metrics = $data['metrics'] ?? [];

        $sql = "
            UPDATE ai_queue_reports
            SET
                status = 'completed',
                report_summary = :summary,
                report_text = :text,
                metrics_json = :metrics_json,

                total_open_start = :total_open_start,
                total_open_end = :total_open_end,
                total_incoming = :total_incoming,
                total_resolved = :total_resolved,
                total_unassigned = :total_unassigned,

                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':summary'          => $data['report_summary'],
            ':text'             => $data['report_text'],
            ':metrics_json'     => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            ':total_open_start' => (int)($metrics['open_start'] ?? 0),
            ':total_open_end'   => (int)($metrics['open_end'] ?? 0),
            ':total_incoming'   => (int)($metrics['incoming'] ?? 0),
            ':total_resolved'   => (int)($metrics['resolved'] ?? 0),
            ':total_unassigned' => (int)($metrics['unassigned'] ?? 0),

            ':id'               => $id,
        ]);
    }

    private function markFailed(int $id, string $error): void
    {
        $sql = "
            UPDATE ai_queue_reports
            SET status = 'failed',
                error_message = :error,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':error' => $error,
            ':id'    => $id
        ]);
    }
    /**
     * buildAiPayload
     * ------------------------------------------------------
     * Construye el payload para OpenAI.
     */
    private function buildAiPayload(array $period, array $metrics, array $comparison): array
    {
        return [
            'period' => [
                'start' => $period['start'],
                'end'   => $period['end'],
                'label' => $period['display'],
            ],
            'metrics' => $metrics,
            'comparison' => $comparison,
        ];
    }
    /**
     * buildPrompt
     * ------------------------------------------------------
     * Genera el prompt para el informe 12H.
     */
    private function buildPrompt(array $payload): string
    {
        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "
    Eres un analista senior de operaciones.

    Analiza la evolución de la cola de incidencias en el siguiente periodo.

    DATOS:
    {$payloadJson}

    Genera:

    1. Resumen ejecutivo (máx 8 líneas)
    2. Estado general de la cola
    3. Evolución (entrantes vs resueltas)
    4. Identificación de problemas
    5. Anomalías detectadas explicadas
    6. Riesgos
    7. Recomendaciones accionables

    IMPORTANTE:
    - Explicar el contexto
    - Ser claro y directo
    - Pensado para un jefe de equipo
    ";
    }
    /**
     * callOpenAI
     * ------------------------------------------------------
     * Llama a OpenAI para generar el informe.
     */
    private function callOpenAI(string $prompt): array
    {
        $url = "https://api.openai.com/v1/chat/completions";

        $apiKey = env('OPENAI_API_KEY');

        $postData = json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres un experto en análisis operativo.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.2
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("Error OpenAI HTTP {$httpCode}: {$result}");
        }

        $json = json_decode($result, true);

        return [
            'text' => $json['choices'][0]['message']['content'] ?? '',
            'raw'  => $json
        ];
    }

}
