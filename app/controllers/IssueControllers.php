<?php
/**
 * app/controllers/IssueController.php
 * -----------------------------------
 * Controlador mínimo para exponer listados y metadatos desde la capa Model.
 */

require_once __DIR__ . '/../models/IssueModel.php';

class IssueController
{
    private IssueModel $model;

    public function __construct()
    {
        $this->model = new IssueModel();
    }

    /**
     * Lista issues con filtros y paginación.
     * $query: [
     *   'limit' => int,
     *   'offset' => int,
     *   'estado' => int (1..5),
     *   'prioridad' => int (1..5),
     *   'q' => texto en summary,
     *   'project' => key del proyecto
     * ]
     */
    public function list(array $query): array
    {
        $limit  = isset($query['limit'])  ? max(1, min(200, (int)$query['limit']))  : 50;
        $offset = isset($query['offset']) ? max(0, (int)$query['offset'])           : 0;

        $filters = [];
        if (isset($query['estado']))    { $filters['estado_nivel']    = (int)$query['estado']; }
        if (isset($query['prioridad'])) { $filters['prioridad_nivel'] = (int)$query['prioridad']; }
        if (!empty($query['project']))  { $filters['project_key']     = $query['project']; }
        if (!empty($query['q']))        { $filters['text']            = $query['q']; }

        $rows = $this->model->listIssues($limit, $offset, $filters, 'updated_at DESC');

        // Adjuntamos la marca de última sincronización para la UI
        $lastSync = $this->model->getLastSyncTime();

        return [
            'ok'          => true,
            'limit'       => $limit,
            'offset'      => $offset,
            'count'       => count($rows),
            'last_sync'   => $lastSync,
            'items'       => $rows
        ];
    }

    /** Devuelve solo la marca de sincronización (por si la UI la pide suelta). */
    public function meta(): array
    {
        $lastSync = $this->model->getLastSyncTime();
        return ['ok' => true, 'last_sync' => $lastSync];
    }
    /**
     * Devuelve el detalle de una incidencia por jira_key.
     * Respuesta estándar del controlador:
     *   - ok: true/false
     *   - item: array|null
     */
    public function getByKey(string $jiraKey): array
    {
        $jiraKey = trim($jiraKey);
        if ($jiraKey === '') {
            return ['ok' => false, 'item' => null, 'error' => 'Parámetro "jira_key" vacío'];
        }

        $item = $this->model->getIssueByKey($jiraKey);
        if (!$item) {
            return ['ok' => false, 'item' => null, 'error' => 'No encontrada'];
        }

        return ['ok' => true, 'item' => $item];
    }
}