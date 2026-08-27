<?php
/**
 * Manejo de sesión, autenticación y control de acceso por rol.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexion.php';

/** Devuelve el usuario logueado (array) o null si no hay sesión activa. */
function usuarioActual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** Corta la ejecución y redirige al login si no hay sesión. */
function requerirLogin(): void
{
    if (!usuarioActual()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Corta la ejecución si el usuario logueado no tiene uno de los roles permitidos.
 * Uso: requerirRol(['admin', 'coordinador']);
 */
function requerirRol(array $rolesPermitidos): void
{
    requerirLogin();
    $usuario = usuarioActual();
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
        http_response_code(403);
        echo 'No tenés permiso para acceder a esta página.';
        exit;
    }
}

/** Deja solo los dígitos de un DNI, sin importar cómo lo haya tipeado la persona (con puntos, espacios, etc). */
function normalizarDni(string $dni): string
{
    return preg_replace('/\D/', '', $dni);
}

/** Intenta autenticar por DNI + contraseña. Devuelve el usuario o null. */
function intentarLogin(string $dniIngresado, string $passwordPlano): ?array
{
    $pdo = obtenerConexion();
    $dni = normalizarDni($dniIngresado);
    $stmt = $pdo->prepare(
        'SELECT * FROM usuarios WHERE dni = :dni AND activo = 1 LIMIT 1'
    );
    $stmt->execute(['dni' => $dni]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($passwordPlano, $usuario['password_hash'])) {
        unset($usuario['password_hash']); // nunca guardar el hash en la sesión
        return $usuario;
    }

    return null;
}

/** Registra un cambio de estado en el historial, para trazabilidad. */
function registrarHistorial(PDO $pdo, int $ticketId, ?string $estadoAnterior, string $estadoNuevo, int $usuarioId, ?string $comentario = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO historial_estados (ticket_id, estado_anterior, estado_nuevo, usuario_id, comentario)
         VALUES (:ticket_id, :estado_anterior, :estado_nuevo, :usuario_id, :comentario)'
    );
    $stmt->execute([
        'ticket_id'       => $ticketId,
        'estado_anterior' => $estadoAnterior,
        'estado_nuevo'    => $estadoNuevo,
        'usuario_id'      => $usuarioId,
        'comentario'      => $comentario,
    ]);
}

/** Escapa texto para mostrarlo seguro en HTML. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Lee un valor de la tabla configuracion_sistema.
 * Devuelve el valor o $default si la clave no existe.
 */
function config(PDO $pdo, string $clave, string $default = ''): string
{
    if (!isset($GLOBALS['__cesde_config_cache'])) {
        $GLOBALS['__cesde_config_cache'] = [];
    }
    if (!array_key_exists($clave, $GLOBALS['__cesde_config_cache'])) {
        $stmt = $pdo->prepare('SELECT valor FROM configuracion_sistema WHERE clave = :c');
        $stmt->execute(['c' => $clave]);
        $row = $stmt->fetch();
        $GLOBALS['__cesde_config_cache'][$clave] = $row ? (string)($row['valor'] ?? '') : $default;
    }
    return $GLOBALS['__cesde_config_cache'][$clave];
}

/**
 * Graba un valor en la tabla configuracion_sistema.
 * Si la clave no existe la crea; si ya existe la actualiza.
 * Actualiza también la caché en memoria para que config() devuelva el valor nuevo en la misma request.
 */
function setConfig(PDO $pdo, string $clave, ?string $valor): void
{
    $pdo->prepare(
        'INSERT INTO configuracion_sistema (clave, valor)
         VALUES (:c, :v)
         ON DUPLICATE KEY UPDATE valor = :v2'
    )->execute(['c' => $clave, 'v' => $valor, 'v2' => $valor]);
    if (!isset($GLOBALS['__cesde_config_cache'])) {
        $GLOBALS['__cesde_config_cache'] = [];
    }
    $GLOBALS['__cesde_config_cache'][$clave] = $valor ?? '';
}

/** Devuelve el acta de equipo del ticket (o null si todavía no existe la fila). */
function obtenerActaEquipo(PDO $pdo, int $ticketId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM actas_equipo WHERE ticket_id = :id');
    $stmt->execute(['id' => $ticketId]);
    $acta = $stmt->fetch();
    return $acta ?: null;
}

/**
 * Campos mínimos obligatorios para considerar completa cada etapa del acta.
 * Se usa tanto para bloquear el avance del ticket como para mostrar el
 * estado (✓ / pendiente) en la pantalla de la constancia.
 */
