<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/vistas.php';

$destacadas = q("SELECT c.*, MIN(COALESCE(NULLIF(i.precio_promo,0), i.precio)) AS desde, COUNT(i.id) AS total
                 FROM colecciones c LEFT JOIN items i ON i.coleccion_id=c.id AND i.activo=1
                 WHERE c.activa=1 GROUP BY c.id ORDER BY c.destacada DESC, c.orden LIMIT 8")->fetchAll();

// Imágenes para el "álbum" del hero
$fotos = q("SELECT imagen FROM items WHERE activo=1 AND imagen IS NOT NULL ORDER BY RAND() LIMIT 9")->fetchAll(PDO::FETCH_COLUMN);

$cat_img = [];
foreach (q("SELECT categoria_id, imagen FROM colecciones WHERE activa=1 AND imagen IS NOT NULL ORDER BY destacada DESC, orden") as $r) {
    $cat_img[$r['categoria_id']] = $cat_img[$r['categoria_id']] ?? $r['imagen'];
}

$cli = cliente();
$mis = $cli ? mis_colecciones((int)$cli['id'], 3) : [];

require __DIR__ . '/inc/header.php';
?>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <h1>¿Te falta <em>uno</em>?</h1>
            <p class="lead">Todo gran equipo tiene un integrante que todavía no llegó. Marcá los que ya custodiás en tu estante y nosotros salimos a rescatar al que falta. <strong>Ningún héroe queda atrás.</strong></p>
            <div class="hero-cta">
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Explorar colecciones</a>
                <a class="btn btn-claro" href="<?= url($cli ? 'cuenta.php' : 'registro.php') ?>"><?= $cli ? 'Ir a mi base' : 'Armá tu equipo' ?></a>
            </div>
        </div>
        <div class="hero-album" aria-hidden="true">
            <?php
            $faltan = [3, 7, 10];
            $f = 0;
            for ($n = 1; $n <= 12; $n++):
                if (in_array($n, $faltan, true) || !isset($fotos[$f])): ?>
                    <div class="slot falta"><?= $n ?></div>
                <?php else: ?>
                    <div class="slot lleno" style="background-image:url('<?= e($fotos[$f++]) ?>')"></div>
                <?php endif;
            endfor; ?>
        </div>
    </div>
</section>

<?php if ($mis): ?>
<section class="section" style="padding-bottom:0">
    <div class="container">
        <div class="section-head">
            <h2>Tu misión sigue en curso</h2>
            <a href="<?= url('cuenta.php') ?>">Ver todas</a>
        </div>
        <div class="mis-cols">
            <?php foreach ($mis as $m) echo tarjeta_mi_coleccion($m); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="section-head">
            <h2>Colecciones <span class="hl">legendarias</span></h2>
            <a href="<?= url('colecciones.php') ?>">Ver todas →</a>
        </div>
        <div class="grid-colecciones">
            <?php foreach ($destacadas as $c) echo tarjeta_coleccion($c); ?>
        </div>
    </div>
</section>

<section class="section" style="background:var(--white);border-block:1px solid var(--line)">
    <div class="container">
        <h2 style="text-align:center;margin-bottom:24px">Tu misión, en tres pasos</h2>
        <div class="pasos">
            <div class="paso">
                <div class="paso-num">1</div>
                <h3>Elegí tu saga</h3>
                <p>Comics, figuras, autos de leyenda y más. Sumala a tu base con un clic.</p>
            </div>
            <div class="paso">
                <div class="paso-num">2</div>
                <h3>Pasá lista</h3>
                <p>Marcá los números que ya tenés y descubrí quién falta en tu equipo.</p>
            </div>
            <div class="paso">
                <div class="paso-num">3</div>
                <h3>Pedí refuerzos</h3>
                <p>Los que hay, al carrito. Los que no, nos los pedís: salimos a buscarlos y te avisamos.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2>Elegí tu universo</h2>
        <div class="cats">
            <?php foreach (categorias() as $cat): ?>
                <a class="cat-card" href="<?= url('colecciones.php?cat=' . urlencode($cat['slug'])) ?>"
                   <?php if (!empty($cat_img[$cat['id']])): ?>style="background-image:url('<?= e($cat_img[$cat['id']]) ?>')"<?php endif; ?>>
                    <h3><?= e($cat['nombre']) ?></h3>
                    <p><?= e($cat['descripcion']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="container">
    <div class="banner-falta">
        <div>
            <h2>¿El que te falta no aparece en la web?</h2>
            <p>Danos la pista: colección y número. Nosotros salimos a buscarlo.</p>
        </div>
        <a class="btn btn-teal" href="<?= url('me-falta.php') ?>">Activar la búsqueda</a>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
