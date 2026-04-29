<?php
/**
 * public/ai_reports.php
 * ------------------------------------------------------------------
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Pantalla de informes IA.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa public/api/ai_reports.php para cargar el listado.
 * - Usa public/api/ai_report_detail.php para cargar el detalle.
 * - Usa public/api/ai_generate_report.php para lanzar un informe manual.
 * - Incluye public/partials/navbar.php para la navegación común.
 *
 * FUNCIONES PRINCIPALES:
 * - Mostrar listado de informes ordenado por fecha.
 * - Permitir generar un informe IA manual.
 * - Al hacer click en un informe, desplegar su detalle.
 */
require_once __DIR__ . '/../app/helpers/Auth.php';
auth_require_role('admin');
// Solo admin puede acceder a la pantalla de informes IA.
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informes IA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background: #f8f9fa; }

    .reports-wrapper {
      max-width: 1200px;
      margin: 0 auto;
    }

    .report-meta {
      font-size: .9rem;
      color: #6c757d;
    }

    .report-pre {
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
              Generar informe IA
            </button>
          </div>
        </div>

        <!-- Estado -->
        <div id="reportsStatus" class="small text-muted mb-3"></div>

        <!-- Listado -->
        <div id="reportsContainer" class="accordion"></div>

      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ======================================================
    // CONFIG
    // ======================================================
    const API_REPORTS        = './api/ai_reports.php';
    const API_REPORT_DETAIL  = './api/ai_report_detail.php';
    const API_GENERATE       = './api/ai_generate_report.php';

    // ======================================================
    // DOM
    // ======================================================
    const reportsContainer   = document.getElementById('reportsContainer');
    const reportsStatus      = document.getElementById('reportsStatus');
    const btnGenerateReport  = document.getElementById('btnGenerateReport');

    // ======================================================
    // UTILIDADES
    // ======================================================

    /**
     * Muestra mensajes de estado en pantalla.
     */
    function setStatus(message, type = 'muted') {
      reportsStatus.className = `small text-${type} mb-3`;
      reportsStatus.textContent = message || '';
    }

    /**
     * Escapa HTML para evitar inyecciones al pintar texto.
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
     * Devuelve una fecha legible.
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
     * Devuelve badge visual según estado del informe.
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
     * Devuelve badge visual según criticidad.
     */
    function getCriticalBadge(isCritical) {
      return isCritical
        ? '<span class="badge text-bg-danger critical-badge">CRÍTICA</span>'
        : '<span class="badge text-bg-secondary critical-badge">NO crítica</span>';
    }

    // ======================================================
    // RENDER LISTADO
    // ======================================================

    /**
     * Pinta el listado de informes como accordion.
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

      reportsContainer.innerHTML = items.map((report, index) => {
        const collapseId = `report-collapse-${report.id}`;
        const headingId  = `report-heading-${report.id}`;

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
                data-report-id="${report.id}"
              >
                <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2 pe-3">
                  <div>
                    <div class="fw-semibold">${escapeHtml(report.report_name || ('Informe #' + report.id))}</div>
                    <div class="report-meta">
                      ${escapeHtml(formatDateTime(report.created_at))}
                      · ${escapeHtml(String(report.total_issues_analyzed ?? 0))} incidencias
                      · ${escapeHtml(String(report.total_critical_detected ?? 0))} críticas
                    </div>
                  </div>
                  <div>${getStatusBadge(report.status)}</div>
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
                <div id="report-detail-${report.id}" class="text-muted small">
                  Cargando detalle...
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');

      // Cargar detalle al desplegar
      reportsContainer.querySelectorAll('.accordion-button').forEach(btn => {
        btn.addEventListener('click', async () => {
          const reportId = btn.dataset.reportId;
          if (!reportId) return;

          const detailContainer = document.getElementById(`report-detail-${reportId}`);
          if (!detailContainer) return;

          // Evita recargas innecesarias si ya está cargado
          if (detailContainer.dataset.loaded === '1') {
            return;
          }

          await loadReportDetail(reportId, detailContainer);
        });
      });
    }

    // ======================================================
    // RENDER DETALLE
    // ======================================================

    /**
     * Pinta la cabecera del informe.
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
                  <div class="small text-muted">Críticas detectadas</div>
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
     * Pinta resumen + texto completo.
     */
    function buildReportTextHtml(report) {
      return `
        <div class="mb-4">
          <h6 class="mb-2">Resumen ejecutivo</h6>
          <div class="report-pre mb-3">${escapeHtml(report.report_summary || 'Sin resumen')}</div>

          <h6 class="mb-2">Informe completo</h6>
          <div class="report-pre">${escapeHtml(report.report_text || 'Sin informe')}</div>
        </div>
      `;
    }

    /**
     * Pinta configuración usada.
     */
    function buildReportPromptHtml(report) {
      return `
        <div class="mb-4">
          <h6 class="mb-2">Prompt usado</h6>
          <div class="report-pre mb-3">${escapeHtml(report.prompt_general_used || '—')}</div>

          <h6 class="mb-2">Definición de incidencia crítica usada</h6>
          <div class="report-pre">${escapeHtml(report.def_incidencia_critica_used || '—')}</div>
        </div>
      `;
    }

    /**
     * Pinta el detalle por incidencia.
     */
    function buildIssuesHtml(issues) {
      if (!issues || !issues.length) {
        return `
          <div class="alert alert-light border text-muted mb-0">
            Este informe no contiene incidencias analizadas.
          </div>
        `;
      }

      return `
        <div class="d-flex flex-column gap-3">
          ${issues.map(issue => `
            <div class="issue-analysis-card p-3">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                  <div class="fw-semibold">${escapeHtml(issue.jira_key)} - ${escapeHtml(issue.summary || '')}</div>
                  <div class="small text-muted">
                    Estado: ${escapeHtml(issue.current_status || '—')}
                    · Prioridad: ${escapeHtml(issue.current_priority || '—')}
                    ${issue.score !== null && issue.score !== undefined ? '· Score: ' + escapeHtml(String(issue.score)) : ''}
                  </div>
                </div>
                <div>${getCriticalBadge(Number(issue.is_critical) === 1)}</div>
              </div>

              <div class="mb-2">
                <div class="small fw-semibold">Motivo</div>
                <div>${escapeHtml(issue.critical_reason || '—')}</div>
              </div>

              <div class="mb-2">
                <div class="small fw-semibold">Acción recomendada</div>
                <div>${escapeHtml(issue.recommended_action || '—')}</div>
              </div>

              <div>
                <div class="small fw-semibold">Análisis</div>
                <div>${escapeHtml(issue.analysis_text || '—')}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    }

    /**
     * Carga y pinta el detalle de un informe.
     */
    async function loadReportDetail(reportId, targetEl) {
      targetEl.innerHTML = 'Cargando detalle...';

      try {
        const res = await fetch(`${API_REPORT_DETAIL}?id=${encodeURIComponent(reportId)}&t=${Date.now()}`);
        const json = await res.json();

        if (!json.ok) {
          targetEl.innerHTML = `<div class="text-danger">No se pudo cargar el detalle del informe.</div>`;
          return;
        }

        targetEl.innerHTML = `
          ${buildReportHeaderHtml(json.report)}
          ${buildReportTextHtml(json.report)}
          ${buildReportPromptHtml(json.report)}

          <div>
            <h6 class="mb-3">Incidencias analizadas</h6>
            ${buildIssuesHtml(json.issues)}
          </div>
        `;

        targetEl.dataset.loaded = '1';
      } catch (err) {
        console.error(err);
        targetEl.innerHTML = `<div class="text-danger">Error cargando el detalle del informe.</div>`;
      }
    }

    // ======================================================
    // CARGA LISTADO
    // ======================================================

    /**
     * Carga el listado de informes desde backend.
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

        renderReportsList(json.data || []);
        setStatus(`Informes cargados: ${json.count ?? 0}`, 'success');
      } catch (err) {
        console.error(err);
        reportsContainer.innerHTML = '';
        setStatus('Error cargando el listado de informes.', 'danger');
      }
    }

    // ======================================================
    // GENERAR INFORME MANUAL
    // ======================================================

    /**
     * Lanza la generación manual de un informe IA.
     */
    async function generateReport() {
      btnGenerateReport.disabled = true;
      setStatus('Generando informe IA... Esto puede tardar unos segundos.', 'muted');

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
          setStatus(json.error || 'No se pudo generar el informe IA.', 'danger');
          return;
        }

        setStatus('Informe IA generado correctamente.', 'success');
        await loadReports();

      } catch (err) {
        console.error(err);
        setStatus('Error de red generando el informe IA.', 'danger');
      } finally {
        btnGenerateReport.disabled = false;
      }
    }

    // ======================================================
    // EVENTOS
    // ======================================================

    btnGenerateReport.addEventListener('click', generateReport);

    // ======================================================
    // INIT
    // ======================================================
    loadReports();
  </script>
</body>
</html>
