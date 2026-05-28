# Guía de la Aplicación Jira Sync + IA

**Audiencia:** usuarios funcionales, administradores y programadores  
**Formato:** guía de usuario + guía técnica  
**Aplicación:** Monitorización de incidencias Jira con IA, alertas, timeline e informes

---

# 1. Introducción general

Esta aplicación permite sincronizar incidencias desde Jira Cloud, almacenarlas en una base de datos local y analizarlas mediante IA.

La aplicación cubre:

- Panel inicial de incidencias.
- Sincronización Jira → base de datos.
- Dashboard por estados y prioridades.
- Timeline de evolución de incidencias.
- Edición manual de incidencias desde la app.
- Alertas críticas detectadas por IA.
- Informes IA de incidencias.
- Informes IA 12H de evolución de cola.
- Informes IA de calidad de cierre.
- Notificaciones por email, Teams y llamadas telefónicas.
- Gestión de usuarios.
- Configuración global de IA.

---

# 2. Arquitectura general

## 2.1. Flujo general

```text
Jira Cloud
   ↓
Servicios PHP
   ↓
Base de datos MySQL/MariaDB
   ↓
Endpoints public/api
   ↓
Páginas PHP + JavaScript
   ↓
Usuario
```

## 2.2. Capas principales

```text
public/*.php
```

Páginas visuales de la aplicación.

```text
public/api/*.php
```

Endpoints HTTP consumidos por las páginas.

```text
app/services/*.php
```

Servicios de negocio: Jira, IA, sync, alertas, notificaciones, llamadas.

```text
app/models/*.php
```

Modelos de acceso a base de datos.

```text
app/config/*.php
```

Configuración global, base de datos y Jira.

```text
app/helpers/*.php
```

Autenticación, JSON, CORS y utilidades comunes.

---

# 3. Autenticación, roles y navegación

## 3.1. Qué hace

La aplicación usa sesión PHP y roles.

Roles:

- `admin`
- `operador`

## 3.2. Archivos implicados

```text
public/login.php
public/logout.php
public/partials/navbar.php
app/helpers/Auth.php
app/models/UserModel.php
```

## 3.3. Flujo de login

```text
Usuario abre login.php
   ↓
Introduce usuario y contraseña
   ↓
login.php consulta tabla users
   ↓
password_verify valida password_hash
   ↓
Auth.php guarda usuario en sesión
   ↓
Se redirige según rol
```

## 3.4. Redirección por rol

Definido en:

```text
app/helpers/Auth.php
```

Función:

```php
auth_home_path()
```

Reglas:

```text
admin     → index.php
operador  → ai_alerts_page.php
```

## 3.5. Protección de páginas

Las páginas usan:

```php
auth_require_role('admin');
```

o:

```php
auth_require_role(['admin', 'operador']);
```

## 3.6. Protección de APIs

Los endpoints usan:

```php
auth_require_api_role('admin');
```

o:

```php
auth_require_api_role(['admin', 'operador']);
```

Si no hay sesión:

```json
{
  "ok": false,
  "error": "No autenticado."
}
```

## 3.7. Navbar

Archivo:

```text
public/partials/navbar.php
```

Admin ve:

- DXC / Home
- Timeline
- Conf IA
- Informes
- Alertas
- Usuarios
- Logout

Operador ve:

- Alertas
- Logout

---

# 4. Página inicial / Dashboard principal

## 4.1. Qué hace

La página inicial muestra el estado general de la cola de incidencias.

Incluye:

- Última actualización.
- Comparación con snapshot anterior.
- Total de tickets abiertos.
- Tarjetas por estado.
- Tarjetas por prioridad.
- Filtros.
- Tabla de incidencias.
- Botón `Sincronizar ahora`.
- Modal de edición rápida.

## 4.2. Archivo visual

```text
public/index.php
```

En este archivo están:

- estructura HTML
- estilos CSS
- lógica JavaScript
- tabla de incidencias
- modal de edición
- botones de sincronización

## 4.3. APIs usadas

```javascript
const API_DASHBOARD = "./api/dashboard.php";
const API_ISSUES = "./api/issues.php";
const API_SYNC = "./api/sync.php";
const API_JIRA_UPDATE = "./api/jira_update_issue.php";
```

## 4.4. Flujo dashboard

```text
public/index.php
   ↓ loadDashboard()
GET public/api/dashboard.php
   ↓
tabla snapshots
   ↓
último snapshot + snapshot anterior
   ↓
cálculo de diferencias
   ↓
JSON
   ↓
index.php pinta tarjetas
```

## 4.5. Flujo listado incidencias

```text
public/index.php
   ↓ loadIssues()
GET public/api/issues.php
   ↓
tabla issues
   ↓
aplica filtros
   ↓
JSON
   ↓
index.php pinta tabla
```

## 4.6. Filtros

Definidos en:

```text
public/index.php
```

Procesados en:

```text
public/api/issues.php
```

Filtros:

```text
estado
prioridad
project
q
limit
offset
```

