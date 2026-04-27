<?php
/**
 * app/helpers/Auth.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Helper centralizado de autenticación y sesión.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Será usado por login.php, logout.php y por las páginas protegidas.
 * - No depende de un modelo concreto; solo gestiona sesión y roles.
 *
 * FUNCIONES PRINCIPALES:
 * - auth_boot(): inicia sesión de forma segura.
 * - auth_user(): devuelve el usuario logado.
 * - auth_check(): indica si hay sesión activa.
 * - auth_login(): guarda el usuario en sesión.
 * - auth_logout(): destruye la sesión.
 * - auth_require_login(): obliga a login.
 * - auth_require_role(): obliga a un rol concreto.
 * - auth_redirect_home(): redirige según rol.
 */

if (!function_exists('auth_boot')) {
    /**
     * Inicia la sesión si aún no está iniciada.
     */
    function auth_boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('auth_user')) {
    /**
     * Devuelve los datos del usuario logado o null si no hay sesión.
     */
    function auth_user(): ?array
    {
        auth_boot();
        return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])
            ? $_SESSION['auth_user']
            : null;
    }
}

if (!function_exists('auth_check')) {
    /**
     * Indica si hay un usuario autenticado.
     */
    function auth_check(): bool
    {
        return auth_user() !== null;
    }
}

if (!function_exists('auth_login')) {
    /**
     * Guarda en sesión los datos mínimos del usuario autenticado.
     *
     * @param array $user Fila del usuario recuperada desde BBDD
     */
    function auth_login(array $user): void
    {
        auth_boot();

        $_SESSION['auth_user'] = [
            'id'              => (int)($user['id'] ?? 0),
            'username'        => (string)($user['username'] ?? ''),
            'display_name'    => (string)($user['display_name'] ?? ''),
            'role'            => (string)($user['role'] ?? ''),
            'jira_account_id' => (string)($user['jira_account_id'] ?? ''),
        ];
    }
}

if (!function_exists('auth_logout')) {
    /**
     * Destruye completamente la sesión actual.
     */
    function auth_logout(): void
    {
        auth_boot();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                $params['secure'] ?? false,
                $params['httponly'] ?? true
            );
        }

        session_destroy();
    }
}

if (!function_exists('auth_is_admin')) {
    /**
     * Indica si el usuario logado tiene rol admin.
     */
    function auth_is_admin(): bool
    {
        $user = auth_user();
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('auth_is_operator')) {
    /**
     * Indica si el usuario logado tiene rol operador.
     */
    function auth_is_operator(): bool
    {
        $user = auth_user();
        return $user !== null && ($user['role'] ?? '') === 'operador';
    }
}

if (!function_exists('auth_require_login')) {
    /**
     * Obliga a que exista una sesión válida.
     * Si no la hay, redirige a login.php.
     */
    function auth_require_login(): void
    {
        if (!auth_check()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('auth_require_role')) {
    /**
     * Obliga a que el usuario tenga uno de los roles permitidos.
     *
     * @param string|array $roles Rol o lista de roles permitidos
     */
    function auth_require_role(string|array $roles): void
    {
        auth_require_login();

        $roles = is_array($roles) ? $roles : [$roles];
        $user  = auth_user();

        if (!$user || !in_array($user['role'] ?? '', $roles, true)) {
            http_response_code(403);
            echo '403 - Acceso no autorizado';
            exit;
        }
    }
}

if (!function_exists('auth_redirect_home')) {
    /**
     * Redirige al usuario a la página de inicio correspondiente a su rol.
     */
    function auth_redirect_home(): void
    {
        header('Location: ' . auth_home_path());
        exit;
    }
}

if (!function_exists('auth_home_path')) {
    /**
     * Devuelve la ruta de inicio según el rol del usuario logado.
     *
     * - admin     -> index.php
     * - operador  -> ai_alerts_page.php
     */
    function auth_home_path(): string
    {
        $user = auth_user();

        if (!$user) {
            return 'login.php';
        }

        return ($user['role'] ?? '') === 'operador'
            ? 'ai_alerts_page.php'
            : 'index.php';
    }
}

if (!function_exists('auth_require_api_role')) {
    /**
     * Protege endpoints API devolviendo JSON si el rol no está autorizado.
     *
     * @param string|array $roles Rol o lista de roles permitidos
     */
    function auth_require_api_role(string|array $roles): void
    {
        auth_boot();

        $roles = is_array($roles) ? $roles : [$roles];
        $user  = auth_user();

        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'No autenticado.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!in_array($user['role'] ?? '', $roles, true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'No autorizado.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}
