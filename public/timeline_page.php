<?php
/**
 * public/timeline_page.php
 * -------------------------------------------------------
 * Timeline / Gantt de incidencias (vista de 12 horas).
 */
require_once __DIR__ . '/../app/config/constants.php';
// Carga las constantes del .env antes de renderizar el HTML/JS.
// Esto evita el error por usar env()/
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Timeline de Incidencias</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f8f9fa; }
    table.timeline th,
    table.timeline td {
      text-align:center;
      font-size:.75rem;
      padding:.25rem;
      min-width:42px;
      white-space:nowrap;
    }
    table.timeline th:first-child,
    table.timeline td:first-child {
      position:sticky;
      left:0;
      background:#fff;
      font-weight:600;
      z-index:2;
    }
    .state-cell {
      height:22px;
      border-radius:4px;
      margin:2px auto;
    }
    .legend-box {
      width:22px;
      height:22px;
      border-radius:4px;
      flex-shrink:0;
    }
    .legend-item {
      display:flex;
      align-items:center;
      gap:.5rem;
      font-size:.8rem;
    }
    .issue-link {
      color: inherit;
      text-decoration: none;
      cursor: pointer;
    }
    .issue-link:hover {
      text-decoration: underline;
    }
    input[type="time"] {
      font-variant-numeric: tabular-nums;
    }
    input[type="time"]::-webkit-datetime-edit-ampm-field {
      display: none;
    }
  </style>
</head>

<body>
<div id="page-wrapper">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Volver</a>

    <div class="d-flex align-items-center gap-3">
      <div class="d-flex flex-column">
        <label class="small">Fecha inicio</label>
        <input type="date" id="dateFrom" class="form-control form-control-sm">
        <div class="d-flex align-items-center gap-1 mt-1">
          <select id="hourFrom" class="form-select form-select-sm" style="width:70px"></select>
          <span>:</span>
          <select id="minuteFrom" class="form-select form-select-sm" style="width:70px"></select>
        </div>
      </div>

      <div class="d-flex flex-column">
        <label class="small">Fecha fin</label>
        <input type="date" id="dateTo" class="form-control form-control-sm">
        <div class="d-flex align-items-center gap-1 mt-1">
          <select id="hourTo" class="form-select form-select-sm" style="width:70px"></select>
          <span>:</span>
          <select id="minuteTo" class="form-select form-select-sm" style="width:70px"></select>
        </div>
      </div>

      <div class="align-self-end">
        <button id="btnApplyRange" class="btn btn-primary btn-sm">Aplicar</button>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
      <button class="btn btn-outline-secondary btn-sm" id="btnPrev">← 12h</button>
      <button class="btn btn-outline-secondary btn-sm" id="btnNext">12h →</button>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-3 mb-3">
    <div class="legend-item"><div class="legend-box" style="background:#f4a261"></div>Esperando ayuda</div>
    <div class="legend-item"><div class="legend-box" style="background:#e63946"></div>Escalated</div>
    <div class="legend-item"><div class="legend-box" style="background:#2a9d8f"></div>En curso</div>
    <div class="legend-item"><div class="legend-box" style="background:#6c757d"></div>Pending</div>
    <div class="legend-item"><div class="legend-box" style="background:#457b9d"></div>Waiting approval</div>
    <div class="legend-item"><div class="legend-box" style="background:#90dbf4"></div>Waiting customer</div>
    <div class="legend-item"><div class="legend-box" style="background:#2ecc71"></div>Finalizado</div>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered timeline" id="timelineTable">
      <thead></thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<div id="issueDetail" class="card mt-4 d-none">
  <div class="card-body">
    <h5 id="issueTitle" class="mb-2"></h5>
    <ul class="list-unstyled small mb-2">
      <li><strong>Estado:</strong> <span id="issueStatus"></span></li>
      <li><strong>Prioridad:</strong> <span id="issuePriority"></span></li>
      <li><strong>Asignado:</strong> <span id="issueAssignee"></span></li>
    </ul>

    <div class="mt-2">
      <strong>Descripción:</strong>
      <p id="issueDescription" class="mb-0"></p>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button id="btnEditTimelineIssue" class="btn btn-sm btn-outline-secondary d-none">Editar</button>
      <a id="issueJiraLink"
         href="#"
         target="_blank"
         rel="noopener noreferrer"
         class="btn btn-sm btn-outline-primary">
        Abrir en Jira
      </a>
    </div>
  </div>
