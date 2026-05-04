<?php
/**
 * app/services/AlertNotificationService.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Servicio encargado de notificar nuevas alertas críticas:
 * - correo a todos los usuarios activos de la app
 * - mensaje al canal de Teams (si hay webhook configurado)
 * - registro en alert_notifications para evitar duplicados
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar PDO
 * - Usa app/services/SmtpMailService.php para enviar correos
 * - Será llamado más adelante desde AiAnalysisService.php
 *
 * FUNCIONES PRINCIPALES:
 * - notifyNewAlertsForReport(): procesa alertas nuevas de un informe
 * - getNewAlertsForReport(): obtiene alertas críticas nuevas
 * - sendAlertEmailToAll(): envía el correo a todos los usuarios activos
 * - sendAlertToTeams(): envía el aviso al webhook de Teams si existe
 * - saveNotificationStatus(): registra el envío en alert_notifications
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SmtpMailService.php';

class AlertNotificationService
{
    /**
     * Conexión PDO del sistema.
     */
    private PDO $pdo;

    /**
     * Servicio de correo reutilizable.
     */
    private SmtpMailService $mailer;

    /**
     * __construct
     * --------------------------------------------------------------
     * Inicializa dependencias del servicio.
     *
     * @param PDO|null $pdo Conexión opcional inyectada
     * @param SmtpMailService|null $mailer Servicio SMTP opcional inyectado
     */
    public function __construct(?PDO $pdo = null, ?SmtpMailService $mailer = null)
    {
        $this->pdo    = $pdo instanceof PDO ? $pdo : getPDO();
        $this->mailer = $mailer ?? new SmtpMailService();
    }

    /**
     * notifyNewAlertsForReport
     * --------------------------------------------------------------
     * Procesa todas las alertas nuevas de un informe IA.
     *
     * QUÉ HACE:
     * - busca las alertas críticas nuevas del report_id indicado
     * - envía correo a todos los usuarios activos
     * - envía aviso a Teams si hay webhook configurado
     * - registra el resultado en alert_notifications
     *
     * @param int $reportId ID del informe IA recién generado
     * @return array Resumen del proceso
     */
    public function notifyNewAlertsForReport(int $reportId): array
    {
        $alerts = $this->getNewAlertsForReport($reportId);

        $processed  = 0;
        $emailSent  = 0;
        $teamsSent  = 0;

        foreach ($alerts as $alert) {
            $processed++;

            $emailOk = $this->sendAlertEmailToAll($alert);
            if ($emailOk) {
                $emailSent++;
            }

            $teamsOk = $this->sendAlertToTeams($alert);
            if ($teamsOk) {
                $teamsSent++;
            }

            $this->saveNotificationStatus(
                (string)$alert['jira_key'],
                (int)$alert['report_id'],
                $teamsOk,
                $emailOk
            );
        }

        return [
            'alerts_found'   => count($alerts),
            'alerts_sent'    => $processed,
            'email_sent'     => $emailSent,
            'teams_sent'     => $teamsSent,
        ];
    }

    /**
     * getNewAlertsForReport
     * --------------------------------------------------------------
     * Obtiene las alertas críticas "nuevas" del informe actual.
     *
     * REGLA:
     * - solo incidencias del report actual
     * - solo críticas
     * - solo visibles
     * - solo sin asignar
     * - solo si ese jira_key no fue notificado antes
     *
     * @param int $reportId ID del informe
     * @return array
     */
    private function getNewAlertsForReport(int $reportId): array
    {
        $sql = "
            SELECT
                ari.report_id,
                ari.jira_key,
                i.summary,
                i.status_name AS current_status,
                i.priority_name AS current_priority,
                i.prioridad_nivel,
                ari.critical_reason,
                ari.recommended_action,
                ari.analysis_text,
                ari.score,
                ar.report_name,
                ar.created_at AS report_created_at
            FROM ai_report_issues ari

            INNER JOIN ai_reports ar
                ON ar.id = ari.report_id

            INNER JOIN issues i
                ON i.jira_key COLLATE utf8mb4_unicode_ci = ari.jira_key COLLATE utf8mb4_unicode_ci

            INNER JOIN (
                SELECT
                    jira_key,
                    MAX(report_id) AS last_report_id
                FROM ai_report_issues
                GROUP BY jira_key
            ) latest
                ON latest.jira_key COLLATE utf8mb4_unicode_ci = ari.jira_key COLLATE utf8mb4_unicode_ci
               AND latest.last_report_id = ari.report_id

            WHERE
                ari.report_id = :report_id
                AND ari.is_critical = 1
                AND i.visible = 1
                AND (
                    i.assignee_account_id IS NULL
                    OR i.assignee_account_id = ''
                )
            ORDER BY ari.score DESC, ari.jira_key ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':report_id' => $reportId]);
        $rows = $st->fetchAll() ?: [];

        // Filtrar solo las incidencias que nunca se han notificado antes
        $newAlerts = [];

        foreach ($rows as $row) {
            if (!$this->hasAnyNotificationForJiraKey((string)$row['jira_key'])) {
                $newAlerts[] = $row;
            }
        }

        return $newAlerts;
    }

    /**
     * hasAnyNotificationForJiraKey
     * --------------------------------------------------------------
     * Comprueba si una incidencia ya fue notificada anteriormente.
     *
     * @param string $jiraKey Clave Jira
     * @return bool
     */
    private function hasAnyNotificationForJiraKey(string $jiraKey): bool
    {
        $sql = "
            SELECT id
            FROM alert_notifications
            WHERE jira_key = :jira_key
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':jira_key' => $jiraKey]);

        return (bool)$st->fetchColumn();
    }

    /**
     * getActiveRecipientEmails
     * --------------------------------------------------------------
     * Devuelve todos los usuarios activos de la tabla users.
     *
     * @return array Lista de emails
     */
    private function getActiveRecipientEmails(): array
    {
        $sql = "
            SELECT username
            FROM users
            WHERE is_active = 1
            ORDER BY id ASC
        ";

        $rows = $this->pdo->query($sql)->fetchAll() ?: [];

        $emails = [];

        foreach ($rows as $row) {
            $email = trim((string)($row['username'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * sendAlertEmailToAll
     * --------------------------------------------------------------
     * Envía un correo a todos los usuarios activos con la información
     * de la alerta y el enlace a la app.
     *
     * @param array $alert Datos de la alerta
     * @return bool True si al menos se intentó enviar correctamente a todos
     */
    private function sendAlertEmailToAll(array $alert): bool
    {
        $emails = $this->getActiveRecipientEmails();

        if (empty($emails)) {
            return false;
        }

        $subject = 'Nueva alerta crítica: ' . (string)$alert['jira_key'];
        $alertUrl = $this->buildAlertUrl($alert);

        $html = '
            <h2>Nueva alerta crítica detectada</h2>
            <p><strong>Incidencia:</strong> ' . htmlspecialchars((string)$alert['jira_key'], ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Resumen:</strong> ' . htmlspecialchars((string)($alert['summary'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Estado:</strong> ' . htmlspecialchars((string)($alert['current_status'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Prioridad:</strong> ' . htmlspecialchars((string)($alert['current_priority'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Motivo crítico:</strong> ' . htmlspecialchars((string)($alert['critical_reason'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Acción recomendada:</strong> ' . htmlspecialchars((string)($alert['recommended_action'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Score:</strong> ' . htmlspecialchars((string)($alert['score'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Informe IA:</strong> ' . htmlspecialchars((string)($alert['report_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><a href="' . htmlspecialchars($alertUrl, ENT_QUOTES, 'UTF-8') . '">Abrir alerta en la aplicación</a></p>
        ';

        foreach ($emails as $email) {
            $this->mailer->sendHtmlMail($email, $subject, $html);
        }

        return true;
    }

    /**
 * sendAlertToTeams
 * --------------------------------------------------------------
 * Envía una alerta crítica al webhook de Teams / Workflows.
 *
 * QUÉ HACE:
 * - Lee la URL del webhook desde TEAMS_WEBHOOK_URL
 * - Construye un mensaje de texto con los datos de la alerta
 * - Hace un POST HTTP en JSON al webhook
 * - Permite dos modos SSL:
 *   1) Validación normal usando CURL_CAINFO si está configurado
 *   2) Desactivar validación SSL en local mediante LOCAL_DISABLE_SSL_VERIFY=1
 *
 * VARIABLES DE ENTORNO RELACIONADAS:
 * - TEAMS_WEBHOOK_URL
 * - CURL_CAINFO
 * - LOCAL_DISABLE_SSL_VERIFY
 *
 * @param array $alert Datos de la alerta crítica
 * @return bool True si el envío fue correcto
 */
private function sendAlertToTeams(array $alert): bool
{
    // URL del webhook de Teams / Power Automate
    $webhookUrl = trim((string)env('TEAMS_WEBHOOK_URL', ''));

    // Si no hay webhook configurado, no intentamos enviar nada
    if ($webhookUrl === '') {
        return false;
    }

    // Construimos el enlace a la alerta dentro de la app
    $alertUrl = $this->buildAlertUrl($alert);

    // Texto del mensaje que se publicará en Teams
    
    $text =
            "🚨 **Nueva alerta crítica**\n\n"
            . "**Incidencia:** " . (string)$alert['jira_key'] . "\n\n"
            . "**Resumen:** " . (string)($alert['summary'] ?? '') . "\n\n"
            . "**Prioridad:** " . (string)($alert['current_priority'] ?? '') . "\n\n"
            . "**Motivo:** " . (string)($alert['critical_reason'] ?? '') . "\n\n"
            . "**Informe:** " . (string)($alert['report_name'] ?? '') . "\n\n"
            . "[👉 Abrir alerta:](" . $alertUrl . ")";


    // Payload JSON esperado por el webhook simple de Teams / Workflows
    $payload = [
        'text' => $text,
    ];

    // Inicializamos cURL
    $ch = curl_init();

    // Opciones base comunes
    $curlOptions = [
        CURLOPT_URL            => $webhookUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
    ];

    // ----------------------------------------------------------
    // VALIDACIÓN SSL
    // ----------------------------------------------------------
    // 1) Si existe un archivo CA configurado, lo usamos
    $caInfo = trim((string)env('CURL_CAINFO', ''));
    if ($caInfo !== '') {
        $curlOptions[CURLOPT_CAINFO] = $caInfo;
    }

    // 2) Si estás en local y quieres desactivar temporalmente
    //    la validación SSL para pruebas, usa:
    //    LOCAL_DISABLE_SSL_VERIFY=1
    //
    //    IMPORTANTE:
    //    Esto es solo para desarrollo local.
    $disableSslVerify = trim((string)env('LOCAL_DISABLE_SSL_VERIFY', '0')) === '1';

    if ($disableSslVerify) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    // Aplicamos configuración a cURL
    curl_setopt_array($ch, $curlOptions);

    // Ejecutamos petición
    $raw   = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    // En PHP 8+ basta con liberar la referencia
    unset($ch);

    // Error de conexión / SSL / red
    if ($errno !== 0) {
        throw new RuntimeException('Error enviando alerta a Teams: ' . $error);
    }

    // Error HTTP devuelto por el webhook
    if ($http < 200 || $http >= 300) {
        throw new RuntimeException(
            'Teams respondió con error HTTP ' . $http . ': ' . (string)$raw
        );
    }

    return true;
}

    /**
     * buildAlertUrl
     * --------------------------------------------------------------
     * Construye el enlace a la alerta.
     *
     * COMPORTAMIENTO:
     * - si el usuario ya está logado, luego se le podrá redirigir a Alertas
     * - si no está logado, pasará por login y luego a Alertas
     *
     * @param array $alert Datos de la alerta
     * @return string URL absoluta
     */
    private function buildAlertUrl(array $alert): string
    {
        $appBaseUrl = rtrim((string)env('APP_BASE_URL', ''), '/');

        $params = http_build_query([
            'redirect'  => 'ai_alerts_page.php',
            'jira_key'  => (string)$alert['jira_key'],
            'report_id' => (int)$alert['report_id'],
        ]);

        return $appBaseUrl . '/public/login.php?' . $params;
    }

    /**
     * saveNotificationStatus
     * --------------------------------------------------------------
     * Registra el resultado del envío en alert_notifications.
     *
     * @param string $jiraKey Clave Jira
     * @param int $reportId ID del informe IA
     * @param bool $teamsSent Si se envió a Teams
     * @param bool $emailSent Si se envió email
     * @return void
     */
    private function saveNotificationStatus(
        string $jiraKey,
        int $reportId,
        bool $teamsSent,
        bool $emailSent
    ): void {
        $sql = "
            INSERT INTO alert_notifications (
                jira_key,
                report_id,
                notified_teams,
                notified_email,
                created_at
            ) VALUES (
                :jira_key,
                :report_id,
                :notified_teams,
                :notified_email,
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':jira_key'       => $jiraKey,
            ':report_id'      => $reportId,
            ':notified_teams' => $teamsSent ? 1 : 0,
            ':notified_email' => $emailSent ? 1 : 0,
        ]);
    }
    /**
     * retryNotificationsForReport
     * --------------------------------------------------------------
     * Reintenta el envío de notificaciones de un informe ya generado,
     * sin volver a ejecutar la IA.
     *
     * QUÉ HACE:
     * - toma las incidencias críticas del report_id indicado
     * - revisa alert_notifications
     * - si Teams o email no se enviaron, los reintenta
     *
     * @param int $reportId ID del informe ya existente
     * @return array Resumen del reintento
     */
    public function retryNotificationsForReport(int $reportId): array
    {
        $alerts = $this->getRetryableAlertsForReport($reportId);

        $processed = 0;
        $emailSent = 0;
        $teamsSent = 0;

        foreach ($alerts as $alert) {
            $processed++;

            $alreadyEmail = (int)($alert['notified_email'] ?? 0) === 1;
            $alreadyTeams = (int)($alert['notified_teams'] ?? 0) === 1;

            $emailOk = $alreadyEmail;
            $teamsOk = $alreadyTeams;

            if (!$alreadyEmail) {
                $emailOk = $this->sendAlertEmailToAll($alert);
                if ($emailOk) {
                    $emailSent++;
                }
            }

            if (!$alreadyTeams) {
                try {
                    $teamsOk = $this->sendAlertToTeams($alert);
                    if ($teamsOk) {
                        $teamsSent++;
                    }
                } catch (Throwable $teamsError) {
                    $teamsOk = false;
                }
            }

            $this->upsertNotificationStatus(
                (string)$alert['jira_key'],
                (int)$alert['report_id'],
                $teamsOk,
                $emailOk
            );
        }

        return [
            'alerts_found' => count($alerts),
            'alerts_sent'  => $processed,
            'email_sent'   => $emailSent,
            'teams_sent'   => $teamsSent,
        ];
    }

    /**
     * getRetryableAlertsForReport
     * --------------------------------------------------------------
     * Devuelve las alertas críticas de un informe que necesitan
     * reintento de Teams o email.
     *
     * REGLA:
     * - usa SOLO el contenido guardado del informe
     * - solo incidencias críticas
     * - solo incidencias actualmente sin asignar
     * - reintenta si:
     *   - no existe fila en alert_notifications
     *   - o notified_teams = 0
     *   - o notified_email = 0
     *
     * @param int $reportId ID del informe
     * @return array
     */
    private function getRetryableAlertsForReport(int $reportId): array
    {
        $sql = "
            SELECT
                ari.report_id,
                ari.jira_key,
                COALESCE(i.summary, ari.summary) AS summary,
                COALESCE(i.status_name, ari.current_status) AS current_status,
                COALESCE(i.priority_name, ari.current_priority) AS current_priority,
                i.prioridad_nivel,
                i.assignee_account_id,
                ari.critical_reason,
                ari.recommended_action,
                ari.analysis_text,
                ari.score,
                ar.report_name,
                ar.created_at AS report_created_at,
                an.notified_teams,
                an.notified_email
            FROM ai_report_issues ari

            INNER JOIN ai_reports ar
                ON ar.id = ari.report_id

            LEFT JOIN issues i
                ON i.jira_key COLLATE utf8mb4_unicode_ci = ari.jira_key COLLATE utf8mb4_unicode_ci

            LEFT JOIN alert_notifications an
                ON an.jira_key COLLATE utf8mb4_unicode_ci = ari.jira_key COLLATE utf8mb4_unicode_ci
            AND an.report_id = ari.report_id

            WHERE
                ari.report_id = :report_id
                AND ari.is_critical = 1
                AND (
                    i.assignee_account_id IS NULL
                    OR i.assignee_account_id = ''
                )
                AND (
                    an.id IS NULL
                    OR an.notified_teams = 0
                    OR an.notified_email = 0
                )

            ORDER BY ari.score DESC, ari.jira_key ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':report_id' => $reportId
        ]);

        return $st->fetchAll() ?: [];
    }


    /**
     * upsertNotificationStatus
     * --------------------------------------------------------------
     * Inserta o actualiza el estado de notificación de una alerta.
     *
     * @param string $jiraKey Clave Jira
     * @param int $reportId ID del informe
     * @param bool $teamsSent Si Teams se envió correctamente
     * @param bool $emailSent Si email se envió correctamente
     * @return void
     */
    private function upsertNotificationStatus(
        string $jiraKey,
        int $reportId,
        bool $teamsSent,
        bool $emailSent
    ): void {
        $sqlCheck = "
            SELECT id
            FROM alert_notifications
            WHERE jira_key = :jira_key
              AND report_id = :report_id
            LIMIT 1
        ";

        $stCheck = $this->pdo->prepare($sqlCheck);
        $stCheck->execute([
            ':jira_key'  => $jiraKey,
            ':report_id' => $reportId,
        ]);

        $existingId = $stCheck->fetchColumn();

        if ($existingId) {
            $sqlUpdate = "
                UPDATE alert_notifications
                SET
                    notified_teams = :notified_teams,
                    notified_email = :notified_email
                WHERE id = :id
                LIMIT 1
            ";

            $stUpdate = $this->pdo->prepare($sqlUpdate);
            $stUpdate->execute([
                ':notified_teams' => $teamsSent ? 1 : 0,
                ':notified_email' => $emailSent ? 1 : 0,
                ':id'             => $existingId,
            ]);

            return;
        }

        $this->saveNotificationStatus($jiraKey, $reportId, $teamsSent, $emailSent);
    }
}