## 4.7. Tabla origen

```text
issues
```

Campos usados:

```text
jira_key
summary
status_name
estado_categoria
priority_name
prioridad_nivel
assignee_display_name
created_at
updated_at
visible
```

## 4.8. Botón Sincronizar ahora

En `public/index.php`:

```javascript
await fetch(API_SYNC + "?full=1");
```

Flujo:

```text
Botón Sincronizar ahora
   ↓
public/api/sync.php?full=1
   ↓
SyncService->runSync(true)
   ↓
Jira Cloud
   ↓
issues / snapshots / issue_timeline / ai_closure_reports
   ↓
index.php recarga dashboard e incidencias
```

---

# 5. Endpoint de incidencias

## 5.1. Archivo

```text
public/api/issues.php
```

## 5.2. Qué hace

Tiene dos modos:

### Listado

Devuelve incidencias filtradas desde `issues`.

### Detalle

Si recibe:

```text
?key=LIP-XX
```

devuelve una incidencia concreta.

## 5.3. Flujo listado

```text
GET public/api/issues.php
   ↓
lee filtros GET
   ↓
construye WHERE
   ↓
consulta issues
   ↓
devuelve JSON
```

## 5.4. Respuesta

```json
{
  "ok": true,
  "data": [],
  "meta": {
    "total": 0,
    "limit": 20,
    "offset": 0,
    "page": 1,
    "pageSize": 20
  }
}
```

---

# 6. Dashboard y snapshots

## 6.1. Qué hace

El dashboard muestra la última foto agregada de la cola y la compara con la foto anterior.

## 6.2. Endpoint

```text
public/api/dashboard.php
```

## 6.3. Servicio que genera snapshots

```text
app/services/SnapshotService.php
```

## 6.4. Tabla

```text
snapshots
```

Campos principales:

```text
created_at
esperando_ayuda
escalated
en_curso
pending
waiting_approval
waiting_customer
cerrado_unificado
other
p1
p2
p3
p4
p5
total_abiertas
```

## 6.5. Flujo completo

```text
SyncService
   ↓
SnapshotService->createSnapshot()
   ↓
lee tabla issues
   ↓
agrupa por estado y prioridad
   ↓
inserta fila en snapshots
   ↓
dashboard.php lee último y penúltimo snapshot
   ↓
index.php pinta tarjetas
```

## 6.6. Estados del dashboard

```text
esperando_ayuda
escalated
en_curso
pending
waiting_approval
waiting_customer
cerrado_unificado
other
```

## 6.7. Cerrados

Los cierres se agrupan en:

```text
cerrado_unificado
```

Incluye estados como:

```text
Completado
Completed
Done
Closed
Resolved
Cancelled
Canceled
```

---

# 7. Sincronización Jira → Base de datos

## 7.1. Qué hace

La sincronización trae incidencias desde Jira Cloud y actualiza la base local.

También:

- actualiza `issues`
- genera snapshots
- registra cambios reales en `issue_timeline`
- detecta borrados en Jira
- genera informes de cierre automáticos

## 7.2. Endpoint

```text
public/api/sync.php
```

## 7.3. Servicio principal

```text
app/services/SyncService.php
```

## 7.4. Servicios relacionados

```text
app/services/JiraService.php
app/models/IssueModel.php
app/services/SnapshotService.php
app/services/IssueTimelineService.php
app/services/ClosureReportService.php
```

## 7.5. Flujo completo

```text
public/api/sync.php
   ↓
SyncService->runSync($full)
   ↓
IssueModel->getLastSyncTime()
   ↓
SyncService->buildIncrementalJql()
   ↓
JiraService->paginateAll()
   ↓
IssueModel->upsertBatchFromJiraIssues()
   ↓
IssueModel->setLastSyncTime()
   ↓
SyncService reconciliación de borrados
   ↓
SnapshotService->createSnapshot()
   ↓
IssueTimelineService->storeSnapshotStateIfStatusChanged()
   ↓
IssueTimelineService->storeDeletedIssuesAsClosed()
   ↓
ClosureReportService->generateReport() si hay cierres nuevos
```

## 7.6. Full sync

Se activa con:

```text
?full=1
```

En ese caso se ignora la última sincronización.

## 7.7. Sync incremental

Si no se indica `full=1`, se usa la última fecha guardada en:

```text
sync_metadata
```

Clave:

```text
issues_last_sync
```

La app aplica una ventana de seguridad de 2 minutos.

## 7.8. Campos pedidos a Jira

```text
id
key
summary
status
assignee
updated
created
project
priority
customfield_10041
customfield_10004
```

Donde:

```text
customfield_10041 → Urgency
customfield_10004 → Impact
```

## 7.9. Reconciliación de borrados

Si una incidencia estaba visible en local pero ya no existe en Jira:

```text
status_name = Completado
estado_categoria = cerrado_unificado
visible = 0
```

También se registra en:

```text
issue_timeline
```

## 7.10. Informes de cierre automáticos

Método:

