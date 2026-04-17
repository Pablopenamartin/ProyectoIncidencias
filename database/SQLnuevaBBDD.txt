/* =========================================================
   MIGRACIÓN DE issue_timeline
   Objetivo:
   - Mantener compatibilidad con snapshots actuales
   - Preparar la tabla para webhooks / app / AI
   - Añadir trazabilidad con event_type, source,
     webhook_identifier y correlation_id
========================================================= */

/* ---------------------------------------------------------
   1) Hacer snapshot_id nullable
   ---------------------------------------------------------
   Antes era obligatorio porque todo venía de snapshots.
   Ahora habrá eventos webhook que NO tendrán snapshot_id.
--------------------------------------------------------- */
ALTER TABLE issue_timeline
  MODIFY COLUMN snapshot_id BIGINT(20) NULL;

/* ---------------------------------------------------------
   2) Añadir columnas nuevas de trazabilidad y contexto
--------------------------------------------------------- */
ALTER TABLE issue_timeline
  ADD COLUMN status_id VARCHAR(32) NULL AFTER summary,
  ADD COLUMN priority_id VARCHAR(32) NULL AFTER estado_categoria,
  ADD COLUMN priority_name VARCHAR(64) NULL AFTER priority_id,
  MODIFY COLUMN prioridad_nivel TINYINT(4) NULL,
  ADD COLUMN assignee_account_id VARCHAR(64) NULL AFTER prioridad_nivel,
  ADD COLUMN assignee_display_name VARCHAR(255) NULL AFTER assignee_account_id,
  ADD COLUMN event_type VARCHAR(50) NULL AFTER assignee_display_name,
  ADD COLUMN source VARCHAR(20) NULL AFTER event_type,
  ADD COLUMN webhook_identifier VARCHAR(255) NULL AFTER source,
  ADD COLUMN correlation_id VARCHAR(255) NULL AFTER webhook_identifier;

/* ---------------------------------------------------------
   3) Rellenar filas históricas existentes
   ---------------------------------------------------------
   Todo lo actual en issue_timeline proviene del modelo
   snapshot/manual, así que lo marcamos como snapshot_sync
   y source = system
--------------------------------------------------------- */
UPDATE issue_timeline
SET
  event_type = 'snapshot_sync',
  source = 'system'
WHERE event_type IS NULL
   OR source IS NULL;

/* ---------------------------------------------------------
   4) Convertir event_type y source en obligatorias
--------------------------------------------------------- */
ALTER TABLE issue_timeline
  MODIFY COLUMN event_type VARCHAR(50) NOT NULL,
  MODIFY COLUMN source VARCHAR(20) NOT NULL;

/* ---------------------------------------------------------
   5) Índices nuevos para el modelo futuro
   ---------------------------------------------------------
   - event_type: consultar cambios por tipo
   - source: distinguir jira/app/ai/system
   - webhook_identifier: deduplicar reintentos
   - correlation_id: enlazar app/AI/Jira
--------------------------------------------------------- */
ALTER TABLE issue_timeline
  ADD INDEX idx_it_event_type (event_type),
  ADD INDEX idx_it_source (source),
  ADD INDEX idx_it_webhook_identifier (webhook_identifier),
  ADD INDEX idx_it_correlation_id (correlation_id);

/* ---------------------------------------------------------
   6) Restricción lógica de deduplicación por webhook
   ---------------------------------------------------------
   Solo si más adelante procesas webhooks:
   - mismo jira_key
   - mismo webhook_identifier
   - mismo event_type
   no debería insertarse dos veces

   IMPORTANTE:
   En MariaDB / MySQL, múltiples NULL en UNIQUE están permitidos,
   así que las filas antiguas con webhook_identifier = NULL
   no romperán esta restricción.
--------------------------------------------------------- */
ALTER TABLE issue_timeline
  ADD UNIQUE KEY uq_it_jira_webhook_event (
    jira_key,
    webhook_identifier,
    event_type
  );