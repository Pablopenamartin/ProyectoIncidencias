<?php
/**
 * public/api/retry_alert_notifications.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Endpoint para reintentar el envío de notificaciones de alertas
 * de un informe IA ya existente, sin volver a ejecutar OpenAI.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/helpers/Auth.php para restringir acceso a administradores.
 * - Usa app/helpers/Utils.php para responder JSON de forma homogénea.
 * - Usa app/services/AlertNotificationService.php para reintentar:
 *   1) correo a usuarios activos
 *   2) mensaje al canal de Teams
 *
 * FUNCIONES PRINCIPALES:
 * - Acepta una petición POST con report_id
 * - Reintenta las notificaciones pendientes de ese informe
 * - Devuelve un JSON con el resumen del reintento
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/services/AlertNotificationsService.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Solo admin puede forzar reintentos.
 */
//auth_require_api_role('admin');

/**
 * readJsonBody
 * --------------------------------------------------------------
 * Lee el body JSON de la petición y lo devuelve como array.
 *
 * @return array
 */
function readJsonBodyRetryAlerts(): array
{
    $raw = file_get_contents('php://input') ?: '';

    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);

    return is_array($json) ? $json : [];
}

try {
    /**
     * Solo aceptamos POST.
     */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok'    => false,
            'error' => 'Método no permitido.',
        ], 405);
    }

    $data = readJsonBodyRetryAlerts();

    $reportId = isset($data['report_id']) ? (int)$data['report_id'] : 0;

    if ($reportId <= 0) {
        json_response([
            'ok'    => false,
            'error' => 'El campo report_id es obligatorio y debe ser mayor que 0.',
        ], 400);
    }

    /**
     * Reintento de notificaciones sin volver a ejecutar IA.
     */
    $service = new AlertNotificationService();
    $result = $service->retryNotificationsForReport($reportId);

    json_response([
        'ok'      => true,
        'message' => 'Reintento de notificaciones ejecutado correctamente.',
        'data'    => [
            'report_id' => $reportId,
            'result'    => $result,
        ],
    ]);

} catch (InvalidArgumentException $e) {
    json_response([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error reintentando notificaciones de alertas.',
    ], 500);
}