```php
generateClosureReportsForNewlyClosedIssues($prevStates)
```

Regla:

- antes no estaba cerrada
- ahora sí está cerrada
- no existe informe previo para esa `jira_key`

Origen guardado:

```text
trigger_source = auto_sync
```

---

# 8. Cliente Jira para búsquedas

## 8.1. Archivo

```text
app/services/JiraService.php
```

## 8.2. API externa

Jira Cloud REST API v3.

Endpoint:

```text
/rest/api/3/search/jql
```

## 8.3. Paginación

Usa:

```text
nextPageToken
```

No usa:

```text
startAt
total
```

## 8.4. Flujo

```text
SyncService
   ↓
JiraService->paginateAll()
   ↓
JiraService->searchIssuesPage()
   ↓
GET Jira /search/jql
   ↓
JSON Jira
   ↓
callback onChunk
   ↓
IssueModel->upsertBatchFromJiraIssues()
```

## 8.5. Autenticación

Basic Auth con:

```text
JIRA_EMAIL
JIRA_API_TOKEN
```

Definido en:

```text
app/config/jira.php
```

---

# 9. Modelo de incidencias

## 9.1. Archivo

```text
app/models/IssueModel.php
```

## 9.2. Qué hace

Gestiona:

```text
issues
sync_metadata
```

Responsabilidades:

- leer última sync
- guardar última sync
- capturar estados previos
- mapear estados Jira
- resolver niveles de estado
- hacer UPSERT de incidencias

## 9.3. Mapeo de estados

Método:

```php
mapEstadoCategoria()
```

Ejemplos:

```text
Open / Abierta → esperando_ayuda
Escalated → escalated
In Progress / En curso / Investigar → en_curso
Pending → pending
Waiting for approval → waiting_approval
Waiting for customer → waiting_customer
Done / Completed / Closed / Resolved → cerrado_unificado
```

## 9.4. Visibilidad

Si una incidencia aparece cerrada por primera vez, puede seguir visible en esa sync.

Si ya estaba cerrada previamente:

```text
visible = 0
```

---

# 10. Timeline de incidencias

## 10.1. Qué hace

Muestra la evolución de estados de incidencias en bloques de 15 minutos.

## 10.2. Archivo visual

```text
public/timeline_page.php
```

## 10.3. API principal

```text
public/api/timeline.php
```

## 10.4. API detalle Jira

```text
public/api/jira_TLissue.php
```

## 10.5. Servicio histórico

```text
app/services/IssueTimelineService.php
```

## 10.6. Tabla

```text
issue_timeline
```

Campos principales:

```text
snapshot_time
jira_key
summary
status_name
estado_categoria
priority_name
prioridad_nivel
assignee_account_id
assignee_display_name
event_type
source
webhook_identifier
correlation_id
```

## 10.7. Flujo generación timeline

```text
SyncService
   ↓
IssueTimelineService->storeSnapshotStateIfStatusChanged()
   ↓
compara estado previo vs estado actual
   ↓
si cambia, inserta status_change
   ↓
issue_timeline
```

## 10.8. Flujo visual

```text
timeline_page.php
   ↓ loadTimeline()
GET public/api/timeline.php?from=...&to=...
   ↓
issue_timeline + issues
   ↓
agrupa por jira_key
   ↓
normaliza cierres como Completado
   ↓
devuelve slots de 15 minutos
   ↓
timeline_page.php pinta tabla de colores
```

## 10.9. Estructura visual

En:

```text
public/timeline_page.php
```

Se define:

- selector de fecha inicio
- selector de fecha fin
- botones `← 12h` y `12h →`
- leyenda de colores
- tabla timeline
- tarjeta de detalle
- modal de edición

## 10.10. Colores

Definidos en JavaScript:

```javascript
estadoColors;
```

Estados visuales:

```text
Abierta
Escalated
Work in progress
Pending
Waiting for approval
Esperando por el cliente
Completado
```

---

# 11. Edición manual de incidencias

## 11.1. Qué hace

Permite editar incidencias desde:

- página inicial
- timeline

## 11.2. Endpoint

```text
public/api/jira_update_issue.php
```

## 11.3. Servicio

```text
app/services/JiraIssueMutationService.php
```

## 11.4. Flujo GET

Carga contexto del modal:

```text
GET public/api/jira_update_issue.php?key=LIP-XX
   ↓
JiraIssueMutationService->getEditContext()
   ↓
issues local
   ↓
transiciones disponibles en Jira
   ↓
opciones de prioridad y asignado
   ↓
JSON para el modal
```

## 11.5. Flujo POST

```text
POST public/api/jira_update_issue.php
   ↓
JiraIssueMutationService->applyManualEdit()
   ↓
PUT Jira /issue/{key}
   ↓
POST Jira /issue/{key}/transitions si cambia estado
   ↓
GET Jira /issue/{key}
   ↓
IssueModel->upsertBatchFromJiraIssues()
   ↓
IssueTimelineService->appendEvent()
```

