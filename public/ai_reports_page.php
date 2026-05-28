<?php
/**
 * public/ai_reports_page.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Pantalla de informes IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa public/api/ai_reports.php para cargar el listado unificado de informes.
 * - Usa public/api/ai_report_detail.php para cargar el detalle de cada tipo de informe.
 * - Usa public/api/ai_generate_report.php para lanzar un informe de incidencia manual.
 * - Usa public/api/generate_queue_report.php para lanzar un informe 12H manual.
 * - Incluye public/partials/navbar.php para la navegación común.
 *
 * TIPOS DE INFORME SOPORTADOS:
 * - incidencia  -> informes actuales de análisis de incidencias.
 * - 12h         -> informes de evolución de cola cada 12 horas.
 * - closure     -> informes de calidad de cierre de incidencias.
 *
 * FUNCIONES PRINCIPALES:
 * - Mostrar listado de informes ordenado por fecha.
 * - Filtrar por tipo: Todos / Incidencias / 12H / Cierres.
 * - Permitir generar informe de incidencia manual.
 * - Permitir generar informe 12H manual.
 * - Al hacer click en un informe, desplegar su detalle.
 * - Mostrar secciones internas del informe en formato desplegable.
 * - Permitir marcar informes failed como completed.
 */

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/helpers/Auth.php';

