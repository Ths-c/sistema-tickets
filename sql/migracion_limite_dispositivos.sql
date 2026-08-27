-- =====================================================================
-- MIGRACIÓN: límite de dos dispositivos por ticket
--
-- Qué hace:
--   · Crea la tabla ticket_dispositivos para registrar los equipos/
--     dispositivos incluidos dentro de cada ticket (máximo 2).
--   · Agrega la clave configuracion_sistema.limite_dispositivos_por_ticket
--     con valor por defecto 2, configurable desde admin_bloqueo.php.
--   · No toca datos existentes. Los tickets ya creados siguen funcionando;
--     simplemente tendrán 0 dispositivos vinculados hasta que se agreguen.
--
-- Cómo aplicarlo:
--   1. phpMyAdmin → base "tickets_distrital" → pestaña "SQL"
--   2. Pegá este contenido completo y ejecutá
-- =====================================================================

USE tickets_distrital;

-- ---------------------------------------------------------------------
-- Tabla de dispositivos por ticket (máximo 2 por ticket)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_dispositivos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT NOT NULL,
    tipo            VARCHAR(100) NOT NULL,
    marca_modelo    VARCHAR(150) NULL,
    numero_serie    VARCHAR(100) NULL,
    descripcion     TEXT NULL,
    fecha_creacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dispositivos_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_disp_ticket (ticket_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Configuración: límite de dispositivos por ticket (por defecto 2)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuracion_sistema (clave, valor, descripcion) VALUES
 ('limite_dispositivos_por_ticket', '2',
  'Cantidad máxima de dispositivos que puede tener un mismo ticket');

-- Copiar dispositivos existentes desde actas_equipo (si los hay) para que
-- tickets viejos no queden con 0 dispositivos. Solo inserta si el ticket
-- aún no tiene ningún dispositivo cargado.
INSERT INTO ticket_dispositivos (ticket_id, tipo, marca_modelo, numero_serie, descripcion)
SELECT ticket_id, equipo_tipo, equipo_marca_modelo, equipo_numero_serie, NULL
FROM actas_equipo
WHERE equipo_tipo IS NOT NULL AND equipo_tipo != ''
  AND ticket_id NOT IN (SELECT ticket_id FROM ticket_dispositivos);

SELECT 'Migración de límite de dispositivos (2 por ticket) completada correctamente.' AS resultado;
