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
     * Calcula métricas clave del periodo
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

    private function countResolved(string $start, string $end): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM issue_timeline
            WHERE event_type = 'status_changed'
              AND to_status = 'Done'
              AND created_at BETWEEN :start AND :end
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':start' => $start, ':end' => $end]);

        return (int)$st->fetchColumn();
    }

    private function countOpenAt(string $moment): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM issues
            WHERE visible = 1
              AND (resolved_at IS NULL OR resolved_at > :moment)
              AND created_at <= :moment
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':moment' => $moment]);

        return (int)$st->fetchColumn();
    }

    private function countUnassigned(string $moment): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM issues
            WHERE visible = 1
              AND (assignee_account_id IS NULL OR assignee_account_id = '')
              AND (resolved_at IS NULL OR resolved_at > :moment)
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':moment' => $moment]);

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

    private function markCompleted(int $id, array $data): void
    {
        $sql = "
            UPDATE ai_queue_reports
            SET status = 'completed',
                report_summary = :summary,
                report_text = :text,
                metrics_json = :metrics,
                raw_response_json = :raw,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':summary' => $data['report_summary'],
            ':text'    => $data['report_text'],
            ':metrics' => json_encode($data['metrics']),
            ':raw'     => $data['raw'] ?? null,
            ':id'      => $id
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