auth_require_role('admin');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informes IA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body {
      background: #f8f9fa;
    }

    .reports-wrapper {
      max-width: 1200px;
      margin: 0 auto;
    }

    .report-meta {
      font-size: .9rem;
      color: #6c757d;
    }

    .report-pre,
    .report-section-pre {
      white-space: pre-wrap;
      word-break: break-word;
      font-size: .95rem;
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: .375rem;
      padding: 1rem;
    }

    .issue-analysis-card {
      border: 1px solid #dee2e6;
      border-radius: .5rem;
      background: #fff;
    }

    .critical-badge {
      font-size: .75rem;
    }

    #reportsStatus {
      min-height: 22px;
    }

    .report-sections-accordion .accordion-button,
    .issue-details-accordion .accordion-button {
      font-weight: 600;
    }

    .issue-summary-line {
      font-weight: 600;
    }

    .issue-meta-line {
      font-size: .9rem;
      color: #6c757d;
    }

    .issue-badge-wrap {
      display: flex;
      align-items: center;
      gap: .5rem;
      flex-wrap: wrap;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/navbar.php'; ?>

  <div id="page-wrapper">
    <div class="container py-4">
      <div class="reports-wrapper">

        <!-- Cabecera -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
          <div>
            <h2 class="h4 mb-1">Informes IA</h2>
            <div class="text-muted small">
              Listado de informes generados por la IA. Puedes generar uno nuevo manualmente.
            </div>
          </div>

          <div class="d-flex align-items-center gap-2">
            <button id="btnGenerateReport" class="btn btn-primary btn-sm">
              Generar informe incidencia
            </button>

            <button id="btnGenerateQueueReport" class="btn btn-outline-dark btn-sm">
              Generar informe 12H
            </button>
          </div>
        </div>

        <!-- Estado -->
        <div id="reportsStatus" class="small text-muted mb-3"></div>

        <!-- Filtros de tipo de informe -->
        <div class="d-flex gap-2 flex-wrap mb-3">
          <button type="button" class="btn btn-sm btn-primary report-filter-btn" data-filter="all">
            Todos
          </button>

          <button type="button" class="btn btn-sm btn-outline-primary report-filter-btn" data-filter="incidencia">
            Incidencias
          </button>

          <button type="button" class="btn btn-sm btn-outline-dark report-filter-btn" data-filter="12h">
            12H
          </button>

          <button type="button" class="btn btn-sm btn-outline-warning report-filter-btn" data-filter="closure">
            Cierres
          </button>
        </div>

        <!-- Listado -->
        <div id="reportsContainer" class="accordion"></div>

      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ======================================================
    // CONFIG
    // ======================================================
    const API_REPORTS       = './api/ai_reports.php';
    const API_REPORT_DETAIL = './api/ai_report_detail.php';
    const API_GENERATE      = './api/ai_generate_report.php';
    const API_QUEUE_REPORT  = './api/generate_queue_report.php';

    // ======================================================
    // DOM
    // ======================================================
    const reportsContainer       = document.getElementById('reportsContainer');
    const reportsStatus          = document.getElementById('reportsStatus');
    const btnGenerateReport      = document.getElementById('btnGenerateReport');
    const btnGenerateQueueReport = document.getElementById('btnGenerateQueueReport');

    // Cache local de informes para poder filtrar sin volver a pedir al backend.
    let reportsCache = [];

    // Filtro activo actual.
    // Valores posibles: all | incidencia | 12h | closure
    let activeReportFilter = 'all';

    // ======================================================
    // UTILIDADES
    // ======================================================

    /**
     * setStatus
     * ------------------------------------------------------
     * Muestra mensajes de estado en pantalla.
     *
     * @param {string} message Mensaje visible
     * @param {string} type Tipo Bootstrap textual: muted, success, danger...
     * @returns {void}
     */
    function setStatus(message, type = 'muted') {
      reportsStatus.className = `small text-${type} mb-3`;
      reportsStatus.textContent = message || '';
    }

    /**
     * escapeHtml
     * ------------------------------------------------------
     * Escapa HTML para evitar inyección de contenido al pintar datos dinámicos.
     *
     * @param {string|number|null|undefined} value Valor a escapar
     * @returns {string}
     */
    function escapeHtml(value) {
      return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    /**
     * formatDateTime
     * ------------------------------------------------------
     * Devuelve una fecha legible en formato local español.
     *
     * @param {string|null} value Fecha en texto
     * @returns {string}
     */
    function formatDateTime(value) {
      if (!value) return '—';

      const normalized = String(value).replace(' ', 'T');
      const dt = new Date(normalized);

      if (isNaN(dt.getTime())) {
        return value;
      }

      return dt.toLocaleString('es-ES');
    }

    /**
     * getStatusBadge
     * ------------------------------------------------------
     * Devuelve badge visual según estado del informe.
     *
     * @param {string} status Estado del informe
     * @returns {string}
     */
    function getStatusBadge(status) {
      const s = String(status || '').toLowerCase();

      if (s === 'completed') {
        return '<span class="badge text-bg-success">completed</span>';
      }

      if (s === 'failed') {
        return '<span class="badge text-bg-danger">failed</span>';
      }

      return '<span class="badge text-bg-secondary">pending</span>';
    }

    /**
     * getCriticalBadge
     * ------------------------------------------------------
     * Devuelve badge visual según criticidad de incidencia.
     *
     * @param {boolean} isCritical Si la incidencia es crítica
     * @returns {string}
     */
    function getCriticalBadge(isCritical) {
      return isCritical
        ? '<span class="badge text-bg-danger critical-badge">CRÍTICA</span>'
        : '<span class="badge text-bg-secondary critical-badge">NO crítica</span>';
    }

    /**
     * getFilteredReports
     * ------------------------------------------------------
     * Devuelve los informes filtrados según el tipo seleccionado.
     *
     * TIPOS:
     * - all        -> todos los informes
     * - incidencia -> informes de análisis de incidencias
     * - 12h        -> informes ejecutivos de evolución de cola
     * - closure    -> informes de calidad de cierre
     *
     * @returns {Array}
     */
    function getFilteredReports() {
      if (activeReportFilter === 'all') {
        return reportsCache;
      }

      return reportsCache.filter((report) => {
        return String(report.report_type || '').toLowerCase() === activeReportFilter;
      });
    }

    /**
     * updateReportFilterButtons
     * ------------------------------------------------------
     * Actualiza visualmente los botones de filtro.
     *
     * QUÉ HACE:
     * - El filtro activo queda relleno.
     * - Los filtros inactivos quedan en outline.
     *
     * @returns {void}
     */
    function updateReportFilterButtons() {
      document.querySelectorAll('.report-filter-btn').forEach((btn) => {
        const filter = btn.dataset.filter || 'all';
        const isActive = filter === activeReportFilter;

        btn.classList.remove(
          'btn-primary',
          'btn-outline-primary',
          'btn-dark',
          'btn-outline-dark',
          'btn-warning',
          'btn-outline-warning'
        );

        if (filter === '12h') {
          btn.classList.add(isActive ? 'btn-dark' : 'btn-outline-dark');
          return;
        }

        if (filter === 'closure') {
          btn.classList.add(isActive ? 'btn-warning' : 'btn-outline-warning');
          return;
        }

        btn.classList.add(isActive ? 'btn-primary' : 'btn-outline-primary');
      });
    }

    /**
     * buildAccordionSection
     * ------------------------------------------------------
     * Construye una sección desplegable reutilizable.
     *
     * @param {string} sectionId ID interno
     * @param {string} title Título visible
     * @param {string} contentHtml Contenido HTML ya generado
     * @param {boolean} open Si arranca abierta
     * @returns {string}
     */
    function buildAccordionSection(sectionId, title, contentHtml, open = false) {
      return `
        <div class="accordion-item">
          <h2 class="accordion-header" id="heading-${sectionId}">
            <button
              class="accordion-button ${open ? '' : 'collapsed'}"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#collapse-${sectionId}"
              aria-expanded="${open ? 'true' : 'false'}"
              aria-controls="collapse-${sectionId}"
            >
              ${escapeHtml(title)}
            </button>
          </h2>

          <div
            id="collapse-${sectionId}"
            class="accordion-collapse collapse ${open ? 'show' : ''}"
            aria-labelledby="heading-${sectionId}"
          >
            <div class="accordion-body">
              ${contentHtml}
            </div>
          </div>
        </div>
      `;
    }

    // ======================================================
    // RENDER LISTADO
    // ======================================================

    /**
     * renderReportsList
     * ------------------------------------------------------
     * Pinta el listado de informes en formato accordion.
     *
     * QUÉ HACE:
     * - Muestra informes incidencia, 12H y cierre.
     * - Añade badge visual por tipo.
     * - Usa claves DOM compuestas por tipo + id para evitar colisiones
     *   entre tablas distintas con ids iguales.
     * - Carga el detalle bajo demanda al desplegar.
     *
     * @param {Array} items Lista de informes devuelta por backend
     * @returns {void}
     */
    function renderReportsList(items) {
      if (!items || !items.length) {
        reportsContainer.innerHTML = `
          <div class="alert alert-light border text-muted">
            No hay informes generados todavía.
          </div>
        `;
        return;
      }

      reportsContainer.innerHTML = items.map((report) => {
        const reportType = String(report.report_type || 'incidencia').toLowerCase();

        // ID seguro para DOM. Evita conflictos entre informes de tablas distintas.
        const domKey = `${reportType}-${report.id}`.replace(/[^a-zA-Z0-9_-]/g, '-');

        const collapseId = `report-collapse-${domKey}`;
        const headingId  = `report-heading-${domKey}`;
        const detailId   = `report-detail-${domKey}`;

        const typeBadge =
          reportType === '12h'
            ? '<span class="badge text-bg-dark me-2">12H</span>'
            : reportType === 'closure'
              ? '<span class="badge text-bg-warning me-2">CIERRE</span>'
              : '<span class="badge text-bg-primary me-2">INCIDENCIA</span>';

        const metaText =
          reportType === '12h'
            ? `
              ${escapeHtml(formatDateTime(report.created_at))}
              · Informe evolución cola
            `
            : reportType === 'closure'
              ? `
                ${escapeHtml(formatDateTime(report.created_at))}
                · Informe calidad de cierre
              `
              : `
                ${escapeHtml(formatDateTime(report.created_at))}
                · ${escapeHtml(String(report.total_issues_analyzed ?? 0))} incidencias
                · ${escapeHtml(String(report.total_critical_detected ?? 0))} críticas
              `;

        return `
          <div class="accordion-item mb-3 shadow-sm">
            <h2 class="accordion-header" id="${headingId}">
              <button
                class="accordion-button collapsed"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#${collapseId}"
                aria-expanded="false"
                aria-controls="${collapseId}"
                data-report-id="${escapeHtml(report.id)}"
                data-report-type="${escapeHtml(reportType)}"
              >
                <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2 pe-3">
                  <div>
                    <div class="fw-semibold">
                      ${typeBadge}
                      ${escapeHtml(report.report_name || ('Informe #' + report.id))}
                    </div>

                    <div class="report-meta">
                      ${metaText}
                    </div>
                  </div>

                  <div>
                    ${getStatusBadge(report.status)}
                  </div>
                </div>
              </button>
            </h2>

            <div
              id="${collapseId}"
              class="accordion-collapse collapse"
              aria-labelledby="${headingId}"
              data-bs-parent="#reportsContainer"
            >
              <div class="accordion-body">
                <div id="${detailId}" class="text-muted small">
                  Cargando detalle...
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');

      // Cargar detalle al desplegar
      reportsContainer.querySelectorAll('.accordion-button').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const reportId = btn.dataset.reportId;
          const reportType = btn.dataset.reportType || 'incidencia';

          if (!reportId) {
            return;
          }

          const domKey = `${reportType}-${reportId}`.replace(/[^a-zA-Z0-9_-]/g, '-');
          const detailContainer = document.getElementById(`report-detail-${domKey}`);

          if (!detailContainer) {
            return;
          }

          if (detailContainer.dataset.loaded === '1') {
            return;
          }

          await loadReportDetail(reportId, reportType, detailContainer);
        });
      });
    }

    // ======================================================
    // RENDER DETALLE
    // ======================================================

    /**
     * buildReportHeaderHtml
     * ------------------------------------------------------
     * Pinta una cabecera genérica de informe.
     *
     * @param {Object} report Cabecera del informe
     * @returns {string}
     */
    function buildReportHeaderHtml(report) {
      return `
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
              <h5 class="mb-1">${escapeHtml(report.report_name || ('Informe #' + report.id))}</h5>
              <div class="report-meta">
                Creado: ${escapeHtml(formatDateTime(report.created_at))}<br>
                Inicio: ${escapeHtml(formatDateTime(report.started_at))}<br>
                Fin: ${escapeHtml(formatDateTime(report.completed_at))}<br>
                Trigger: ${escapeHtml(report.trigger_source || '—')}<br>
                Modelo: ${escapeHtml(report.model || '—')}
              </div>
            </div>
            <div>${getStatusBadge(report.status)}</div>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-12 col-md-4">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Incidencias analizadas</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_issues_analyzed ?? 0))}</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Críticas / Rating</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_critical_detected ?? 0))}</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Sync referencia</div>
                  <div class="fw-semibold">${escapeHtml(formatDateTime(report.sync_reference_time))}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      `;
    }

    /**
     * buildReportErrorHtml
     * ------------------------------------------------------
     * Muestra el error del informe si existe.
     *
     * @param {Object} report Cabecera del informe
     * @returns {string}
     */
    function buildReportErrorHtml(report) {
      const isFailed = String(report.status || '').toLowerCase() === 'failed';
      const errorMessage = String(report.error_message || '').trim();

      if (!isFailed || !errorMessage) {
        return '';
      }

      return `
        <div class="alert alert-danger mb-4">
          <div class="fw-semibold mb-1">Error del informe</div>
          <div>${escapeHtml(errorMessage)}</div>
        </div>
      `;
    }

    /**
     * buildReportManualActionsHtml
     * ------------------------------------------------------
     * Muestra acciones manuales disponibles para el informe.
     *
     * @param {Object} report Cabecera del informe
     * @returns {string}
     */
    function buildReportManualActionsHtml(report) {
      const isFailed = String(report.status || '').toLowerCase() === 'failed';

      if (!isFailed) {
        return '';
      }

      return `
        <div class="mb-4">
          <button
            type="button"
            class="btn btn-sm btn-outline-success btn-mark-report-completed"
            data-report-id="${escapeHtml(report.id)}"
          >
            Marcar como completed
          </button>
        </div>
      `;
    }

    /**
     * buildReportContentHtml
     * ------------------------------------------------------
     * Construye el bloque desplegable del informe de incidencia.
     *
     * @param {Object} report Informe de incidencia
     * @returns {string}
     */
    function buildReportContentHtml(report) {
      const reportId = String(report.id || 'report');

      return `
        <div class="accordion report-sections-accordion mb-4" id="report-sections-${escapeHtml(reportId)}">
          ${buildAccordionSection(
            `summary-${reportId}`,
            'Resumen ejecutivo',
            `<div class="report-section-pre">${escapeHtml(report.report_summary || 'Sin resumen')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `full-${reportId}`,
            'Informe completo',
            `<div class="report-section-pre">${escapeHtml(report.report_text || 'Sin informe')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `prompt-${reportId}`,
            'Prompt usado',
            `<div class="report-section-pre">${escapeHtml(report.prompt_general_used || '—')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `critical-def-${reportId}`,
            'Definición de incidencia crítica usada',
            `<div class="report-section-pre">${escapeHtml(report.def_incidencia_critica_used || '—')}</div>`,
            false
          )}
        </div>
      `;
    }

    /**
     * buildQueueReportContentHtml
     * ------------------------------------------------------
     * Construye el detalle visual de un informe 12H.
     *
     * @param {Object} report Informe 12H
     * @returns {string}
     */
    function buildQueueReportContentHtml(report) {
      let metrics = {};

      try {
        metrics = report.metrics_json ? JSON.parse(report.metrics_json) : {};
      } catch (e) {
        metrics = {};
      }

      return `
        <div class="mb-4">
          <div class="row g-3">
            <div class="col-12 col-md-3">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Abiertas inicio</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_open_start ?? 0))}</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-3">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Abiertas fin</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_open_end ?? 0))}</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-3">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Entrantes</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_incoming ?? 0))}</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-3">
              <div class="card">
                <div class="card-body py-2">
                  <div class="small text-muted">Resueltas</div>
                  <div class="fw-semibold">${escapeHtml(String(report.total_resolved ?? 0))}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="accordion report-sections-accordion mb-4" id="queue-report-sections-${escapeHtml(report.id)}">
          ${buildAccordionSection(
            `queue-summary-${report.id}`,
            'Resumen ejecutivo',
            `<div class="report-section-pre">${escapeHtml(report.report_summary || 'Sin resumen')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `queue-full-${report.id}`,
            'Informe completo',
            `<div class="report-section-pre">${escapeHtml(report.report_text || 'Sin informe')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `queue-metrics-${report.id}`,
            'Métricas calculadas',
            `<div class="report-section-pre">${escapeHtml(JSON.stringify(metrics, null, 2))}</div>`,
            false
          )}

          ${buildAccordionSection(
            `queue-prompt-${report.id}`,
            'Prompt usado',
            `<div class="report-section-pre">${escapeHtml(report.prompt_used || '—')}</div>`,
            false
          )}
        </div>
      `;
    }

    /**
     * buildClosureReportContentHtml
     * ------------------------------------------------------
     * Construye el detalle visual de un informe de cierre.
     *
     * @param {Object} report Informe de cierre
     * @returns {string}
     */
    function buildClosureReportContentHtml(report) {
      const rating = report.rating ?? report.total_critical_detected ?? 'N/A';

      return `
        <div class="mb-4">
          <div class="alert alert-warning border">
            <div class="fw-semibold">Rating de calidad de cierre</div>
            <div class="fs-5">${escapeHtml(String(rating))}/10</div>
          </div>
        </div>

        <div class="accordion report-sections-accordion mb-4" id="closure-report-sections-${escapeHtml(report.id)}">
          ${buildAccordionSection(
            `closure-summary-${report.id}`,
            'Resumen del cierre',
            `<div class="report-section-pre">${escapeHtml(report.report_summary || 'Sin resumen')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `closure-full-${report.id}`,
            'Informe completo de cierre',
            `<div class="report-section-pre">${escapeHtml(report.report_text || 'Sin informe')}</div>`,
            false
          )}

          ${buildAccordionSection(
            `closure-raw-${report.id}`,
            'Respuesta IA / datos técnicos',
            `<div class="report-section-pre">${escapeHtml(report.raw_response_json || '—')}</div>`,
            false
          )}
        </div>
      `;
    }

    /**
     * buildIssuesHtml
     * ------------------------------------------------------
     * Pinta las incidencias analizadas como elementos desplegables.
     *
     * @param {Array} issues Lista de incidencias
     * @param {number|string} reportId ID del informe
     * @returns {string}
     */
    function buildIssuesHtml(issues, reportId) {
      if (!issues || !issues.length) {
        return `
          <div class="alert alert-light border text-muted mb-0">
            Este informe no contiene incidencias analizadas.
          </div>
        `;
      }

      const accordionId = `issues-accordion-${reportId}`;

      return `
        <div class="accordion issue-details-accordion" id="${accordionId}">
          ${issues.map((issue, index) => {
            const safeKey = String(issue.jira_key || `issue-${index}`).replace(/[^a-zA-Z0-9_-]/g, '-');
            const itemId = `${accordionId}-${safeKey}-${index}`;
            const issueTitle = `${issue.jira_key || '—'} - ${issue.summary || ''}`;

            return `
              <div class="accordion-item mb-2">
                <h2 class="accordion-header" id="heading-${itemId}">
                  <button
                    class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse-${itemId}"
                    aria-expanded="false"
                    aria-controls="collapse-${itemId}"
                  >
                    <div class="w-100 d-flex justify-content-between align-items-start flex-wrap gap-2 pe-3">
                      <div>
                        <div class="issue-summary-line">${escapeHtml(issueTitle)}</div>
                      </div>
                      <div class="issue-badge-wrap">
                        ${getCriticalBadge(Number(issue.is_critical) === 1)}
                      </div>
                    </div>
                  </button>
                </h2>

                <div
                  id="collapse-${itemId}"
                  class="accordion-collapse collapse"
                  aria-labelledby="heading-${itemId}"
                  data-bs-parent="#${accordionId}"
                >
                  <div class="accordion-body">
                    <div class="issue-meta-line mb-3">
                      Estado: ${escapeHtml(issue.current_status || '—')}
                      · Prioridad: ${escapeHtml(issue.current_priority || '—')}
                      ${issue.score !== null && issue.score !== undefined ? '· Score: ' + escapeHtml(String(issue.score)) : ''}
                    </div>

                    <div class="mb-3">
                      <div class="small fw-semibold">Motivo</div>
                      <div>${escapeHtml(issue.critical_reason || '—')}</div>
                    </div>

                    <div class="mb-3">
                      <div class="small fw-semibold">Acción recomendada</div>
                      <div>${escapeHtml(issue.recommended_action || '—')}</div>
                    </div>

                    <div>
                      <div class="small fw-semibold">Análisis</div>
                      <div>${escapeHtml(issue.analysis_text || '—')}</div>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;
    }

    /**
     * markReportCompletedManually
     * ------------------------------------------------------
     * Marca manualmente un informe failed como completed.
     *
     * @param {number|string} reportId ID del informe
     * @param {string} reportType Tipo: incidencia | 12h | closure
     * @returns {Promise<void>}
     */
    async function markReportCompletedManually(reportId, reportType = 'incidencia') {
      if (!reportId) {
        return;
      }

      try {
        const res = await fetch(API_REPORT_DETAIL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id: Number(reportId),
            type: reportType,
            action: 'mark_completed'
          })
        });

        const json = await res.json();

        if (!json.ok) {
          alert(json.error || 'No se pudo marcar el informe como completed.');
          return;
        }

        setStatus('Informe marcado manualmente como completed.', 'success');
        await loadReports();

      } catch (err) {
        console.error(err);
        alert('Error de red marcando el informe como completed.');
      }
    }

    /**
     * loadReportDetail
     * ------------------------------------------------------
     * Carga y pinta el detalle de un informe.
     *
     * SOPORTA:
     * - incidencia
     * - 12h
     * - closure
     *
     * @param {number|string} reportId ID del informe
     * @param {string} reportType Tipo de informe
     * @param {HTMLElement} targetEl Contenedor del detalle
     * @returns {Promise<void>}
     */
    async function loadReportDetail(reportId, reportType, targetEl) {
      targetEl.innerHTML = 'Cargando detalle...';

      try {
        const res = await fetch(
          `${API_REPORT_DETAIL}?id=${encodeURIComponent(reportId)}&type=${encodeURIComponent(reportType)}&t=${Date.now()}`
        );

        const json = await res.json();

        if (!json.ok) {
          targetEl.innerHTML = `<div class="text-danger">No se pudo cargar el detalle del informe.</div>`;
          return;
        }

        const normalizedType = String(reportType || '').toLowerCase();

        if (normalizedType === '12h') {
          targetEl.innerHTML = `
            ${buildReportHeaderHtml(json.report)}
            ${buildReportErrorHtml(json.report)}
            ${buildReportManualActionsHtml(json.report)}
            ${buildQueueReportContentHtml(json.report)}
          `;
        } else if (normalizedType === 'closure') {
          targetEl.innerHTML = `
            ${buildReportHeaderHtml(json.report)}
            ${buildReportErrorHtml(json.report)}
            ${buildReportManualActionsHtml(json.report)}
            ${buildClosureReportContentHtml(json.report)}
          `;
        } else {
          targetEl.innerHTML = `
            ${buildReportHeaderHtml(json.report)}
            ${buildReportErrorHtml(json.report)}
            ${buildReportManualActionsHtml(json.report)}
            ${buildReportContentHtml(json.report)}

            <div>
              <h6 class="mb-3">Incidencias analizadas</h6>
              ${buildIssuesHtml(json.issues, reportId)}
            </div>
          `;
        }

        targetEl.dataset.loaded = '1';

        const btnMarkCompleted = targetEl.querySelector('.btn-mark-report-completed');

        if (btnMarkCompleted) {
          btnMarkCompleted.addEventListener('click', async () => {
            await markReportCompletedManually(reportId, reportType);
          });
        }

      } catch (err) {
        console.error(err);
        targetEl.innerHTML = `<div class="text-danger">Error cargando el detalle del informe.</div>`;
      }
    }

    // ======================================================
    // CARGA LISTADO
    // ======================================================

    /**
     * loadReports
     * ------------------------------------------------------
     * Carga el listado de informes desde backend.
     *
     * @returns {Promise<void>}
     */
    async function loadReports() {
      setStatus('Cargando informes...', 'muted');

      try {
        const res = await fetch(`${API_REPORTS}?t=${Date.now()}`);
        const json = await res.json();

        if (!json.ok) {
          setStatus('No se pudieron cargar los informes.', 'danger');
          reportsContainer.innerHTML = '';
          return;
        }

        reportsCache = Array.isArray(json.data) ? json.data : [];

        renderReportsList(getFilteredReports());

        setStatus(
          `Informes cargados: ${reportsCache.length} · Mostrando: ${getFilteredReports().length}`,
          'success'
        );
      } catch (err) {
        console.error(err);
        reportsContainer.innerHTML = '';
        setStatus('Error cargando el listado de informes.', 'danger');
      }
    }

    // ======================================================
    // GENERAR INFORMES MANUALES
    // ======================================================

    /**
     * generateReport
     * ------------------------------------------------------
     * Lanza la generación manual de un informe de incidencia.
     *
     * @returns {Promise<void>}
     */
    async function generateReport() {
      btnGenerateReport.disabled = true;
      setStatus('Generando informe incidencia... Esto puede tardar unos segundos.', 'muted');

      try {
        const res = await fetch(API_GENERATE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            trigger_source: 'manual_button'
          })
        });

        const json = await res.json();

        if (!json.ok) {
          setStatus(json.error || 'No se pudo generar el informe incidencia.', 'danger');
          return;
        }

        setStatus('Informe incidencia generado correctamente.', 'success');
        await loadReports();

      } catch (err) {
        console.error(err);
        setStatus('Error de red generando el informe incidencia.', 'danger');
      } finally {
        btnGenerateReport.disabled = false;
      }
    }

    /**
     * generateQueueReport
     * ------------------------------------------------------
     * Genera el informe 12H manualmente.
     *
     * @returns {Promise<void>}
     */
    async function generateQueueReport() {
      btnGenerateQueueReport.disabled = true;
      setStatus('Generando informe 12H...', 'muted');

      try {
        const res = await fetch(API_QUEUE_REPORT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' }
        });

        const json = await res.json();

        if (!json.ok) {
          setStatus(json.error || 'Error generando informe 12H.', 'danger');
          return;
        }

        setStatus('Informe 12H generado correctamente.', 'success');
        await loadReports();

      } catch (err) {
        console.error(err);
        setStatus('Error de red generando informe 12H.', 'danger');
      } finally {
        btnGenerateQueueReport.disabled = false;
      }
    }

    // ======================================================
    // EVENTOS
    // ======================================================

    btnGenerateReport.addEventListener('click', generateReport);
    btnGenerateQueueReport.addEventListener('click', generateQueueReport);

    // Filtros de tipo de informe
    document.querySelectorAll('.report-filter-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        activeReportFilter = btn.dataset.filter || 'all';

        updateReportFilterButtons();
        renderReportsList(getFilteredReports());

        setStatus(
          `Informes cargados: ${reportsCache.length} · Mostrando: ${getFilteredReports().length}`,
          'success'
        );
      });
    });

    // ======================================================
    // INIT
    // ======================================================
    updateReportFilterButtons();
    loadReports();
  </script>
</body>
</html>