<?php
/**
 * public/api/ai_generate_report.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Endpoint HTTP para generar manualmente un informe IA.
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/services/AiAnalysisService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $service = new AiAnalysisService();
    $result = $service->generate(
        $data['trigger_source'] ?? 'manual_button',
        $data['sync_reference_time'] ?? null
    );

    json_response(['ok' => true, 'data' => $result]);

} catch (Throwable $t) {
    json_response([
        'ok' => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error generando informe IA'
    ], 500);
}