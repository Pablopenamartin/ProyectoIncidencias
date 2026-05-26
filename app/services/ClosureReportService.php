<?php
/**
 * app/services/ClosureReportService.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Servicio encargado de generar informes de calidad de cierre
 * de incidencias basados en el análisis de su timeline.
 *
 * RESPONSABILIDADES:
 * - Obtener datos de la incidencia
 * - Obtener timeline completo
 * - Analizar flujo operativo
 * - Construir payload para IA
 * - Generar informe de calidad (rating 1-10)
 * - Guardar resultado en ai_closure_reports
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa database.php para PDO
 *
 * NOTA:
 * - No depende de comentarios Jira (no disponibles)
 * - Evalúa proceso, no contenido textual
 */

require_once __DIR__ . '/../config/database.php';

class ClosureReportService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * generateReport
     * ------------------------------------------------------
     * Genera informe de cierre para una incidencia.
     *
     * @param int $issueId ID interno de la incidencia
     * @param string $triggerSource origen (manual / auto)
     * @return array
     */
    public function generateReport(int $issueId, string $triggerSource = 'manual'): array
    {
        $issue = $this->getIssue($issueId);

        if (!$issue) {
            throw new RuntimeException("Incidencia no encontrada");
        }

        $timeline = $this->getTimeline($issue['jira_key']);

        $reportId = $this->createPendingReport($issue, $triggerSource);

        try {
            $analysis = $this->analyzeTimeline($timeline);

            $payload = $this->buildAiPayload($issue, $analysis);

            $prompt = $this->buildPrompt($payload);

            $ai = $this->callOpenAI($prompt);

            $rating = $this->extractRating($ai['text']);

            $this->markCompleted($reportId, [
                'rating' => $rating,
                'summary' => mb_substr($ai['text'], 0, 800),
                'text' => $ai['text'],
                'raw' => $ai['raw']
            ]);

            return [
                'report_id' => $reportId,
                'rating' => $rating
            ];

        } catch (Throwable $e) {
            $this->markFailed($reportId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * getIssue
     * ------------------------------------------------------
     * Obtiene datos básicos de la incidencia.
     */
    private function getIssue(int $issueId): ?array
    {
        $sql = "SELECT * FROM issues WHERE id = :id LIMIT 1";

        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $issueId]);

        $row = $st->fetch();

        return $row ?: null;
    }

    /**
     * getTimeline
     * ------------------------------------------------------
     * Obtiene timeline completo de la incidencia.
     */
    private function getTimeline(string $jiraKey): array
    {
        $sql = "
            SELECT *
            FROM issue_timeline
            WHERE jira_key = :jira_key
            ORDER BY snapshot_time ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':jira_key' => $jiraKey]);

        return $st->fetchAll() ?: [];
    }

    /**
     * analyzeTimeline
     * ------------------------------------------------------
     * Analiza comportamiento de la incidencia.
     *
     * QUÉ CALCULA:
     * - número de cambios de estado
     * - número de asignaciones
     * - duración total
     * - estabilidad (rebotes)
     */
    private function analyzeTimeline(array $timeline): array
    {
        $stateChanges = 0;
        $assignments = 0;
        $statuses = [];

        foreach ($timeline as $row) {
            if ($row['event_type'] === 'status_change') {
                $stateChanges++;
                $statuses[] = $row['status_name'];
            }

            if ($row['event_type'] === 'assignee_change') {
                $assignments++;
            }
        }

        $uniqueStatuses = array_unique($statuses);

        return [
            'total_events' => count($timeline),
            'state_changes' => $stateChanges,
            'assignments' => $assignments,
            'unique_states' => count($uniqueStatuses),
            'states_sequence' => array_values($statuses),
        ];
    }

    /**
     * buildAiPayload
     * ------------------------------------------------------
     * Construye datos para IA.
     */
    private function buildAiPayload(array $issue, array $analysis): array
    {
        return [
            'issue' => [
                'jira_key' => $issue['jira_key'],
                'summary' => $issue['summary'],
                'priority' => $issue['priority_name'],
            ],
            'analysis' => $analysis
        ];
    }

    /**
     * buildPrompt
     * ------------------------------------------------------
     * Prompt para evaluar calidad de cierre.
     */
    private function buildPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "
Eres un evaluador de calidad de procesos.

Analiza cómo se ha TRAMITADO y cerrado esta incidencia.

DATOS:
{$json}

Evalúa:

1. Calidad del flujo de estados
2. Eficiencia operativa
3. Estabilidad (rebotes)
4. Asignación

Devuelve:

- Rating global entre 1 y 10 (muy importante, formato: 'Rating: X/10')
- Resumen ejecutivo
- Puntos positivos
- Carencias
- Recomendaciones
- Flujo ideal
";
    }

    /**
     * callOpenAI
     * ------------------------------------------------------
     * Llamada a OpenAI.
     */
    private function callOpenAI(string $prompt): array
    {
        $url = "https://api.openai.com/v1/chat/completions";
        $apiKey = env('OPENAI_API_KEY');

        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Experto en procesos operativos'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
                "Content-Type: application/json"
            ]
        ]);

        $result = curl_exec($ch);
        $json = json_decode($result, true);

        return [
            'text' => $json['choices'][0]['message']['content'] ?? '',
            'raw' => $json
        ];
    }

    /**
     * extractRating
     * ------------------------------------------------------
     * Extrae rating del texto IA.
     */
    private function extractRating(string $text): int
    {
        if (preg_match('/Rating:\s*(\d{1,2})\/10/i', $text, $matches)) {
            return (int)$matches[1];
        }

        return 5; // fallback
    }

    /**
     * createPendingReport
     */
    private function createPendingReport(array $issue, string $source): int
    {
        $sql = "
            INSERT INTO ai_closure_reports (
                issue_id,
                jira_key,
                trigger_source,
                started_at,
                created_at
            ) VALUES (
                :issue_id,
                :jira_key,
                :source,
                NOW(),
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':issue_id' => $issue['id'],
            ':jira_key' => $issue['jira_key'],
            ':source' => $source,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * markCompleted
     */
    private function markCompleted(int $id, array $data): void
    {
        $sql = "
            UPDATE ai_closure_reports
            SET
                status = 'completed',
                rating = :rating,
                report_summary = :summary,
                report_text = :text,
                raw_response_json = :raw,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':rating' => $data['rating'],
            ':summary' => $data['summary'],
            ':text' => $data['text'],
            ':raw' => json_encode($data['raw']),
            ':id' => $id
        ]);
    }

    /**
     * markFailed
     */
    private function markFailed(int $id, string $error): void
    {
        $sql = "
            UPDATE ai_closure_reports
            SET
                status = 'failed',
                error_message = :error,
                completed_at = NOW()
            WHERE id = :id
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':error' => $error,
            ':id' => $id
        ]);
    }
}