</div>

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
          <label class="form-label">Título</label>
          <input type="text" class="form-control" id="editSummary">
        </div>

        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Prioridad</label>
            <select class="form-select" id="editPriority"></select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Asignado</label>
            <select class="form-select" id="editAssignee"></select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Estado destino</label>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// --------------------------------------------------
// DOM ELEMENTS (declaración explícita)
// --------------------------------------------------

const dateFrom   = document.getElementById('dateFrom');
const dateTo     = document.getElementById('dateTo');
const btnApplyRange = document.getElementById('btnApplyRange');

const btnPrev = document.getElementById('btnPrev');
const btnNext = document.getElementById('btnNext');

const timelineTable = document.getElementById('timelineTable');

const editJiraKey   = document.getElementById('editJiraKey');
const editSummary   = document.getElementById('editSummary');
const editPriority  = document.getElementById('editPriority');
const editAssignee  = document.getElementById('editAssignee');
const editStatus    = document.getElementById('editStatus');
const editModalInfo = document.getElementById('editModalInfo');
const btnSaveIssueEdit = document.getElementById('btnSaveIssueEdit');

const API = './api/timeline.php';
const API_JIRA_UPDATE = './api/jira_update_issue.php';
const SLOT_MINUTES = 15;

const JIRA_BROWSE_BASE = <?= json_encode(rtrim(JIRA_SITE, '/') . '/browse/') ?>;
// Genera un literal JavaScript válido usando la constante ya cargada desde


const issueDetail      = document.getElementById('issueDetail');
const issueTitle       = document.getElementById('issueTitle');
const issueDescription = document.getElementById('issueDescription');
const issueStatus      = document.getElementById('issueStatus');
const issuePriority    = document.getElementById('issuePriority');
const issueAssignee    = document.getElementById('issueAssignee');
const issueJiraLink    = document.getElementById('issueJiraLink');
const btnEditTimelineIssue = document.getElementById('btnEditTimelineIssue');

const hourFrom   = document.getElementById('hourFrom');
const minuteFrom = document.getElementById('minuteFrom');
const hourTo     = document.getElementById('hourTo');
const minuteTo   = document.getElementById('minuteTo');

const estadoColors = {
  'Abierta': '#f4a261',
  'Escalated': '#e63946',
  'Work in progress': '#2a9d8f',
  'Pending': '#6c757d',
  'Waiting for approval': '#457b9d',
  'Esperando por el cliente': '#90dbf4',
  'Completado': '#2ecc71'
};

let timeWindow = {};
let issueEditModal;
let currentDetailIssueKey = null;

function initWindow() {
  const now = new Date();
  const from = new Date(now);
  from.setMinutes(0, 0, 0);

  if (from.getHours() < 12) {
    from.setHours(0);
  } else {
    from.setHours(12);
  }

  const to = new Date(from.getTime() + 12 * 60 * 60 * 1000);
  timeWindow.from = from;
  timeWindow.to   = to;

  dateFrom.value = from.toISOString().slice(0, 10);
  dateTo.value   = to.toISOString().slice(0, 10);
  hourFrom.value   = String(from.getHours()).padStart(2,'0');
  minuteFrom.value = '00';

  if (to.getHours() === 0) {
    hourTo.value = '23';
    minuteTo.value = '59';
  } else {
    hourTo.value = String(to.getHours()).padStart(2,'0');
    minuteTo.value = '00';
  }
}

btnApplyRange.addEventListener('click', () => {
  if (!dateFrom.value || !dateTo.value) {
    alert('Selecciona fechas válidas');
    return;
  }

  const from = new Date(`${dateFrom.value}T${hourFrom.value}:${minuteFrom.value}:00`);
  let to;

  if (hourTo.value === '23' && minuteTo.value === '59') {
    const end = new Date(`${dateTo.value}T00:00:00`);
    to = new Date(end.getTime() + 24 * 60 * 60 * 1000);
  } else {
    to = new Date(`${dateTo.value}T${hourTo.value}:${minuteTo.value}:00`);
  }

  if (to <= from) {
    alert('El rango final debe ser mayor que el inicial');
    return;
  }

  timeWindow.from = from;
  timeWindow.to = to;
  loadTimeline();
});

