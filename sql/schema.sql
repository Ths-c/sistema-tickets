-- =====================================================================
-- CESDE - Centro de Soporte Digital Educativo
-- Centro de Soporte Digital Educativo
-- =====================================================================

CREATE DATABASE IF NOT EXISTS tickets_distrital
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tickets_distrital;

-- ---------------------------------------------------------------------
-- TIPOS DE ESCUELA (tabla propia para poder agregarlos desde la UI)
-- ---------------------------------------------------------------------
CREATE TABLE tipos_escuela (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80)  NOT NULL UNIQUE,
    descripcion VARCHAR(200) NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ESCUELAS del distrito
-- ---------------------------------------------------------------------
CREATE TABLE escuelas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(150) NOT NULL,
    localidad       VARCHAR(100) NOT NULL,
    tipo_id         INT NULL,
    direccion       VARCHAR(200) NULL,
    telefono        VARCHAR(50)  NULL,
    activa          TINYINT(1)   NOT NULL DEFAULT 1,
    tickets_bloqueados   TINYINT(1)   NOT NULL DEFAULT 0,
    bloqueo_fecha        DATETIME     NULL,
    bloqueo_responsable  VARCHAR(150) NULL,
    fecha_creacion  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_escuelas_tipo FOREIGN KEY (tipo_id) REFERENCES tipos_escuela(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USUARIOS
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(80)  NOT NULL,
    apellido        VARCHAR(80)  NOT NULL,
    dni             VARCHAR(15)  NOT NULL UNIQUE,
    email           VARCHAR(150) NULL,
    password_hash   VARCHAR(255) NOT NULL,
    rol             ENUM('admin','coordinador','tecnico','solicitante') NOT NULL,
    escuela_id      INT NULL,
    anio_curso      VARCHAR(10)  NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    fecha_creacion  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_escuela FOREIGN KEY (escuela_id) REFERENCES escuelas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATEGORÍAS de problema
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80)  NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    activa      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TICKETS
-- ---------------------------------------------------------------------
CREATE TABLE tickets (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    titulo              VARCHAR(150) NOT NULL,
    descripcion         TEXT NOT NULL,
    categoria_id        INT NOT NULL,
    prioridad           ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
    estado              ENUM('nuevo','asignado','en_proceso','resuelto','cerrado','cancelado') NOT NULL DEFAULT 'nuevo',
    escuela_id          INT NOT NULL,
    solicitante_id      INT NOT NULL,
    tecnico_id          INT NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_asignacion    DATETIME NULL,
    fecha_resolucion    DATETIME NULL,
    fecha_cierre        DATETIME NULL,
    veces_reabierto     INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_tickets_categoria   FOREIGN KEY (categoria_id)   REFERENCES categorias(id),
    CONSTRAINT fk_tickets_escuela     FOREIGN KEY (escuela_id)     REFERENCES escuelas(id),
    CONSTRAINT fk_tickets_solicitante FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
    CONSTRAINT fk_tickets_tecnico     FOREIGN KEY (tecnico_id)     REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- HISTORIAL DE ESTADOS — trazabilidad real
-- ---------------------------------------------------------------------
CREATE TABLE historial_estados (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT NOT NULL,
    estado_anterior VARCHAR(20) NULL,
    estado_nuevo    VARCHAR(20) NOT NULL,
    usuario_id      INT NOT NULL,
    comentario      VARCHAR(255) NULL,
    fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_historial_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- COMENTARIOS
-- ---------------------------------------------------------------------
CREATE TABLE comentarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT NOT NULL,
    usuario_id  INT NOT NULL,
    comentario  TEXT NOT NULL,
    visibilidad ENUM('publico','interno') NOT NULL DEFAULT 'publico',
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comentarios_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_comentarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ADJUNTOS
-- ---------------------------------------------------------------------
CREATE TABLE adjuntos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT NOT NULL,
    nombre_archivo  VARCHAR(255) NOT NULL,
    ruta_archivo    VARCHAR(255) NOT NULL,
    usuario_id      INT NOT NULL,
    fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adjuntos_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_adjuntos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- EVALUACIONES de satisfacción
-- ---------------------------------------------------------------------
CREATE TABLE evaluaciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT NOT NULL UNIQUE,
    puntaje     TINYINT NOT NULL,
    comentario  VARCHAR(255) NULL,
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evaluaciones_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT chk_puntaje CHECK (puntaje BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CONFIGURACIÓN DEL SISTEMA (clave → valor)
-- ---------------------------------------------------------------------
CREATE TABLE configuracion_sistema (
    clave       VARCHAR(80)  NOT NULL PRIMARY KEY,
    valor       TEXT         NULL,
    descripcion VARCHAR(200) NULL,
    actualizado DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Valores por defecto
INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES
 ('tickets_bloqueados',   '0',
  'Si es 1, no se pueden crear nuevos tickets'),
 ('mensaje_bloqueo',
  'El sistema de soporte técnico está temporalmente suspendido por alta demanda. Por favor aguardá a que se habilite nuevamente o comunicá tu problema de forma presencial.',
  'Mensaje que ven todos los usuarios cuando los tickets están bloqueados'),
 ('mensaje_habilitado',
  'El soporte técnico está disponible de lunes a viernes de 8:00 a 17:00 hs. Podés crear un ticket en cualquier momento y será atendido en ese horario.',
  'Mensaje que ven todos los usuarios cuando el sistema está habilitado'),
 ('bloqueo_fecha',        NULL, 'Fecha y hora en que se activó el bloqueo'),
 ('bloqueo_responsable',  NULL, 'Nombre del admin que activó el bloqueo'),
 ('limite_tickets_abiertos_escuela', '5',
  'Cantidad máxima de tickets abiertos (no cerrados ni cancelados) que puede tener una misma escuela al mismo tiempo'),
 ('limite_dispositivos_por_ticket', '2',
  'Cantidad máxima de dispositivos que puede tener un mismo ticket');

-- ---------------------------------------------------------------------
-- DISPOSITIVOS por ticket (máximo configurable, 2 por defecto)
-- ---------------------------------------------------------------------
CREATE TABLE ticket_dispositivos (
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
-- NOTIFICACIONES por usuario
-- ---------------------------------------------------------------------
CREATE TABLE notificaciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,               -- destinatario
    ticket_id   INT NOT NULL,
    tipo        ENUM('cambio_estado','comentario') NOT NULL,
    mensaje     VARCHAR(255) NOT NULL,
    leida       TINYINT(1) NOT NULL DEFAULT 0,
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
    INDEX idx_notif_usuario_leida (usuario_id, leida)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ACTAS DE ENTREGA Y RECEPCIÓN DE EQUIPO (4 etapas obligatorias)
-- ---------------------------------------------------------------------
CREATE TABLE actas_equipo (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id                   INT NOT NULL UNIQUE,

    -- Datos generales del equipo (se completan normalmente en la etapa 1)
    equipo_tipo                  VARCHAR(100) NULL,
    equipo_marca_modelo          VARCHAR(150) NULL,
    equipo_numero_serie          VARCHAR(100) NULL,
    accesorios                   VARCHAR(255) NULL,

    -- Etapa 1: ENTREGA — la escuela entrega el equipo al proyecto
    entrega_fecha                DATETIME NULL,
    entrega_estado_equipo        TEXT NULL,
    entrega_nombre_escuela       VARCHAR(150) NULL,
    entrega_cargo_escuela        VARCHAR(100) NULL,
    entrega_nombre_receptor      VARCHAR(150) NULL,

    -- Etapa 2: ASIGNACIÓN — el proyecto entrega el equipo al técnico
    asignacion_fecha             DATETIME NULL,
    asignacion_nombre_tecnico    VARCHAR(150) NULL,
    asignacion_observaciones     TEXT NULL,

    -- Etapa 3: RESOLUCIÓN — el técnico documenta el trabajo realizado
    resolucion_fecha             DATETIME NULL,
    resolucion_trabajo_realizado TEXT NULL,
    resolucion_estado_equipo     TEXT NULL,

    -- Etapa 4: DEVOLUCIÓN — el equipo vuelve a la escuela originante del ticket
    devolucion_fecha             DATETIME NULL,
    devolucion_nombre_tecnico    VARCHAR(150) NULL,
    devolucion_estado_equipo     TEXT NULL,
    devolucion_nombre_escuela    VARCHAR(150) NULL,
    devolucion_cargo_escuela     VARCHAR(100) NULL,

    usuario_id                  INT NOT NULL,
    fecha_actualizacion         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_acta_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_acta_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- =====================================================================
-- DATOS SEMILLA
-- =====================================================================

INSERT INTO tipos_escuela (nombre, descripcion) VALUES
 ('Primaria',    'Escuela de nivel primario'),
 ('Secundaria',  'Escuela de nivel secundario'),
 ('Técnica',     'Escuela técnica de nivel secundario'),
 ('Especial',    'Educación especial'),
 ('Otro',        'Otro tipo de institución');

INSERT INTO escuelas (nombre, localidad, tipo_id) VALUES
 ('CESDE - Centro de Soporte Digital Educativo',  'Monte Hermoso', 3),
 ('CESDE - Escuela Primaria N°1',              'Monte Hermoso', 1),
 ('CESDE - Escuela Secundaria N°1',            'Monte Hermoso', 2);

INSERT INTO categorias (nombre, descripcion) VALUES
 ('Hardware',           'Problemas físicos: PC, impresoras, proyectores, periféricos'),
 ('Software',           'Sistema operativo, programas, instalaciones, actualizaciones'),
 ('Redes y conectividad','WiFi, cableado, acceso a Internet, configuración de red'),
 ('Cuentas y accesos',  'Usuarios, contraseñas, permisos, correo institucional'),
 ('Otro',               'Cualquier otra consulta o problema');

-- Contraseña para todos: "cambiar123"
INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol, escuela_id, anio_curso) VALUES
 ('Admin',    'Sistema',      '30111222', 'admin@etmh.edu.ar',                '$2y$10$.Q7pq8FK9iVY4xvtZtkKBu/TLCsG44L7M/WFQRJZ9SJxvPpkfkROW', 'admin',        1, NULL),
 ('Laura',    'Coordinadora', '28222333', 'coordinacion@etmh.edu.ar',         '$2y$10$.Q7pq8FK9iVY4xvtZtkKBu/TLCsG44L7M/WFQRJZ9SJxvPpkfkROW', 'coordinador',  1, NULL),
 ('Juan',     'Pérez',        '45333444', 'juan.tecnico@etmh.edu.ar',         '$2y$10$.Q7pq8FK9iVY4xvtZtkKBu/TLCsG44L7M/WFQRJZ9SJxvPpkfkROW', 'tecnico',      1, '6to'),
 ('Mariana',  'Gómez',        '32444555', 'directora.primaria1@monte.edu.ar', '$2y$10$.Q7pq8FK9iVY4xvtZtkKBu/TLCsG44L7M/WFQRJZ9SJxvPpkfkROW', 'solicitante',  2, NULL);
