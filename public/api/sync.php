<?php
/**
 * public/api/sync.php
 * -------------------
 * Lanza la sincronización Jira → BD manualmente vía HTTP.
 * Ahora permite:
 *   - FULL SYNC si viene ?full=1
 *   - Incremental si no se indica nada
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/services/SyncService.php';

send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

try {
    // ✅ Detectar FULL SYNC (forzado por el botón del index)
    $full = isset($_GET['full']) && $_GET['full'] == '1';

    $sync = new SyncService();
    // 🔹 Pasamos el parámetro a runSync
    $result = $sync->runSync($full);

    json_response($result, 200);

} catch (Throwable $t) {
    $msg = APP_DEBUG ? $t->getMessage() : 'Error en sincronización';
    json_response(['ok' => false, 'error' => $msg], 500);
}