<?php
/**
 * app/models/UserModel.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Este modelo gestiona los usuarios locales de la aplicación.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar la conexión PDO.
 * - Será usado por:
 *   - login.php
 *   - JiraUserProvisionService.php
 *   - admin_update_user.php
 *   - futuras funciones de llamadas automáticas
 *
 * FUNCIONES PRINCIPALES:
 * - findByUsername(): busca un usuario por username/email.
 * - findById(): busca un usuario por ID.
 * - createUser(): inserta un usuario nuevo en la tabla users.
 * - countActiveAdmins(): cuenta administradores activos.
 * - countUsersWithPhoneNotificationsEnabled(): cuenta usuarios activos con llamadas habilitadas.
 * - updateUserAdminData(): actualiza los datos administrativos editables de un usuario.
 */

require_once __DIR__ . '/../config/database.php';

class UserModel
{
    /**
     * Conexión PDO reutilizable del sistema.
     */
    private PDO $pdo;

    /**
     * __construct
     * --------------------------------------------------------------
     * Inicializa el modelo reutilizando la conexión PDO global.
     *
     * @param PDO|null $pdo Conexión inyectada opcional
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * findByUsername
     * --------------------------------------------------------------
     * Busca un usuario local por username/email.
     *
     * QUÉ HACE:
     * - Consulta la tabla users
     * - Busca coincidencia exacta por username
     * - Devuelve la fila completa si existe
     *
     * @param string $username Email/username del usuario
     * @return array|null Datos del usuario o null si no existe
     */
    public function findByUsername(string $username): ?array
    {
        $sql = "
            SELECT
                id,
                username,
                password_hash,
                display_name,
                role,
                jira_account_id,
                phone_number,
                phone_notifications_enabled,
                is_active,
                created_at,
                updated_at
            FROM users
            WHERE username = :username
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':username' => $username
        ]);

        $row = $st->fetch();

        return $row ?: null;
    }

    /**
     * findById
     * --------------------------------------------------------------
     * Busca un usuario local por su ID interno.
     *
     * QUÉ HACE:
     * - Consulta la tabla users
     * - Busca coincidencia exacta por id
     * - Devuelve la fila completa si existe
     *
     * @param int $userId ID interno del usuario
     * @return array|null Datos del usuario o null si no existe
     */
    public function findById(int $userId): ?array
    {
        $sql = "
            SELECT
                id,
                username,
                password_hash,
                display_name,
                role,
                jira_account_id,
                phone_number,
                phone_notifications_enabled,
                is_active,
                created_at,
                updated_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':id' => $userId
        ]);

        $row = $st->fetch();

        return $row ?: null;
    }

    /**
     * createUser
     * --------------------------------------------------------------
     * Inserta un usuario nuevo en la tabla users.
     *
     * QUÉ HACE:
     * - Guarda username, password hash, nombre visible, rol y accountId de Jira
     * - Guarda también:
     *   - phone_number
     *   - phone_notifications_enabled
     *   - is_active
     * - Devuelve el ID insertado
     *
     * NOTA:
     * - phone_number puede venir vacío/null
     * - phone_notifications_enabled por defecto será 0 si no se pasa
     *
     * @param array $data Datos del usuario ya validados
     * @return int ID del nuevo usuario insertado
     */
    public function createUser(array $data): int
    {
        $sql = "
            INSERT INTO users (
                username,
                password_hash,
                display_name,
                role,
                jira_account_id,
                phone_number,
                phone_notifications_enabled,
                is_active,
                created_at,
                updated_at
            ) VALUES (
                :username,
                :password_hash,
                :display_name,
                :role,
                :jira_account_id,
                :phone_number,
                :phone_notifications_enabled,
                :is_active,
                NOW(),
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':username'                    => $data['username'],
            ':password_hash'               => $data['password_hash'],
            ':display_name'                => $data['display_name'],
            ':role'                        => $data['role'],
            ':jira_account_id'             => $data['jira_account_id'],
            ':phone_number'                => !empty($data['phone_number']) ? $data['phone_number'] : null,
            ':phone_notifications_enabled' => !empty($data['phone_notifications_enabled']) ? 1 : 0,
            ':is_active'                   => !empty($data['is_active']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * countActiveAdmins
     * --------------------------------------------------------------
     * Cuenta cuántos administradores activos existen actualmente.
     *
     * QUÉ HACE:
     * - Cuenta solo usuarios con:
     *   - role = admin
     *   - is_active = 1
     * - Permite excluir un usuario concreto del conteo
     *
     * @param int|null $excludeUserId ID a excluir del conteo (opcional)
     * @return int Número de administradores activos
     */
    public function countActiveAdmins(?int $excludeUserId = null): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM users
            WHERE role = 'admin'
              AND is_active = 1
        ";

        $params = [];

        if ($excludeUserId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeUserId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return (int)$st->fetchColumn();
    }

    /**
     * countUsersWithPhoneNotificationsEnabled
     * --------------------------------------------------------------
     * Cuenta cuántos usuarios activos tienen llamadas habilitadas.
     *
     * QUÉ HACE:
     * - Cuenta solo usuarios con:
     *   - is_active = 1
     *   - phone_notifications_enabled = 1
     * - Permite excluir un usuario concreto del conteo
     *
     * @param int|null $excludeUserId ID a excluir del conteo (opcional)
     * @return int Número de usuarios activos con llamadas habilitadas
     */
    public function countUsersWithPhoneNotificationsEnabled(?int $excludeUserId = null): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM users
            WHERE is_active = 1
              AND phone_notifications_enabled = 1
        ";

        $params = [];

        if ($excludeUserId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeUserId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return (int)$st->fetchColumn();
    }

    /**
     * updateUserAdminData
     * --------------------------------------------------------------
     * Actualiza los datos administrativos editables de un usuario.
     *
     * QUÉ HACE:
     * - Permite cambiar:
     *   - display_name
     *   - role
     *   - is_active
     *   - phone_number
     *   - phone_notifications_enabled
     *
     * NO TOCA:
     * - username/email
     * - password_hash
     * - jira_account_id
     *
     * @param int         $userId ID del usuario
     * @param string      $displayName Nombre visible
     * @param string      $role Rol final (admin|operador)
     * @param int         $isActive Estado activo (1|0)
     * @param string|null $phoneNumber Teléfono en formato internacional
     * @param int         $phoneNotificationsEnabled Llamadas habilitadas (1|0)
     * @return bool True si la actualización fue correcta
     */
    public function updateUserAdminData(
        int $userId,
        string $displayName,
        string $role,
        int $isActive,
        ?string $phoneNumber,
        int $phoneNotificationsEnabled
    ): bool {
        $sql = "
            UPDATE users
            SET
                display_name = :display_name,
                role = :role,
                is_active = :is_active,
                phone_number = :phone_number,
                phone_notifications_enabled = :phone_notifications_enabled,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);

        return $st->execute([
            ':display_name'                => $displayName,
            ':role'                        => $role,
            ':is_active'                   => $isActive,
            ':phone_number'                => ($phoneNumber !== null && $phoneNumber !== '') ? $phoneNumber : null,
            ':phone_notifications_enabled' => $phoneNotificationsEnabled,
            ':id'                          => $userId,
        ]);
    }
}