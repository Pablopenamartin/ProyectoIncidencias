<?php
/**
 * app/services/JiraService.php
 * -------------------------------------------------------
 * Servicio HTTP para Jira Cloud (REST API v3).
 *
 * Cambios clave:
 *  - Sustituido el endpoint retirado /rest/api/3/search por /rest/api/3/search/jql.
 *  - La paginación ahora se gestiona con nextPageToken (NO startAt/total).
 *  - Se usa GET con JQL URL-encoded (querystring) y 'fields' explícitos.
 *
 * Requiere helpers en app/config/jira.php:
 *  - jira_search_url(): string      → .../rest/api/3/search/jql
 *  - jira_headers(): array          → Authorization + Accept (y Content-Type en POST si se usa)
 */

require_once __DIR__ . '/../config/jira.php'; // jira_search_url(), jira_headers()

class JiraService
{
    /**
     * Parámetros cURL por defecto.
     */
    private int $timeoutSeconds        = 30;  // tiempo máximo por request
    private int $connectTimeoutSeconds = 10;  // tiempo máximo para abrir conexión
    private int $maxRetries            = 3;   // reintentos para 429/5xx/errores transitorios
    private int $retryBaseDelayMs      = 500; // backoff exponencial base (ms)

    /**
     * Ejecuta UNA búsqueda por JQL (UNA página) con el nuevo endpoint.
     * Firma compatible con código legado; $startAt se IGNORA en /search/jql.
     *
     * @param string $jql
     * @param int    $max       Tamaño de página (1..100 recomendado).
     * @param int    $startAt   IGNORADO (mantenido por compatibilidad).
     * @param array  $fields    Campos a solicitar; si vacío, mínimos necesarios por la app.
     * @return array            Respuesta JSON decodificada (issues[], nextPageToken?, ...)
     * @throws RuntimeException
     */
    public function searchIssues(string $jql = '', int $max = 50, int $startAt = 0, array $fields = []): array
    {
        return $this->searchIssuesPage($jql, $max, null, $fields);
    }

    /**
     * Recorre TODAS las páginas usando nextPageToken.
     *
     * Compatibilidad con callback legado:
     *   onChunk(array $issuesChunk, int $startAt, int $fetched, int $totalOrNull)
     * - startAt    → simulado como pageIndex * pageSize
     * - fetched    → elementos de la página
     * - totalOrNull→ null (el nuevo endpoint no garantiza 'total')
     *
     * @param string        $jql
     * @param int           $pageSize   (1..100)
     * @param array         $fields
     * @param callable|null $onChunk
     * @return array                    Issues acumulados si no hay callback; si hay callback, [].
     * @throws RuntimeException
     */
    public function paginateAll(string $jql, int $pageSize = 100, array $fields = [], ?callable $onChunk = null): array
    {
        if ($pageSize < 1)   { $pageSize = 1; }
        if ($pageSize > 100) { $pageSize = 100; }

        $accumulated = [];
        $pageToken   = null;   // token devuelto por la respuesta para la siguiente página
        $pageIndex   = 0;

        while (true) {
            $resp   = $this->searchIssuesPage($jql, $pageSize, $pageToken, $fields);
            $issues = $resp['issues']        ?? [];
            $token  = $resp['nextPageToken'] ?? null;

            // Callback legado o acumulación local
            $legacyStartAt = $pageIndex * $pageSize;
            $fetched       = count($issues);
            $legacyTotal   = null; // el nuevo endpoint no garantiza 'total'

            if ($onChunk && is_callable($onChunk)) {
                $onChunk($issues, $legacyStartAt, $fetched, $legacyTotal);
            } else {
                foreach ($issues as $it) {
                    $accumulated[] = $it;
                }
            }

            // Fin de paginación
            if (empty($token) || $fetched === 0) {
                break;
            }

            $pageToken = $token;
            $pageIndex++;
        }

        return $onChunk ? [] : $accumulated;
    }

    // =====================================================================
    // ================= Implementación HTTP y utilidades ===================
    // =====================================================================

