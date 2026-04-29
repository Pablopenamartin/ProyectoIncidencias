<?php
/**
 * public/index.php
 */
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/helpers/Auth.php';
auth_require_role('admin');
// Solo admin puede acceder al panel principal.
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Panel de Incidencias Jira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- ✅ Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background-color: #f8f9fa; }

    .card-metric {
      border-left: 6px solid #0d6efd;
      background: #fff;
    }
    .metric-title {
      font-size: .82rem;
      text-transform: uppercase;
      font-weight: 600;
      color: #6c757d;
    }
    .metric-value {
      font-size: 1.9rem;
      font-weight: bold;
      line-height: 1.2;
    }
    .metric-diff {
      font-size: .9rem;
      font-weight: bold;
    }
    .metric-diff.plus { color: #198754; }
    .metric-diff.minus { color: #dc3545; }
    .metric-diff.zero { color: #6c757d; }

    .state-cols, .prio-cols {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .state-cols .card,
    .prio-cols .card {
      width: 160px;
      min-height: 120px;
    }

    .issue-actions {
      display: flex;
      gap: .4rem;
      flex-wrap: wrap;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/partials/navbar.php'; ?>
<div id="page-wrapper">
<div class="container py-4">

<!-- =======================================================
     PANEL SUPERIOR
======================================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h2 class="h4">Estado de la cola de incidencias</h2>
    <div class="text-muted small">
      Última actualización: <span id="lastUpdate">—</span><br>
      Comparado con: <span id="previousUpdate">—</span>
    </div>
  </div>

  <div class="d-flex gap-2">
    <!-- ✅ Botón Sync -->
    <button id="btnSyncNow" class="btn btn-primary btn-sm">
      Sincronizar ahora
    </button>
  </div>
</div>

<!-- =======================================================
     TARJETA TOTAL
======================================================= -->
<div class="card card-metric shadow-sm mb-4" style="max-width:300px;">
  <div class="card-body text-center">
    <div class="metric-title">Tickets abiertos</div>
    <div class="metric-value" id="totalAbiertas">0</div>
    <div id="totalAbiertasDiff" class="metric-diff zero">0</div>
  </div>
</div>

<!-- =======================================================
     DASHBOARD ESTADOS
======================================================= -->
<h4 class="mt-4 mb-2">Estados</h4>
<div class="state-cols mt-3" id="stateCards"></div>

<!-- =======================================================
     DASHBOARD PRIORIDADES
======================================================= -->
<h4 class="mt-4 mb-2">Prioridades</h4>
<div class="prio-cols mt-3" id="priorityCards"></div>

<!-- =======================================================
     FILTROS
======================================================= -->
<div class="card mt-5 mb-3">
  <div class="card-body">
    <form id="filterForm" class="row g-2">

      <div class="col-12 col-md-3">
        <label class="form-label">Estado</label>
        <select id="estado" class="form-select">
          <option value="">Todos</option>
          <option value="esperando_ayuda">Esperando ayuda</option>
          <option value="escalated">Escalated</option>
          <option value="en_curso">En curso</option>
          <option value="pending">Pending</option>
          <option value="waiting_approval">Waiting approval</option>
          <option value="waiting_customer">Waiting customer</option>
          <option value="cerrado_unificado">Cerrados</option>
        </select>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Prioridad</label>
        <select id="prioridad" class="form-select">
          <option value="">Todas</option>
          <option value="1">P1 – Critical</option>
          <option value="2">P2 – High</option>
          <option value="3">P3 – Medium</option>
          <option value="4">P4 – Low</option>
          <option value="5">P5 – Lowest</option>
        </select>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Proyecto</label>
        <input id="project" class="form-control" placeholder="LIP..." />
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Buscar</label>
        <div class="input-group">
          <input id="q" class="form-control" placeholder="Texto..." />
          <button class="btn btn-primary" type="submit">Filtrar</button>
          <button id="btnClear" type="button" class="btn btn-outline-secondary">Limpiar</button>
        </div>
      </div>

    </form>
  </div>
</div>

<!-- =======================================================
     TABLA INCIDENCIAS
======================================================= -->
<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Clave</th>
            <th>Título</th>
            <th>Estado</th>
            <th>Prioridad</th>
            <th>Asignado</th>
            <th>Fecha</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody id="issueRows">
          <tr><td colspan="7" class="text-center text-muted">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>
</div>

<!-- =======================================================
     MODAL EDICIÓN RÁPIDA
======================================================= -->
<div class="modal fade" id="issueEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar incidencia</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editJiraKey">

        <div class="mb-3">
            <label class="form-label" for="editSummary">Título</label>
            <input type="text" class="form-control" id="editSummary">
        </div>

        <div class="row g-3">
          <div class="col-12 col-md-4">           
          <label class="form-label" for="editPriority">Prioridad</label>
          <select class="form-select" id="editPriority"></select>
          </div>

          <div class="col-12 col-md-4">            
          <label class="form-label" for="editAssignee">Asignado</label>
          <select class="form-select" id="editAssignee"></select>
          </div>

          <div class="col-12 col-md-4">
          <label class="form-label" for="editStatus">Estado destino</label>
          <select class="form-select" id="editStatus"></select>
          </div>
        </div>

        <div class="mt-3 small text-muted" id="editModalInfo"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnSaveIssueEdit">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- ✅ Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// =======================================================
// CONFIG
// =======================================================
const API_DASHBOARD   = './api/dashboard.php';
const API_ISSUES      = './api/issues.php';
const API_SYNC        = './api/sync.php';
const API_JIRA_UPDATE = './api/jira_update_issue.php';
const JIRA_BROWSE_BASE = <?= json_encode(rtrim(JIRA_SITE, '/') . '/browse/') ?>;

const state = { limit:20, offset:0 };
let issueEditModal;
const lastUpdate        = document.getElementById('lastUpdate');
const previousUpdate    = document.getElementById('previousUpdate');
const totalAbiertas     = document.getElementById('totalAbiertas');
const totalAbiertasDiff = document.getElementById('totalAbiertasDiff');
const stateCards        = document.getElementById('stateCards');
const priorityCards     = document.getElementById('priorityCards');

const filterForm        = document.getElementById('filterForm');
const estado            = document.getElementById('estado');
const prioridad         = document.getElementById('prioridad');
const project           = document.getElementById('project');
const q                 = document.getElementById('q');
const btnClear          = document.getElementById('btnClear');
const btnSyncNow        = document.getElementById('btnSyncNow');
const issueRows         = document.getElementById('issueRows');

const editJiraKey       = document.getElementById('editJiraKey');
const editSummary       = document.getElementById('editSummary');
const editPriority      = document.getElementById('editPriority');
const editAssignee      = document.getElementById('editAssignee');
const editStatus        = document.getElementById('editStatus');
const editModalInfo     = document.getElementById('editModalInfo');
const btnSaveIssueEdit  = document.getElementById('btnSaveIssueEdit');
// =======================================================
// UTILIDADES
// =======================================================

const fmtDiff = n => {
  if (n > 0) return `<span class="metric-diff plus">+${n}</span>`;
  if (n < 0) return `<span class="metric-diff minus">${n}</span>`;
  return `<span class="metric-diff zero">0</span>`;
};


function escapeHtml(str) {
  return String(str ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function buildIssueRowHtml(it) {
  return `
    <tr id="issue-row-${it.jira_key}">
      <td>${escapeHtml(it.jira_key)}</td>
      <td>${escapeHtml(it.summary)}</td>
      <td>${escapeHtml(it.status)}</td>
      <td>${escapeHtml(it.priority)}</td>
      <td>${it.assigned ? '✅ ' + escapeHtml(it.assignee || '') : 'Pendiente'}</td>
      <td>${escapeHtml(it.created_at)}</td>
      <td>
        <div class="issue-actions">
          <button class="btn btn-sm btn-outline-primary btn-edit-issue" data-key="${escapeHtml(it.jira_key)}">Editar</button>
          <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="${escapeHtml(it.jira_url || (JIRA_BROWSE_BASE + it.jira_key))}">Abrir en Jira</a>
        </div>
      </td>
    </tr>
  `;
}

function replaceIssueRow(row) {
  const existing = document.getElementById(`issue-row-${row.jira_key}`);
  if (!existing) {
    loadIssues();
    return;
  }

  const tmp = document.createElement('tbody');
  tmp.innerHTML = buildIssueRowHtml(row).trim();
  const newRow = tmp.firstElementChild;
  existing.replaceWith(newRow);
}

function fillSelect(select, options, selectedValue, placeholder = '—') {
  select.innerHTML = '';

  const empty = document.createElement('option');
  empty.value = '';
  empty.textContent = placeholder;
  select.appendChild(empty);

  options.forEach(opt => {
    const el = document.createElement('option');
    el.value = opt.value;
    el.textContent = opt.label;
    if (String(opt.value) === String(selectedValue ?? '')) {
      el.selected = true;
    }
    select.appendChild(el);
  });
}

// =======================================================
// DASHBOARD ESTADOS + PRIORIDADES
// =======================================================
async function loadDashboard() {
  console.log('loadDashboard()');
  try {
    const res = await fetch(API_DASHBOARD + '?t=' + Date.now());
    const text = await res.text();

    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      console.error('dashboard.php NO devuelve JSON válido:', text);
      return;
    }

    if (!json.ok) {
      console.error('dashboard.php respondió ok=false:', json);
      return;
    }

    const { last, previous, diff } = json;

    lastUpdate.textContent = last?.created_at ?? '—';
    previousUpdate.textContent = previous?.created_at ?? '—';

    totalAbiertas.textContent = last?.total_abiertas ?? 0;
    totalAbiertasDiff.innerHTML = fmtDiff(diff?.total_abiertas ?? 0);

    const estados = {
      esperando_ayuda: "Esperando ayuda",
      escalated: "Escalated",
      en_curso: "En curso",
      pending: "Pending",
      waiting_approval: "Waiting approval",
      waiting_customer: "Waiting customer",
      cerrado_unificado: "Cerrados"
    };

    stateCards.innerHTML = '';
    Object.entries(estados).forEach(([k, label]) => {
      stateCards.innerHTML += `
        <div class="card card-metric shadow-sm">
          <div class="card-body text-center p-2">
            <div class="metric-title">${label}</div>
            <div class="metric-value">${last?.[k] ?? 0}</div>
            <div>${fmtDiff(diff?.[k] ?? 0)}</div>
          </div>
        </div>`;
    });

    const prioridades = [
      { id:1, label:"P1 – Critical" },
      { id:2, label:"P2 – High" },
      { id:3, label:"P3 – Medium" },
      { id:4, label:"P4 – Low" },
      { id:5, label:"P5 – Lowest" }
    ];

    priorityCards.innerHTML = '';
    prioridades.forEach(p => {
      const key = 'p' + p.id;
      priorityCards.innerHTML += `
        <div class="card card-metric shadow-sm">
          <div class="card-body text-center p-2">
            <div class="metric-title">${p.label}</div>
            <div class="metric-value">${last?.[key] ?? 0}</div>
            <div>${fmtDiff(diff?.[key] ?? 0)}</div>
          </div>
        </div>`;
    });

  } catch (err) {
    console.error('Error en loadDashboard():', err);
  }
}

// =======================================================
// LISTA INCIDENCIAS
// =======================================================
async function loadIssues() {
  console.log('loadIssues()')
  try {
    const params = new URLSearchParams({
      limit: state.limit,
      offset: state.offset,
      t: Date.now()
    });

    if (estado.value)    params.set('estado', estado.value);
    if (prioridad.value) params.set('prioridad', prioridad.value);
    if (project.value)   params.set('project', project.value.trim());
    if (q.value)         params.set('q', q.value.trim());

    const res = await fetch(`${API_ISSUES}?${params}`);
    const text = await res.text();

    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      console.error('issues.php NO devuelve JSON válido:', text);
      issueRows.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Error: issues.php no devuelve JSON válido</td></tr>`;
      return;
    }

    issueRows.innerHTML = '';

    if (!json.ok || !json.data || !json.data.length) {
      issueRows.innerHTML = `<tr><td colspan="7" class="text-center text-muted">Sin datos</td></tr>`;
      return;
    }

    issueRows.innerHTML = json.data.map(buildIssueRowHtml).join('');

  } catch (err) {
    console.error('Error en loadIssues():', err);
    issueRows.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Error cargando incidencias</td></tr>`;
  }
}

// =======================================================
// MODAL EDICIÓN
// =======================================================
async function openIssueEditModal(jiraKey) {
  const res = await fetch(`${API_JIRA_UPDATE}?key=${encodeURIComponent(jiraKey)}&t=${Date.now()}`);
  const json = await res.json();

  if (!json.ok) {
    alert(json.error || 'No se pudo cargar el contexto de edición.');
    return;
  }

  const issue = json.issue;

  editJiraKey.value = issue.jira_key;
  editSummary.value = issue.summary || '';

  fillSelect(
    editPriority,
    json.priority_options.map(opt => ({ value: String(opt.level), label: opt.label })),
    String(issue.prioridad_nivel || ''),
    'Sin cambios'
  );

  fillSelect(
    editAssignee,
    json.assignee_options.map(opt => ({ value: opt.account_id, label: opt.display_name })),
    issue.assignee_account_id || '',
    'Sin asignar'
  );

  fillSelect(
    editStatus,
    json.status_options.map(opt => ({ value: opt.id, label: opt.status_name || opt.name })),
    '',
    'Sin cambios'
  );

  editModalInfo.textContent = `${issue.jira_key} · Estado actual: ${issue.status_name} · Prioridad actual: ${issue.priority_name || '—'}`;
  issueEditModal.show();
}

async function saveIssueEdit() {
  const payload = {
    jira_key: editJiraKey.value,
    summary: editSummary.value.trim(),
    priority_level: editPriority.value || null,
    assignee_account_id: editAssignee.value,
    transition_id: editStatus.value || null,
  };

  btnSaveIssueEdit.disabled = true;

  try {
    const res = await fetch(API_JIRA_UPDATE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (!json.ok) {
      alert(json.error || 'No se pudo actualizar la incidencia.');
      return;
    }

    replaceIssueRow(json.row);
    issueEditModal.hide();

  } catch (err) {
    console.error(err);
    alert('Error de red actualizando la incidencia.');
  } finally {
    btnSaveIssueEdit.disabled = false;
  }
}

// =======================================================
// EVENTOS
// =======================================================
filterForm.addEventListener('submit', e => {
  e.preventDefault();
  state.offset = 0;
  loadIssues();
});

btnClear.addEventListener('click', () => {
  estado.value = prioridad.value = project.value = q.value = '';
  loadIssues();
});

btnSyncNow.addEventListener('click', async () => {
  await fetch(API_SYNC + '?full=1');
  await loadDashboard();
  await loadIssues();
});

document.addEventListener('click', async e => {
  const btnEdit = e.target.closest('.btn-edit-issue');
  if (!btnEdit) return;

  const jiraKey = btnEdit.dataset.key;
  if (jiraKey) {
    await openIssueEditModal(jiraKey);
  }
});

btnSaveIssueEdit.addEventListener('click', saveIssueEdit);

// =======================================================
// INIT + AUTO REFRESH
// =======================================================
(async () => {
  try {
    console.log('init start');

    issueEditModal = new bootstrap.Modal(document.getElementById('issueEditModal'));
    console.log('modal ok');

    await loadDashboard();
    console.log('dashboard ok');

    await loadIssues();
    console.log('issues ok');

    setInterval(() => {
      loadDashboard();
      loadIssues();
    }, 60 * 1000);

    console.log('init end');

  } catch (err) {
    console.error('Error en init index:', err);
  }
})();
</script>
</body>
</html>
