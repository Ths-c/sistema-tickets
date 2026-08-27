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

$limiteDispositivos = limiteDispositivosPorTicket($pdo);

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
            // ── Validar dispositivos (máximo $limiteDispositivos por ticket) ──
            $dispRaw = $_POST['dispositivos'] ?? [];
            $dispositivosValidos = [];
            if (is_array($dispRaw)) {
                foreach ($dispRaw as $d) {
                    if (!is_array($d)) continue;
                    $tipo        = trim($d['tipo'] ?? '');
                    $marcaModelo = trim($d['marca_modelo'] ?? '');
                    $serie       = trim($d['numero_serie'] ?? '');
                    $desc        = trim($d['descripcion'] ?? '');
                    // Solo se considera un dispositivo si al menos el tipo está cargado
                    if ($tipo === '') continue;
                    $dispositivosValidos[] = [
                        'tipo'         => $tipo,
                        'marca_modelo' => $marcaModelo !== '' ? $marcaModelo : null,
                        'numero_serie' => $serie !== '' ? $serie : null,
                        'descripcion'  => $desc !== '' ? $desc : null,
                    ];
                }
            }
            if (count($dispositivosValidos) > $limiteDispositivos) {
                $error = "Solo se permiten {$limiteDispositivos} dispositivos por ticket. Sacá " . (count($dispositivosValidos) - $limiteDispositivos) . " antes de crear el ticket.";
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
                // Guardar dispositivos si los hay
                if (!empty($dispositivosValidos)) {
                    try {
                        $ins = $pdo->prepare(
                            'INSERT INTO ticket_dispositivos (ticket_id, tipo, marca_modelo, numero_serie, descripcion)
                             VALUES (:tid, :tipo, :marca, :serie, :desc)'
                        );
                        foreach ($dispositivosValidos as $dv) {
                            $ins->execute([
                                'tid'   => $ticketId,
                                'tipo'  => $dv['tipo'],
                                'marca' => $dv['marca_modelo'],
                                'serie' => $dv['numero_serie'],
                                'desc'  => $dv['descripcion'],
                            ]);
                        }
                    } catch (PDOException $e) {
                        // Si la tabla aún no existe (migración pendiente), el ticket igual se creó
                        error_log('Error guardando dispositivos: ' . $e->getMessage());
                    }
                }
                header('Location: ticket_detalle.php?id=' . $ticketId . '&creado=1');
                exit;
            }
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
        <form method="post" id="form-ticket">
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

            <!-- Dispositivos (máximo <?= $limiteDispositivos ?>) -->
            <div style="margin-top:1.25rem; border-top:1px solid var(--borde); padding-top:1rem;">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem;">
                    <label style="margin:0; font-weight:700;">Dispositivos incluidos <span class="texto-3" style="font-weight:400;">(máximo <?= $limiteDispositivos ?> por ticket)</span></label>
                    <span class="etiqueta estado-nuevo" id="contador-dispositivos">0/<?= $limiteDispositivos ?></span>
                </div>
                <p class="texto-3" style="margin:0 0 0.75rem;">Indicá qué equipos necesitan atención. Podés cargar hasta <?= $limiteDispositivos ?> dispositivos en un mismo ticket.</p>

                <div id="lista-dispositivos"></div>

                <button type="button" id="btn-agregar-dispositivo" class="boton boton-secundario boton-sm" style="margin-top:0.5rem;">
                    + Agregar dispositivo
                </button>
                <p class="texto-3" id="msg-limite-dispositivos" style="display:none; margin-top:0.5rem; color:var(--rojo);">
                    Llegaste al máximo de <?= $limiteDispositivos ?> dispositivos por ticket. Si necesitás reportar más equipos, creá otro ticket.
                </p>
            </div>

            <div class="acciones-fila" style="margin-top:1.5rem;">
                <button type="submit">Crear ticket</button>
            </div>
        </form>
    </div>

    <script>
    (function(){
        const LIMITE = <?= (int)$limiteDispositivos ?>;
        const lista = document.getElementById('lista-dispositivos');
        const btnAgregar = document.getElementById('btn-agregar-dispositivo');
        const contador = document.getElementById('contador-dispositivos');
        const msgLimite = document.getElementById('msg-limite-dispositivos');
        let idx = 0;

        // Datos previos si hubo error de validación (para no perder lo cargado)
        const previos = <?= json_encode($_POST['dispositivos'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

        function actualizarEstado(){
            const n = lista.querySelectorAll('.dispositivo-card').length;
            contador.textContent = n + '/' + LIMITE;
            if(n >= LIMITE){
                btnAgregar.style.display = 'none';
                msgLimite.style.display = 'block';
            } else {
                btnAgregar.style.display = 'inline-flex';
                msgLimite.style.display = 'none';
            }
            // Marcar visualmente si está en el límite
            contador.style.background = n >= LIMITE ? 'var(--rojo-claro)' : '';
            contador.style.color = n >= LIMITE ? 'var(--rojo)' : '';
        }

        function crearCard(datos){
            if(lista.querySelectorAll('.dispositivo-card').length >= LIMITE) return;
            const i = idx++;
            const card = document.createElement('div');
            card.className = 'dispositivo-card tarjeta';
            card.style.cssText = 'padding:1rem; margin-bottom:0.75rem; background:var(--fondo); border:1px solid var(--borde); position:relative;';
            card.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
                    <strong class="texto-sm">Dispositivo #<span class="num"></span></strong>
                    <button type="button" class="boton boton-secundario boton-sm btn-quitar" style="padding:0.2rem 0.6rem; font-size:0.78rem;">Quitar</button>
                </div>
                <div class="grid-2" style="gap:0.75rem;">
                    <div>
                        <label style="font-size:0.82rem; margin-bottom:2px;">Tipo de equipo *</label>
                        <input type="text" name="dispositivos[${i}][tipo]" placeholder="Ej: Notebook, Proyector, Impresora" maxlength="100" value="${(datos.tipo||'').replace(/"/g,'&quot;')}" required>
                    </div>
                    <div>
                        <label style="font-size:0.82rem; margin-bottom:2px;">Marca / modelo</label>
                        <input type="text" name="dispositivos[${i}][marca_modelo]" placeholder="Ej: Lenovo ThinkPad E14" maxlength="150" value="${(datos.marca_modelo||'').replace(/"/g,'&quot;')}">
                    </div>
                </div>
                <div class="grid-2" style="gap:0.75rem; margin-top:0.6rem;">
                    <div>
                        <label style="font-size:0.82rem; margin-bottom:2px;">N° de serie / inventario</label>
                        <input type="text" name="dispositivos[${i}][numero_serie]" placeholder="Ej: SN12345 / INV-001" maxlength="100" value="${(datos.numero_serie||'').replace(/"/g,'&quot;')}">
                    </div>
                    <div>
                        <label style="font-size:0.82rem; margin-bottom:2px;">Descripción de la falla</label>
                        <input type="text" name="dispositivos[${i}][descripcion]" placeholder="Ej: No enciende, pantalla rota" maxlength="255" value="${(datos.descripcion||'').replace(/"/g,'&quot;')}">
                    </div>
                </div>
            `;
            card.querySelector('.btn-quitar').addEventListener('click', function(){
                card.remove();
                renumerar();
                actualizarEstado();
            });
            lista.appendChild(card);
            renumerar();
            actualizarEstado();
        }

        function renumerar(){
            lista.querySelectorAll('.dispositivo-card').forEach((c, k)=>{
                c.querySelector('.num').textContent = k+1;
            });
        }

        btnAgregar.addEventListener('click', ()=> crearCard({}));

        // Restaurar datos previos tras error de validación
        if(Array.isArray(previos) && previos.length){
            previos.forEach(d=> crearCard(d));
        } else {
            // Crear el primer dispositivo vacío por defecto para guiar al usuario
            crearCard({});
        }
        // Si ya se alcanzó el límite al restaurar, no agregar más
        actualizarEstado();
    })();
    </script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