function populateTimeSelects() {
  for (let h = 0; h < 24; h++) {
    const v = String(h).padStart(2, '0');
    hourFrom.add(new Option(v, v));
    hourTo.add(new Option(v, v));
  }

  ['00','15','30','45'].forEach(m => {
    minuteFrom.add(new Option(m, m));
    minuteTo.add(new Option(m, m));
  });
}

function buildSlots() {
  const slots = [];
  let t = new Date(timeWindow.from);
  while (t < timeWindow.to) {
    slots.push(String(t.getHours()).padStart(2,'0') + ':' + String(t.getMinutes()).padStart(2,'0'));
    t = new Date(t.getTime() + SLOT_MINUTES * 60 * 1000);
  }
  return slots;
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

async function openIssueEditModal(jiraKey) {
  const res = await fetch(`${API_JIRA_UPDATE}?key=${encodeURIComponent(jiraKey)}&t=${Date.now()}`);
  const json = await res.json();

  if (!json.ok) {
    alert(json.error || 'No se pudo cargar el contexto de edición.');
    return;
  }

  editJiraKey.value = json.issue.jira_key;
  editSummary.value = json.issue.summary || '';

  fillSelect(
    editPriority,
    json.priority_options.map(opt => ({ value: String(opt.level), label: opt.label })),
    String(json.issue.prioridad_nivel || ''),
    'Sin cambios'
  );

  fillSelect(
    editAssignee,
    json.assignee_options.map(opt => ({ value: opt.account_id, label: opt.display_name })),
    json.issue.assignee_account_id || '',
    'Sin asignar'
  );

  fillSelect(
    editStatus,
    json.status_options.map(opt => ({ value: opt.id, label: opt.status_name || opt.name })),
    '',
    'Sin cambios'
  );

  editModalInfo.textContent = `${json.issue.jira_key} · Estado actual: ${json.issue.status_name} · Prioridad actual: ${json.issue.priority_name || '—'}`;
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

    if (json.detail) {
      issueTitle.textContent = `${json.detail.key} - ${json.detail.summary}`;
      issueDescription.textContent = json.detail.description || 'Sin descripción';
      issueStatus.textContent = json.detail.status;
      issuePriority.textContent = json.detail.priority;
      issueAssignee.textContent = json.detail.assignee || '—';
      issueJiraLink.href = json.detail.url || (JIRA_BROWSE_BASE + json.detail.key);
    }

    issueEditModal.hide();
    await loadTimeline();

  } catch (err) {
    console.error(err);
    alert('Error de red actualizando la incidencia.');
  } finally {
    btnSaveIssueEdit.disabled = false;
  }
}