## 11.6. Cambios soportados

- título
- prioridad
- asignado
- estado

## 11.7. Eventos timeline generados

```text
summary_change
priority_change
assignee_change
status_change
```

Origen:

```text
source = app
```

---

# 12. Configuración global de IA

## 12.1. Qué hace

Permite definir:

- prompt general
- definición de incidencia crítica

## 12.2. Archivo visual

```text
public/ai_config.php
```

## 12.3. Endpoint

```text
public/api/ai_settings.php
```

## 12.4. Modelo

```text
app/models/AiSettingsModel.php
```

## 12.5. Tabla

```text
ai_settings
```

Campos:

```text
prompt_general
def_incidencia_critica
language
provider
model
is_active
```

## 12.6. Flujo de carga

```text
ai_config.php
   ↓ loadAiSettings()
GET public/api/ai_settings.php
   ↓
AiSettingsModel->getActiveSettings()
   ↓
ai_settings
   ↓
textareas de configuración
```

## 12.7. Flujo de guardado

```text
ai_config.php
   ↓ saveAiSettings()
POST public/api/ai_settings.php
   ↓
AiSettingsModel->saveSettings()
   ↓
ai_settings
```

## 12.8. Uso en IA

Se usa en:

```text
app/services/AiAnalysisService.php
```

Método:

```php
buildPrompts()
```

---

# 13. Informes IA de incidencias

## 13.1. Qué hacen

Analizan incidencias visibles y detectan críticas según la configuración IA.

## 13.2. Activación

Desde:

```text
public/ai_reports_page.php
```

Botón:

```text
Generar informe incidencia
```

## 13.3. Endpoint

```text
public/api/ai_generate_report.php
```

## 13.4. Servicio

```text
app/services/AiAnalysisService.php
```

## 13.5. Cliente OpenAI

```text
app/services/OpenAiProviderService.php
```

## 13.6. Modelo

```text
app/models/AiReportModel.php
```

## 13.7. Tablas

```text
ai_reports
ai_report_issues
```

## 13.8. Flujo completo

```text
Usuario pulsa Generar informe incidencia
   ↓
public/ai_reports_page.php
   ↓
POST public/api/ai_generate_report.php
   ↓
AiAnalysisService->generate()
   ↓
AiSettingsModel->getActiveSettings()
   ↓
SELECT issues WHERE visible = 1
   ↓
AiReportModel->createPendingReport()
   ↓
AiAnalysisService->buildPrompts()
   ↓
OpenAiProviderService->analyze()
   ↓
OpenAI API
   ↓
AiAnalysisService->normalize()
   ↓
AiReportModel->markCompleted()
   ↓
AiReportModel->saveIssueAnalyses()
   ↓
AlertNotificationService->notifyNewAlertsForReport()
```

## 13.9. Dónde está el prompt

Archivo:

```text
app/services/AiAnalysisService.php
```

Método:

```php
private function buildPrompts(array $cfg, array $issues): array
```

Contenido enviado:

```text
PROMPT:
{prompt_general}

CRÍTICAS:
{def_incidencia_critica}

INCIDENCIAS:
{JSON de incidencias visibles}
```

## 13.10. OpenAI

Archivo:

```text
app/services/OpenAiProviderService.php
```

Endpoint:

```text
https://api.openai.com/v1/chat/completions
```

Modelo:

```text
gpt-4.1-mini
```

Formato esperado:

```php
'response_format' => ['type' => 'json_object']
```

## 13.11. Resultado esperado

La app espera JSON con:

```text
report_summary
report_text
issues[]
```

Cada incidencia puede contener:

```text
jira_key
is_critical
critical_reason
recommended_action
analysis_text
score
```

## 13.12. Visualización

Vista:

```text
public/ai_reports_page.php
```

Detalle:

```text
public/api/ai_report_detail.php?type=incidencia&id=...
```

---

# 14. Alertas críticas

## 14.1. Qué hacen

Muestran incidencias que la IA marcó como críticas y que siguen sin asignar.

## 14.2. Página visual

```text
public/ai_alerts_page.php
```

## 14.3. Endpoint

```text
public/api/alerts.php
```

## 14.4. Modelo usado

```text
app/models/AiReportModel.php
```

Método:

```php
getLatestCriticalUnassignedAlerts()
```

## 14.5. Flujo

```text
ai_alerts_page.php
   ↓ loadAlerts()
GET public/api/alerts.php
   ↓
AiReportModel->getLatestCriticalUnassignedAlerts()
   ↓
ai_report_issues + ai_reports + issues
   ↓
JSON
   ↓
ai_alerts_page.php pinta tarjetas
```

## 14.6. Reglas

Aparece como alerta si:

- última evaluación IA es crítica
- está visible
- no tiene asignado

## 14.7. Coger incidencia

Endpoint:

```text
public/api/claim_alert.php
```

Flujo:

