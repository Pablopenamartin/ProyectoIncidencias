<?php
/**
 * public/logout.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Cierra la sesión del usuario actual y lo redirige al login.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/helpers/Auth.php para destruir la sesión.
 */

require_once __DIR__ . '/../app/helpers/Auth.php';

auth_logout();

header('Location: login.php');
exit;