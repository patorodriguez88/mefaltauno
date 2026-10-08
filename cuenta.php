<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/vistas.php';

$cli = requiere_login();
if (es_admin($cli)) redirect('admin/');
$tab = $_GET['tab'] ?? 'colecciones';
if (!in_array($tab, ['colecciones', 'pedidos', 'faltantes', 'datos'], true)) $tab = 'colecciones';

// Guardar datos del perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'perfil') {
    csrf_exigir();
    $f = [];
    foreach (['nombre', 'apellido', 'telefono', 'direccion', 'localidad', 'provincia', 'cp'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    if ($f['nombre'] === '') {
        flash('error', 'El nombre no puede quedar vacío.');
    } else {
        q("UPDATE clientes SET nombre=?, apellido=?, telefono=?, direccion=?, localidad=?, provincia=?, cp=? WHERE id=?", [
            $f['nombre'], $f['apellido'], $f['telefono'] ?: null, $f['direccion'] ?: null, $f['localidad'] ?: null, $f['provincia'] ?: null, $f['cp'] ?: null, $cli['id'],
        ]);
        $nueva = $_POST['password_nueva'] ?? '';
        if ($nueva !== '') {
            if (!password_verify($_POST['password_actual'] ?? '', $cli['password_hash'])) {
                flash('error', 'La contraseña actual no es correcta: no la cambiamos.');
                redirect('cuenta.php?tab=datos');
            }
            if (strlen($nueva) < 8) {
                flash('error', 'La contraseña nueva tiene que tener al menos 8 caracteres.');
                redirect('cuenta.php?tab=datos');
            }
            q("UPDATE clientes SET password_hash=? WHERE id=?", [password_hash($nueva, PASSWORD_DEFAULT), $cli['id']]);
        }
        flash('ok', 'Guardamos tus datos.');
    }
    redirect('cuenta.php?tab=datos');
}

$cnt_faltantes = (int)q("SELECT COUNT(*) FROM solicitudes WHERE cliente_id=? AND estado IN ('pendiente','buscando','conseguido')", [$cli['id']])->fetchColumn();
$cnt_pedidos = (int)q("SELECT COUNT(*) FROM pedidos WHERE cliente_id=? AND estado NOT IN ('entregado','cancelado')", [$cli['id']])->fetchColumn();

$titulo = 'Mi cuenta';
require __DIR__ . '/inc/header.php';
?>

<?php if (es_admin($cli)): ?>
<div class="admin-bar"><div class="container"><a href="<?= url('admin/') ?>">→ Ir al panel de administración</a></div></div>
<?php endif; ?>

<section class="cuenta-head">
    <div class="container">
        <h1 style="margin:0">Hola, <?= e($cli['nombre']) ?></h1>
        <p class="muted" style="margin:4px 0 0">Tu base de operaciones: tus colecciones, quién falta y tus pedidos.</p>
        <nav class="tabs">
            <a href="?tab=colecciones" class="<?= $tab === 'colecciones' ? 'activo' : '' ?>">Mis colecciones</a>
            <a href="?tab=faltantes" class="<?= $tab === 'faltantes' ? 'activo' : '' ?>">En búsqueda<?= $cnt_faltantes ? '<span class="cnt">' . $cnt_faltantes . '</span>' : '' ?></a>
            <a href="?tab=pedidos" class="<?= $tab === 'pedidos' ? 'activo' : '' ?>">Mis pedidos<?= $cnt_pedidos ? '<span class="cnt">' . $cnt_pedidos . '</span>' : '' ?></a>
            <a href="?tab=datos" class="<?= $tab === 'datos' ? 'activo' : '' ?>">Mis datos</a>
        </nav>
    </div>
</section>

<section class="section" style="padding-top:28px">
    <div class="container">

    <?php if ($tab === 'colecciones'):
        $mis = mis_colecciones((int)$cli['id']); ?>
        <?php if ($mis): ?>
            <div class="mis-cols">
                <?php foreach ($mis as $m) echo tarjeta_mi_coleccion($m); ?>
            </div>
            <p class="muted small" style="margin-top:20px">
                <span class="badge badge-verde">verde</span> los tenés ·
                <span class="badge badge-amarillo">amarillo</span> pedidos o en camino ·
                <span class="badge badge-gris">gris</span> te faltan. Entrá a cada colección para marcar o pedir números.
            </p>
        <?php else: ?>
            <div class="card vacio">
                <h3>Tu base está vacía</h3>
                <p>Toda leyenda empieza con un primer número. Entrá a una colección, pasá lista y acá vas a ver cómo crece tu equipo.</p>
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Elegir una colección</a>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'faltantes'):
        $sols = q("SELECT * FROM solicitudes WHERE cliente_id=? ORDER BY FIELD(estado,'conseguido','buscando','pendiente') DESC, created_at DESC", [$cli['id']])->fetchAll(); ?>
        <div class="section-head">
            <p class="muted" style="margin:0;max-width:640px">Tus misiones de búsqueda: los números que salimos a rastrear para vos. Te avisamos por mail con cada novedad.</p>
            <a class="btn btn-teal btn-chico" href="<?= url('me-falta.php') ?>">Activar otra búsqueda</a>
        </div>
        <?php if (!$sols): ?>
            <div class="card vacio">
                <h3>No hay búsquedas activas</h3>
                <p>En cada colección seleccioná los números sin stock y tocá <b>“Activar la búsqueda”</b>. Nosotros salimos a buscarlos.</p>
            </div>
        <?php endif; ?>
        <div class="lista">
            <?php foreach ($sols as $s):
                $its = q("SELECT si.descripcion, c.slug FROM solicitud_items si LEFT JOIN items i ON i.id=si.item_id LEFT JOIN colecciones c ON c.id=i.coleccion_id WHERE si.solicitud_id=?", [$s['id']])->fetchAll(); ?>
                <div class="card">
                    <div class="section-head" style="margin-bottom:8px">
                        <div><b>Misión de búsqueda #<?= (int)$s['id'] ?></b> <span class="muted small">· <?= fecha($s['created_at'], false) ?></span></div>
                        <?= badge_estado($s['estado'], ESTADOS_SOLICITUD) ?>
                    </div>
                    <p class="muted small" style="margin:0 0 8px"><?= e(ESTADOS_SOLICITUD[$s['estado']][1] ?? '') ?></p>
                    <ul style="margin:0 0 8px;padding-left:20px">
                        <?php foreach ($its as $i): ?>
                            <li><?= $i['slug'] ? '<a href="' . url('coleccion.php?c=' . urlencode($i['slug'])) . '">' . e($i['descripcion']) . '</a>' : e($i['descripcion']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($s['respuesta']): ?>
                        <div class="aviso" style="background:var(--teal-50);color:var(--teal-900)"><b>Base MeFaltaUno:</b> <?= nl2br(e($s['respuesta'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'pedidos'):
        $peds = q("SELECT p.*, (SELECT SUM(cantidad) FROM pedido_items WHERE pedido_id=p.id) AS unidades FROM pedidos p WHERE cliente_id=? ORDER BY created_at DESC", [$cli['id']])->fetchAll(); ?>
        <?php if (!$peds): ?>
            <div class="card vacio">
                <h3>Todavía no hay misiones en tu historial</h3>
                <p>Tu primera colección completa está a un clic.</p>
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Ver colecciones</a>
            </div>
        <?php endif; ?>
        <div class="lista">
            <?php foreach ($peds as $p): ?>
                <a class="fila-card" href="<?= url('pedido.php?id=' . (int)$p['id']) ?>">
                    <div>
                        <div class="titulo">Pedido #<?= (int)$p['id'] ?></div>
                        <div class="muted small"><?= fecha($p['created_at']) ?> · <?= (int)$p['unidades'] ?> número<?= $p['unidades'] == 1 ? '' : 's' ?> · <?= e($p['envio_punto'] ? 'Retiro en ' . $p['envio_punto'] : (ENVIO_METODOS[$p['envio_metodo']] ?? '')) ?></div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:center">
                        <strong><?= precio((float)$p['total']) ?></strong>
                        <?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="layout-2">
            <form method="post" class="card form">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="perfil">
                <h3>Mis datos</h3>
                <div class="form-row">
                    <label class="campo">Nombre<input type="text" name="nombre" value="<?= e($cli['nombre']) ?>" required></label>
                    <label class="campo">Apellido<input type="text" name="apellido" value="<?= e($cli['apellido']) ?>"></label>
                </div>
                <label class="campo">Email <small>(no se puede cambiar)</small><input type="email" value="<?= e($cli['email']) ?>" disabled></label>
                <label class="campo">Teléfono<input type="tel" name="telefono" value="<?= e($cli['telefono']) ?>"></label>
                <label class="campo">Dirección <small>(la usamos para mostrarte los puntos de retiro más cercanos)</small><input type="text" name="direccion" value="<?= e($cli['direccion']) ?>"></label>
                <div class="form-row">
                    <label class="campo">Localidad<input type="text" name="localidad" value="<?= e($cli['localidad']) ?>"></label>
                    <label class="campo">Provincia<input type="text" name="provincia" value="<?= e($cli['provincia']) ?>"></label>
                </div>
                <label class="campo">Código postal<input type="text" name="cp" value="<?= e($cli['cp']) ?>"></label>
                <h3 style="margin-top:12px">Cambiar contraseña <small class="muted" style="font-weight:400">(opcional)</small></h3>
                <div class="form-row">
                    <label class="campo">Actual<input type="password" name="password_actual" autocomplete="current-password"></label>
                    <label class="campo">Nueva<input type="password" name="password_nueva" minlength="8" autocomplete="new-password"></label>
                </div>
                <button class="btn btn-primario" type="submit">Guardar</button>
            </form>
            <aside class="card">
                <h3>Sesión</h3>
                <p class="muted small">Ingresaste como <?= e($cli['email']) ?>.</p>
                <form method="post" action="<?= url('salir.php') ?>"><?= csrf_field() ?><button class="btn btn-linea btn-bloque" type="submit">Cerrar sesión</button></form>
            </aside>
        </div>
    <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