```text
Operador pulsa Coger incidencia
   ↓
POST public/api/claim_alert.php
   ↓
lee usuario autenticado
   ↓
usa jira_account_id del usuario
   ↓
JiraIssueMutationService->applyManualEdit()
   ↓
asigna incidencia en Jira
   ↓
actualiza issues local
   ↓
registra assignee_change en issue_timeline
   ↓
alerta desaparece del listado
```

---

# 15. Notificaciones de alertas

## 15.1. Qué hacen

Cuando se detectan nuevas alertas críticas, la app intenta avisar por:

- email
- Teams
- llamada telefónica

## 15.2. Servicio principal

```text
app/services/AlertNotificationsService.php
```

## 15.3. Activación

Al final de:

```text
AiAnalysisService->generate()
```

Código:

```php
$alertNotifier->notifyNewAlertsForReport($reportId);
```

## 15.4. Flujo

```text
AiAnalysisService termina informe
   ↓
AlertNotificationService->notifyNewAlertsForReport()
   ↓
getNewAlertsForReport()
   ↓
por cada alerta:
      ↓
      sendAlertEmailToAll()
      ↓
      sendAlertToTeams()
      ↓
      PhoneCallNotificationService->callUsersForAlert()
      ↓
      saveNotificationStatus()
```

## 15.5. Evitar duplicados

Tabla:

```text
alert_notifications
```

Antes de notificar, comprueba si la `jira_key` ya fue notificada.

## 15.6. Email

Servicio:

```text
app/services/SmtpMailService.php
```

Variables:

```text
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_FROM_EMAIL
SMTP_FROM_NAME
```

El correo se envía a usuarios activos cuyo `username` sea email válido.

## 15.7. Teams

Método:

```php
sendAlertToTeams()
```

Variable:

```text
TEAMS_WEBHOOK_URL
```

Payload:

```json
{
  "text": "mensaje de alerta"
}
```

Variables relacionadas:

```text
CURL_CAINFO
LOCAL_DISABLE_SSL_VERIFY
```

## 15.8. Llamadas Twilio

Servicio:

```text
app/services/PhoneCallNotificationService.php
```

API externa:

```text
https://api.twilio.com/2010-04-01/Accounts/{SID}/Calls.json
```

Variables:

```text
TWILIO_ACCOUNT_SID
TWILIO_AUTH_TOKEN
TWILIO_PHONE_NUMBER
TWILIO_TTS_LANGUAGE
```

Usuarios llamables:

```text
is_active = 1
phone_notifications_enabled = 1
phone_number no vacío
```

Registro:

```text
alert_phone_notifications
```

## 15.9. Reintento

Endpoint:

```text
public/api/retry_alert_notifications.php
```

Servicio:

```php
AlertNotificationService->retryNotificationsForReport($reportId)
```

Reintenta sin volver a ejecutar IA.

---

# 16. Informes 12H

## 16.1. Qué hacen

Generan un informe ejecutivo de evolución de cola en el último bloque cerrado.

Bloques:

```text
00:00 - 11:59 → Mañana
12:00 - 23:59 → Tarde
```

## 16.2. Activación

Desde:

```text
public/ai_reports_page.php
```

Botón:

```text
Generar informe 12H
```

## 16.3. Endpoint

```text
public/api/generate_queue_report.php
```

## 16.4. Servicio

```text
app/services/QueueReportService.php
```

## 16.5. Tabla

```text
ai_queue_reports
```

## 16.6. Flujo

```text
Usuario pulsa Generar informe 12H
   ↓
POST public/api/generate_queue_report.php
   ↓
QueueReportService->generateReport('manual_button')
   ↓
getClosedPeriod()
   ↓
calculateMetrics()
   ↓
getPreviousPeriodMetrics()
   ↓
compareWithPrevious()
   ↓
buildAiPayload()
   ↓
buildPrompt()
   ↓
callOpenAI()
   ↓
markCompleted()
   ↓
ai_queue_reports
```

## 16.7. Métricas

```text
open_start
open_end
incoming
resolved
unassigned
```

## 16.8. Fuentes

### Abiertas inicio / fin

Primero usa:

```text
snapshots.total_abiertas
```

Si no hay snapshot:

```text
issues
```

### Entrantes

```text
issues.created_at BETWEEN periodo
```

### Resueltas

```text
issue_timeline.event_type = status_change
estado_categoria = cerrado_unificado
```

### Sin asignar

```text
issues.visible = 1
assignee_account_id vacío o NULL
```

## 16.9. Dónde está el prompt

Archivo:

```text
app/services/QueueReportService.php
```

Método:

```php
private function buildPrompt(array $payload): string
```

Estructura solicitada:

```text
1. Resumen ejecutivo
2. Estado general de la cola
3. Evolución entrantes vs resueltas
4. Identificación de problemas
5. Anomalías detectadas
6. Riesgos
7. Recomendaciones accionables
```

## 16.10. OpenAI

Método:

```php
callOpenAI()
```

Endpoint:

```text
https://api.openai.com/v1/chat/completions
```

Modelo:

```text
gpt-4o-mini
```

## 16.11. Visualización

