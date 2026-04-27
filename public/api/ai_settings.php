<?php
/**
 * public/api/ai_settings.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL:
 * Endpoint API para leer y guardar la configuración global de IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/models/AiSettingsModel.php para acceder a ai_settings.
 * - Usa app/helpers/Utils.php para responder JSON de forma homogénea.
 *
 * MÉTODOS:
 * - GET  -> devuelve la configuración activa
 * - POST -> guarda/actualiza la configuración activa
 */

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/helpers/Utils.php';
require_once __DIR__ . '/../../app/models/AiSettingsModel.php';

require_once __DIR__ . '/../../app/helpers/Auth.php';
auth_require_api_role('admin');
// Solo admin puede leer/guardar configuración IA


header('Content-Type: application/json; charset=utf-8');

/**
 * readInputData
 * ----------------------------------------------------------------
 * Lee datos de entrada soportando:
 * - application/json
 * - formulario clásico ($_POST)
 *
 * @return array
 */
function readInputData(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $model  = new AiSettingsModel();

    if ($method === 'GET') {
        json_response([
            'ok'   => true,
            'data' => $model->getActiveSettings(),
        ]);
    }

    if ($method === 'POST') {
        $data = readInputData();

        $saved = $model->saveSettings([
            'prompt_general'         => $data['prompt_general'] ?? '',
            'def_incidencia_critica' => $data['def_incidencia_critica'] ?? '',
            // Estos campos quedan preparados aunque la UI de momento
            // solo edite los dos textareas principales.
            'language'               => $data['language'] ?? 'es',
            'provider'               => $data['provider'] ?? 'openai',
            'model'                  => $data['model'] ?? 'gpt-4.1-mini',
        ]);

        json_response([
            'ok'      => true,
            'message' => 'Configuración IA guardada correctamente.',
            'data'    => $saved,
        ]);
    }

    json_response([
        'ok'    => false,
        'error' => 'Método no permitido.',
    ], 405);

} catch (InvalidArgumentException $e) {
    json_response([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $t) {
    json_response([
        'ok'    => false,
        'error' => APP_DEBUG ? $t->getMessage() : 'Error gestionando la configuración IA.',
    ], 500);
}