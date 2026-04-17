<?php
/**
 * public/api/issues.php
 * -------------------------------------------------------
 * ENDPOINT REST:
 *  ✅ Listar incidencias con filtros reales
 *  ✅ Ver detalle de una incidencia (key=)
 *
 * Filtros soportados:
 *   estado = esperando_ayuda | escalated | en_curso |
 *            pending | waiting_approval | waiting_customer |
 *            cerrado_unificado | other
 *
 *   prioridad = 1..5
 *   project   = texto
 *   q         = texto libre
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';

send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

try {

    // -------------------------------------------------------------
    // 1) CONEXIÓN BD
    // -------------------------------------------------------------
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


    // -------------------------------------------------------------
    // 2) DETALLE (si viene ?key=)
    // -------------------------------------------------------------
    if (isset($_GET['key']) && trim($_GET['key']) !== '') {

        $sql = "
            SELECT
                jira_id,
                jira_key,
                summary,
                status_name AS status,
                estado_categoria,
                priority_name AS priority,
                prioridad_nivel AS priority_level,
                assignee_display_name AS assignee,
                project_key,
                created_at,
                updated_at,
                urgency_name,
                impact_name,
                (assignee_account_id IS NOT NULL) AS assigned
            FROM issues
            WHERE jira_key = :key
            LIMIT 1
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':key', trim($_GET['key']));
        $st->execute();

        $item = $st->fetch();

        if (!$item) {
            json_response(['ok' => false, 'error' => 'Incidencia no encontrada'], 404);
        }

        json_response(['ok' => true, 'item' => $item]);
        return;
    }


    // -------------------------------------------------------------
    // 3) LISTADO
    // -------------------------------------------------------------

    // Paginación
    $limit  = isset($_GET['limit'])  ? max(1, min(200, (int)$_GET['limit'])) : 20;
    $offset = isset($_GET['offset']) ? max(0,             (int)$_GET['offset']) : 0;

    // Filtros
    $estado    = isset($_GET['estado'])    ? trim($_GET['estado']) : '';
    $prioridad = isset($_GET['prioridad']) ? (int)$_GET['prioridad'] : 0;
    $project   = isset($_GET['project'])   ? trim($_GET['project']) : '';
    $q         = isset($_GET['q'])         ? trim($_GET['q']) : '';

    $where  = ["visible = 1"];
    $params = [];


    // ✅ ESTADO (categoría usada en dashboard y snapshots)
    if ($estado !== '') {
        $where[] = "estado_categoria = :estado";
        $params[':estado'] = $estado;
    }

    // ✅ PRIORIDAD (1 = Critical, 5 = Lowest)
    if ($prioridad >= 1 && $prioridad <= 5) {
        $where[] = "prioridad_nivel = :prio";
        $params[':prio'] = $prioridad;
    }

    // ✅ PROJECT KEY
    if ($project !== '') {
        $where[] = "project_key LIKE :project";
        $params[':project'] = "%$project%";
    }

    // ✅ TEXTO LIBRE summary/jira_key
    if ($q !== '') {
        $where[] = "(summary LIKE :q OR jira_key LIKE :q)";
        $params[':q'] = "%$q%";
    }


    $whereSql = implode(" AND ", $where);


    // -------------------------------------------------------------
    // 4) TOTAL
    // -------------------------------------------------------------
    $sqlTotal = "SELECT COUNT(*) FROM issues WHERE $whereSql";

    $st = $pdo->prepare($sqlTotal);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();

    $total = (int)$st->fetchColumn();


    // -------------------------------------------------------------
    // 5) CONSULTA PRINCIPAL
    // -------------------------------------------------------------
    $sqlList = "
        SELECT
            jira_id,
            jira_key,
            summary,
            status_name AS status,
            estado_categoria,
            priority_name AS priority,
            prioridad_nivel AS priority_level,
            assignee_display_name AS assignee,
            project_key,
            created_at,
            updated_at,
            (assignee_account_id IS NOT NULL) AS assigned
        FROM issues
        WHERE $whereSql
        ORDER BY updated_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $pdo->prepare($sqlList);

    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }

    $st->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);

    $st->execute();
    $rows = $st->fetchAll();


    // -------------------------------------------------------------
    // 6) LIMPIEZA
    // -------------------------------------------------------------
    foreach ($rows as &$r) {
        $r['assigned'] = (bool)$r['assigned'];
        $r['date']     = $r['created_at'];
    }


    // -------------------------------------------------------------
    // 7) RESPUESTA FINAL
    // -------------------------------------------------------------
    json_response([
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'page'     => (int)floor($offset / $limit) + 1,
            'pageSize' => $limit
        ]
    ]);

} catch (Throwable $t) {

    json_response([
        'ok' => false,
        'error' => APP_DEBUG ? $t->getMessage() : "Error cargando issues"
    ], 500);
}