Vista:

```text
public/ai_reports_page.php
```

Detalle:

```text
public/api/ai_report_detail.php?type=12h&id=...
```

La UI muestra:

- abiertas inicio
- abiertas fin
- entrantes
- resueltas
- resumen ejecutivo
- informe completo
- métricas calculadas
- prompt usado

---

# 17. Informes de cierre

## 17.1. Qué hacen

Evalúan la calidad del proceso de cierre de una incidencia.

No analizan comentarios de Jira, porque actualmente no se guardan comentarios.

Analizan:

- timeline
- cambios de estado
- cambios de asignación
- secuencia de estados
- estabilidad del flujo
- trazabilidad del proceso

## 17.2. Activación manual

Endpoint:

```text
public/api/generate_closure_report.php
```

Body:

```json
{
  "issue_id": 2940
}
```

## 17.3. Activación automática

Durante la sincronización:

```text
SyncService->generateClosureReportsForNewlyClosedIssues()
```

Se genera si:

- antes no estaba cerrada
- ahora sí está cerrada
- no existe ya informe para esa `jira_key`

## 17.4. Servicio

```text
app/services/ClosureReportService.php
```

## 17.5. Tabla

```text
ai_closure_reports
```

Campos:

```text
issue_id
jira_key
status
rating
report_summary
report_text
raw_response_json
error_message
trigger_source
started_at
completed_at
created_at
```

## 17.6. Evitar duplicados

Hay índice único sobre:

```text
jira_key
```

## 17.7. Flujo completo

```text
Cierre detectado o generación manual
   ↓
ClosureReportService->generateReport()
   ↓
getIssue()
   ↓
getTimeline()
   ↓
createPendingReport()
   ↓
analyzeTimeline()
   ↓
buildAiPayload()
   ↓
buildPrompt()
   ↓
callOpenAI()
   ↓
extractRating()
   ↓
markCompleted()
   ↓
ai_closure_reports
```

## 17.8. Dónde está el prompt

Archivo:

```text
app/services/ClosureReportService.php
```

Método:

```php
private function buildPrompt(array $payload): string
```

Estructura solicitada:

```text
- Rating global entre 1 y 10, formato Rating: X/10
- Resumen ejecutivo
- Puntos positivos
- Carencias
- Recomendaciones
- Flujo ideal
```

## 17.9. Datos enviados a IA

Método:

```php
buildAiPayload()
```

Incluye:

```text
jira_key
summary
priority
total_events
state_changes
assignments
unique_states
states_sequence
```

## 17.10. Rating

Método:

```php
extractRating()
```

Busca:

```text
Rating: X/10
```

Si no lo encuentra:

```text
5
```

## 17.11. Visualización

Vista:

```text
public/ai_reports_page.php
```

Detalle:

```text
public/api/ai_report_detail.php?type=closure&id=...
```

Muestra:

- rating
- resumen del cierre
- informe completo
- raw JSON técnico

---

# 18. Pantalla de informes

## 18.1. Qué hace

Centraliza informes:

```text
incidencia
12h
closure
```

## 18.2. Archivo visual

```text
public/ai_reports_page.php
```

## 18.3. Endpoint listado

```text
public/api/ai_reports.php
```

## 18.4. Endpoint detalle

```text
public/api/ai_report_detail.php
```

## 18.5. Flujo listado

```text
ai_reports_page.php
   ↓ loadReports()
GET public/api/ai_reports.php
   ↓
UNION ALL:
   - ai_reports
   - ai_queue_reports
   - ai_closure_reports
   ↓
JSON con report_type
   ↓
frontend pinta badges y filtros
```

## 18.6. Filtros

```text
Todos
Incidencias
12H
Cierres
```

## 18.7. Badges

```text
INCIDENCIA
12H
CIERRE
```

## 18.8. Funciones visuales clave

En:

```text
public/ai_reports_page.php
```

Funciones:

```text
buildReportContentHtml()        → informe incidencia
buildQueueReportContentHtml()   → informe 12H
buildClosureReportContentHtml() → informe cierre
```

---

# 19. Gestión de usuarios

## 19.1. Qué hace

Permite al administrador:

- crear usuarios
- listar activos
- mostrar inactivos
- editar datos administrativos
- configurar teléfono
- activar/desactivar llamadas

## 19.2. Archivo visual

```text
public/admin_users_page.php
```

## 19.3. Endpoints

```text
public/api/admin_users.php
public/api/admin_create_user.php
public/api/admin_update_user.php
```

## 19.4. Modelos y servicios

```text
app/models/UserModel.php
app/services/JiraUserProvisionService.php
app/services/SmtpMailService.php
```

## 19.5. Tabla

```text
users
```

Campos:

```text
username
password_hash
display_name
role
jira_account_id
phone_number
phone_notifications_enabled
is_active
```

## 19.6. Crear usuario

