<?php
/**
 * public/api/timeline.php
 * -------------------------------------------------------
 * Devuelve el histórico de estados de incidencias
 * agrupado por intervalos de 15 minutos.
 *
 * FIXES APLICADOS:
 * ✅ Consistencia de slots (HH:mm)
 * ✅ Estado inicial correcto
 * ✅ Evita incoherencias en sobrescritura de slots
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';

header('Content-Type: application/json; charset=utf-8');

try {

    // --------------------------------------------------
    // 1) CONEXIÓN A BASE DE DATOS
    // --------------------------------------------------
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        env('DB_HOST'),
        env('DB_PORT'),
        env('DB_NAME')
    );

    $pdo = new PDO(
        $dsn,
        env('DB_USER'),
        env('DB_PASS'),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // --------------------------------------------------
    // 2) VALIDACIÓN DE PARÁMETROS
    // --------------------------------------------------
    $from = $_GET['from'] ?? null;
    $to   = $_GET['to']   ?? null;

    if (!$from || !$to) {
        json_response([
            'ok'    => false,
            'error' => 'Parámetros from y to obligatorios'
        ], 400);
    }

    $project = $_GET['project'] ?? null;

    // --------------------------------------------------
    // 3) CONSULTA BASE
    // --------------------------------------------------
    $sql = "
        SELECT
            jira_key,
            summary,
            status_name,
            snapshot_time
        FROM issue_timeline
        WHERE snapshot_time <= :from
    ";

    $params = [
        ':from'   => $from,
    ];

    if ($project) {
        $sql .= " AND jira_key LIKE :project";
        $params[':project'] = $project . '%';
    }

    // 🔑 IMPORTANTE: orden cronológico correcto
    $sql .= " ORDER BY jira_key, snapshot_time";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }

    $st->execute();
    $rows = $st->fetchAll();

    // --------------------------------------------------
    // 3.1) ESTADO INICIAL (ANTES DE LA VENTANA)
    // --------------------------------------------------
    $sqlInitial = "
        SELECT
            jira_key,
            status_name
        FROM issue_timeline t
        WHERE snapshot_time = (
            SELECT MAX(snapshot_time)
            FROM issue_timeline
            WHERE jira_key = t.jira_key
              AND snapshot_time <= :from
          )
    ";

    $stInit = $pdo->prepare($sqlInitial);
    $stInit->bindValue(':from', $from);
    $stInit->execute();

    $initialStates = [];
    foreach ($stInit->fetchAll() as $rowInit) {
        $initialStates[$rowInit['jira_key']] = $rowInit['status_name'];
    }

    if (!$rows) {
        json_response([
            'ok'   => true,
            'from' => $from,
            'to'   => $to,
            'step' => '15min',
            'data' => []
        ]);
    }

    // --------------------------------------------------
    // 4) AGRUPACIÓN POR INCIDENCIA
    // --------------------------------------------------
    $timeline = [];

    foreach ($rows as $r) {

        $key = $r['jira_key'];

        if (!isset($timeline[$key])) {
            $timeline[$key] = [
                'jira_key'      => $key,
                'summary'       => $r['summary'],
                'initial_state' => $initialStates[$key] ?? null,
                'states'        => []
            ];
        }

        // --------------------------------------------------
        // NORMALIZAR SLOT A BLOQUE DE 15 MINUTOS
        // --------------------------------------------------
        $timestamp = strtotime($r['snapshot_time']);
        $slot = floor($timestamp / 900) * 900;

        // 🔑 CLAVE: formato EXACTO HH:mm (igual que frontend)
        $slotTime = date('H:i', $slot);

        // --------------------------------------------------
        // IMPORTANTE:
        // Nos quedamos con el último estado del slot
        // (gracias al ORDER BY ya viene en orden correcto)
        // --------------------------------------------------
        $timeline[$key]['states'][$slotTime] = $r['status_name'];
    }

        // --------------------------------------------------
        // 5.1) ÚLTIMA SYNC REAL
        // --------------------------------------------------
        $stLast = $pdo->query("
        
        SELECT value
            FROM sync_metadata
            WHERE name = 'issues_last_sync'

        LIMIT 1
        ");
        $lastSync = $stLast->fetchColumn();

    // --------------------------------------------------
    // 5) RESPUESTA FINAL
    // --------------------------------------------------
    json_response([
        'ok'   => true,
        'from' => $from,
        'to'   => $to,
        'last_sync' => $lastSync,
        'step' => '15min',
        'data' => array_values($timeline)
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error obteniendo timeline'
    ], 500);
}