    /**
     * Trae UNA página con GET /rest/api/3/search/jql.
     *
     * @param string      $jql
     * @param int         $max
     * @param string|null $pageToken nextPageToken desde la página anterior (si existe)
     * @param array       $fields
     * @return array
     * @throws RuntimeException
     */
    private function searchIssuesPage(string $jql, int $max, ?string $pageToken = null, array $fields = []): array
    {
        $baseUrl = jira_search_url(); // .../rest/api/3/search/jql

        if (empty($fields)) {
            // Campos mínimos que consume nuestro modelo/servicio
            $fields = ['id','key','summary','status','priority','assignee','updated','created','project'];
        }

        // Construcción de querystring (http_build_query se encarga del URL-encoding)
        $params = [
            'jql'        => $jql,
            'maxResults' => $max,
            'fields'     => implode(',', $fields),
        ];
        if (!empty($pageToken)) {
            // Paginación moderna: encadenar con nextPageToken
            $params['nextPageToken'] = $pageToken;
        }

        $url     = $baseUrl . '?' . http_build_query($params);
        $headers = jira_headers(); // Authorization + Accept

        return $this->getJsonWithRetries($url, $headers);
    }

    /**
     * GET JSON con reintentos para 429/5xx y errores transitorios de cURL.
     *
     * @param string $url
     * @param array  $headers
     * @return array
     * @throws RuntimeException
     */
    private function getJsonWithRetries(string $url, array $headers): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            [$http, $resp, $curlErrNo, $curlErrMsg, $info] =
                $this->execCurlJson('GET', $url, $headers, null);

            // Errores de transporte (cURL)
            if ($curlErrNo !== 0) {
                if ($this->shouldRetryCurlError($curlErrNo) && $attempt <= $this->maxRetries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                $msg = defined('APP_DEBUG') && APP_DEBUG
                    ? "JiraService: cURL error ($curlErrNo) $curlErrMsg"
                    : "JiraService: Error de red al contactar Jira.";
                throw new RuntimeException($msg);
            }

            // Decodificación robusta
            $decoded = [];
            if (is_string($resp) && $resp !== '') {
                $decoded = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $msg = defined('APP_DEBUG') && APP_DEBUG
                        ? "JiraService: Respuesta JSON inválida. HTTP=$http. Body: " . substr($resp, 0, 4000)
                        : "JiraService: Respuesta inválida de Jira.";
                    throw new RuntimeException($msg);
                }
            }

            // Éxito
            if ($http >= 200 && $http < 300) {
                return $decoded ?: [];
            }

            // 401/403 → credenciales o permisos insuficientes
            if ($http === 401 || $http === 403) {
                $msg = defined('APP_DEBUG') && APP_DEBUG
                    ? "JiraService: Autenticación/permiso denegado (HTTP $http)."
                    : "JiraService: No autorizado o sin permisos.";
                throw new RuntimeException($msg);
            }

            // 429 → rate limit (aplicar backoff y reintentar)
            if ($http === 429 && $attempt <= $this->maxRetries) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // 5xx → error temporal (reintentar si quedan intentos)
            if ($http >= 500 && $http < 600 && $attempt <= $this->maxRetries) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // Otros códigos → error final
            $msg = defined('APP_DEBUG') && APP_DEBUG
                ? "JiraService: Error HTTP $http. Body: " . substr($resp ?? '', 0, 4000)
                : "JiraService: Error al consultar Jira (HTTP $http).";
            throw new RuntimeException($msg);
        }
    }

    /**
     * POST JSON con reintentos (no se usa en /search/jql actual; queda para otros endpoints).
     *
     * @param string $url
     * @param array  $headers
     * @param array  $body
     * @return array
     * @throws RuntimeException
     */
    