```text
admin_users_page.php
   ↓
POST public/api/admin_create_user.php
   ↓
JiraUserProvisionService->registerUser()
   ↓
valida entrada
   ↓
POST Jira /rest/api/3/user
   ↓
resuelve accountId
   ↓
UserModel->createUser()
   ↓
envía email login
   ↓
envía email Teams si hay URL
```

## 19.7. APIs externas al crear usuario

Jira:

```text
POST /rest/api/3/user
GET /rest/api/3/user/search?query=email
```

SMTP:

```text
SmtpMailService->sendHtmlMail()
```

## 19.8. Editar usuario

Endpoint:

```text
public/api/admin_update_user.php
```

Permite editar:

- nombre visible
- rol
- activo/inactivo
- teléfono
- llamadas automáticas

## 19.9. Reglas

- No puedes desactivarte a ti mismo.
- Debe quedar al menos 1 admin activo.
- Debe quedar al menos 1 usuario activo con llamadas habilitadas.
- Si las llamadas están activadas, teléfono obligatorio.

Formato teléfono:

```text
+34600111222
```

---

# 20. Configuración global y variables de entorno

## 20.1. constants.php

Archivo:

```text
app/config/constants.php
```

Hace:

- carga `.env`
- define `env()`
- define constantes
- configura timezone
- valida variables críticas

## 20.2. database.php

Archivo:

```text
app/config/database.php
```

Expone:

```php
getPDO()
```

## 20.3. jira.php

Archivo:

```text
app/config/jira.php
```

Funciones:

```text
jira_site()
jira_cloud_id()
jira_api_base()
jira_endpoint()
jira_search_url()
jira_headers()
```

## 20.4. Variables principales

### Base de datos

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
```

### Jira

```text
JIRA_SITE
JIRA_EMAIL
JIRA_API_TOKEN
JIRA_PROJECT_KEY
JIRA_JQL_BASE
JIRA_CLOUD_ID
JIRA_USE_ATLASSIAN_API
```

### OpenAI

```text
OPENAI_API_KEY
```

### SMTP

```text
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_FROM_EMAIL
SMTP_FROM_NAME
```

### Teams

```text
TEAMS_WEBHOOK_URL
TEAMS_CHANNEL_INVITE_URL
```

### Twilio

```text
TWILIO_ACCOUNT_SID
TWILIO_AUTH_TOKEN
TWILIO_PHONE_NUMBER
TWILIO_TTS_LANGUAGE
```

---

# 21. Base de datos

## 21.1. Tablas actuales

```text
ai_closure_reports
ai_queue_reports
ai_report_issues
ai_reports
ai_settings
alert_notifications
alert_phone_notifications
issue_timeline
issues
priority_map
snapshots
status_map
sync_metadata
users
```

## 21.2. Tabla issues

Guarda incidencias sincronizadas desde Jira.

Uso:

- dashboard
- listado
- filtros
- sync
- timeline
- informes IA
- alertas
- usuarios asignados

## 21.3. Tabla snapshots

Guarda fotos agregadas de la cola.

Uso:

- dashboard
- informes 12H

## 21.4. Tabla issue_timeline

Guarda eventos históricos.

Uso:

- timeline
- informes de cierre
- trazabilidad

## 21.5. Tablas IA

```text
ai_settings
ai_reports
ai_report_issues
ai_queue_reports
ai_closure_reports
```

## 21.6. Tablas alertas

```text
alert_notifications
alert_phone_notifications
```

## 21.7. Tabla users

Guarda usuarios locales, roles y configuración de llamadas.

---

# 22. APIs externas utilizadas

## 22.1. Jira Cloud REST API

Usada para:

- buscar incidencias
- editar incidencias
- cambiar estado
- obtener transiciones
- crear/invitar usuarios
- buscar accountId

Archivos:

```text
app/services/JiraService.php
app/services/JiraIssueMutationService.php
app/services/JiraUserProvisionService.php
```

## 22.2. OpenAI API

Usada para:

- informes de incidencias
- informes 12H
- informes de cierre

Endpoint:

```text
https://api.openai.com/v1/chat/completions
```

Archivos:

```text
app/services/OpenAiProviderService.php
app/services/QueueReportService.php
app/services/ClosureReportService.php
```

## 22.3. Teams / Power Automate webhook

Archivo:

```text
app/services/AlertNotificationsService.php
```

Variable:

```text
TEAMS_WEBHOOK_URL
```

## 22.4. SMTP

Archivo:

```text
app/services/SmtpMailService.php
```

Usado para:

- alertas por email
- confirmación de alta
- invitación a Teams

## 22.5. Twilio Voice API

Archivo:

```text
app/services/PhoneCallNotificationService.php
```

Endpoint:

```text
https://api.twilio.com/2010-04-01/Accounts/{SID}/Calls.json
```

---

# 23. Mapa rápido de archivos

## 23.1. Páginas visuales

```text
public/index.php                         → página inicial / dashboard / tabla incidencias
public/timeline_page.php                 → timeline tipo Gantt
public/ai_alerts_page.php                → alertas críticas sin asignar
public/ai_reports_page.php               → listado y detalle de informes
public/ai_config.php                     → configuración IA
public/admin_users_page.php              → gestión de usuarios
public/login.php                         → login
public/logout.php                        → logout
public/partials/navbar.php               → navegación común
```

## 23.2. APIs

```text
public/api/dashboard.php                 → datos del dashboard
public/api/issues.php                    → listado/detalle de incidencias
public/api/sync.php                      → lanza sync Jira
public/api/timeline.php                  → datos de timeline
public/api/jira_TLissue.php              → detalle Jira desde timeline
public/api/jira_update_issue.php         → edición de incidencia
public/api/alerts.php                    → listado de alertas
public/api/claim_alert.php               → asignar alerta al usuario logado
public/api/retry_alert_notifications.php → reintento de notificaciones
public/api/ai_reports.php                → listado unificado de informes
public/api/ai_report_detail.php          → detalle de informes
public/api/ai_generate_report.php        → generar informe incidencia
public/api/generate_queue_report.php     → generar informe 12H
public/api/generate_closure_report.php   → generar informe cierre
public/api/ai_settings.php               → leer/guardar configuración IA
public/api/admin_users.php               → listar usuarios
public/api/admin_create_user.php         → crear usuario
public/api/admin_update_user.php         → actualizar usuario
```

## 23.3. Servicios

```text
app/services/SyncService.php                  → orquestador de sincronización
app/services/JiraService.php                  → cliente Jira para búsquedas
app/services/SnapshotService.php              → genera snapshots
app/services/IssueTimelineService.php         → registra histórico timeline
app/services/JiraIssueMutationService.php     → edita incidencias en Jira
app/services/AiAnalysisService.php            → informes IA de incidencias
app/services/OpenAiProviderService.php        → cliente OpenAI JSON
app/services/QueueReportService.php           → informes 12H
app/services/ClosureReportService.php         → informes de cierre
app/services/AlertNotificationsService.php    → email, Teams y llamadas
app/services/PhoneCallNotificationService.php → llamadas Twilio
app/services/SmtpMailService.php              → envío SMTP
app/services/JiraUserProvisionService.php     → alta usuarios Jira + app
```

## 23.4. Modelos

```text
app/models/IssueModel.php        → issues y sync metadata
app/models/AiReportModel.php     → ai_reports y ai_report_issues
app/models/AiSettingsModel.php   → ai_settings
app/models/UserModel.php         → users
```

## 23.5. Configuración y helpers

```text
app/config/constants.php         → carga .env y constantes
app/config/database.php          → getPDO()
app/config/jira.php              → helpers Jira API
app/helpers/Auth.php             → autenticación y roles
app/helpers/Utils.php            → JSON, CORS y utilidades
```

---

# 24. Resumen final de flujos críticos

## 24.1. Sync Jira

```text
sync.php
   ↓
