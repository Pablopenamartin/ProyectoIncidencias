<?php
/**
 * app/models/UserModel.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Modelo encargado de gestionar usuarios locales de la aplicación.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar PDO.
 * - Será usado por el backend de alta de usuarios.
 *
 * FUNCIONES PRINCIPALES:
 * - findByUsername(): busca usuario local por username.
 * - createUser(): inserta un usuario local ya validado.
 */

require_once __DIR__ . '/../config/database.php';

class UserModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * Busca un usuario local por username.
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
        $st->execute([':username' => $username]);

        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Inserta un usuario local ya validado.
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
