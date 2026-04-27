<?php
/**
 * public/api/ai_report_detail.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para obtener el detalle completo de un informe IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/models/AiReportModel.php
 * - Será consumido por la futura pantalla "Informes"
 *
 * FUNCIONES PRINCIPALES:
 * - GET ?id=... -> devuelve:
 *   - cabecera del informe
 *   - detalle de incidencias analizadas
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/models/AiReportModel.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'GET') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.',
        ], 405);
    }

    $reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($reportId <= 0) {
        json_response([
            'ok'    => false,
            'error' => 'Parámetro "id" inválido.',
        ], 400);
    }

    $model = new AiReportModel();

    $report = $model->getReportById($reportId);
    if (!$report) {
        json_response([
            'ok'    => false,
            'error' => 'Informe no encontrado.',
        ], 404);
    }

    $issues = $model->getReportIssues($reportId);

    json_response([
        'ok'     => true,
        'report' => $report,
        'issues' => $issues,
    ]);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error obteniendo el detalle del informe IA.',
    ], 500);
}