SyncService
   ↓
JiraService
   ↓
IssueModel
   ↓
issues
   ↓
SnapshotService
   ↓
snapshots
   ↓
IssueTimelineService
   ↓
issue_timeline
```

## 24.2. Informe incidencia IA

```text
ai_reports_page.php
   ↓
ai_generate_report.php
   ↓
AiAnalysisService
   ↓
OpenAiProviderService
   ↓
ai_reports / ai_report_issues
   ↓
AlertNotificationService
```

## 24.3. Alerta crítica

```text
ai_report_issues
   ↓
alerts.php
   ↓
ai_alerts_page.php
   ↓
claim_alert.php
   ↓
JiraIssueMutationService
   ↓
Jira
```

## 24.4. Informe 12H

```text
generate_queue_report.php
   ↓
QueueReportService
   ↓
snapshots / issues / issue_timeline
   ↓
OpenAI
   ↓
ai_queue_reports
```

## 24.5. Informe cierre

```text
SyncService o generate_closure_report.php
   ↓
ClosureReportService
   ↓
issue_timeline
   ↓
OpenAI
   ↓
ai_closure_reports
```

## 24.6. Crear usuario

```text
admin_users_page.php
   ↓
admin_create_user.php
   ↓
JiraUserProvisionService
   ↓
Jira + users + SMTP
```

---

# 25. Notas para mantenimiento

- La estructura visual de cada pantalla está en `public/*.php`.
- La lógica de negocio está en `app/services/*.php`.
- El acceso a datos está en `app/models/*.php`.
- Los prompts IA están en los servicios que generan informes.
- La configuración editable de IA está en `ai_settings`.
- Las alertas dependen de `ai_report_issues`.
- Los informes de cierre dependen de `issue_timeline`.
- Los informes 12H dependen de `snapshots`, `issues` e `issue_timeline`.
- La autenticación y permisos se controlan en `Auth.php`.
- Jira se centraliza principalmente en `JiraService`, `JiraIssueMutationService` y `JiraUserProvisionService`.
- OpenAI se usa en informes de incidencia, informes 12H e informes de cierre.
- Teams, email y llamadas se activan desde el flujo de alertas críticas.
