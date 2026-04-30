<?php
/**
 * app/services/JiraUserProvisionService.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Servicio encargado del alta completa de usuarios:
 * - valida datos de entrada
 * - invita/crea usuario en Jira Cloud
 * - recupera el accountId real de Jira
 * - crea el usuario local en la tabla users
 * - envía 2 correos:
 *   1) confirmación + login
 *   2) enlace al canal de Teams
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/models/UserModel.php para guardar el usuario local.
 * - Usa app/services/SmtpMailService.php para enviar correos.
 * - Usa app/config/constants.php para leer JIRA_SITE, JIRA_EMAIL y JIRA_API_TOKEN.
 * - Usa app/config/database.php para reutilizar la conexión PDO.
 *
 * VARIABLES DE ENTORNO ESPERADAS:
 * - JIRA_SITE
 * - JIRA_EMAIL
 * - JIRA_API_TOKEN
 * - JIRA_USER_PRODUCTS
 * - APP_BASE_URL
 * - TEAMS_CHANNEL_INVITE_URL
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/SmtpMailService.php';

class JiraUserProvisionService
{
    /**
     * Conexión PDO del sistema.
     */
    private PDO $pdo;

    /**
     * Modelo de usuarios locales.
     */
    private UserModel $users;

    /**
     * Servicio de correo SMTP.
     */
    private SmtpMailService $mailer;

    /**
     * __construct
     * --------------------------------------------------------------
     * Inicializa dependencias del servicio.
     *
     * @param PDO|null $pdo Conexión opcional inyectada
     * @param UserModel|null $users Modelo opcional inyectado
     * @param SmtpMailService|null $mailer Servicio SMTP opcional inyectado
     */
    public function __construct(
        ?PDO $pdo = null,
        ?UserModel $users = null,
        ?SmtpMailService $mailer = null
    ) {
        $this->pdo    = $pdo instanceof PDO ? $pdo : getPDO();
        $this->users  = $users ?? new UserModel($this->pdo);
        $this->mailer = $mailer ?? new SmtpMailService();
    }

    /**
     * registerUser
     * --------------------------------------------------------------
     * Ejecuta el alta completa de usuario:
     * 1. valida datos
     * 2. comprueba que no exista en la app
     * 3. invita/crea en Jira
     * 4. recupera accountId
     * 5. crea usuario local en BBDD
     * 6. envía correo de confirmación
     * 7. envía correo con enlace a Teams
     *
     * @param array $data Datos del usuario recibidos desde la UI/API
     * @return array Resultado resumido del alta
     */
    public function registerUser(array $data): array
    {
        $username    = trim((string)($data['username'] ?? ''));
        $password    = (string)($data['password'] ?? '');
        $displayName = trim((string)($data['display_name'] ?? ''));
        $role        = trim((string)($data['role'] ?? ''));
        $isActive    = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $this->validateInput($username, $password, $displayName, $role);

        if ($this->users->findByUsername($username)) {
            throw new RuntimeException('Ya existe un usuario local con ese username.');
        }

        // ----------------------------------------------------------
        // 1) Crear / invitar usuario en Jira Cloud
        // ----------------------------------------------------------
        $jiraCreateResponse = $this->inviteUserInJira($username);

        // ----------------------------------------------------------
        // 2) Recuperar accountId real
        // ----------------------------------------------------------
        $jiraAccountId = trim((string)($jiraCreateResponse['accountId'] ?? ''));

        if ($jiraAccountId === '') {
            $jiraAccountId = $this->waitAndResolveAccountId($username);
        }

        if ($jiraAccountId === '') {
            throw new RuntimeException('No se pudo recuperar el accountId del usuario en Jira.');
        }

        // ----------------------------------------------------------
        // 3) Crear usuario local en la app
        // ----------------------------------------------------------
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $userId = $this->users->createUser([
            'username'        => $username,
            'password_hash'   => $passwordHash,
            'display_name'    => $displayName,
            'role'            => $role,
            'jira_account_id' => $jiraAccountId,
            'is_active'       => $isActive,
        ]);

        // ----------------------------------------------------------
        // 4) Enviar correo de confirmación + login
        // ----------------------------------------------------------
        $this->sendLoginConfirmationMail($username, $displayName);

        // ----------------------------------------------------------
        // 5) Enviar correo con enlace al canal de Teams
        // ----------------------------------------------------------
        $this->sendTeamsInvitationMail($username, $displayName);

        return [
            'user_id'         => $userId,
            'username'        => $username,
            'display_name'    => $displayName,
            'role'            => $role,
            'jira_account_id' => $jiraAccountId,
        ];
    }

    /**
     * validateInput
     * --------------------------------------------------------------
     * Valida los datos mínimos del usuario.
     *
     * @param string $username Email / username
     * @param string $password Contraseña en texto plano
     * @param string $displayName Nombre visible
     * @param string $role Rol del usuario
     * @return void
     */
    private function validateInput(
        string $username,
        string $password,
        string $displayName,
        string $role
    ): void {
        if ($username === '' || !filter_var($username, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El username debe ser un email válido.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('La contraseña es obligatoria.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('El nombre visible es obligatorio.');
        }

        if (!in_array($role, ['admin', 'operador'], true)) {
            throw new InvalidArgumentException('El rol debe ser admin u operador.');
        }
    }

    /**
     * inviteUserInJira
     * --------------------------------------------------------------
     * Invita o crea el usuario en Jira Cloud usando:
     * POST /rest/api/3/user
     *
     * products se deja configurable desde JIRA_USER_PRODUCTS.
     *
     * @param string $email Email real del usuario
     * @return array Respuesta JSON decodificada de Jira
     */
    private function inviteUserInJira(string $email): array
    {
        $url = rtrim((string)JIRA_SITE, '/') . '/rest/api/3/user';

        $productsRaw = trim((string)env('JIRA_USER_PRODUCTS', '[]'));
        $products = json_decode($productsRaw, true);

        if (!is_array($products)) {
            $products = [];
        }

        $payload = [
            'emailAddress' => $email,
            'products'     => $products,
        ];

        return $this->jiraRequest('POST', $url, $payload);
    }

    /**
     * waitAndResolveAccountId
     * --------------------------------------------------------------
     * Jira a veces no devuelve el accountId instantáneamente.
     * Reintenta varias veces buscando por email.
     *
     * @param string $email Email del usuario recién invitado
     * @return string accountId real o cadena vacía
     */
    private function waitAndResolveAccountId(string $email): string
    {
        $attempts = 5;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                sleep(2);
            }

            $accountId = $this->resolveAccountIdByEmail($email);
            if ($accountId !== '') {
                return $accountId;
            }
        }

        return '';
    }

    /**
     * resolveAccountIdByEmail
     * --------------------------------------------------------------
     * Busca el accountId real del usuario en Jira mediante:
     * GET /rest/api/3/user/search?query=email
     *
     * @param string $email Email del usuario
     * @return string accountId o cadena vacía si no aparece
     */
    private function resolveAccountIdByEmail(string $email): string
    {
        $url = rtrim((string)JIRA_SITE, '/') . '/rest/api/3/user/search?query=' . rawurlencode($email);

        $response = $this->jiraRequest('GET', $url, null);

        if (!is_array($response)) {
            return '';
        }

        // Intento 1: coincidencia exacta por emailAddress
        foreach ($response as $user) {
            $accountId    = trim((string)($user['accountId'] ?? ''));
            $emailAddress = strtolower(trim((string)($user['emailAddress'] ?? '')));

            if ($accountId !== '' && $emailAddress === strtolower($email)) {
                return $accountId;
            }
        }

        // Intento 2: fallback al primer usuario con accountId
        foreach ($response as $user) {
            $accountId = trim((string)($user['accountId'] ?? ''));
            if ($accountId !== '') {
                return $accountId;
            }
        }

        return '';
    }

    /**
     * jiraRequest
     * --------------------------------------------------------------
     * Wrapper HTTP mínimo para Jira Cloud REST API.
     *
     * AUTENTICACIÓN:
     * - Basic Auth
     * - email + API token
     *
     * @param string $method Método HTTP
     * @param string $url Endpoint completo
     * @param array|null $body Body opcional
     * @return array Respuesta JSON decodificada
     */
    private function jiraRequest(string $method, string $url, ?array $body): array
    {
        $auth = base64_encode((string)JIRA_EMAIL . ':' . (string)JIRA_API_TOKEN);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . $auth,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);

        if ($body !== null) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $raw   = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Error de conexión con Jira: ' . $error);
        }

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Jira respondió con error HTTP ' . $http . ': ' . (string)$raw);
        }

        if ($raw === '' || $raw === false) {
            return [];
        }

        $json = json_decode((string)$raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * sendLoginConfirmationMail
     * --------------------------------------------------------------
     * Envía el correo de confirmación de alta con enlace al login.
     *
     * @param string $email Email del usuario
     * @param string $displayName Nombre visible
     * @return void
     */
    private function sendLoginConfirmationMail(string $email, string $displayName): void
    {
        $appBaseUrl = rtrim((string)env('APP_BASE_URL', ''), '/');
        $loginUrl = $appBaseUrl . '/public/login.php';

        $subject = 'Alta completada en la aplicación';

        $html = '
            <h2>Hola ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</h2>
            <p>Tu alta en la aplicación se ha completado correctamente.</p>
            <p>Ya puedes acceder desde este enlace:</p>
            <p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">Ir al login</a></p>
        ';

        $this->mailer->sendHtmlMail($email, $subject, $html);
    }

    /**
     * sendTeamsInvitationMail
     * --------------------------------------------------------------
     * Envía un correo con el enlace fijo al Team/canal de Teams.
     *
     * @param string $email Email del usuario
     * @param string $displayName Nombre visible
     * @return void
     */
    private function sendTeamsInvitationMail(string $email, string $displayName): void
    {
        $teamsUrl = trim((string)env('TEAMS_CHANNEL_INVITE_URL', ''));
        if ($teamsUrl === '') {
            return;
        }

        $subject = 'Acceso al canal de Teams';

        $html = '
            <h2>Hola ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</h2>
            <p>Te compartimos el enlace al canal de Teams usado para alertas y seguimiento.</p>
            <p><a href="' . htmlspecialchars($teamsUrl, ENT_QUOTES, 'UTF-8') . '">Abrir canal de Teams</a></p>
        ';

        $this->mailer->sendHtmlMail($email, $subject, $html);
    }
}
