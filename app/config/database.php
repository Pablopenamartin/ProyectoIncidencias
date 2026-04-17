<?php
/**
 * app/config/database.php
 * -----------------------
 * Crea una conexión PDO a la base de datos usando las constantes
 * definidas en app/config/constants.php. Expone una función
 * getPDO() con singleton simple para reutilizar la conexión.
 */

// Asegúrate de cargar constants.php antes en el flujo de tu app.
// require_once __DIR__ . '/constants.php'; // ← descomenta si este archivo se llama directo

/**
 * getPDO
 * ------
 * Devuelve una instancia PDO conectada a MySQL/MariaDB.
 * - Usa DSN con host, puerto y nombre de BD desde el .env
 * - Charset utf8mb4 para soportar caracteres y emojis
 * - Modo de errores: excepciones
 * - Emula prepares deshabilitado (mejor seguridad y rendimiento real)
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Construcción del DSN para MySQL/MariaDB
    $host = DB_HOST;
    $port = DB_PORT ?: 3306;
    $name = DB_NAME;

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    // Opciones recomendadas para PDO
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // lanza excepciones en errores
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch en arrays asociativos
        PDO::ATTR_EMULATE_PREPARES   => false,                  // usa prepares nativos del driver
        PDO::ATTR_TIMEOUT            => 5, // ← evita bloqueos largos si algo va mal
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Configura zona horaria a nivel de sesión (opcional, útil para timestamps coherentes)
        $tz = APP_ENV === 'local' ? date_default_timezone_get() : 'UTC';
        $stmt = $pdo->prepare("SET time_zone = :tz");
        $stmt->execute([':tz' => date('P')]); // establece el offset +HH:MM actual del servidor PHP

        return $pdo;
    } catch (PDOException $e) {
        // Mensaje claro en desarrollo; en prod conviene loguear y mostrar genérico
        if (APP_DEBUG) {
            die("ERROR BD: No se pudo conectar a la base de datos. Detalle: " . $e->getMessage());
        }
        http_response_code(500);
        die("ERROR BD: No se pudo conectar a la base de datos.");
    }
}

/**
 * db_healthcheck
 * --------------
 * Función auxiliar opcional para verificar la conexión.
 * Realiza una consulta trivial "SELECT 1".
 */
function db_healthcheck(): bool
{
    try {
        $pdo = getPDO();
        $pdo->query("SELECT 1");
        return true;
    } catch (Throwable $t) {
        return false;
    }
}