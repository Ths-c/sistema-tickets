-- =====================================================================
-- MIGRACIÓN: agrega la tabla configuracion_sistema con el sistema
-- de bloqueo de tickets personalizable.
--
-- Cómo aplicarlo:
--   1. phpMyAdmin → base "tickets_distrital" → pestaña "SQL"
--   2. Pegá este contenido y ejecutá
--   No toca ninguna tabla existente ni borra datos.
-- =====================================================================

USE tickets_distrital;

CREATE TABLE IF NOT EXISTS configuracion_sistema (
    clave       VARCHAR(80)  NOT NULL PRIMARY KEY,
    valor       TEXT         NULL,
    descripcion VARCHAR(200) NULL,
    actualizado DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Inserta los valores por defecto solo si no existen
INSERT IGNORE INTO configuracion_sistema (clave, valor, descripcion) VALUES
 ('tickets_bloqueados',  '0',
  'Si es 1, no se pueden crear nuevos tickets'),
 ('mensaje_bloqueo',
  'El sistema de soporte técnico está temporalmente suspendido por alta demanda. Por favor aguardá a que se habilite nuevamente o comunicá tu problema de forma presencial.',
  'Mensaje visible para todos cuando los tickets están bloqueados'),
 ('mensaje_habilitado',
  'El soporte técnico está disponible de lunes a viernes de 8:00 a 17:00 hs. Podés crear un ticket en cualquier momento y será atendido en ese horario.',
  'Mensaje visible para todos cuando el sistema está habilitado'),
 ('bloqueo_fecha',       NULL, 'Fecha y hora en que se activó el bloqueo'),
 ('bloqueo_responsable', NULL, 'Nombre del admin que activó el bloqueo');

SELECT 'Migración completada correctamente.' AS resultado;
