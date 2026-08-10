<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['solicitante', 'admin', 'coordinador']);

$usuario = usuarioActual();
$pdo     = obtenerConexion();
$tituloPagina = 'Nuevo ticket';
$error = null;

// ── Verificar bloqueo del sistema (global, por escuela y por límite) ──
// El admin y coordinador pueden crear tickets igual, pero ven el aviso.
// El solicitante es bloqueado completamente por cualquiera de los 3 motivos.
$bloqueado      = ticketsBloqueados($pdo);
$msgBloqueo     = config($pdo, 'mensaje_bloqueo');

$escuelaId      = (int) ($usuario['escuela_id'] ?? 0);
$infoEscuela    = $escuelaId > 0 ? escuelaBloqueada($pdo, $escuelaId) : ['bloqueada' => false, 'fecha' => null, 'responsable' => null];
$bloqueadoEscuela = $infoEscuela['bloqueada'];

$ticketsAbiertos  = $escuelaId > 0 ? contarTicketsAbiertosEscuela($pdo, $escuelaId) : 0;
$limiteAbiertos   = limiteTicketsAbiertosEscuela($pdo);
$limiteAlcanzado  = $ticketsAbiertos >= $limiteAbiertos;

$esSolicitante  = $usuario['rol'] === 'solicitante';
$soloLectura    = $esSolicitante && ($bloqueado || $bloqueadoEscuela || $limiteAlcanzado);

// Motivo principal a mostrar en la pantalla de bloqueo (en orden de prioridad)
$motivoBloqueo = null;
if ($soloLectura) {
    if ($bloqueado) {
        $motivoBloqueo = 'global';
    } elseif ($bloqueadoEscuela) {
        $motivoBloqueo = 'escuela';
    } else {
        $motivoBloqueo = 'limite';
    }
}

