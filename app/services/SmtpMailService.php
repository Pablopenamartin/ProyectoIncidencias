<?php
/**
 * app/services/SmtpMailService.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Servicio SMTP mínimo para enviar correos desde la app.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/constants.php para leer variables SMTP del .env
 * - Será usado por JiraUserProvisionService.php para:
 *   1) correo de confirmación + login
 *   2) correo con enlace al canal de Teams
 *
 * FUNCIONES PRINCIPALES:
 * - sendHtmlMail(): envía un correo HTML simple por SMTP
 *
 * VARIABLES DE ENTORNO NECESARIAS:
 * - SMTP_HOST
 * - SMTP_PORT
 * - SMTP_USERNAME
 * - SMTP_PASSWORD
 * - SMTP_FROM_EMAIL
 * - SMTP_FROM_NAME
 *
 * NOTA:
 * - Pensado para Gmail SMTP con App Password
 * - Usa STARTTLS en puerto 587
 */

require_once __DIR__ . '/../config/constants.php';

class SmtpMailService
{
    /**
     * Host SMTP.
     */
    private string $host;

    /**
     * Puerto SMTP.
     */
    private int $port;

    /**
     * Usuario SMTP.
     */
    private string $username;

    /**
     * Contraseña SMTP / App Password.
     */
    private string $password;

    /**
     * Email remitente.
     */
    private string $fromEmail;

    /**
     * Nombre remitente.
     */
    private string $fromName;

    /**
     * __construct
     * --------------------------------------------------------------
     * Carga la configuración SMTP desde el entorno.
     */
    public function __construct()
    {
        $this->host      = trim((string)env('SMTP_HOST', 'smtp.gmail.com'));
        $this->port      = (int)env('SMTP_PORT', 587);
        $this->username  = trim((string)env('SMTP_USERNAME', ''));
        $this->password  = trim((string)env('SMTP_PASSWORD', ''));
        $this->fromEmail = trim((string)env('SMTP_FROM_EMAIL', $this->username));
        $this->fromName  = trim((string)env('SMTP_FROM_NAME', 'Jira Monitoring'));

        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('SMTP_USERNAME y SMTP_PASSWORD son obligatorios.');
        }
    }

    /**
     * sendHtmlMail
     * --------------------------------------------------------------
     * Envía un correo HTML simple mediante SMTP AUTH + STARTTLS.
     *
     * QUÉ HACE:
     * 1. abre conexión con el servidor SMTP
     * 2. inicia STARTTLS
     * 3. autentica con AUTH LOGIN
     * 4. envía remitente, destinatario y cuerpo HTML
     *
     * @param string $to Destinatario
     * @param string $subject Asunto
     * @param string $htmlBody Cuerpo HTML
     * @return void
     */
    public function sendHtmlMail(string $to, string $subject, string $htmlBody): void
    {
        $socket = stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            30
        );

        if (!$socket) {
            throw new RuntimeException("No se pudo conectar al servidor SMTP: {$errstr}");
        }

        // Saludo inicial del servidor
        $this->expect($socket, [220]);

        // EHLO inicial
        $this->send($socket, "EHLO localhost");
        $this->expect($socket, [250]);

        // Iniciar TLS
        $this->send($socket, "STARTTLS");
        $this->expect($socket, [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new RuntimeException('No se pudo iniciar TLS en SMTP.');
        }

        // EHLO después de TLS
        $this->send($socket, "EHLO localhost");
        $this->expect($socket, [250]);

        // AUTH LOGIN
        $this->send($socket, "AUTH LOGIN");
        $this->expect($socket, [334]);

        $this->send($socket, base64_encode($this->username));
        $this->expect($socket, [334]);

        $this->send($socket, base64_encode($this->password));
        $this->expect($socket, [235]);

        // MAIL FROM
        $this->send($socket, "MAIL FROM:<{$this->fromEmail}>");
        $this->expect($socket, [250]);

        // RCPT TO
        $this->send($socket, "RCPT TO:<{$to}>");
        $this->expect($socket, [250, 251]);

        // DATA
        $this->send($socket, "DATA");
        $this->expect($socket, [354]);

        $headers = [
            'From: ' . $this->formatAddress($this->fromEmail, $this->fromName),
            'To: ' . $this->formatAddress($to, $to),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . $htmlBody
            . "\r\n.";

        $this->sendRaw($socket, $message);
        $this->expect($socket, [250]);

        // Cerrar sesión SMTP
        $this->send($socket, "QUIT");

        fclose($socket);
    }

    /**
     * formatAddress
     * --------------------------------------------------------------
     * Formatea una dirección de correo con nombre visible.
     *
     * @param string $email Email
     * @param string $name  Nombre visible
     * @return string
     */
    private function formatAddress(string $email, string $name): string
    {
        return sprintf('"%s" <%s>', addslashes($name), $email);
    }

    /**
     * encodeHeader
     * --------------------------------------------------------------
     * Codifica el asunto en UTF-8 Base64 para evitar problemas
     * con caracteres especiales.
     *
     * @param string $value Texto a codificar
     * @return string
     */
    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * send
     * --------------------------------------------------------------
     * Envía un comando SMTP estándar terminado en CRLF.
     *
     * @param resource $socket Socket abierto
     * @param string $command Comando SMTP
     * @return void
     */
    private function send($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    /**
     * sendRaw
     * --------------------------------------------------------------
     * Envía contenido raw al socket SMTP.
     *
     * @param resource $socket Socket abierto
     * @param string $raw Texto raw
     * @return void
     */
    private function sendRaw($socket, string $raw): void
    {
        fwrite($socket, $raw . "\r\n");
    }

    /**
     * expect
     * --------------------------------------------------------------
     * Lee la respuesta SMTP y valida que el código esté entre
     * los permitidos.
     *
     * @param resource $socket Socket abierto
     * @param array $validCodes Códigos válidos esperados
     * @return void
     */
    private function expect($socket, array $validCodes): void
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            // La última línea SMTP tiene espacio en posición 4
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);

        if (!in_array($code, $validCodes, true)) {
            throw new RuntimeException('SMTP respondió con error: ' . trim($response));
        }
    }
}
