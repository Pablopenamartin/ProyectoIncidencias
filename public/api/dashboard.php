<?php
/**
 * public/api/dashboard.php
 * ------------------------------------------------------------
 * Endpoint REST para obtener el dashboard superior con la nueva lógica:
 *
 *  - Último snapshot
 *  - Penúltimo snapshot
 *  - Diferencias (+/-)
 *  - Total de tickets abiertos
 *  - Distribución según tus grupos:
 *      esperando_ayuda
 *      escalated
 *      en_curso
 *      pending
 *      waiting_approval
 *      waiting_customer
 *      cerrado_unificado
 *      other
 *
 * Este endpoint alimenta el panel superior del index.php.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';

send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

try {

    // 1) Conexión PDO
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        env('DB_HOST'),
        env('DB_PORT'),
        env('DB_NAME')
    );

    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);


    // 2) Obtener último snapshot
    $sqlLast = "SELECT * FROM snapshots ORDER BY id DESC LIMIT 1";
    $last = $pdo->query($sqlLast)->fetch();

    if (!$last) {
        json_response([
            'ok'       => true,
            'msg'      => 'No hay snapshots todavía',
            'last'     => null,
            'previous' => null,
            'diff'     => null
        ], 200);
        return;
    }

    // 3) Obtener snapshot anterior
    $sqlPrev = "SELECT * FROM snapshots WHERE id < :id ORDER BY id DESC LIMIT 1";
    $st = $pdo->prepare($sqlPrev);
    $st->bindValue(':id', $last['id'], PDO::PARAM_INT);
    $st->execute();
    $prev = $st->fetch();


    // 4) Campos que queremos calcular diferencia
    // ✅ Actualizado según tu nueva lógica
    $fields = [
        'esperando_ayuda',
        'escalated',
        'en_curso',
        'pending',
        'waiting_approval',
        'waiting_customer',
        'cerrado_unificado',
        'other',

        // Prioridades
        'p1', 'p2', 'p3', 'p4', 'p5',

        // Total
        'total_abiertas'
    ];

    // Calcular diff
    $diff = [];
    if ($prev) {
        foreach ($fields as $f) {
            $diff[$f] = ((int)$last[$f]) - ((int)$prev[$f]);
        }
    } else {
        foreach ($fields as $f) {
            $diff[$f] = 0;
        }
    }


    // 5) Respuesta JSON
    json_response([
        'ok'              => true,
        'last'            => $last,
        'previous'        => $prev ?: null,
        'diff'            => $diff,
        'last_update'     => $last['created_at'],
        'previous_update' => $prev['created_at'] ?? null
    ], 200);


} catch (Throwable $t) {

    $msg = APP_DEBUG ? $t->getMessage() : 'Error obteniendo dashboard';

    json_response([
        'ok'    => false,
        'error' => $msg
    ], 500);
}
