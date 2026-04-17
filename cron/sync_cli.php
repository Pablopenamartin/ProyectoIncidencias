<?php   //LLAMA A SYNCSERVICE CADA 15 MIN
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/services/SyncService.php';

try {
    $sync = new SyncService();
    $result = $sync->runSync(true);  // Esto genera un snapshot

    echo "SYNC OK\n";
    echo "Inserted or updated: " . $result['inserted'] . "\n";
    echo "Snapshot ID: " . $result['snapshot_id'] . "\n";

} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}