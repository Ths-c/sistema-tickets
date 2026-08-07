-- =====================================================================
-- MIGRACIÓN: agrega columna "activa/activo" a las tablas que la
-- necesitan y que no existían en versiones anteriores del schema.
--
-- Tablas afectadas:
--   · categorias    → columna "activa"
--   · tipos_escuela → columna "activo"
--
-- Si alguna ya tiene la columna, el ALTER TABLE la ignora sin error
-- gracias al bloque de verificación con IF NOT EXISTS.
--
-- Cómo aplicarlo:
--   1. phpMyAdmin → base "tickets_distrital" → pestaña "SQL"
--   2. Pegá este contenido completo y ejecutá
-- =====================================================================

USE tickets_distrital;

-- ── categorias ───────────────────────────────────────────────
ALTER TABLE categorias
    MODIFY nombre VARCHAR(80) NOT NULL;   -- fuerza re-parse del schema

-- Agrega la columna solo si no existe
SET @existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'categorias'
      AND COLUMN_NAME  = 'activa'
);
SET @sql = IF(@existe = 0,
    'ALTER TABLE categorias ADD COLUMN activa TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT "columna activa ya existe en categorias"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── tipos_escuela ────────────────────────────────────────────
-- La tabla puede no existir si venís de una versión muy anterior
CREATE TABLE IF NOT EXISTS tipos_escuela (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80)  NOT NULL UNIQUE,
    descripcion VARCHAR(200) NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Si ya existía pero sin la columna "activo", la agregamos
SET @existe2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tipos_escuela'
      AND COLUMN_NAME  = 'activo'
);
SET @sql2 = IF(@existe2 = 0,
    'ALTER TABLE tipos_escuela ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT "columna activo ya existe en tipos_escuela"'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- ── escuelas: columna tipo_id ────────────────────────────────
-- Si la tabla venía con columna "tipo" (ENUM) en vez de "tipo_id" (FK),
-- agregamos tipo_id sin tocar la columna vieja para no perder datos.
SET @existe3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'escuelas'
      AND COLUMN_NAME  = 'tipo_id'
);
SET @sql3 = IF(@existe3 = 0,
    'ALTER TABLE escuelas ADD COLUMN tipo_id INT NULL AFTER localidad',
    'SELECT "columna tipo_id ya existe en escuelas"'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- Datos semilla mínimos para tipos_escuela (solo si está vacía)
INSERT IGNORE INTO tipos_escuela (nombre, descripcion) VALUES
 ('Primaria',   'Escuela de nivel primario'),
 ('Secundaria', 'Escuela de nivel secundario'),
 ('Técnica',    'Escuela técnica de nivel secundario'),
 ('Especial',   'Educación especial'),
 ('Otro',       'Otro tipo de institución');

SELECT 'Migración completada correctamente.' AS resultado;
