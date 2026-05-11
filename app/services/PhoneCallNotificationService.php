<?php
/**
 * app/services/PhoneCallNotificationService.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Servicio encargado de lanzar llamadas automáticas informativas
 * a todos los usuarios activos que tengan las llamadas habilitadas.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar PDO.
 * - Leerá de la tabla users:
 *   - phone_number
 *   - phone_notifications_enabled
 *   - is_active
 *
 * FUNCIONES PRINCIPALES:
 * - callUsersForAlert(): llama a todos los usuarios activos con llamadas habilitadas
 * - getCallableUsers(): obtiene teléfonos válidos
 * - buildCallMessage(): construye el texto a voz
 * - callPhoneNumber(): ejecuta la llamada saliente en Twilio usando TwiML inline
 *
 * VARIABLES DE ENTORNO NECESARIAS:
 * - TWILIO_ACCOUNT_SID
 * - TWILIO_AUTH_TOKEN
 * - TWILIO_PHONE_NUMBER
 * - TWILIO_TTS_LANGUAGE
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

class PhoneCallNotificationService
{
    /**
     * Conexión PDO del sistema.
     */
    private PDO $pdo;

    /**
     * Credenciales y configuración Twilio.
     */
    private string $twilioAccountSid;
    private string $twilioAuthToken;
    private string $twilioPhoneNumber;
    private string $ttsLanguage;

    /**
     * __construct
     * --------------------------------------------------------------
     * Inicializa la conexión y lee configuración Twilio desde entorno.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();

        $this->twilioAccountSid = trim((string)env('TWILIO_ACCOUNT_SID', ''));
        $this->twilioAuthToken  = trim((string)env('TWILIO_AUTH_TOKEN', ''));
        $this->twilioPhoneNumber = trim((string)env('TWILIO_PHONE_NUMBER', ''));
        $this->ttsLanguage = trim((string)env('TWILIO_TTS_LANGUAGE', 'es-ES'));

        if ($this->twilioAccountSid === '' || $this->twilioAuthToken === '' || $this->twilioPhoneNumber === '') {
            throw new RuntimeException('Faltan variables de entorno de Twilio (SID, token o número origen).');
        }
    }

    /**
     * callUsersForAlert
     * --------------------------------------------------------------
     * Llama a todos los usuarios activos con llamadas habilitadas.
     *
     * QUÉ HACE:
     * - lee usuarios de la tabla users
     * - construye un mensaje de voz corto
     * - ejecuta una llamada por cada teléfono
     *
     * @param array $alert Datos de la alerta crítica
     * @return array Resumen del proceso
     */
    public function callUsersForAlert(array $alert): array
    {
        $users = $this->getCallableUsers();

        if (empty($users)) {
            return [
                'callable_users' => 0,
                'calls_made'     => 0,
            ];
        }

        $message = $this->buildCallMessage($alert);
        $callsMade = 0;

        foreach ($users as $user) {
            $phone = trim((string)($user['phone_number'] ?? ''));

            if ($phone === '') {
                continue;
            }

            $this->callPhoneNumber($phone, $message);
            $callsMade++;
        }

        return [
            'callable_users' => count($users),
            'calls_made'     => $callsMade,
        ];
    }

    /**
     * getCallableUsers
     * --------------------------------------------------------------
     * Obtiene los usuarios activos con llamadas habilitadas y teléfono válido.
     *
     * @return array
     */
    private function getCallableUsers(): array
    {
        $sql = "
            SELECT
                id,
                username,
                display_name,
                phone_number
            FROM users
            WHERE is_active = 1
              AND phone_notifications_enabled = 1
              AND phone_number IS NOT NULL
              AND phone_number <> ''
            ORDER BY id ASC
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * buildCallMessage
     * --------------------------------------------------------------
     * Construye el texto a voz que Twilio leerá en la llamada.
     *
     * IMPORTANTE:
     * - Debe ser corto y claro
     * - Evitamos mensajes demasiado largos
     *
     * @param array $alert Datos de la alerta
     * @return string
     */
    private function buildCallMessage(array $alert): string
    {
        $jiraKey = (string)($alert['jira_key'] ?? 'incidencia desconocida');
        $summary = trim((string)($alert['summary'] ?? ''));
        $reason  = trim((string)($alert['critical_reason'] ?? ''));

        // Cortamos para no generar una locución excesiva
        $summaryShort = mb_substr($summary, 0, 180);
        $reasonShort  = mb_substr($reason, 0, 180);

        return "Se ha detectado una incidencia crítica. "
            . "Incidencia {$jiraKey}. "
            . "Resumen: {$summaryShort}. "
            . "Motivo crítico: {$reasonShort}. "
            . "Revise la alerta en la aplicación.";
    }

    /**
     * callPhoneNumber
     * --------------------------------------------------------------
     * Ejecuta una llamada saliente mediante Twilio Calls API usando
     * TwiML inline con <Say>, sin necesitar URL pública para TwiML.
     *
     * @param string $toPhone Teléfono destino en formato E.164
     * @param string $message Mensaje a reproducir por TTS
     * @return void
     */
    private function callPhoneNumber(string $toPhone, string $message): void
    {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->twilioAccountSid) . '/Calls.json';

        $twiml = $this->buildTwimlSay($message);

        $postFields = http_build_query([
            'To'    => $toPhone,
            'From'  => $this->twilioPhoneNumber,
            'Twiml' => $twiml,
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->twilioAccountSid . ':' . $this->twilioAuthToken,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
        ]);

        $raw   = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Error llamando a Twilio: ' . $error);
        }

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Twilio respondió con error HTTP ' . $http . ': ' . (string)$raw);
        }
    }

    /**
     * buildTwimlSay
     * --------------------------------------------------------------
     * Genera el TwiML inline que Twilio usará para leer el mensaje.
     *
     * @param string $message Texto a voz
     * @return string
     */
    private function buildTwimlSay(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $language = htmlspecialchars($this->ttsLanguage, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Response>'
            . '<Say language="' . $language . '">' . $safeMessage . '</Say>'
            . '</Response>';
    }
}
