<?php
/**
 * public/admin_users_page.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Pantalla admin para crear usuarios y ver usuarios locales existentes.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para permitir acceso solo admin.
 * - Usa admin_users.php para listar usuarios.
 * - Usa admin_create_user.php para crear usuarios.
 * - Incluye navbar.php para la navegación común.
 *
 * FUNCIONES PRINCIPALES:
 * - Mostrar formulario de alta de usuario
 * - Mostrar listado de usuarios locales
 * - Crear usuario en Jira + app mediante backend
 */

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/helpers/Auth.php';

auth_require_role('admin');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Usuarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background: #f8f9fa; }
        .page-wrap { max-width: 1200px; margin: 0 auto; }
        .status-box { min-height: 24px; }
        .table td, .table th { vertical-align: middle; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/partials/navbar.php'; ?>

    <div id="page-wrapper">
        <div class="container py-4">
            <div class="page-wrap">

                <!-- Cabecera -->
                <div class="mb-4">
                    <h1 class="h4 mb-1">Gestión de usuarios</h1>
                    <div class="text-muted small">
                        Crear usuarios en Jira y registrarlos en la aplicación.
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Formulario -->
                    <div class="col-12 col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h2 class="h6 mb-3">Crear usuario</h2>

                                <div id="userFormStatus" class="status-box small text-muted mb-3"></div>

                                <form id="createUserForm" novalidate>
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Email / username</label>
                                        <input type="email" id="username" class="form-control" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="password" class="form-label">Contraseña</label>
                                        <input type="password" id="password" class="form-control" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="displayName" class="form-label">Nombre visible</label>
                                        <input type="text" id="displayName" class="form-control" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="role" class="form-label">Rol</label>
                                        <select id="role" class="form-select" required>
                                            <option value="operador">operador</option>
                                            <option value="admin">admin</option>
                                        </select>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="isActive" checked>
                                        <label class="form-check-label" for="isActive">
                                            Usuario activo
                                        </label>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" id="btnCreateUser" class="btn btn-primary">
                                            Crear usuario
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Listado -->
                    <div class="col-12 col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h2 class="h6 mb-0">Usuarios registrados</h2>
                                    <button type="button" id="btnReloadUsers" class="btn btn-sm btn-outline-secondary">
                                        Recargar
                                    </button>
                                </div>

                                <div id="usersListStatus" class="status-box small text-muted mb-3"></div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered bg-white">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Username</th>
                                                <th>Nombre</th>
                                                <th>Rol</th>
                                                <th>Jira account ID</th>
                                                <th>Activo</th>
                                            </tr>
                                        </thead>
                                        <tbody id="usersRows">
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">Cargando...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        /**
         * CONFIG
         */
        const API_CREATE_USER = './api/admin_create_user.php';
        const API_USERS       = './api/admin_users.php';

        /**
         * DOM
         */
        const createUserForm  = document.getElementById('createUserForm');
        const username        = document.getElementById('username');
        const password        = document.getElementById('password');
        const displayName     = document.getElementById('displayName');
        const role            = document.getElementById('role');
        const isActive        = document.getElementById('isActive');
        const btnCreateUser   = document.getElementById('btnCreateUser');
        const btnReloadUsers  = document.getElementById('btnReloadUsers');
        const userFormStatus  = document.getElementById('userFormStatus');
        const usersListStatus = document.getElementById('usersListStatus');
        const usersRows       = document.getElementById('usersRows');

        /**
         * Utilidad simple para mensajes de estado.
         */
        function setStatus(el, message, type = 'muted') {
            el.className = `status-box small text-${type} mb-3`;
            el.textContent = message || '';
        }

        /**
         * Escapa HTML al pintar texto dinámico.
         */
        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        /**
         * Carga el listado de usuarios locales.
         */
        async function loadUsers() {
            setStatus(usersListStatus, 'Cargando usuarios...', 'muted');

            try {
                const res = await fetch(`${API_USERS}?t=${Date.now()}`);
                const json = await res.json();

                if (!json.ok) {
                    usersRows.innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center text-danger">No se pudieron cargar los usuarios</td>
                        </tr>
                    `;
                    setStatus(usersListStatus, json.error || 'Error cargando usuarios.', 'danger');
                    return;
                }

                const rows = json.data || [];

                if (!rows.length) {
                    usersRows.innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center text-muted">No hay usuarios registrados</td>
                        </tr>
                    `;
                    setStatus(usersListStatus, 'No hay usuarios registrados.', 'muted');
                    return;
                }

                usersRows.innerHTML = rows.map(u => `
                    <tr>
                        <td>${escapeHtml(u.id)}</td>
                        <td>${escapeHtml(u.username)}</td>
                        <td>${escapeHtml(u.display_name)}</td>
                        <td>${escapeHtml(u.role)}</td>
                        <td>${escapeHtml(u.jira_account_id)}</td>
                        <td>${Number(u.is_active) === 1 ? 'Sí' : 'No'}</td>
                    </tr>
                `).join('');

                setStatus(usersListStatus, `Usuarios cargados: ${rows.length}`, 'success');

            } catch (err) {
                console.error(err);
                usersRows.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center text-danger">Error de red</td>
                    </tr>
                `;
                setStatus(usersListStatus, 'Error cargando usuarios.', 'danger');
            }
        }

        /**
         * Envía al backend la creación del usuario.
         * El backend:
         * - crea/invita en Jira
         * - obtiene accountId real
         * - guarda en users
         */
        async function createUser() {
            const payload = {
                username: username.value.trim(),
                password: password.value,
                display_name: displayName.value.trim(),
                role: role.value,
                is_active: isActive.checked ? 1 : 0
            };

            if (!payload.username || !payload.password || !payload.display_name || !payload.role) {
                setStatus(userFormStatus, 'Todos los campos son obligatorios.', 'danger');
                return;
            }

            btnCreateUser.disabled = true;
            setStatus(userFormStatus, 'Creando usuario...', 'muted');

            try {
                const res = await fetch(API_CREATE_USER, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const json = await res.json();

                if (!json.ok) {
                    setStatus(userFormStatus, json.error || 'No se pudo crear el usuario.', 'danger');
                    return;
                }

                setStatus(userFormStatus, 'Usuario creado correctamente.', 'success');

                // Limpiar formulario
                createUserForm.reset();
                isActive.checked = true;
                role.value = 'operador';

                // Recargar listado
                await loadUsers();

            } catch (err) {
                console.error(err);
                setStatus(userFormStatus, 'Error de red creando el usuario.', 'danger');
            } finally {
                btnCreateUser.disabled = false;
            }
        }

        /**
         * Eventos
         */
        createUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            await createUser();
        });

        btnReloadUsers.addEventListener('click', loadUsers);

        /**
         * INIT
         */
        loadUsers();
    </script>
</body>
</html>
