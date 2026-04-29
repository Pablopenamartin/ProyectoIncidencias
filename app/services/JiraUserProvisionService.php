<?php
/**
 * app/services/JiraUserProvisionService.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Servicio backend para crear/invitar usuarios en Jira Cloud
 * y guardar después el usuario local en la app.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa UserModel.php para insertar el usuario local.
 * - Usa constants.php para leer JIRA_SITE, JIRA_EMAIL, JIRA_API_TOKEN.
 * - Será usado por public/api/admin_create_user.php.
 *
 * FUNCIONES PRINCIPALES:
 * - registerUser(): flujo completo de alta Jira + alta local.
 * - inviteUserInJira(): crea/invita el usuario en Jira Cloud.
 * - resolveAccountIdByEmail(): intenta recuperar el accountId real.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/UserModel.php';

class JiraUserProvisionService
{
    private PDO $pdo;
    private UserModel $users;

    public function __construct(?PDO $pdo = null, ?UserModel $users = null)
    {
        $this->pdo   = $pdo instanceof PDO ? $pdo : getPDO();
        $this->users = $users ?? new UserModel($this->pdo);
    }

    /**
     * registerUser
     * --------------------------------------------------------------
     * Flujo completo:
     * 1. valida campos
     * 2. comprueba que no exista el usuario local
     * 3. crea/invita el usuario en Jira
     * 4. resuelve accountId real
     * 5. inserta usuario local con password hasheada
     */
    public function registerUser(array $data): array
    {
        $username    = trim((string)($data['username'] ?? ''));
        $password    = (string)($data['password'] ?? '');
        $displayName = trim((string)($data['display_name'] ?? ''));
        $role        = trim((string)($data['role'] ?? ''));
        $isActive    = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        if ($username === '') {
            throw new InvalidArgumentException('El campo username es obligatorio.');
        }

        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El username debe ser un email válido.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('El campo password es obligatorio.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('El campo display_name es obligatorio.');
        }

        if (!in_array($role, ['admin', 'operador'], true)) {
            throw new InvalidArgumentException('El rol debe ser admin u operador.');
        }

        if ($this->users->findByUsername($username)) {
            throw new RuntimeException('Ya existe un usuario local con ese username.');
        }

        // 1) Crear/invitar usuario en Jira Cloud
        $jiraResponse = $this->inviteUserInJira($username);

        // 2) Resolver accountId real
        $accountId = '';
        if (!empty($jiraResponse['accountId'])) {
            $accountId = (string)$jiraResponse['accountId'];
        }

        if ($accountId === '') {
            $accountId = $this->resolveAccountIdByEmail($username);
        }

        if ($accountId === '') {
            throw new RuntimeException('No se pudo obtener el accountId real del usuario en Jira.');
        }

        // 3) Crear usuario local con hash seguro
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $userId = $this->users->createUser([
            'username'        => $username,
            'password_hash'   => $passwordHash,
            'display_name'    => $displayName,
            'role'            => $role,
            'jira_account_id' => $accountId,
            'is_active'       => $isActive,
        ]);

        return [
            'user_id'         => $userId,
            'username'        => $username,
            'display_name'    => $displayName,
            'role'            => $role,
            'jira_account_id' => $accountId,
        ];
    }

    /**
     * inviteUserInJira
     * --------------------------------------------------------------
     * Crea/invita el usuario en Jira Cloud usando:
     * POST /rest/api/3/user
     *
     * Devuelve la respuesta JSON de Jira si la operación tiene éxito.
     */
    private function inviteUserInJira(string $email): array
    {
        $url = rtrim((string)JIRA_SITE, '/') . '/rest/api/3/user';

        /**
         * products = [] mantiene el alta/invitación básica.
         * Más adelante se puede ampliar para grupos/permisos.
         */
        $payload = [
            'emailAddress' => $email,
            'products'     => [],
        ];

        return $this->request('POST', $url, $payload);
    }

    /**
     * resolveAccountIdByEmail
     * --------------------------------------------------------------
     * Intenta localizar el accountId real del usuario buscando por email/query.
     *
     * Si Jira no devuelve accountId directamente al crear el usuario,
     * esta función hace una búsqueda posterior.
     */
    private function resolveAccountIdByEmail(string $email): string
    {
        $url = rtrim((string)JIRA_SITE, '/') . '/rest/api/3/user/search?query=' . rawurlencode($email);

        $response = $this->request('GET', $url, null);

        if (!is_array($response)) {
            return '';
        }

        foreach ($response as $user) {
            $accountId = (string)($user['accountId'] ?? '');
            $display   = strtolower(trim((string)($user['displayName'] ?? '')));
            $query     = strtolower(trim($email));

            if ($accountId !== '') {
                // Preferimos accountId si el usuario aparece en la búsqueda
                return $accountId;
            }
        }

        return '';
    }

    /**
     * request
     * --------------------------------------------------------------
     * Wrapper HTTP mínimo para Jira Cloud usando Basic Auth:
     * email administrador + API token.
     */
    private function request(string $method, string $url, ?array $body = null): array
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
            throw new RuntimeException('Error de conexión a Jira: ' . $error);
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
}
