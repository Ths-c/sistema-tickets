-- =====================================================================
-- MIGRACIÓN: bloqueo de creación de tickets por escuela individual
-- + configuración del límite de tickets abiertos simultáneos por escuela
--
-- Qué agrega:
--   · escuelas.tickets_bloqueados  (0/1) — si está en 1, esa escuela puntual
--     no puede crear tickets nuevos, aunque el sistema esté habilitado
--     globalmente.
--   · escuelas.bloqueo_fecha, escuelas.bloqueo_responsable — trazabilidad
--     de quién y cuándo bloqueó esa escuela puntual.
--   · configuracion_sistema.limite_tickets_abiertos_escuela — cantidad
--     máxima de tickets abiertos (no cerrados/cancelados) que puede tener
--     una misma escuela al mismo tiempo (por defecto: 5).
--
-- Cómo aplicarlo:
--   1. phpMyAdmin → base "tickets_distrital" → pestaña "SQL"
--   2. Pegá este contenido completo y ejecutá
-- =====================================================================

USE tickets_distrital;

-- ── escuelas.tickets_bloqueados ──────────────────────────────
SET @existe1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'escuelas'
      AND COLUMN_NAME  = 'tickets_bloqueados'
);
SET @sql1 = IF(@existe1 = 0,
    'ALTER TABLE escuelas ADD COLUMN tickets_bloqueados TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "columna tickets_bloqueados ya existe en escuelas"'
);
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- ── escuelas.bloqueo_fecha ───────────────────────────────────
SET @existe2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'escuelas'
      AND COLUMN_NAME  = 'bloqueo_fecha'
);
SET @sql2 = IF(@existe2 = 0,
    'ALTER TABLE escuelas ADD COLUMN bloqueo_fecha DATETIME NULL',
    'SELECT "columna bloqueo_fecha ya existe en escuelas"'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- ── escuelas.bloqueo_responsable ─────────────────────────────
SET @existe3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'escuelas'
      AND COLUMN_NAME  = 'bloqueo_responsable'
);
SET @sql3 = IF(@existe3 = 0,
    'ALTER TABLE escuelas ADD COLUMN bloqueo_responsable VARCHAR(150) NULL',
    'SELECT "columna bloqueo_responsable ya existe en escuelas"'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- ── configuracion_sistema.limite_tickets_abiertos_escuela ────
INSERT IGNORE INTO configuracion_sistema (clave, valor, descripcion) VALUES
 ('limite_tickets_abiertos_escuela', '5',
  'Cantidad máxima de tickets abiertos (no cerrados ni cancelados) que puede tener una misma escuela al mismo tiempo');

SELECT 'Migración completada correctamente.' AS resultado;
