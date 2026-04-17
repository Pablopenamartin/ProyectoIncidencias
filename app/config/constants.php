<?php
/**
 * constants.php
 * --------------
 * Cargador robusto del archivo .env y definidor de constantes globales.
 * Se encarga de:
 *   - Leer .env desde la raíz del proyecto
 *   - Limpiar comentarios inline
 *   - Eliminar caracteres invisibles (BOM, zero-width...)
 *   - Validar claves críticas (Jira y BD)
 *   - Configurar TIMEZONE
 */

// -----------------------------------------------------------
// 1) Localiza el archivo .env en la raíz del proyecto
// -----------------------------------------------------------
// Detecta la raíz del proyecto (carpeta que contiene /app y /public)

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/../../'));
}

// Función env(key, default) si no existe
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $val = $_ENV[$key] ?? getenv($key);
        return ($val === false || $val === null || $val === '') ? $default : $val;
    }
}

// ---------------------
// Carga del archivo .env
// ---------------------
$envFile = BASE_PATH . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    // Lee líneas ignorando vacías y comentarios
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // key=value (toma el primer '=' como separador)
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ($k === '') continue;

        // No sobrescribir si ya hay valor en entorno
        if (getenv($k) === false && !array_key_exists($k, $_ENV)) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

$envFile = BASE_PATH . DIRECTORY_SEPARATOR . '.env';
$envVars = [];
if (is_string($envFile) && $envFile !== '' && is_file($envFile) && is_readable($envFile)) {
    $rawLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($rawLines)) {
        foreach ($rawLines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k === '') continue;

            // No sobrescribir si ya viene del entorno
            if (getenv($k) === false && !array_key_exists($k, $_ENV)) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
            $envVars[$k] = $v;
        }
    }
}

foreach ($rawLines as $rawLine) {
    // 1) Recorte básico y eliminación de BOM/caracteres invisibles frecuentes
    //    \xEF\xBB\xBF = UTF-8 BOM, \xC2\xA0 = NBSP, \xE2\x80\x8B = ZWSP
    $line = trim($rawLine, " \t\n\r\0\x0B\xEF\xBB\xBF\xC2\xA0\xE2\x80\x8B");
    if ($line === '' || $line[0] === '#') {
        continue; // comentario o línea en blanco
    }

    // 2) Permitir "export VAR=valor" (estilo shell); si existe, lo quitamos
    if (str_starts_with($line, 'export ')) {
        $line = substr($line, 7);
        $line = ltrim($line);
        if ($line === '') {
            continue;
        }
    }

    // 3) Encontrar el primer '=' real que NO esté dentro de comillas
    //    para dividir "clave=valor" de forma segura.
    $eqPos      = -1;
    $inSingle   = false;
    $inDouble   = false;
    $len        = strlen($line);

    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];

        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
        } elseif ($ch === '=' && !$inSingle && !$inDouble) {
            $eqPos = $i;
            break;
        }
    }

    if ($eqPos === -1) {
        // No hay '=' fuera de comillas → línea inválida en formato .env, la ignoramos
        continue;
    }

    $key   = substr($line, 0, $eqPos);
    $value = substr($line, $eqPos + 1);

    // 4) Recorta espacios alrededor de clave/valor
    $key   = trim($key);
    $value = trim($value);

    if ($key === '') {
        continue; // clave vacía no válida
    }

    // 5) Eliminar comentario inline "# ..." SOLO si está fuera de comillas
    $inSingle = false;
    $inDouble = false;
    $hashCut  = -1;
    for ($i = 0; $i < strlen($value); $i++) {
        $ch = $value[$i];
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
        } elseif ($ch === '#' && !$inSingle && !$inDouble) {
            $hashCut = $i;
            break;
        }
    }
    if ($hashCut !== -1) {
        $value = substr($value, 0, $hashCut);
        $value = rtrim($value);
    }

    // 6) Quitar comillas envolventes si el valor está rodeado por "" o ''
    if (
        (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') ||
        (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'")
    ) {
        $value = substr($value, 1, -1);
    }

    // 7) Limpieza final de espacios y caracteres invisibles residuales
    $value = trim($value, " \t\n\r\0\x0B\xEF\xBB\xBF\xC2\xA0\xE2\x80\x8B");

    // 8) Si después de todo el valor queda null/empty, lo permitimos (p. ej., DB_PASS vacía)
    //    pero nos aseguramos de registrar siempre la clave.
    $envVars[$key] = $value;
}

// -----------------------------------------------------------
// 3) Función global env() para recuperar valores
// -----------------------------------------------------------
function env(string $key, $default = null) {
    global $envVars;
    return $envVars[$key] ?? $default;
}

// -----------------------------------------------------------
// 4) Configurar timezone desde .env
// -----------------------------------------------------------
date_default_timezone_set(env('TIMEZONE', 'Europe/Madrid'));

// -----------------------------------------------------------
// 5) Definir constantes globales (opcionales pero útiles)
// -----------------------------------------------------------
define('APP_ENV', env('APP_ENV', 'local'));
define('APP_DEBUG', env('APP_DEBUG', true));

define('DB_HOST', env('DB_HOST'));
define('DB_PORT', env('DB_PORT'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));

define('JIRA_SITE', env('JIRA_SITE'));
define('JIRA_EMAIL', env('JIRA_EMAIL'));
define('JIRA_API_TOKEN', env('JIRA_API_TOKEN'));
define('JIRA_PROJECT_KEY', env('JIRA_PROJECT_KEY'));
define('JIRA_JQL_BASE', env('JIRA_JQL_BASE'));

define('SYNC_INTERVAL_MINUTES', env('SYNC_INTERVAL_MINUTES', 5));
define('ALLOWED_ORIGINS', env('ALLOWED_ORIGINS', '*'));
define('UI_FRAMEWORK', env('UI_FRAMEWORK', 'bootstrap'));

// -----------------------------------------------------------
// 6) Validar que las variables críticas existen
// -----------------------------------------------------------

$requiredKeys = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'JIRA_SITE',
    'JIRA_EMAIL',
    'JIRA_API_TOKEN',
    'JIRA_PROJECT_KEY'
];

foreach ($requiredKeys as $rk) {
    if (!env($rk)) {
        die("ERROR: Falta en .env la variable obligatoria: $rk");
    }
}

// -----------------------------------------------------------
// Listo, constants.php ya ha cargado y definido todo.
// -----------------------------------------------------------