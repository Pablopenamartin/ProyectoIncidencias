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
    // 3) HISTÓRICO HASTA EL FINAL DE LA VENTANA
    // --------------------------------------------------
    // En vez de reconstruir la timeline con TODO lo anterior a :from
    // y arrastrarlo indefinidamente, traemos el histórico válido hasta :to
    // y decidimos en PHP qué filas representan una transición real de estado.
    //
    // Reglas de esta corrección:
    // - Ignorar registros con snapshot_time inválido
    // - No pintar ruido legacy (snapshot_sync repetidos del mismo estado)
    // - Solo incluir incidencias que tengan al menos un cambio de estado
    //   dentro de la ventana [from, to]
    // - Normalizar cualquier cierre a "Completado" para que el frontend
    //   actual siga funcionando sin cambios adicionales
    $sql = "
        SELECT
            jira_key,
            summary,
            status_name,
            estado_categoria,
            snapshot_time,
            event_type
        FROM issue_timeline
        WHERE snapshot_time IS NOT NULL
          AND snapshot_time <> '0000-00-00 00:00:00'
          AND snapshot_time <= :to
    ";

    $params = [
        ':to' => $to,
    ];

    if ($project) {
        $sql .= " AND jira_key LIKE :project";
        $params[':project'] = $project . '%';
    }

    $sql .= " ORDER BY jira_key, snapshot_time";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }

    $st->execute();
    $rows = $st->fetchAll();

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
    // 3.1) NORMALIZADOR DE ESTADO PARA LA TIMELINE
    // --------------------------------------------------
    // Unificamos todos los estados de cierre en "Completado"
    // para no depender de si Jira devuelve "Closed", "Cancelado",
    // "Completed" o la categoría de negocio "cerrado_unificado".
    $normalizeTimelineStatus = static function (array $row): string {
        $estadoCategoria = (string)($row['estado_categoria'] ?? '');
        $statusName      = (string)($row['status_name'] ?? '');

        if ($estadoCategoria === 'cerrado_unificado') {
            return 'Completado';
        }

        return $statusName;
    };

    $fromTs = strtotime($from);
    $toTs   = strtotime($to);

    // --------------------------------------------------
    // 3.2) AGRUPAR HISTÓRICO POR INCIDENCIA
    // --------------------------------------------------
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['jira_key']][] = $row;
    }

    // --------------------------------------------------
    // 3.3) ESTADO ACTUAL / VISIBILIDAD MAESTRA
    // --------------------------------------------------
    // La tabla issues decide si una incidencia sigue activa (visible = 1)
    // o si ya está cerrada y no debe volver a mostrarse.
    //
    // Regla de negocio:
    // - visible = 1  -> la incidencia debe salir aunque no haya cambiado
    //                    dentro de la ventana, mostrando su estado continuo
    // - visible = 0  -> solo debe salir si su cierre ocurrió dentro
    //                    de la ventana visible
    $sqlCurrent = "
        SELECT
            jira_key,
            summary,
            status_name,
            estado_categoria,
            visible
        FROM issues
    ";

    $currentParams = [];

    if ($project) {
        $sqlCurrent .= " WHERE jira_key LIKE :project";
        $currentParams[':project'] = $project . '%';
    }

    $stCurrent = $pdo->prepare($sqlCurrent);

    foreach ($currentParams as $k => $v) {
        $stCurrent->bindValue($k, $v);
    }

    $stCurrent->execute();

    $currentIssues = [];
    foreach ($stCurrent->fetchAll() as $rowCurrent) {
        $currentIssues[$rowCurrent['jira_key']] = [
            'summary'          => (string)($rowCurrent['summary'] ?? ''),
            'status_name'      => (string)($rowCurrent['status_name'] ?? ''),
            'estado_categoria' => (string)($rowCurrent['estado_categoria'] ?? ''),
            'visible'          => (int)($rowCurrent['visible'] ?? 0),
        ];
    }

    // Unimos claves que existan en histórico y/o en issues.
    // Así no perdemos incidencias activas aunque no tengan transición reciente.
    $allKeys = array_values(array_unique(array_merge(
        array_keys($grouped),
        array_keys($currentIssues)
    )));


    
    foreach ($allKeys as $jiraKey) {
        $events = $grouped[$jiraKey] ?? [];


        
        $summary                 = '';
        $initialState            = null;
        $lastKnownState          = null;
        $states                  = [];
        $hasRelevantChangeInRange = false;
        $hasCloseInRange         = false;

        // Estado actual maestro desde issues
        $currentIssueMeta   = $currentIssues[$jiraKey] ?? null;
        $isCurrentlyVisible = $currentIssueMeta && (int)$currentIssueMeta['visible'] === 1;

        // Si no encontramos summary en el histórico, la recuperamos de issues
        if ($currentIssueMeta && $summary === '') {
            $summary = $currentIssueMeta['summary'];
        }


        foreach ($events as $event) {
            $summary = (string)($event['summary'] ?? $summary);

            $eventTs = strtotime((string)$event['snapshot_time']);
            if ($eventTs === false) {
                continue;
            }

            $currentState = $normalizeTimelineStatus($event);

            // --------------------------------------------------
            // Estado inicial antes de la ventana
            // --------------------------------------------------
            if ($eventTs <= $fromTs) {
                $initialState   = $currentState;
                $lastKnownState = $currentState;
                continue;
            }

            // Seguridad extra: nunca procesar fuera de la ventana
            if ($eventTs > $toTs) {
                continue;
            }

            // --------------------------------------------------
            // CAMBIO REAL DE ESTADO
            // --------------------------------------------------
            // Consideramos relevante una fila SOLO si el estado
            // normalizado es distinto al último estado conocido.
            //
            // Esto elimina:
            // - snapshot_sync repetidos del mismo estado
            // - priority_change / summary_change / assignee_change
            //   cuando NO alteran realmente el estado
            //
            // Y conserva:
            // - status_change reales
            // - snapshots legacy que sí implican transición
            // - cierres reales que lleguen como cerrado_unificado
            if ($currentState === $lastKnownState) {
                continue;
            }

            // Slot de 15 minutos, alineado con el frontend
            $slot = floor($eventTs / 900) * 900;
            $slotTime = date('H:i', $slot);

            $states[$slotTime] = $currentState;
            $lastKnownState = $currentState;
            $hasRelevantChangeInRange = true;

            // Si el cambio real dentro del rango es un cierre,
            // permitimos mostrar la incidencia aunque ya no sea visible.
            if ($currentState === 'Completado') {
                $hasCloseInRange = true;
            }
                // Si no encontramos estado inicial en el histórico pero la incidencia
            // sigue existiendo en issues, usamos su estado actual como referencia.
            if ($initialState === null && $currentIssueMeta) {
                $initialState = $normalizeTimelineStatus([
                    'status_name'      => $currentIssueMeta['status_name'],
                    'estado_categoria' => $currentIssueMeta['estado_categoria'],
                ]);
            }

            // Si tampoco tenemos summary desde timeline, usar el actual de issues
            if ($summary === '' && $currentIssueMeta) {
                $summary = $currentIssueMeta['summary'];
            }
        }

        // --------------------------------------------------
        // REGLA FINAL DE VISIBILIDAD
        // --------------------------------------------------
        // Mostrar si:
        // - la incidencia sigue visible (open / activa), aunque no haya cambiado
        // - o se cerró dentro de la ventana visible
        //
        // No mostrar si:
        // - ya está cerrada/invisible
        // - y su cierre ocurrió antes de la ventana
        if (!$isCurrentlyVisible && !$hasCloseInRange) {
            continue;
        }

        // Si no hay ningún estado utilizable, no se puede pintar la fila
        if ($initialState === null && empty($states)) {
            continue;
        }

        
        
    $timelineRows[] = [
        'jira_key'      => $jiraKey,
        'summary'       => $summary,
        'initial_state' => $initialState,
        'states'        => $states,
    ];

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
        'data' => array_values($timelineRows)
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error obteniendo timeline'
    ], 500);
}