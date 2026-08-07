-- =====================================================================
-- MIGRACIÓN: actas de equipo en 4 etapas obligatorias
--
-- Flujo nuevo:
--   1) Ticket cargado
--   2) ENTREGA: la escuela entrega el equipo al proyecto      → acta obligatoria
--   3) ASIGNACIÓN: se asigna el equipo a un técnico             → acta obligatoria
--   4) RESOLUCIÓN: el técnico documenta el trabajo realizado    → acta obligatoria
--   5) DEVOLUCIÓN: el equipo vuelve a la escuela originante      → acta obligatoria
--
-- Qué hace:
--   · Agrega las columnas nuevas de las 4 etapas a actas_equipo.
--     (entrega_nombre_escuela y entrega_cargo_escuela ya existían con ese
--     nombre y el mismo significado en el modelo viejo, así que se reusan
--     tal cual, sin necesidad de crear columnas nuevas para esas dos).
--   · Copia los datos ya cargados con el modelo viejo (entrega/recepción)
--     a las columnas nuevas correspondientes, como mejor esfuerzo, para
--     no perder información ya asentada.
--   · NO borra las columnas viejas que ya no se usan (fecha_entrega,
--     estado_entrega, entrega_nombre_tecnico, fecha_recepcion,
--     trabajo_realizado, estado_recepcion, recepcion_nombre_tecnico),
--     quedan en la tabla sin uso por si hace falta consultarlas.
--
-- Cómo aplicarlo:
--   1. phpMyAdmin → base "tickets_distrital" → pestaña "SQL"
--   2. Pegá este contenido completo y ejecutá
-- =====================================================================

USE tickets_distrital;

DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS _agregar_col_actas(
    IN colname VARCHAR(64), IN coldef VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actas_equipo' AND COLUMN_NAME = colname
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE actas_equipo ADD COLUMN ', colname, ' ', coldef);
        PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- Etapa 1: ENTREGA (entrega_nombre_escuela y entrega_cargo_escuela ya existen)
CALL _agregar_col_actas('entrega_fecha',                'DATETIME NULL');
CALL _agregar_col_actas('entrega_estado_equipo',         'TEXT NULL');
CALL _agregar_col_actas('entrega_nombre_receptor',       'VARCHAR(150) NULL');

-- Etapa 2: ASIGNACIÓN (enteramente nueva)
CALL _agregar_col_actas('asignacion_fecha',              'DATETIME NULL');
CALL _agregar_col_actas('asignacion_nombre_tecnico',     'VARCHAR(150) NULL');
CALL _agregar_col_actas('asignacion_observaciones',      'TEXT NULL');

-- Etapa 3: RESOLUCIÓN
CALL _agregar_col_actas('resolucion_fecha',              'DATETIME NULL');
CALL _agregar_col_actas('resolucion_trabajo_realizado',  'TEXT NULL');
CALL _agregar_col_actas('resolucion_estado_equipo',      'TEXT NULL');

-- Etapa 4: DEVOLUCIÓN
CALL _agregar_col_actas('devolucion_fecha',              'DATETIME NULL');
CALL _agregar_col_actas('devolucion_nombre_tecnico',     'VARCHAR(150) NULL');
CALL _agregar_col_actas('devolucion_estado_equipo',      'TEXT NULL');
CALL _agregar_col_actas('devolucion_nombre_escuela',     'VARCHAR(150) NULL');
CALL _agregar_col_actas('devolucion_cargo_escuela',      'VARCHAR(100) NULL');

DROP PROCEDURE IF EXISTS _agregar_col_actas;

-- ── Traspaso de datos viejos → nuevos (mejor esfuerzo, no destructivo) ──
UPDATE actas_equipo
SET entrega_fecha           = COALESCE(entrega_fecha, fecha_entrega),
    entrega_estado_equipo   = COALESCE(entrega_estado_equipo, estado_entrega),
    entrega_nombre_receptor = COALESCE(entrega_nombre_receptor, entrega_nombre_tecnico)
WHERE fecha_entrega IS NOT NULL;

UPDATE actas_equipo
SET resolucion_trabajo_realizado = COALESCE(resolucion_trabajo_realizado, trabajo_realizado)
WHERE trabajo_realizado IS NOT NULL AND trabajo_realizado != '';

UPDATE actas_equipo
SET devolucion_fecha          = COALESCE(devolucion_fecha, fecha_recepcion),
    devolucion_nombre_tecnico = COALESCE(devolucion_nombre_tecnico, recepcion_nombre_tecnico),
    devolucion_estado_equipo  = COALESCE(devolucion_estado_equipo, estado_recepcion),
    devolucion_nombre_escuela = COALESCE(devolucion_nombre_escuela, recepcion_nombre_escuela),
    devolucion_cargo_escuela  = COALESCE(devolucion_cargo_escuela, recepcion_cargo_escuela)
WHERE fecha_recepcion IS NOT NULL;

SELECT 'Migración de actas_equipo a 4 etapas completada correctamente.' AS resultado;
