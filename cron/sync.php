<?php
/**
 * public/api/sync.php
 * Lanzar sincronización manual (botón del frontend)
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/services/SyncService.php';
require_once __DIR__ . '/../../app/services/SnapshotService.php';

send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

try {
    $sync = new SyncService();
    $r1 = $sync->runSync(true);

    $snap = new SnapshotService();
    $r2 = $snap->createSnapshot();

    json_response([
        'ok' => true,
        'sync' => $r1,
        'snapshot_id' => $r2
    ]);

} catch (Throwable $t) {
    json_response([
        'ok' => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error en sincronización'
    ], 500);
}