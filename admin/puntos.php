<?php
require __DIR__ . '/_inc.php';

$editar = isset($_GET['id']) ? (int)$_GET['id'] : null;   // 0 = nuevo
$campos = ['nombre', 'direccion', 'localidad', 'provincia', 'cp', 'telefono', 'horario', 'notas'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['accion'] ?? '') === 'activo') {
        q("UPDATE puntos_retiro SET activo = 1 - activo WHERE id=?", [$id]);
        redirect('admin/puntos.php');
    }
    $d = [];
    foreach ($campos as $k) $d[$k] = trim(mb_substr($_POST[$k] ?? '', 0, 200));
    $lat = is_numeric($_POST['lat'] ?? '') ? (float)$_POST['lat'] : null;
    $lng = is_numeric($_POST['lng'] ?? '') ? (float)$_POST['lng'] : null;
    if ($d['nombre'] === '' || $d['direccion'] === '' || $d['localidad'] === '') {
        flash('error', 'Completá nombre, dirección y localidad.');
        redirect('admin/puntos.php?id=' . $id);
    }
    if ($lat === null || $lng === null) {
        flash('error', 'Ubicá el punto en el mapa (botón “Ubicar en el mapa” o tocando el lugar).');
        redirect('admin/puntos.php?id=' . $id);
    }
    $vals = array_map(fn($k) => $d[$k] !== '' ? $d[$k] : null, $campos);
    $vals[] = $lat;
    $vals[] = $lng;
    $vals[] = !empty($_POST['activo']) ? 1 : 0;
    if ($id) {
        $vals[] = $id;
        q("UPDATE puntos_retiro SET nombre=?, direccion=?, localidad=?, provincia=?, cp=?, telefono=?, horario=?, notas=?, lat=?, lng=?, activo=? WHERE id=?", $vals);
    } else {
        q("INSERT INTO puntos_retiro (nombre, direccion, localidad, provincia, cp, telefono, horario, notas, lat, lng, activo) VALUES (?,?,?,?,?,?,?,?,?,?,?)", $vals);
    }
    flash('ok', 'Punto de retiro guardado.');
    redirect('admin/puntos.php');
}

$page_css = ['https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'];
$page_scripts = ['https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', 'assets/js/mapa.js'];

if ($editar !== null):
    $p = $editar ? q("SELECT * FROM puntos_retiro WHERE id=?", [$editar])->fetch() : null;
    if ($editar && !$p) redirect('admin/puntos.php');
    $p = $p ?: array_fill_keys(array_merge($campos, ['lat', 'lng']), '') + ['id' => 0, 'activo' => 1, 'provincia' => 'Córdoba'];
    admin_header($p['id'] ? $p['nombre'] : 'Nuevo punto de retiro', 'puntos');
?>
    <p><a href="<?= url('admin/puntos.php') ?>">← Puntos de retiro</a></p>
    <h1><?= $p['id'] ? e($p['nombre']) : 'Nuevo punto de retiro' ?></h1>
    <form method="post" class="layout-2">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="lat" id="p-lat" value="<?= e($p['lat']) ?>">
        <input type="hidden" name="lng" id="p-lng" value="<?= e($p['lng']) ?>">
        <div class="card">
            <div class="mapa alto" id="mapa-editor"></div>
            <div class="buscar-pie">
                <button class="btn btn-teal btn-chico" type="button" id="btn-ubicar">Ubicar en el mapa</button>
                <span class="muted small" id="ubicar-msg">Completá la dirección y tocá “Ubicar en el mapa”, o tocá el lugar en el mapa.</span>
            </div>
        </div>
        <aside class="card form">
            <label class="campo">Nombre del kiosco<input type="text" name="nombre" value="<?= e($p['nombre']) ?>" required placeholder="Ej: Kiosco Don Pepe"></label>
            <label class="campo">Dirección<input type="text" name="direccion" value="<?= e($p['direccion']) ?>" required placeholder="Ej: Av. Colón 1250"></label>
            <div class="form-row">
                <label class="campo">Localidad<input type="text" name="localidad" value="<?= e($p['localidad']) ?>" required></label>
                <label class="campo">Provincia<input type="text" name="provincia" value="<?= e($p['provincia']) ?>"></label>
            </div>
            <div class="form-row">
                <label class="campo">CP<input type="text" name="cp" value="<?= e($p['cp']) ?>"></label>
                <label class="campo">Teléfono<input type="tel" name="telefono" value="<?= e($p['telefono']) ?>"></label>
            </div>
            <label class="campo">Horario <small>(lo ve el cliente)</small><input type="text" name="horario" value="<?= e($p['horario']) ?>" placeholder="Ej: Lun a Sáb 8 a 20 h"></label>
            <label class="campo">Notas internas<input type="text" name="notas" value="<?= e($p['notas']) ?>"></label>
            <label class="tengo-toggle" style="color:var(--ink)"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>> Disponible para los clientes</label>
            <button class="btn btn-primario" type="submit">Guardar</button>
        </aside>
    </form>
