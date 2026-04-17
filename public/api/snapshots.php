<?php
/**
 * public/api/snapshots.php
 * ------------------------------------------------------------
 * Endpoint REST para obtener la lista de snapshots históricos.
 *
 * Cada snapshot refleja:
 *   - Totales por estado
 *   - Totales por prioridad
 *   - Total de incidencias abiertas
 *   - Fecha del snapshot
 *
 * Uso:
 *   GET /api/snapshots.php
 *
 * Respuesta:
 *   {
 *     ok: true,
 *     data: [
 *        { id, created_at, esperando_ayuda, escalated, ... p5, total_abiertas },
 *        ...
 *     ]
 *   }
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';

send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

try {

    /**
     * ------------------------------------------------------------
     * 1) Conexión PDO
     * ------------------------------------------------------------
     */
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

    /**
     * ------------------------------------------------------------
     * 2) Consultar lista de snapshots
     * ------------------------------------------------------------
     * Ordenados por ID DESC (los más recientes primero)
     */
    $sql = "SELECT
                id,
                created_at,

                -- ESTADOS
                esperando_ayuda,
                escalated,
                en_curso,
                pending,
                waiting_approval,
                waiting_customer,
                other,

                -- CERRADOS
                closed,
                completed,
                cancelled,

                -- PRIORIDADES
                p1, p2, p3, p4, p5,

                -- TOTAL DE ABIERTAS
                total_abiertas

            FROM snapshots
            ORDER BY id DESC";

    $rows = $pdo->query($sql)->fetchAll();


    /**
     * ------------------------------------------------------------
     * 3) Respuesta final
     * ------------------------------------------------------------
     */
    json_response([
        'ok'   => true,
        'data' => $rows
    ], 200);

} catch (Throwable $t) {

    $msg = APP_DEBUG ? $t->getMessage() : 'Error obteniendo snapshots';

    json_response([
        'ok'   => false,
        'error'=> $msg
    ], 500);
}