async function loadTimeline() {
  if (!(timeWindow.from instanceof Date) || isNaN(timeWindow.from.getTime())) return;
  if (!(timeWindow.to   instanceof Date) || isNaN(timeWindow.to.getTime())) return;

  
  const fmt = d =>
    d.getFullYear() + '-' +
    String(d.getMonth() + 1).padStart(2, '0') + '-' +
    String(d.getDate()).padStart(2, '0') + ' ' +
    String(d.getHours()).padStart(2, '0') + ':' +
    String(d.getMinutes()).padStart(2, '0');

  const params = new URLSearchParams({
    from: fmt(timeWindow.from),
    to:   fmt(timeWindow.to)
  });


  const res = await fetch(`${API}?${params}`);
  const json = await res.json();
  if (!json.ok) return;

  window.lastTimelineData = json.data;

  const lastSyncTime = json.last_sync ? new Date(json.last_sync.replace(' ', 'T')) : null;
  const now = (lastSyncTime instanceof Date && !isNaN(lastSyncTime.getTime())) ? lastSyncTime : timeWindow.to;

  const slots = buildSlots();

  timelineTable.querySelector('thead').innerHTML =
    '<tr><th>Incidencia</th>' + slots.map(s => `<th>${s}</th>`).join('') + '</tr>';

  const tbody = timelineTable.querySelector('tbody');
  tbody.innerHTML = '';

  json.data.forEach(row => {
    const tooltipText = `${row.jira_key}: ${row.summary}`;

    let tr = `
      <tr>
        <td>
          <a href="#"
            class="issue-link"
            data-key="${row.jira_key}"
            data-bs-toggle="tooltip"
            data-bs-placement="top"
            title="${tooltipText}">
            ${row.jira_key}
          </a>
        </td>
    `;

    let currentEstado = row.initial_state ?? null;
    let span = 0;
    let hasVisibleBlock = false;
    let currentSlotTime = null;
    let isFinished = false;

    slots.forEach((label, index) => {
      const slotTime = new Date(timeWindow.from.getTime() + index * SLOT_MINUTES * 60 * 1000);
      currentSlotTime = slotTime;

      if (slotTime > now) {
        if (span > 0) closeBlock();
        return;
      }

      const slotKey = String(slotTime.getHours()).padStart(2,'0') + ':' + String(slotTime.getMinutes()).padStart(2,'0');
      if (isFinished) return;

      if (row.states[slotKey]) {
        const nextEstado = row.states[slotKey];

        if (nextEstado !== currentEstado) {
          if (span > 0) closeBlock();
          currentEstado = nextEstado;
          span = 1;

          if (currentEstado === 'Completado') {
            closeBlock();
            isFinished = true;
            return;
          }
        } else if (currentEstado !== 'Completado') {
          span++;
        }
      } else if (currentEstado && currentEstado !== 'Completado') {
        span++;
      }
    });

    if (span > 0) closeBlock();
    

    tr += '</tr>';
    tbody.innerHTML += tr;

    function closeBlock() {
      tr += `
        <td colspan="${span}">
          <div class="state-cell"
              data-bs-toggle="tooltip"
              data-bs-placement="top"
              title="${tooltipText}"
              style="background:${estadoColors[currentEstado]}">
          </div>
        </td>
      `;

      if (currentSlotTime >= timeWindow.from) {
        hasVisibleBlock = true;
      }

      span = 0;
    }
  });

  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    const oldTooltip = bootstrap.Tooltip.getInstance(el);
    if (oldTooltip) oldTooltip.dispose();
  });

  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
  });
}

btnPrev.onclick = () => {
  timeWindow.from = new Date(timeWindow.from.getTime() - 12*60*60*1000);
  timeWindow.to   = new Date(timeWindow.to.getTime() - 12*60*60*1000);
  loadTimeline();
};

btnNext.onclick = () => {
  timeWindow.from = new Date(timeWindow.from.getTime() + 12*60*60*1000);
  timeWindow.to   = new Date(timeWindow.to.getTime() + 12*60*60*1000);
  loadTimeline();
};

document.addEventListener('click', async (e) => {
  const link = e.target.closest('.issue-link');
  if (!link) return;

  e.preventDefault();
  const issueKey = link.dataset.key;

  try {
    const res = await fetch(`./api/jira_TLissue.php?key=${issueKey}`);
    const json = await res.json();

    if (!json.ok) {
      console.error('Error Jira:', json);
      return;
    }

    currentDetailIssueKey = json.key;
    issueTitle.textContent = `${json.key} - ${json.summary}`;
    issueDescription.textContent = json.description || 'Sin descripción';
    issueStatus.textContent = json.status;
    issuePriority.textContent = json.priority;
    issueAssignee.textContent = json.assignee || '—';
    issueJiraLink.href = json.url;
    btnEditTimelineIssue.classList.remove('d-none');

    issueDetail.classList.remove('d-none');
    issueDetail.scrollIntoView({ behavior: 'smooth', block: 'start' });

  } catch (err) {
    console.error('Fallo cargando detalle Jira', err);
  }
});

btnEditTimelineIssue.addEventListener('click', async () => {
  if (!currentDetailIssueKey) return;
  await openIssueEditModal(currentDetailIssueKey);
});

btnSaveIssueEdit.addEventListener('click', saveIssueEdit);

populateTimeSelects();
initWindow();
issueEditModal = new bootstrap.Modal(document.getElementById('issueEditModal'));
loadTimeline();
</script>
</div>
</body>
</html>
