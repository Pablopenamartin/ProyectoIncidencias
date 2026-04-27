<?php
/**
 * public/ai_config.php
 * -------------------------------------------------------
 * FUNCIÓN GENERAL:
 * Pantalla de configuración global de IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa public/api/ai_settings.php para cargar y guardar la configuración.
 * - Incluye public/partials/navbar.php para la navegación común.
 *
 * FUNCIONES PRINCIPALES:
 * - Cargar prompt_general y def_incidencia_critica guardados.
 * - Permitir editarlos.
 * - Guardar cambios mediante un único botón.
 */
require_once __DIR__ . '/../app/config/constants.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Configuración IA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap base de la interfaz -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background: #f8f9fa; }
    .config-card { max-width: 1100px; margin: 0 auto; }
    .form-hint { font-size: .85rem; color: #6c757d; }
    textarea { min-height: 220px; resize: vertical; }
    #saveStatus { min-height: 24px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/navbar.php'; ?>

  <div id="page-wrapper">
    <div class="container py-4">
      <div class="config-card">

        <!-- Cabecera -->
        <div class="mb-4">
          <h2 class="h4 mb-1">Configuración IA</h2>
          <div class="text-muted small">
            Define el prompt general y la regla de criticidad que se enviarán a la IA en cada análisis.
          </div>
        </div>

        <!-- Tarjeta principal -->
        <div class="card shadow-sm">
          <div class="card-body">

            <form id="aiConfigForm" novalidate>
              <div class="mb-4">
                <label for="promptGeneral" class="form-label fw-semibold">Prompt</label>
                <textarea
                  id="promptGeneral"
                  name="prompt_general"
                  class="form-control"
                  placeholder="Ejemplo: Quiero que analices las incidencias, generes un informe detallado por cada una, detectes posibles incidencias críticas y resumas riesgos, impacto y acciones recomendadas."></textarea>
                <div class="form-hint mt-1">
                  Instrucción general que se enviará a la IA en cada ejecución.
                </div>
              </div>

              <div class="mb-4">
                <label for="defIncidenciaCritica" class="form-label fw-semibold">Def incidencia crítica</label>
                <textarea
                  id="defIncidenciaCritica"
                  name="def_incidencia_critica"
                  class="form-control"
                  placeholder="Ejemplo: Considera crítica una incidencia cuando combine alta urgencia e impacto, incluya palabras clave concretas o suponga riesgo operativo relevante."></textarea>
                <div class="form-hint mt-1">
                  Define qué debe considerar la IA como incidencia crítica.
                </div>
              </div>

              <!-- Estado + botón -->
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div id="saveStatus" class="small text-muted"></div>

                <button type="submit" id="btnSaveAiConfig" class="btn btn-primary">
                  Guardar
                </button>
              </div>
            </form>

          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    /**
     * Configuración básica de endpoints
     */
    const API_AI_SETTINGS = './api/ai_settings.php';

    /**
     * Referencias DOM
     */
    const aiConfigForm          = document.getElementById('aiConfigForm');
    const promptGeneral         = document.getElementById('promptGeneral');
    const defIncidenciaCritica  = document.getElementById('defIncidenciaCritica');
    const btnSaveAiConfig       = document.getElementById('btnSaveAiConfig');
    const saveStatus            = document.getElementById('saveStatus');

    /**
     * setStatus
     * ----------------------------------------------------
     * Muestra un mensaje visual simple en la interfaz.
     */
    function setStatus(message, type = 'muted') {
      saveStatus.className = `small text-${type}`;
      saveStatus.textContent = message || '';
    }

    /**
     * loadAiSettings
     * ----------------------------------------------------
     * Carga la configuración activa desde backend y la
     * pinta en los textareas.
     */
    async function loadAiSettings() {
      setStatus('Cargando configuración...', 'muted');

      try {
        const res = await fetch(API_AI_SETTINGS + '?t=' + Date.now());
        const json = await res.json();

        if (!json.ok || !json.data) {
          setStatus('No se pudo cargar la configuración.', 'danger');
          return;
        }

        promptGeneral.value = json.data.prompt_general || '';
        defIncidenciaCritica.value = json.data.def_incidencia_critica || '';

        setStatus('Configuración cargada.', 'success');
      } catch (err) {
        console.error(err);
        setStatus('Error cargando la configuración IA.', 'danger');
      }
    }

    /**
     * saveAiSettings
     * ----------------------------------------------------
     * Envía la configuración al backend para guardarla.
     */
    async function saveAiSettings() {
      const payload = {
        prompt_general: promptGeneral.value.trim(),
        def_incidencia_critica: defIncidenciaCritica.value.trim()
      };

      if (!payload.prompt_general) {
        setStatus('El campo "Prompt" es obligatorio.', 'danger');
        promptGeneral.focus();
        return;
      }

      if (!payload.def_incidencia_critica) {
        setStatus('El campo "Def incidencia crítica" es obligatorio.', 'danger');
        defIncidenciaCritica.focus();
        return;
      }

      btnSaveAiConfig.disabled = true;
      setStatus('Guardando configuración...', 'muted');

      try {
        const res = await fetch(API_AI_SETTINGS, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const json = await res.json();

        if (!json.ok) {
          setStatus(json.error || 'No se pudo guardar la configuración.', 'danger');
          return;
        }

        setStatus('Configuración IA guardada correctamente.', 'success');
      } catch (err) {
        console.error(err);
        setStatus('Error de red guardando la configuración.', 'danger');
      } finally {
        btnSaveAiConfig.disabled = false;
      }
    }

    /**
     * Evento submit del formulario
     */
    aiConfigForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveAiSettings();
    });

    /**
     * Inicio de página
     */
    loadAiSettings();
  </script>
</body>
</html>
