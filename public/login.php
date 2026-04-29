<?php
/**
 * public/login.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Página de login clásica en PHP.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para consultar la tabla users.
 * - Usa app/helpers/Auth.php para crear la sesión.
 *
 * FUNCIONES PRINCIPALES:
 * - Mostrar formulario de acceso
 * - Validar credenciales
 * - Iniciar sesión
 * - Redirigir según rol
 */

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/Auth.php';

auth_boot();

// Si ya hay sesión iniciada, redirigimos al home correspondiente.
if (auth_check()) {
    auth_redirect_home();
}

/**
 * Helper simple para escapar HTML en la vista.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim((string)($_POST['username'] ?? ''));
    $password      = (string)($_POST['password'] ?? '');

    if ($usernameValue === '' || $password === '') {
        $error = 'Usuario y contraseña son obligatorios.';
    } else {
        try {
            $pdo = getPDO();

            /**
             * Buscamos el usuario activo por username.
             */
            $sql = "
                SELECT
                    id,
                    username,
                    password_hash,
                    display_name,
                    role,
                    jira_account_id,
                    is_active
                FROM users
                WHERE username = :username
                  AND is_active = 1
                LIMIT 1
            ";

            $st = $pdo->prepare($sql);
            $st->execute([':username' => $usernameValue]);
            $user = $st->fetch();

            if (!$user) {
                $error = 'Credenciales inválidas.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Credenciales inválidas.';
            } else {
                // Login correcto: guardar datos mínimos en sesión.
                auth_login($user);

                // Redirigir según rol.
                auth_redirect_home();
            }

        } catch (Throwable $t) {
            $error = APP_DEBUG ? $t->getMessage() : 'Error iniciando sesión.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
        }
    </style>
</head>
<body>
    <div class="card shadow-sm login-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-3 text-center">Acceso a la aplicación</h1>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="username" class="form-label">Usuario</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        value="<?= e($usernameValue) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        Entrar
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
