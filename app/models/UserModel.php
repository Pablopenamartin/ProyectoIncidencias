<?php
/**
 * app/models/UserModel.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Este modelo gestiona los usuarios locales de la aplicación.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar la conexión PDO.
 * - Será usado por el servicio de alta de usuarios en Jira y por el login.
 *
 * FUNCIONES PRINCIPALES:
 * - findByUsername(): busca un usuario por username/email.
 * - createUser(): inserta un usuario nuevo en la tabla users.
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
     * createUser
     * --------------------------------------------------------------
     * Inserta un usuario nuevo en la tabla users.
     *
     * QUÉ HACE:
     * - Guarda username, password hash, nombre visible, rol y accountId de Jira
     * - Guarda también si el usuario está activo
     * - Devuelve el ID insertado
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
                is_active,
                created_at,
                updated_at
            ) VALUES (
                :username,
                :password_hash,
                :display_name,
                :role,
                :jira_account_id,
                :is_active,
                NOW(),
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':username'        => $data['username'],
            ':password_hash'   => $data['password_hash'],
            ':display_name'    => $data['display_name'],
            ':role'            => $data['role'],
            ':jira_account_id' => $data['jira_account_id'],
            ':is_active'       => !empty($data['is_active']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
