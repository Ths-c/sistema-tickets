-- =====================================================================
-- MIGRACIÓN: agrega la tabla notificaciones al sistema existente.
--
-- Usá este script si ya tenías el sistema instalado y te aparece:
--   "Table 'tickets_distrital.notificaciones' doesn't exist"
--
-- Cómo aplicarlo:
--   1. Abrí phpMyAdmin → seleccioná la base "tickets_distrital"
--   2. Pestaña "SQL"
--   3. Pegá este contenido completo y ejecutá
--
-- No toca ninguna tabla existente ni borra datos.
-- =====================================================================

USE tickets_distrital;

CREATE TABLE IF NOT EXISTS notificaciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    ticket_id   INT NOT NULL,
    tipo        ENUM('cambio_estado','comentario') NOT NULL,
    mensaje     VARCHAR(255) NOT NULL,
    leida       TINYINT(1) NOT NULL DEFAULT 0,
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)  ON DELETE CASCADE,
    CONSTRAINT fk_notif_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)   ON DELETE CASCADE,
    INDEX idx_notif_usuario_leida (usuario_id, leida)
) ENGINE=InnoDB;
