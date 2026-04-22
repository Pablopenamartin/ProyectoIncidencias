<?php
/**
 * app/services/OpenAiProviderService.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Cliente backend para OpenAI.
 *
 * RESPONSABILIDAD ÚNICA:
 * - Enviar prompt
 * - Recibir JSON estructurado
 * - Validar respuesta
 */

require_once __DIR__ . '/../config/constants.php';

class OpenAiProviderService
{
    private string $apiKey;
    private string $model = 'gpt-4.1-mini';
    private string $endpoint = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');

        if (!$this->apiKey) {
            throw new RuntimeException('OPENAI_API_KEY no configurada');
        }
    }

    /**
     * Ejecuta el análisis IA.
     */
    public function analyze(string $systemPrompt, string $userPrompt): array
    {
        $payload = [
            'model' => $this->model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Error OpenAI: ' . $raw);
        }

        $json = json_decode($raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            throw new RuntimeException('Respuesta IA vacía');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('La IA no devolvió JSON válido');
        }

        $decoded['_raw'] = $json;
        return $decoded;
    }
}
