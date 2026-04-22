<?php
/**
 * app/models/AiSettingsModel.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL:
 * Modelo encargado de gestionar la configuración global de IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa app/config/database.php para reutilizar la conexión PDO.
 * - Será consumido por public/api/ai_settings.php.
 * - Más adelante será consumido también por AiAnalysisService.php.
 *
 * FUNCIONES PRINCIPALES:
 * - getActiveSettings(): obtiene la configuración activa o devuelve defaults.
 * - saveSettings(): guarda/actualiza la configuración global activa.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

class AiSettingsModel
{
    private PDO $pdo;

    /**
     * __construct
     * ----------------------------------------------------------------
     * Crea el modelo reutilizando la conexión PDO global si existe.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : getPDO();
    }

    /**
     * getDefaultSettings
     * ----------------------------------------------------------------
     * Devuelve una configuración por defecto cuando aún no existe
     * ningún registro en ai_settings.
     */
    private function getDefaultSettings(): array
    {
        return [
            'id'                      => null,
            'prompt_general'          => '',
            'def_incidencia_critica'  => '',
            'language'                => 'es',
            'provider'                => 'openai',
            'model'                   => 'gpt-4.1-mini',
            'is_active'               => 1,
            'created_at'              => null,
            'updated_at'              => null,
        ];
    }

    /**
     * getActiveSettings
     * ----------------------------------------------------------------
     * Obtiene la configuración global activa.
     *
     * Si no existe ninguna, devuelve una estructura por defecto
     * para que la UI pueda pintarse sin errores.
     */
    public function getActiveSettings(): array
    {
        $sql = "
            SELECT
                id,
                prompt_general,
                def_incidencia_critica,
                language,
                provider,
                model,
                is_active,
                created_at,
                updated_at
            FROM ai_settings
            WHERE is_active = 1
            ORDER BY id DESC
            LIMIT 1
        ";

        $row = $this->pdo->query($sql)->fetch();

        return $row ?: $this->getDefaultSettings();
    }

    /**
     * saveSettings
     * ----------------------------------------------------------------
     * Guarda o actualiza la configuración global de IA.
     *
     * CÓMO FUNCIONA:
     * - Si ya existe una configuración activa, la actualiza.
     * - Si no existe, crea una nueva fila activa.
     *
     * VALIDACIONES:
     * - prompt_general obligatorio
     * - def_incidencia_critica obligatorio
     *
     * @param array $data Datos recibidos desde la API/UI
     * @return array Configuración activa guardada
     */
    public function saveSettings(array $data): array
    {
        $current = $this->getActiveSettings();

        $promptGeneral = trim((string)($data['prompt_general'] ?? ''));
        $defCritica    = trim((string)($data['def_incidencia_critica'] ?? ''));

        if ($promptGeneral === '') {
            throw new InvalidArgumentException('El campo "Prompt" es obligatorio.');
        }

        if ($defCritica === '') {
            throw new InvalidArgumentException('El campo "Def incidencia crítica" es obligatorio.');
        }

        // Mantener configuración global simple por ahora.
        $language = trim((string)($data['language'] ?? ($current['language'] ?? 'es')));
        $provider = trim((string)($data['provider'] ?? ($current['provider'] ?? 'openai')));
        $model    = trim((string)($data['model'] ?? ($current['model'] ?? 'gpt-4.1-mini')));

        if ($language === '') {
            $language = 'es';
        }

        if ($provider === '') {
            $provider = 'openai';
        }

        if ($model === '') {
            $model = 'gpt-4.1-mini';
        }

        // Si ya existe una fila activa, actualizamos esa misma.
        if (!empty($current['id'])) {
            $sql = "
                UPDATE ai_settings
                SET
                    prompt_general = :prompt_general,
                    def_incidencia_critica = :def_incidencia_critica,
                    language = :language,
                    provider = :provider,
                    model = :model,
                    is_active = 1,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ";

            $st = $this->pdo->prepare($sql);
            $st->execute([
                ':prompt_general'         => $promptGeneral,
                ':def_incidencia_critica' => $defCritica,
                ':language'               => $language,
                ':provider'               => $provider,
                ':model'                  => $model,
                ':id'                     => (int)$current['id'],
            ]);

            return $this->getActiveSettings();
        }

        // Si no existe todavía, creamos la primera configuración.
        $sql = "
            INSERT INTO ai_settings (
                prompt_general,
                def_incidencia_critica,
                language,
                provider,
                model,
                is_active,
                created_at,
                updated_at
            ) VALUES (
                :prompt_general,
                :def_incidencia_critica,
                :language,
                :provider,
                :model,
                1,
                NOW(),
                NOW()
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':prompt_general'         => $promptGeneral,
            ':def_incidencia_critica' => $defCritica,
            ':language'               => $language,
            ':provider'               => $provider,
            ':model'                  => $model,
        ]);

        return $this->getActiveSettings();
    }
}