$categorias = $pdo->query(
    'SELECT id, nombre FROM categorias WHERE activa=1 ORDER BY nombre'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bloqueo server-side: rechazar aunque hagan POST directo
    if ($soloLectura) {
        $error = match ($motivoBloqueo) {
            'escuela' => 'Tu escuela tiene bloqueada la creación de tickets en este momento.',
            'limite'  => "Tu escuela ya tiene {$ticketsAbiertos} tickets abiertos (el máximo permitido es {$limiteAbiertos}). Esperá a que se cierren o cancelen tickets existentes antes de crear uno nuevo.",
            default   => 'El sistema está bloqueado. No se pueden crear nuevos tickets en este momento.',
        };
    } else {
        $titulo      = trim($_POST['titulo']      ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
        // La prioridad ya no la elige el solicitante al cargar el ticket
        // (todos tienden a marcar "urgente"); se crea en "media" y la
        // ajusta después el coordinador o el administrador desde el ticket.
        $prioridad   = 'media';

        if ($titulo === '' || $descripcion === '' || $categoriaId <= 0) {
            $error = 'Completá título, descripción y categoría.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO tickets (titulo, descripcion, categoria_id, prioridad, estado, escuela_id, solicitante_id)
                 VALUES (:titulo, :descripcion, :categoria_id, :prioridad, "nuevo", :escuela_id, :solicitante_id)'
            );
            $stmt->execute([
                'titulo'         => $titulo,
                'descripcion'    => $descripcion,
                'categoria_id'   => $categoriaId,
                'prioridad'      => $prioridad,
                'escuela_id'     => $usuario['escuela_id'],
                'solicitante_id' => $usuario['id'],
            ]);
            $ticketId = (int) $pdo->lastInsertId();
            registrarHistorial($pdo, $ticketId, null, 'nuevo', $usuario['id'], 'Ticket creado');
            header('Location: ticket_detalle.php?id=' . $ticketId . '&creado=1');
            exit;
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Reportar un problema</h1>
    <p>Contanos qué pasó con el detalle que puedas. El equipo de soporte lo va a revisar y asignar a un técnico.</p>
</div>

<?php if ($soloLectura): ?>
    <!-- Pantalla de bloqueo para solicitantes -->
    <div style="max-width:560px; margin:3rem auto; text-align:center;">
        <div style="font-size:3.5rem; margin-bottom:1rem;"><?= $motivoBloqueo === 'limite' ? '📋' : '🔒' ?></div>

        <?php if ($motivoBloqueo === 'global'): ?>
            <h2 style="color:var(--rojo); margin-bottom:0.5rem;">Sistema temporalmente suspendido</h2>
            <p style="color:var(--texto-2); font-size:0.95rem; line-height:1.65; margin-bottom:1.5rem;">
                <?= e($msgBloqueo) ?>
            </p>
        <?php elseif ($motivoBloqueo === 'escuela'): ?>
            <h2 style="color:var(--rojo); margin-bottom:0.5rem;">Creación de tickets suspendida para tu escuela</h2>
            <p style="color:var(--texto-2); font-size:0.95rem; line-height:1.65; margin-bottom:1.5rem;">
                El administrador suspendió temporalmente la creación de tickets nuevos para tu institución.
                <?php if ($infoEscuela['fecha']): ?>
                    Bloqueado desde el <?= date('d/m/Y', strtotime($infoEscuela['fecha'])) ?><?= $infoEscuela['responsable'] ? ' por ' . e($infoEscuela['responsable']) : '' ?>.
                <?php endif; ?>
                Comunicate con la coordinación del proyecto si necesitás reportar algo urgente.
            </p>
        <?php else: /* limite */ ?>
            <h2 style="color:var(--rojo); margin-bottom:0.5rem;">Llegaste al límite de tickets abiertos</h2>
            <p style="color:var(--texto-2); font-size:0.95rem; line-height:1.65; margin-bottom:1.5rem;">
                Tu escuela tiene actualmente <strong><?= $ticketsAbiertos ?></strong> tickets abiertos, y el máximo
                permitido al mismo tiempo es <strong><?= $limiteAbiertos ?></strong>.
                Para crear uno nuevo, esperá a que se resuelva y cierre (o se cancele) alguno de los existentes.
            </p>
        <?php endif; ?>

        <a href="ticket_lista.php" class="boton boton-secundario">Ver mis tickets anteriores</a>
    </div>

<?php else: ?>

    <?php if ($bloqueado): ?>
    <div class="alerta alerta-error">
        <strong>⚠ El sistema está bloqueado para los solicitantes.</strong>
        Como <?= e($usuario['rol']) ?> podés seguir creando tickets, pero los solicitantes no pueden hacerlo en este momento.
        <a href="admin_bloqueo.php" style="font-weight:650; margin-left:0.5rem;">Gestionar bloqueo →</a>
    </div>
    <?php endif; ?>

    <?php if ($bloqueadoEscuela): ?>
    <div class="alerta alerta-error">
        <strong>⚠ Tu escuela tiene bloqueada la creación de tickets.</strong>
        Como <?= e($usuario['rol']) ?> podés seguir creando tickets igual, pero los solicitantes de tu institución no pueden hacerlo en este momento.
        <a href="admin_escuelas.php" style="font-weight:650; margin-left:0.5rem;">Gestionar escuelas →</a>
    </div>
    <?php endif; ?>

    <?php if ($limiteAlcanzado && !$esSolicitante): ?>
    <div class="alerta alerta-error">
        <strong>⚠ Tu escuela alcanzó el límite de tickets abiertos</strong> (<?= $ticketsAbiertos ?>/<?= $limiteAbiertos ?>).
        Como <?= e($usuario['rol']) ?> podés seguir creando tickets igual, pero los solicitantes de tu institución no podrán hacerlo hasta que se cierren algunos.
    </div>
    <?php endif; ?>

    <?php if ($esSolicitante && $ticketsAbiertos >= max(1, $limiteAbiertos - 1) && !$limiteAlcanzado): ?>
    <div class="alerta" style="background:var(--amarillo-claro); color:var(--amarillo); border:1px solid var(--amarillo);">
        Tu escuela tiene <?= $ticketsAbiertos ?> de <?= $limiteAbiertos ?> tickets abiertos permitidos.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alerta alerta-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="tarjeta">
        <form method="post">
            <label for="titulo">Título breve</label>
            <input type="text" id="titulo" name="titulo" required maxlength="150"
                   placeholder="Ej: No anda el proyector del aula 3"
                   value="<?= e($_POST['titulo'] ?? '') ?>">

            <label for="categoria_id">Categoría</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Elegí una categoría</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"
                        <?= (isset($_POST['categoria_id']) && (int)$_POST['categoria_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                        <?= e($cat['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="texto-3" style="margin:-0.5rem 0 1rem;">La prioridad la define el coordinador o el administrador al revisar el ticket.</p>

            <label for="descripcion">Descripción del problema</label>
            <textarea id="descripcion" name="descripcion" required
                placeholder="Contá qué pasó, desde cuándo, y en qué equipo o aula"><?= e($_POST['descripcion'] ?? '') ?></textarea>

            <div class="acciones-fila">
                <button type="submit">Crear ticket</button>
            </div>
        </form>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