private function postJsonWithRetries(string $url, array $headers, array $body): array
    {
        $attempt = 0;
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Aseguramos Content-Type para POST JSON
        $hdrs = $headers;
        $hasCt = false;
        foreach ($hdrs as $h) {
            if (stripos($h, 'Content-Type:') === 0) { $hasCt = true; break; }
        }
        if (!$hasCt) {
            $hdrs[] = 'Content-Type: application/json';
        }

        while (true) {
            $attempt++;

            [$http, $resp, $curlErrNo, $curlErrMsg, $info] =
                $this->execCurlJson('POST', $url, $hdrs, $payload);

            // Errores de transporte (cURL)
            if ($curlErrNo !== 0) {
                if ($this->shouldRetryCurlError($curlErrNo) && $attempt <= $this->maxRetries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                $msg = (defined('APP_DEBUG') && APP_DEBUG)
                    ? "JiraService: cURL error ($curlErrNo) $curlErrMsg"
                    : "JiraService: Error de red al contactar Jira.";
                throw new \RuntimeException($msg);
            }

            // Decodificación segura
            $decoded = [];
            if (is_string($resp) && $resp !== '') {
                $decoded = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $msg = (defined('APP_DEBUG') && APP_DEBUG)
                        ? "JiraService: Respuesta JSON inválida. HTTP=$http. Body: " . substr($resp, 0, 4000)
                        : "JiraService: Respuesta inválida de Jira.";
                    throw new \RuntimeException($msg);
                }
            }

            // Éxito
            if ($http >= 200 && $http < 300) {
                return $decoded ?: [];
            }

            // 401/403 → credenciales o permisos insuficientes
            if ($http === 401 || $http === 403) {
                $msg = (defined('APP_DEBUG') && APP_DEBUG)
                    ? "JiraService: Autenticación/permiso denegado (HTTP $http)."
                    : "JiraService: No autorizado o sin permisos.";
                throw new \RuntimeException($msg);
            }

            // 429 → rate limit (aplicar backoff y reintentar)
            if ($http === 429 && $attempt <= $this->maxRetries) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // 5xx → error temporal (reintentar si quedan intentos)
            if ($http >= 500 && $http < 600 && $attempt <= $this->maxRetries) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // Otros códigos → error final
            $msg = (defined('APP_DEBUG') && APP_DEBUG)
                ? "JiraService: Error HTTP $http. Body: " . substr($resp ?? '', 0, 4000)
                : "JiraService: Error al consultar Jira (HTTP $http).";
            throw new \RuntimeException($msg);
        }
    }

    /**
     * execCurlJson
     * ------------
     * Ejecuta una llamada cURL genérica para JSON (GET/POST).
     *
     * @param string      $method 'GET'|'POST'
     * @param string      $url
     * @param array       $headers
     * @param string|null $body   JSON (para POST) o null
     * @return array              [$httpCode, $responseBody, $curlErrNo, $curlErrMsg, $info]
     */
    private function execCurlJson(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch   = curl_init();
        $hdrs = array_values($headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        $info     = curl_getinfo($ch);

        unset($ch);

        return [$http, $response, $errno, $err, $info];
    }

    /**
     * shouldRetryCurlError
     * --------------------
     * Determina si un error de cURL es transitorio (merece reintento).
     */
    private function shouldRetryCurlError(int $curlErrNo): bool
    {
        $transient = [
            CURLE_OPERATION_TIMEDOUT,   // 28
            CURLE_COULDNT_CONNECT,      // 7
            CURLE_COULDNT_RESOLVE_HOST, // 6
            CURLE_PARTIAL_FILE,         // 18
            CURLE_RECV_ERROR,           // 56
        ];
        return in_array($curlErrNo, $transient, true);
    }

    /**
     * sleepBackoff
     * ------------
     * Backoff exponencial con jitter leve entre reintentos.
     * Si $retryAfterSeconds no es null, se respeta ese valor.
     *
     * @param int      $attempt
     * @param int|null $retryAfterSeconds
     * @return void
     */
    private function sleepBackoff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $ms = $retryAfterSeconds * 1000;
        } else {
            $base   = $this->retryBaseDelayMs * (2 ** ($attempt - 1));
            $jitter = random_int(0, 250);
            $ms     = $base + $jitter;
        }
        usleep($ms * 1000);
    }
} // ← cierre de clase JiraService


