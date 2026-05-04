<?php
/**
 * app/services/AiAnalysisService.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Orquesta el flujo completo del análisis IA:
 *  - lee configuración
 *  - obtiene incidencias visibles
 *  - construye prompt
 *  - llama a OpenAI
 *  - guarda informe y detalle
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AiSettingsModel.php';
require_once __DIR__ . '/../models/AiReportModel.php';
require_once __DIR__ . '/OpenAiProviderService.php';
require_once __DIR__ . '/AlertNotificationsService.php';
// Servicio que detecta alertas nuevas y envía notificaciones por Teams y correo.

class AiAnalysisService
{
    private PDO $pdo;
    private AiSettingsModel $settings;
    private AiReportModel $reports;
    private OpenAiProviderService $openai;

    public function __construct()
    {
        $this->pdo      = getPDO();
        $this->settings = new AiSettingsModel($this->pdo);
        $this->reports  = new AiReportModel($this->pdo);
        $this->openai   = new OpenAiProviderService();
    }

    /**
     * Ejecuta la generación del informe IA.
     */
    public function generate(string $trigger = 'manual_button', ?string $syncTime = null): array
    {
        $cfg = $this->settings->getActiveSettings();
        $issues = $this->getVisibleIssues();

        if (!$issues) {
            throw new RuntimeException('No hay incidencias visibles');
        }

        $reportId = $this->reports->createPendingReport([
            'report_name' => 'Informe IA ' . date('Y-m-d H:i:s'),
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'prompt_general_used' => $cfg['prompt_general'],
            'def_incidencia_critica_used' => $cfg['def_incidencia_critica'],
            'total_issues_analyzed' => count($issues),
            'trigger_source' => $trigger,
            'sync_reference_time' => $syncTime,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            [$system, $user] = $this->buildPrompts($cfg, $issues);
            $ai = $this->openai->analyze($system, $user);

            $normalized = $this->normalize($ai, $issues);
            $criticalCount = count(array_filter($normalized, fn($i) => $i['is_critical']));

            $this->reports->markCompleted($reportId, [
                'report_name' => 'Informe IA ' . date('Y-m-d H:i:s') . " · {$criticalCount} críticas",
                'total_critical_detected' => $criticalCount,
                'report_summary' => $ai['report_summary'] ?? '',
                'report_text' => $ai['report_text'] ?? '',
                'raw_response_json' => json_encode($ai),
            ]);

            $this->reports->saveIssueAnalyses($reportId, $normalized);

            // Notificar nuevas alertas críticas:
            // - correo a todos los usuarios activos
            // - mensaje al canal de Teams
            // - registro en alert_notifications para evitar duplicados
            $alertNotifier = new AlertNotificationService($this->pdo);
            $notificationResult = $alertNotifier->notifyNewAlertsForReport($reportId);

            return [
                'report_id'      => $reportId,
                'critical'       => $criticalCount,
                'notifications'  => $notificationResult,
            ];

        } catch (Throwable $t) {
            $this->reports->markFailed($reportId, $t->getMessage());
            throw $t;
        }
    }

    private function getVisibleIssues(): array
    {
        return $this->pdo
            ->query("SELECT jira_key, summary, status_name, priority_name FROM issues WHERE visible = 1")
            ->fetchAll();
    }

    private function buildPrompts(array $cfg, array $issues): array
    {
        $system = "Responde siempre en español y en JSON.";
        $user = "PROMPT:\n{$cfg['prompt_general']}\n\nCRÍTICAS:\n{$cfg['def_incidencia_critica']}\n\nINCIDENCIAS:\n"
              . json_encode($issues, JSON_UNESCAPED_UNICODE);

        return [$system, $user];
    }

    private function normalize(array $ai, array $issues): array
    {
        $byKey = [];
        foreach ($ai['issues'] ?? [] as $i) {
            $byKey[$i['jira_key']] = $i;
        }

        $out = [];
        foreach ($issues as $i) {
            $k = $i['jira_key'];
            $x = $byKey[$k] ?? [];
            $out[] = [
                'jira_key' => $k,
                'summary' => $i['summary'],
                'current_status' => $i['status_name'],
                'current_priority' => $i['priority_name'],
                'is_critical' => (bool)($x['is_critical'] ?? false),
                'critical_reason' => $x['critical_reason'] ?? '',
                'recommended_action' => $x['recommended_action'] ?? '',
                'analysis_text' => $x['analysis_text'] ?? '',
                'score' => $x['score'] ?? null,
            ];
        }

        return $out;
    }
}