<?php
/**
 * public/admin_users_page.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Pantalla admin para:
 * - crear usuarios
 * - listar usuarios activos
 * - mostrar/ocultar usuarios inactivos
 * - abrir modal de detalle/edición de usuario
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para permitir acceso solo admin.
 * - Usa admin_users.php para listar usuarios.
 * - Usa admin_create_user.php para crear usuarios.
 * - Usa admin_update_user.php para editar nombre, rol, activo/inactivo, teléfono y llamadas.
 * - Incluye navbar.php para la navegación común.
 *
 * FUNCIONES PRINCIPALES:
 * - Mostrar formulario de alta de usuario
 * - Mostrar tabla limpia de usuarios activos
 * - Mostrar tabla de inactivos bajo demanda
 * - Abrir modal de detalle + edición
 * - Guardar cambios de usuario
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

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f8f9fa;
        }

        .page-wrap {
            max-width: 1200px;
            margin: 0 auto;
        }

        .status-box {
            min-height: 24px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .role-badge {
            font-size: .78rem;
            font-weight: 600;
            text-transform: lowercase;
        }

        .user-menu-btn {
            border: 0;
            background: transparent;
            font-size: 1.35rem;
            line-height: 1;
            padding: .1rem .4rem;
            color: #495057;
        }

        .user-menu-btn:hover {
            color: #0d6efd;
        }

        .inactive-section {
            display: none;
        }

        .inactive-section.visible {
            display: block;
        }

        .employee-photo-box {
            width: 120px;
            height: 150px;
            border: 1px solid #ced4da;
            border-radius: .5rem;
            background: #f1f3f5;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .employee-photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: #6c757d;
            background: linear-gradient(135deg, #eef2f7, #dde5ee);
        }

        .modal-readonly {
            background-color: #f8f9fa;
        }
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
                    <!-- =======================================================
                         FORMULARIO DE ALTA
                    ======================================================== -->
                    <div class="col-12 col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h2 class="h6 mb-3">Crear usuario</h2>

                                <div id="userFormStatus" class="status-box small text-muted mb-3"></div>

                                <form id="createUserForm" novalidate>
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Email</label>
                                        <input type="email" id="username" class="form-control" required autocomplete="email">
                                    </div>

                                    <div class="mb-3">
                                        <label for="password" class="form-label">Contraseña</label>
                                        <input type="password" id="password" class="form-control" required autocomplete="new-password">
                                    </div>

                                    <div class="mb-3">
                                        <label for="repeatPassword" class="form-label">Repetir contraseña</label>
                                        <input type="password" id="repeatPassword" class="form-control" required autocomplete="new-password">
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

                                    <!-- Teléfono para llamadas -->
                                    <div class="mb-3">
                                        <label for="phoneNumber" class="form-label">Teléfono</label>
                                        <input
                                            type="text"
                                            id="phoneNumber"
                                            class="form-control"
                                            placeholder="+34600111222"
                                        >
                                        <div class="form-text">
                                            Formato internacional. Ejemplo: +34600111222
                                        </div>
                                    </div>

                                    <!-- Si recibe llamadas -->
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="phoneNotificationsEnabled">
                                        <label class="form-check-label" for="phoneNotificationsEnabled">
                                            Recibe llamadas automáticas
                                        </label>
                                    </div>

                                    <!-- Estado activo -->
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

                    <!-- =======================================================
                         LISTADO DE USUARIOS
                    ======================================================== -->
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

                                <!-- Tabla activos -->
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered bg-white">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Email</th>
                                                <th>Nombre</th>
                                                <th>Rol</th>
                                                <th class="text-center" style="width: 48px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="usersRows">
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">Cargando...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Toggle inactivos -->
                                <div class="mb-3">
                                    <button type="button" id="btnToggleInactiveUsers" class="btn btn-sm btn-outline-secondary d-none">
                                        Mostrar usuarios inactivos
                                    </button>
                                </div>

                                <!-- Tabla inactivos -->
                                <div id="inactiveSection" class="inactive-section">
                                    <h3 class="h6 mb-2">Usuarios inactivos</h3>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered bg-white">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Email</th>
                                                    <th>Nombre</th>
                                                    <th>Rol</th>
                                                    <th class="text-center" style="width: 48px;"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="inactiveUsersRows">
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No hay usuarios inactivos</td>
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
    </div>

    <!-- =======================================================
         MODAL DETALLE / EDICIÓN DE USUARIO
    ======================================================== -->
    <div class="modal fade" id="userEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalle de usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="editUserId">

                    <div id="userEditStatus" class="status-box small text-muted mb-3"></div>

                    <div class="row g-4">
                        <!-- Foto placeholder -->
                        <div class="col-12 col-md-4">
                            <div class="employee-photo-box">
                                <div id="employeePhotoPlaceholder" class="employee-photo-placeholder">
                                    --
                                </div>
                            </div>
                            <div class="small text-muted mt-2">
                                Foto de empleado (placeholder)
                            </div>
                        </div>

                        <!-- Datos -->
                        <div class="col-12 col-md-8">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="editUserEmail" class="form-label">Email</label>
                                    <input type="text" id="editUserEmail" class="form-control modal-readonly" readonly>
                                </div>

                                <div class="col-12">
                                    <label for="editUserDisplayName" class="form-label">Nombre</label>
                                    <input type="text" id="editUserDisplayName" class="form-control">
                                </div>

                                <!-- Rol del modal -->
                                <div class="col-12 col-md-6">
                                    <label for="editUserRole" class="form-label">Rol</label>
                                    <select id="editUserRole" class="form-select">
                                        <option value="operador">operador</option>
                                        <option value="admin">admin</option>
                                    </select>
                                </div>

                                <!-- Estado activo del modal -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label d-block">Estado</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="editUserIsActive">
                                        <label class="form-check-label" for="editUserIsActive">
                                            Usuario activo
                                        </label>
                                    </div>
                                </div>

                                <!-- Teléfono -->
                                <div class="col-12 col-md-6">
                                    <label for="editUserPhoneNumber" class="form-label">Teléfono</label>
                                    <input
                                        type="text"
                                        id="editUserPhoneNumber"
                                        class="form-control"
                                        placeholder="+34600111222"
                                    >
                                    <div class="form-text">
                                        Formato internacional. Ejemplo: +34600111222
                                    </div>
                                </div>

                                <!-- Llamadas -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label d-block">Llamadas</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="editUserPhoneNotificationsEnabled">
                                        <label class="form-check-label" for="editUserPhoneNotificationsEnabled">
                                            Recibe llamadas automáticas
                                        </label>
                                    </div>
                                </div>

                                <!-- Jira account ID -->
                                <div class="col-12">
                                    <label for="editUserJiraAccountId" class="form-label">Jira account ID</label>
                                    <input type="text" id="editUserJiraAccountId" class="form-control modal-readonly" readonly>
                                </div>

                                <!-- Fechas -->
                                <div class="col-12 col-md-6">
                                    <label for="editUserCreatedAt" class="form-label">Creado</label>
                                    <input type="text" id="editUserCreatedAt" class="form-control modal-readonly" readonly>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="editUserUpdatedAt" class="form-label">Actualizado</label>
                                    <input type="text" id="editUserUpdatedAt" class="form-control modal-readonly" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" id="btnSaveUserEdit">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        /**
         * ======================================================
         * CONFIG
         * ======================================================
         */
        const API_CREATE_USER = './api/admin_create_user.php';
        const API_USERS       = './api/admin_users.php';
        const API_UPDATE_USER = './api/admin_update_user.php';

        /**
         * ======================================================
         * DOM
         * ======================================================
         */
        const createUserForm            = document.getElementById('createUserForm');
        const username                  = document.getElementById('username');
        const password                  = document.getElementById('password');
        const repeatPassword            = document.getElementById('repeatPassword');
        const displayName               = document.getElementById('displayName');
        const role                      = document.getElementById('role');
        const phoneNumber               = document.getElementById('phoneNumber');
        const phoneNotificationsEnabled = document.getElementById('phoneNotificationsEnabled');
        const isActive                  = document.getElementById('isActive');
        const btnCreateUser             = document.getElementById('btnCreateUser');

        const btnReloadUsers            = document.getElementById('btnReloadUsers');
        const btnToggleInactiveUsers    = document.getElementById('btnToggleInactiveUsers');

        const userFormStatus            = document.getElementById('userFormStatus');
        const usersListStatus           = document.getElementById('usersListStatus');

        const usersRows                 = document.getElementById('usersRows');
        const inactiveUsersRows         = document.getElementById('inactiveUsersRows');
        const inactiveSection           = document.getElementById('inactiveSection');

        const editUserId                          = document.getElementById('editUserId');
        const editUserEmail                       = document.getElementById('editUserEmail');
        const editUserDisplayName                 = document.getElementById('editUserDisplayName');
        const editUserRole                        = document.getElementById('editUserRole');
        const editUserIsActive                    = document.getElementById('editUserIsActive');
        const editUserJiraAccountId               = document.getElementById('editUserJiraAccountId');
        const editUserPhoneNumber                 = document.getElementById('editUserPhoneNumber');
        const editUserPhoneNotificationsEnabled   = document.getElementById('editUserPhoneNotificationsEnabled');
        const editUserCreatedAt                   = document.getElementById('editUserCreatedAt');
        const editUserUpdatedAt                   = document.getElementById('editUserUpdatedAt');
        const employeePhotoPlaceholder            = document.getElementById('employeePhotoPlaceholder');
        const userEditStatus                      = document.getElementById('userEditStatus');
        const btnSaveUserEdit                     = document.getElementById('btnSaveUserEdit');

        /**
         * ======================================================
         * ESTADO
         * ======================================================
         */
        let usersCache = [];
        let userEditModal;

        /**
         * ======================================================
         * UTILIDADES
         * ======================================================
         */

        /**
         * Muestra un mensaje de estado simple.
         */
        function setStatus(el, message, type = 'muted') {
            el.className = `status-box small text-${type} mb-3`;
            el.textContent = message || '';
        }

        /**
         * Escapa HTML para pintar texto dinámico con seguridad.
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
         * Devuelve iniciales simples para el placeholder de foto.
         */
        function getInitials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return '--';

            const first = parts[0]?.charAt(0) || '';
            const second = parts[1]?.charAt(0) || '';

            return (first + second).toUpperCase() || '--';
        }

        /**
         * Devuelve un badge visual según el rol del usuario.
         */
        function renderRoleBadge(roleValue) {
            const roleText = String(roleValue || '').toLowerCase();

            if (roleText === 'admin') {
                return '<span class="badge text-bg-primary role-badge">admin</span>';
            }

            return '<span class="badge text-bg-secondary role-badge">operador</span>';
        }

        /**
         * Construye una fila de usuario para la tabla.
         */
        function buildUserRowHtml(user) {
            return `
                <tr id="user-row-${escapeHtml(user.id)}">
                    <td>${escapeHtml(user.username)}</td>
                    <td>${escapeHtml(user.display_name)}</td>
                    <td>${renderRoleBadge(user.role)}</td>
                    <td class="text-center">
                        <button
                            type="button"
                            class="user-menu-btn btn-open-user-modal"
                            data-user-id="${escapeHtml(user.id)}"
                            title="Ver detalle"
                            aria-label="Ver detalle"
                        >...</button>
                    </td>
                </tr>
            `;
        }

        /**
         * ======================================================
         * CARGA DE USUARIOS
         * ======================================================
         */

        /**
         * Carga todos los usuarios locales y separa activos/inactivos.
         */
        async function loadUsers() {
            setStatus(usersListStatus, 'Cargando usuarios...', 'muted');

            try {
                const res = await fetch(`${API_USERS}?t=${Date.now()}`);
                const json = await res.json();

                if (!json.ok) {
                    usersRows.innerHTML = `
                        <tr>
                            <td colspan="4" class="text-center text-danger">No se pudieron cargar los usuarios</td>
                        </tr>
                    `;
                    inactiveUsersRows.innerHTML = `
                        <tr>
                            <td colspan="4" class="text-center text-danger">No se pudieron cargar los usuarios</td>
                        </tr>
                    `;
                    setStatus(usersListStatus, json.error || 'Error cargando usuarios.', 'danger');
                    return;
                }

                usersCache = Array.isArray(json.data) ? json.data : [];

                const activeUsers = usersCache.filter(u => Number(u.is_active) === 1);
                const inactiveUsers = usersCache.filter(u => Number(u.is_active) !== 1);

                renderActiveUsers(activeUsers);
                renderInactiveUsers(inactiveUsers);

                setStatus(usersListStatus, `Usuarios cargados: ${usersCache.length}`, 'success');

            } catch (err) {
                console.error(err);

                usersRows.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-danger">Error de red</td>
                    </tr>
                `;

                inactiveUsersRows.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-danger">Error de red</td>
                    </tr>
                `;

                setStatus(usersListStatus, 'Error cargando usuarios.', 'danger');
            }
        }

        /**
         * Pinta la tabla principal con usuarios activos.
         */
        function renderActiveUsers(rows) {
            if (!rows.length) {
                usersRows.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-muted">No hay usuarios activos</td>
                    </tr>
                `;
                return;
            }

            usersRows.innerHTML = rows.map(buildUserRowHtml).join('');
        }

        /**
         * Pinta la tabla secundaria con usuarios inactivos.
         */
        function renderInactiveUsers(rows) {
            if (!rows.length) {
                inactiveUsersRows.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-muted">No hay usuarios inactivos</td>
                    </tr>
                `;
                btnToggleInactiveUsers.classList.add('d-none');
                inactiveSection.classList.remove('visible');
                btnToggleInactiveUsers.textContent = 'Mostrar usuarios inactivos';
                return;
            }

            inactiveUsersRows.innerHTML = rows.map(buildUserRowHtml).join('');
            btnToggleInactiveUsers.classList.remove('d-none');
        }

        /**
         * ======================================================
         * ALTA DE USUARIO
         * ======================================================
         */

        /**
         * Envía al backend la creación del usuario.
         */
        async function createUser() {
            const payload = {
                username: username.value.trim(),
                password: password.value,
                display_name: displayName.value.trim(),
                role: role.value,
                phone_number: phoneNumber.value.trim(),
                phone_notifications_enabled: phoneNotificationsEnabled.checked ? 1 : 0,
                is_active: isActive.checked ? 1 : 0
            };

            if (!payload.username || !payload.password || !repeatPassword.value || !payload.display_name || !payload.role) {
                setStatus(userFormStatus, 'Todos los campos obligatorios deben estar rellenos.', 'danger');
                return;
            }

            // Validación rápida: ambas contraseñas deben coincidir
            if (payload.password !== repeatPassword.value) {
                setStatus(userFormStatus, 'Las contraseñas no coinciden.', 'danger');
                return;
            }

            // Si las llamadas están habilitadas, exigimos teléfono válido
            if (payload.phone_notifications_enabled === 1) {
                const phoneRegex = /^\+[1-9]\d{6,14}$/;

                if (!phoneRegex.test(payload.phone_number)) {
                    setStatus(
                        userFormStatus,
                        'Si las llamadas están habilitadas, el teléfono debe estar en formato internacional válido (por ejemplo, +34600111222).',
                        'danger'
                    );
                    return;
                }
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

                createUserForm.reset();
                isActive.checked = true;
                role.value = 'operador';
                repeatPassword.value = '';
                phoneNotificationsEnabled.checked = false;
                phoneNumber.value = '';

                await loadUsers();

            } catch (err) {
                console.error(err);
                setStatus(userFormStatus, 'Error de red creando el usuario.', 'danger');
            } finally {
                btnCreateUser.disabled = false;
            }
        }

        /**
         * ======================================================
         * MODAL DETALLE / EDICIÓN
         * ======================================================
         */

        /**
         * Busca un usuario en caché por ID.
         */
        function getUserFromCache(userId) {
            return usersCache.find(u => Number(u.id) === Number(userId)) || null;
        }

        /**
         * Abre el modal del usuario y carga sus datos.
         */
        function openUserEditModal(userId) {
            const user = getUserFromCache(userId);

            if (!user) {
                setStatus(userEditStatus, 'No se encontró el usuario seleccionado.', 'danger');
                return;
            }

            editUserId.value = user.id;
            editUserEmail.value = user.username || '';
            editUserDisplayName.value = user.display_name || '';
            editUserRole.value = user.role || 'operador';
            editUserIsActive.checked = Number(user.is_active) === 1;
            editUserJiraAccountId.value = user.jira_account_id || '';
            editUserPhoneNumber.value = user.phone_number || '';
            editUserPhoneNotificationsEnabled.checked = Number(user.phone_notifications_enabled) === 1;
            editUserCreatedAt.value = user.created_at || '';
            editUserUpdatedAt.value = user.updated_at || '';

            employeePhotoPlaceholder.textContent = getInitials(user.display_name || user.username || '--');

            setStatus(userEditStatus, '', 'muted');

            userEditModal.show();
        }

        /**
         * Guarda los cambios del usuario desde el modal.
         */
        async function saveUserEdit() {
            const payload = {
                user_id: Number(editUserId.value),
                display_name: editUserDisplayName.value.trim(),
                role: editUserRole.value,
                is_active: editUserIsActive.checked ? 1 : 0,
                phone_number: editUserPhoneNumber.value.trim(),
                phone_notifications_enabled: editUserPhoneNotificationsEnabled.checked ? 1 : 0
            };

            if (!payload.user_id || !payload.display_name || !payload.role) {
                setStatus(userEditStatus, 'Nombre, rol y estado son obligatorios.', 'danger');
                return;
            }

            if (payload.phone_notifications_enabled === 1) {
                const phoneRegex = /^\+[1-9]\d{6,14}$/;

                if (!phoneRegex.test(payload.phone_number)) {
                    setStatus(
                        userEditStatus,
                        'Si las llamadas están activadas, el teléfono debe estar en formato internacional válido (por ejemplo, +34600111222).',
                        'danger'
                    );
                    return;
                }
            }

            btnSaveUserEdit.disabled = true;
            setStatus(userEditStatus, 'Guardando cambios...', 'muted');

            try {
                const res = await fetch(API_UPDATE_USER, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const json = await res.json();

                if (!json.ok) {
                    setStatus(userEditStatus, json.error || 'No se pudo actualizar el usuario.', 'danger');
                    return;
                }

                setStatus(userEditStatus, 'Usuario actualizado correctamente.', 'success');

                await loadUsers();

                // Recargar datos actualizados en el modal
                const updated = getUserFromCache(payload.user_id);
                if (updated) {
                    editUserEmail.value = updated.username || '';
                    editUserDisplayName.value = updated.display_name || '';
                    editUserRole.value = updated.role || 'operador';
                    editUserIsActive.checked = Number(updated.is_active) === 1;
                    editUserJiraAccountId.value = updated.jira_account_id || '';
                    editUserPhoneNumber.value = updated.phone_number || '';
                    editUserPhoneNotificationsEnabled.checked = Number(updated.phone_notifications_enabled) === 1;
                    editUserCreatedAt.value = updated.created_at || '';
                    editUserUpdatedAt.value = updated.updated_at || '';
                    employeePhotoPlaceholder.textContent = getInitials(updated.display_name || updated.username || '--');
                }

                setTimeout(() => {
                    userEditModal.hide();
                }, 450);

            } catch (err) {
                console.error(err);
                setStatus(userEditStatus, 'Error de red actualizando el usuario.', 'danger');
            } finally {
                btnSaveUserEdit.disabled = false;
            }
        }

        /**
         * ======================================================
         * EVENTOS
         * ======================================================
         */

        createUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            await createUser();
        });

        btnReloadUsers.addEventListener('click', loadUsers);

        btnToggleInactiveUsers.addEventListener('click', () => {
            const visible = inactiveSection.classList.toggle('visible');
            btnToggleInactiveUsers.textContent = visible
                ? 'Ocultar usuarios inactivos'
                : 'Mostrar usuarios inactivos';
        });

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-open-user-modal');
            if (!btn) return;

            const userId = btn.dataset.userId || '';
            if (userId) {
                openUserEditModal(Number(userId));
            }
        });

        btnSaveUserEdit.addEventListener('click', saveUserEdit);

        /**
         * ======================================================
         * INIT
         * ======================================================
         */
        (async () => {
            try {
                userEditModal = new bootstrap.Modal(document.getElementById('userEditModal'));
                await loadUsers();
            } catch (err) {
                console.error('Error inicializando gestión de usuarios:', err);
            }
        })();
    </script>
</body>
</html>