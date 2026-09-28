-- Migración: agregar rol lector (solo lectura, con vista de administrador)
-- El lector ve lo mismo que el admin pero no puede modificar nada (se bloquea en PHP).
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','coordinador','tecnico','solicitante','lector') NOT NULL;