<?php
    admin_footer();
    exit;
endif;

$puntos = q("SELECT pr.*, (SELECT COUNT(*) FROM pedidos p WHERE p.punto_id=pr.id) AS pedidos,
                    (SELECT COUNT(*) FROM pedidos p WHERE p.punto_id=pr.id AND p.estado='enviado') AS para_retirar
             FROM puntos_retiro pr ORDER BY pr.activo DESC, pr.localidad, pr.nombre")->fetchAll();
admin_header('Puntos de retiro', 'puntos');
?>

<div class="section-head">
    <div>
        <h1 style="margin:0">Puntos de retiro</h1>
        <p class="muted" style="margin:0">Kioscos donde los clientes retiran sus pedidos. Solo aparecen los disponibles y ubicados en el mapa.</p>
    </div>
    <a class="btn btn-primario" href="<?= url('admin/puntos.php?id=0') ?>">+ Nuevo punto</a>
</div>

<?php if ($puntos): ?>
    <div class="card" style="margin-bottom:16px;padding:12px">
        <div class="mapa" id="mapa-puntos-admin"></div>
    </div>
<?php endif; ?>

<div class="tabla-wrap">
    <table class="tabla">
        <tr><th>Punto</th><th>Dirección</th><th>Horario</th><th>Teléfono</th><th>Pedidos</th><th>Para retirar</th><th>Estado</th><th></th></tr>
        <?php foreach ($puntos as $p): ?>
            <tr>
                <td><a href="<?= url('admin/puntos.php?id=' . (int)$p['id']) ?>"><b><?= e($p['nombre']) ?></b></a></td>
                <td class="small"><?= e($p['direccion']) ?>, <?= e($p['localidad']) ?><?= $p['lat'] === null ? ' <span class="badge badge-rojo">Sin ubicar</span>' : '' ?></td>
                <td class="small"><?= e($p['horario']) ?></td>
                <td class="small"><?= e($p['telefono']) ?></td>
                <td><?= (int)$p['pedidos'] ?></td>
                <td><?= $p['para_retirar'] ? '<span class="badge badge-azul">' . (int)$p['para_retirar'] . '</span>' : '0' ?></td>
                <td><?= $p['activo'] ? '<span class="badge badge-verde">Disponible</span>' : '<span class="badge badge-gris">Pausado</span>' ?></td>
                <td>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn-texto small" type="submit"><?= $p['activo'] ? 'Pausar' : 'Activar' ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$puntos): ?><tr><td colspan="8" class="muted">Todavía no hay puntos de retiro. Cargá el primero para que los clientes puedan comprar.</td></tr><?php endif; ?>
    </table>
</div>

<?php if ($puntos): ?>
<script>
window.addEventListener('load', () => {
    const pts = <?= json_encode(array_values(array_filter(array_map(fn($p) => $p['lat'] !== null ? ['n' => $p['nombre'], 'a' => $p['activo'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng']] : null, $puntos))), JSON_UNESCAPED_UNICODE) ?>;
    if (!pts.length) return;
    const m = crearMapa('mapa-puntos-admin');
    pts.forEach(p => L.marker([p.lat, p.lng], { icon: pinIcono(p.a ? '' : 'pausado'), opacity: p.a ? 1 : .5 }).addTo(m).bindTooltip(p.n));
    m.fitBounds(L.latLngBounds(pts.map(p => [p.lat, p.lng])).pad(0.2), { maxZoom: 14 });
});
</script>
<?php endif; ?>

<?php admin_footer(); ?>
