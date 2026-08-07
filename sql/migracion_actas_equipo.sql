-- =====================================================================
-- MIGRACIÓN: agrega la tabla actas_equipo (constancia de entrega y
-- recepción de equipo) sin tocar el resto de la base existente.
--
-- Usar solo si ya tenías el sistema instalado con una versión anterior
-- del esquema y te aparece el error:
--   "Table 'tickets_distrital.actas_equipo' doesn't exist"
--
-- Cómo usarlo:
-- 1. Abrí phpMyAdmin (http://localhost/phpmyadmin)
-- 2. Seleccioná la base "tickets_distrital" en el panel izquierdo
-- 3. Pestaña "SQL"
-- 4. Pegá todo este archivo y ejecutá
-- =====================================================================

USE tickets_distrital;

CREATE TABLE IF NOT EXISTS actas_equipo (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id                   INT NOT NULL UNIQUE,
    equipo_tipo                 VARCHAR(100) NULL,
    equipo_marca_modelo         VARCHAR(150) NULL,
    equipo_numero_serie         VARCHAR(100) NULL,
    accesorios                  VARCHAR(255) NULL,
    fecha_entrega                DATETIME NULL,
    estado_entrega               TEXT NULL,
    entrega_nombre_escuela       VARCHAR(150) NULL,
    entrega_cargo_escuela        VARCHAR(100) NULL,
    entrega_nombre_tecnico       VARCHAR(150) NULL,
    fecha_recepcion              DATETIME NULL,
    trabajo_realizado            TEXT NULL,
    estado_recepcion             TEXT NULL,
    recepcion_nombre_escuela     VARCHAR(150) NULL,
    recepcion_cargo_escuela      VARCHAR(100) NULL,
    recepcion_nombre_tecnico     VARCHAR(150) NULL,
    usuario_id                  INT NOT NULL,
    fecha_actualizacion          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_acta_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_acta_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;