function camposObligatoriosActa(string $etapa): array
{
    return match ($etapa) {
        'entrega'     => ['entrega_fecha', 'entrega_nombre_escuela', 'entrega_nombre_receptor'],
        'asignacion'  => ['asignacion_fecha', 'asignacion_nombre_tecnico'],
        'resolucion'  => ['resolucion_fecha', 'resolucion_trabajo_realizado'],
        'devolucion'  => ['devolucion_fecha', 'devolucion_nombre_tecnico', 'devolucion_nombre_escuela'],
        default       => [],
    };
}

/** Indica si una etapa puntual del acta (entrega/asignacion/resolucion/devolucion) está completa. */
function actaEtapaCompleta(?array $acta, string $etapa): bool
{
    if (!$acta) return false;
    foreach (camposObligatoriosActa($etapa) as $campo) {
        if (trim((string) ($acta[$campo] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

/** Cantidad de etapas completas (0 a 4), para mostrar un resumen tipo "2/4". */
function actaEtapasCompletas(?array $acta): int
{
    $n = 0;
    foreach (['entrega', 'asignacion', 'resolucion', 'devolucion'] as $etapa) {
        if (actaEtapaCompleta($acta, $etapa)) $n++;
    }
    return $n;
}

/**
 * Devuelve true si el sistema tiene los tickets bloqueados.
 */
function ticketsBloqueados(PDO $pdo): bool
{
    return config($pdo, 'tickets_bloqueados', '0') === '1';
}

/**
 * Devuelve el estado de bloqueo puntual de una escuela (independiente
 * del bloqueo global del sistema). Incluye fecha y responsable para
 * mostrar en pantalla.
 */
function escuelaBloqueada(PDO $pdo, int $escuelaId): array
{
    $stmt = $pdo->prepare(
        'SELECT tickets_bloqueados, bloqueo_fecha, bloqueo_responsable
         FROM escuelas WHERE id = :id'
    );
    $stmt->execute(['id' => $escuelaId]);
    $fila = $stmt->fetch();

    return [
        'bloqueada'    => $fila ? (bool) $fila['tickets_bloqueados'] : false,
        'fecha'        => $fila['bloqueo_fecha']       ?? null,
        'responsable'  => $fila['bloqueo_responsable'] ?? null,
    ];
}

/**
 * Devuelve la cantidad de tickets "abiertos" (no cerrados ni cancelados)
 * que tiene actualmente una escuela.
 */
function contarTicketsAbiertosEscuela(PDO $pdo, int $escuelaId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tickets
         WHERE escuela_id = :id AND estado NOT IN ('cerrado','cancelado')"
    );
    $stmt->execute(['id' => $escuelaId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Devuelve el límite configurado de tickets abiertos simultáneos por escuela.
 */
function limiteTicketsAbiertosEscuela(PDO $pdo): int
{
    return (int) config($pdo, 'limite_tickets_abiertos_escuela', '5');
}

/**
 * Devuelve el límite configurado de dispositivos por ticket.
 * Por defecto 2, configurable desde admin_bloqueo.php.
 */
function limiteDispositivosPorTicket(PDO $pdo): int
{
    $val = (int) config($pdo, 'limite_dispositivos_por_ticket', '2');
    return $val > 0 ? $val : 2;
}

/**
 * Cuenta cuántos dispositivos tiene un ticket.
 */
function contarDispositivosTicket(PDO $pdo, int $ticketId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ticket_dispositivos WHERE ticket_id = :id');
    $stmt->execute(['id' => $ticketId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Devuelve todos los dispositivos de un ticket, ordenados por fecha.
 */
function obtenerDispositivosTicket(PDO $pdo, int $ticketId): array
{
    $stmt = $pdo->prepare('SELECT * FROM ticket_dispositivos WHERE ticket_id = :id ORDER BY fecha_creacion ASC, id ASC');
    $stmt->execute(['id' => $ticketId]);
    return $stmt->fetchAll();
}

/**
 * Indica si se puede agregar otro dispositivo al ticket sin superar el límite.
 */
function puedeAgregarDispositivo(PDO $pdo, int $ticketId): bool
{
    return contarDispositivosTicket($pdo, $ticketId) < limiteDispositivosPorTicket($pdo);
}

/**
 * Crea notificaciones para todos los usuarios relacionados con un ticket,
 * excepto el que genera la acción.
 *
 * Destinatarios:
 *   - El solicitante del ticket
 *   - El técnico asignado (si lo hay)
 *   - Todos los coordinadores y admins activos
 */
function crearNotificaciones(
    PDO    $pdo,
    int    $ticketId,
    string $tipo,          // 'cambio_estado' | 'comentario'
    string $mensaje,
    int    $autorId        // quien genera la acción — NO recibe notificación
): void {
    // Obtener destinatarios directos del ticket
    $stmt = $pdo->prepare(
        'SELECT solicitante_id, tecnico_id FROM tickets WHERE id = :id'
    );
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) return;

    // Obtener todos los coordinadores y admins activos
    $gestores = $pdo->query(
        "SELECT id FROM usuarios WHERE rol IN ('admin','coordinador') AND activo = 1"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Armar conjunto único de destinatarios (sin el autor)
    $destinatarios = array_unique(array_filter(
        array_merge(
            [(int) $ticket['solicitante_id'], (int) ($ticket['tecnico_id'] ?? 0)],
            array_map('intval', $gestores)
        ),
        fn(int $id) => $id > 0 && $id !== $autorId
    ));

    if (empty($destinatarios)) return;

    $insert = $pdo->prepare(
        'INSERT INTO notificaciones (usuario_id, ticket_id, tipo, mensaje)
         VALUES (:uid, :tid, :tipo, :mensaje)'
    );
    foreach ($destinatarios as $uid) {
        $insert->execute([
            'uid'     => $uid,
            'tid'     => $ticketId,
            'tipo'    => $tipo,
            'mensaje' => $mensaje,
        ]);
    }
}

/**
 * Devuelve el conteo de notificaciones no leídas del usuario actual.
 * Si se pasa $tipo, cuenta solo ese tipo ('cambio_estado' | 'comentario').
 * Se usa para el badge de la campana.
 */
function contarNotificacionesSinLeer(PDO $pdo, int $usuarioId, ?string $tipo = null): int
{
    $sql = 'SELECT COUNT(*) FROM notificaciones WHERE usuario_id = :uid AND leida = 0';
    $params = ['uid' => $usuarioId];
    if ($tipo !== null) {
        $sql .= ' AND tipo = :tipo';
        $params['tipo'] = $tipo;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Devuelve las últimas N notificaciones del usuario (leídas y sin leer).
 * Si se pasa $tipo, filtra solo ese tipo.
 */
function obtenerNotificaciones(PDO $pdo, int $usuarioId, int $limite = 10, ?string $tipo = null): array
{
    $sql = "SELECT n.*, t.titulo AS ticket_titulo
            FROM notificaciones n
            JOIN tickets t ON t.id = n.ticket_id
            WHERE n.usuario_id = :uid";
    $params = ['uid' => $usuarioId];
    if ($tipo !== null) {
        $sql .= ' AND n.tipo = :tipo';
        $params['tipo'] = $tipo;
    }
    $sql .= " ORDER BY n.fecha DESC LIMIT {$limite}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Roles que tienen una bandeja de mensajes separada de la campanita.
 * Para estos roles, la campana solo muestra cambios de estado; los
 * comentarios/mensajes de tickets se ven aparte, en mensajes.php.
 */
function rolConBandejaMensajes(string $rol): bool
{
    return in_array($rol, ['solicitante', 'coordinador'], true);
}

/**
 * Cuenta las notificaciones sin leer que corresponden a la CAMPANITA
 * (excluye comentarios para los roles que tienen bandeja de mensajes propia).
 */
function contarNotificacionesCampana(PDO $pdo, int $usuarioId, string $rol): int
{
    return rolConBandejaMensajes($rol)
        ? contarNotificacionesSinLeer($pdo, $usuarioId, 'cambio_estado')
        : contarNotificacionesSinLeer($pdo, $usuarioId);
}

/**
 * Lista las notificaciones que corresponden a la CAMPANITA (ver arriba).
 */
function obtenerNotificacionesCampana(PDO $pdo, int $usuarioId, string $rol, int $limite = 15): array
{
    return rolConBandejaMensajes($rol)
        ? obtenerNotificaciones($pdo, $usuarioId, $limite, 'cambio_estado')
        : obtenerNotificaciones($pdo, $usuarioId, $limite);
}

/** Cuenta los mensajes (comentarios) sin leer de la bandeja de mensajes. */
function contarMensajesSinLeer(PDO $pdo, int $usuarioId): int
{
    return contarNotificacionesSinLeer($pdo, $usuarioId, 'comentario');
}

/** Lista los mensajes (comentarios) de la bandeja de mensajes. */
function obtenerMensajes(PDO $pdo, int $usuarioId, int $limite = 50): array
{
    return obtenerNotificaciones($pdo, $usuarioId, $limite, 'comentario');
}
