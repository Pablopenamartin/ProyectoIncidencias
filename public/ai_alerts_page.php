<?php
/**
 * public/ai_alerts_page.php
 * =========================================================
 * FUNCIÓN GENERAL:
 * Pantalla de alertas críticas sin asignar.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa alerts.php para cargar alertas.
 * - Usa claim_alert.php para "coger" la incidencia.
 * - Incluye navbar.php para navegación común.
 */

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/helpers/Auth.php';

auth_require_role(['admin', 'operador']);

$user = auth_user();
$jiraBrowseBase = rtrim((string)JIRA_SITE, '/') . '/browse/';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Alertas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background: #f8f9fa; }
        .alerts-wrapper { max-width: 1250px; margin: 0 auto; }
        .alert-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: .5rem;
        }
        .alert-meta {
            font-size: .9rem;
            color: #6c757d;
        }
        .alert-block-title {
            font-size: .8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: .25rem;
        }
        #alertsStatus { min-height: 22px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/partials/navbar.php'; ?>

    <div id="page-wrapper">
        <div class="container py-4">
            <div class="alerts-wrapper">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div>
                        <h1 class="h4 mb-1">Alertas críticas sin asignar</h1>
                        <div class="text-muted small">
                            Últimas incidencias críticas detectadas por la IA y pendientes de asignación.
                        </div>
                    </div>

                    <div class="text-muted small">
                        Usuario: <?= htmlspecialchars($user['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div id="alertsStatus" class="small text-muted mb-3"></div>

                <div id="alertsContainer" class="d-flex flex-column gap-3">
                    <div class="text-muted">Cargando alertas...</div>
                </div>
            </div>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <script>
        // ======================================================
        // CONFIG
        // ======================================================
        const API_ALERTS = './api/alerts.php';
        const API_CLAIM  = './api/claim_alert.php';
        const JIRA_BROWSE_BASE = <?= json_encode($jiraBrowseBase) ?>;

        // ======================================================
        // DOM
        // ======================================================
        const alertsContainer = document.getElementById('alertsContainer');
        const alertsStatus    = document.getElementById('alertsStatus');

        // ======================================================
        // UTILIDADES
        // ======================================================
        function setStatus(message, type = 'muted') {
            alertsStatus.className = `small text-${type} mb-3`;
            alertsStatus.textContent = message || '';
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function formatDateTime(value) {
            if (!value) return '—';

            const normalized = String(value).replace(' ', 'T');
            const dt = new Date(normalized);

            if (isNaN(dt.getTime())) return value;
            return dt.toLocaleString('es-ES');
        }

        function scoreBadge(score) {
            if (score === null || score === undefined || score === '') {
                return '<span class="badge text-bg-secondary">Sin score</span>';
            }

            const numeric = Number(score);
            if (Number.isNaN(numeric)) {
                return '<span class="badge text-bg-secondary">Sin score</span>';
            }

            if (numeric >= 8) {
                return `<span class="badge text-bg-danger">Score ${escapeHtml(String(score))}</span>`;
            }
            if (numeric >= 5) {
                return `<span class="badge text-bg-warning text-dark">Score ${escapeHtml(String(score))}</span>`;
            }

            return `<span class="badge text-bg-secondary">Score ${escapeHtml(String(score))}</span>`;
        }

        // ======================================================
        // RENDER
        // ======================================================
        function renderAlerts(items) {
            if (!items || !items.length) {
                alertsContainer.innerHTML = `
                    <div class="alert alert-light border text-muted mb-0">
                        No hay alertas críticas sin asignar.
                    </div>
                `;
                return;
            }

            alertsContainer.innerHTML = items.map(item => {
                const jiraUrl = JIRA_BROWSE_BASE + encodeURIComponent(item.jira_key);

                return `
                    <div class="alert-card p-3" id="alert-card-${escapeHtml(item.jira_key)}">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                            <div>
                                <div class="fw-semibold">
                                    ${escapeHtml(item.jira_key)} - ${escapeHtml(item.summary || '')}
                                </div>
                                <div class="alert-meta">
                                    Estado: ${escapeHtml(item.current_status || '—')}
                                    · Prioridad: ${escapeHtml(item.current_priority || '—')}
                                    · Informe: ${escapeHtml(item.report_name || '—')}
                                    · Fecha informe: ${escapeHtml(formatDateTime(item.report_created_at))}
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                ${scoreBadge(item.score)}
                                ${escapeHtml(jiraUrl)}
                                    Abrir en Jira
                                </a>
                                <button
                                    class="btn btn-sm btn-primary btn-claim-alert"
                                    data-key="${escapeHtml(item.jira_key)}"
                                >
                                    Coger incidencia
                                </button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-lg-4">
                                <div class="alert-block-title">Motivo crítico</div>
                                <div>${escapeHtml(item.critical_reason || '—')}</div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="alert-block-title">Acción recomendada</div>
                                <div>${escapeHtml(item.recommended_action || '—')}</div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="alert-block-title">Análisis IA</div>
                                <div>${escapeHtml(item.analysis_text || '—')}</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ======================================================
        // CARGA ALERTAS
        // ======================================================
        async function loadAlerts() {
            setStatus('Cargando alertas...', 'muted');

            try {
                const res = await fetch(`${API_ALERTS}?t=${Date.now()}`);
                const json = await res.json();

                if (!json.ok) {
                    alertsContainer.innerHTML = '';
                    setStatus(json.error || 'No se pudieron cargar las alertas.', 'danger');
                    return;
                }

                renderAlerts(json.data || []);
                setStatus(`Alertas cargadas: ${json.count ?? 0}`, 'success');

            } catch (err) {
                console.error(err);
                alertsContainer.innerHTML = '';
                setStatus('Error cargando alertas.', 'danger');
            }
        }

        // ======================================================
        // COGER INCIDENCIA
        // ======================================================
        async function claimAlert(jiraKey, buttonEl) {
            if (!jiraKey) return;

            buttonEl.disabled = true;
            setStatus(`Asignando ${jiraKey}...`, 'muted');

            try {
                const res = await fetch(API_CLAIM, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ jira_key: jiraKey })
                });

                const json = await res.json();

                if (!json.ok) {
                    setStatus(json.error || 'No se pudo asignar la incidencia.', 'danger');
                    buttonEl.disabled = false;
                    return;
                }

                setStatus(`Incidencia ${jiraKey} asignada correctamente.`, 'success');

                // Recargar alertas para que desaparezca del listado
                await loadAlerts();

            } catch (err) {
                console.error(err);
                setStatus('Error de red asignando la incidencia.', 'danger');
                buttonEl.disabled = false;
            }
        }

        // ======================================================
        // EVENTOS
        // ======================================================
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-claim-alert');
            if (!btn) return;

            const jiraKey = btn.dataset.key || '';
            await claimAlert(jiraKey, btn);
        });

        // ======================================================
        // INIT
        // ======================================================
        loadAlerts();
    </script>
</body>
</html>