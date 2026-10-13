<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vistas.php';

$cat = null;
$params = [];
$where = 'c.activa=1';
if (!empty($_GET['cat'])) {
    $cat = q("SELECT * FROM categorias WHERE slug=?", [$_GET['cat']])->fetch();
    if ($cat) {
        $where .= ' AND c.categoria_id=?';
        $params[] = $cat['id'];
    }
}
$buscar = trim($_GET['q'] ?? '');
if ($buscar !== '') {
    $where .= ' AND (c.nombre LIKE ? OR c.descripcion LIKE ? OR EXISTS (SELECT 1 FROM items x WHERE x.coleccion_id=c.id AND x.titulo LIKE ?))';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%");
}

$cols = q("SELECT c.*, MIN(CASE WHEN i.congelado=0 AND (i.stock>0 OR COALESCE(i.brihet_stock,0)>0) AND c.proximamente=0 THEN COALESCE(NULLIF(i.precio_promo,0), i.precio) END) AS desde, COUNT(i.id) AS total
           FROM colecciones c LEFT JOIN items i ON i.coleccion_id=c.id AND i.activo=1
           WHERE $where GROUP BY c.id ORDER BY c.destacada DESC, c.orden, c.nombre", $params)->fetchAll();

$titulo = $cat ? $cat['nombre'] : 'Colecciones';
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h1 style="margin-bottom:4px"><?= e($titulo) ?></h1>
                <p class="muted" style="margin:0"><?= e($cat['descripcion'] ?? 'Cada saga, cada número, cada leyenda. Elegí la tuya.') ?></p>
            </div>
            <form method="get" style="min-width:260px">
                <?php if ($cat): ?><input type="hidden" name="cat" value="<?= e($cat['slug']) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar colección o número…" aria-label="Buscar">
            </form>
        </div>

        <?php if ($cols): ?>
            <div class="grid-colecciones">
                <?php foreach ($cols as $c) echo tarjeta_coleccion($c); ?>
            </div>
        <?php else: ?>
            <div class="vacio">
                <h3>Ni rastro<?= $buscar ? ' de “' . e($buscar) . '”' : '' ?> en nuestro catálogo</h3>
                <p>Pero ningún héroe queda atrás: <a href="<?= url('me-falta.php') ?>">danos la pista</a> y salimos a buscarlo.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
