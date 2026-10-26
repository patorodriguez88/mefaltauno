<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vistas.php';
require __DIR__ . '/inc/comics.php';

$destacadas = q("SELECT c.*, MIN(CASE WHEN i.congelado=0 AND (i.stock>0 OR COALESCE(i.brihet_stock,0)>0) AND c.proximamente=0 THEN COALESCE(NULLIF(i.precio_promo,0), i.precio) END) AS desde, COUNT(i.id) AS total
                 FROM colecciones c LEFT JOIN items i ON i.coleccion_id=c.id AND i.activo=1
                 WHERE c.activa=1 GROUP BY c.id ORDER BY c.destacada DESC, c.orden LIMIT 8")->fetchAll();

// Imágenes para el "álbum" del hero
$fotos = q("SELECT imagen FROM items WHERE activo=1 AND imagen IS NOT NULL ORDER BY RAND() LIMIT 9")->fetchAll(PDO::FETCH_COLUMN);

$cat_img = [];
foreach (q("SELECT categoria_id, imagen FROM colecciones WHERE activa=1 AND imagen IS NOT NULL ORDER BY destacada DESC, orden") as $r) {
    $cat_img[$r['categoria_id']] = $cat_img[$r['categoria_id']] ?? $r['imagen'];
}

$cli = cliente();
$op = es_admin($cli);
$mis = $cli && !$op ? mis_colecciones((int)$cli['id'], 3) : [];

// Ventana de novedades: colecciones "Próximamente" y las publicadas en los últimos 15 días (se arma sola)
$novedades = ajuste('novedades_activo', '1') === '1'
    ? q("SELECT c.*, (c.created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) AND c.proximamente=0) AS es_nueva FROM colecciones c
         WHERE c.activa=1 AND (c.proximamente=1 OR c.created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY))
         ORDER BY c.proximamente DESC, c.created_at DESC LIMIT 6")->fetchAll()
    : [];

// Ventana de inicio (admin/ventana.php): mientras está activa reemplaza a la de novedades.
// ?ventana=ver la muestra siempre (el operador la ve aunque esté apagada, para revisarla).
$forzar_ventana = isset($_GET['ventana']);
$ventana = ventana_activa();
if (!$ventana && $forzar_ventana && $op) {
    $ventana = ventana_preparar(ventana_ajustes()) ?: null;
    if ($ventana && !$ventana['slides']) $ventana = null;
}
if ($ventana) $novedades = [];

require __DIR__ . '/inc/header.php';
?>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <div class="kicker">★ Para coleccionistas de verdad ★</div>
            <h1>¿Te falta <em>uno</em>?</h1>
            <p class="lead">Todo coleccionista conoce esa sensación: el estante casi completo y un hueco que no se cierra. Porque toda gran colección tiene un número que todavía no llegó.</p>
            <p class="lead">Este es el lugar para descubrir colecciones, llevar la cuenta de las tuyas y pedirnos el que te falta: nosotros salimos a buscarlo. Para que <em>“me falta uno”</em> sea solo una frase. <strong>Las colecciones se completan.</strong></p>
            <div class="hero-cta">
                <a class="btn btn-primario" href="<?= url('colecciones.php') ?>">Explorar colecciones</a>
                <a class="btn btn-claro" href="<?= url($op ? 'admin/' : ($cli ? 'cuenta.php' : 'registro.php')) ?>"><?= $op ? 'Ir al panel' : ($cli ? 'Ir a Mis colecciones' : 'Empezá tu colección') ?></a>
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
                    <div class="slot lleno" style="background-image:url('<?= e(img($fotos[$f++])) ?>')"></div>
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
                <p>Comics, figuras, autos de leyenda y más. Sumala a Mis colecciones con un clic.</p>
            </div>
            <div class="paso">
                <div class="paso-num">2</div>
                <h3>Pasá lista</h3>
                <p>Marcá los números que ya tenés y descubrí qué números te faltan.</p>
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
                   <?php if (!empty($cat_img[$cat['id']])): ?>style="background-image:url('<?= e(img($cat_img[$cat['id']])) ?>')"<?php endif; ?>>
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

<?php [$p1, $p2, $p3] = comic_al_azar(3); // en cada visita, otros personajes (no siempre el mismo primero) ?>
<?= comic_cameo($p1, 'izq', '.hero') ?>
<?= comic_cameo($p2, 'der', '.cats') ?>
<?= comic_cameo($p3, 'izq', '.banner-falta') ?>

<?php if ($novedades): ?>
<dialog class="modal modal-novedades" id="modal-novedades" aria-labelledby="novedades-titulo" data-firma="<?= e(md5(implode(',', array_column($novedades, 'id')) . ajuste('novedades_titulo'))) ?>">
    <div class="modal-caja">
        <button class="modal-cerrar" type="button" aria-label="Cerrar" onclick="this.closest('dialog').close()">×</button>
        <div class="kicker" style="color:var(--teal)">★ Novedades ★</div>
        <h3 id="novedades-titulo"><?= e(ajuste('novedades_titulo') ?: 'Lo que llega a la base') ?></h3>
        <p class="muted" style="margin:0"><?= e(ajuste('novedades_texto') ?: 'Lanzamientos y colecciones que están por llegar. Entrá y activá las novedades para enterarte primero.') ?></p>
        <div class="novedades-lista">
            <?php foreach ($novedades as $n): ?>
                <a class="novedad" href="<?= url('coleccion.php?c=' . urlencode($n['slug'])) ?>">
                    <?= foto($n['imagen'], '📚') ?>
                    <span class="novedad-info">
                        <span class="badge <?= $n['proximamente'] ? 'badge-amarillo' : 'badge-verde' ?>"><?= $n['proximamente'] ? 'Próximamente' . ($n['lanzamiento'] ? ' · ' . e($n['lanzamiento']) : '') : 'Nuevo' ?></span>
                        <b><?= e($n['nombre']) ?></b>
                        <small><?= $n['proximamente'] ? 'Avisame cuando llegue →' : 'Ver colección →' ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primario btn-bloque" type="button" onclick="this.closest('dialog').close()">Seguir a la tienda</button>
    </div>
</dialog>
<script>
// Se muestra una vez; vuelve a aparecer solo si cambian las novedades
(() => {
    const d = document.getElementById('modal-novedades');
    if (!d || !d.showModal) return;
    let vista = '';
    try { vista = localStorage.getItem('mfu-novedades') || ''; } catch (e) {}
    if (vista === d.dataset.firma) return;
    setTimeout(() => d.showModal(), 900);
    d.addEventListener('close', () => { try { localStorage.setItem('mfu-novedades', d.dataset.firma); } catch (e) {} });
    d.addEventListener('click', ev => { if (ev.target === d) d.close(); });   // tocar afuera cierra
})();
</script>
<?php endif; ?>

<?php if ($ventana): $varias = count($ventana['slides']) > 1; ?>
<dialog class="modal-ventana<?= $varias ? ' con-varias' : '' ?>" id="modal-ventana" aria-labelledby="ventana-titulo-0"
        data-firma="<?= e(ventana_firma($ventana)) ?>" data-frecuencia="<?= e($ventana['frecuencia']) ?>" data-forzar="<?= $forzar_ventana ? '1' : '0' ?>">
    <?php foreach ($ventana['slides'] as $i => $s):
        $yt = $s['youtube'];
        $poster = $s['imagen'] !== '' ? url($s['imagen']) : ($yt ? "https://img.youtube.com/vi/$yt/maxresdefault.jpg" : ''); ?>
        <section class="ventana-slide" data-youtube="<?= e($yt) ?>" aria-roledescription="presentación" <?= $varias ? 'aria-label="' . ($i + 1) . ' de ' . count($ventana['slides']) . '"' : '' ?>>
            <div class="ventana-fondo">
                <?php if ($poster): ?>
                    <img class="ventana-poster" src="<?= e($poster) ?>" alt="" <?= $i ? 'loading="lazy"' : '' ?>
                         <?= $yt && $s['imagen'] === '' ? 'onerror="this.onerror=null;this.src=\'https://img.youtube.com/vi/' . e($yt) . '/hqdefault.jpg\'"' : '' ?>>
                <?php endif; ?>
                <div class="ventana-video"></div>
            </div>
            <div class="ventana-capa"></div>
            <div class="ventana-contenido">
                <img class="ventana-logo" src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="137" height="46">
                <?php if ($s['kicker'] !== ''): ?><span class="ventana-kicker"><?= e($s['kicker']) ?></span><?php endif; ?>
                <h2 class="ventana-titulo" id="ventana-titulo-<?= $i ?>"><?= e($s['titulo']) ?></h2>
                <?php if ($s['texto'] !== ''): ?><p class="ventana-texto"><?= nl2br(e($s['texto'])) ?></p><?php endif; ?>
                <div class="ventana-acciones">
                    <?php if ($s['boton_texto'] !== '' && $s['destino'] !== ''): ?>
                        <a class="btn btn-primario" href="<?= e($s['destino']) ?>"><?= e($s['boton_texto']) ?> →</a>
                    <?php endif; ?>
                    <button class="btn btn-texto" type="button" data-cerrar>Seguir a la tienda</button>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if ($varias): ?>
        <button class="ventana-flecha ventana-ant" type="button" aria-label="Anterior" data-paso="-1">‹</button>
        <button class="ventana-flecha ventana-sig" type="button" aria-label="Siguiente" data-paso="1">›</button>
        <div class="ventana-puntos">
            <?php foreach ($ventana['slides'] as $i => $_): ?><button type="button" aria-label="Ir a la presentación <?= $i + 1 ?>" data-ir="<?= $i ?>"></button><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <button class="ventana-cerrar" type="button" aria-label="Cerrar" data-cerrar>×</button>
</dialog>
<script>
// Una vez por visitante (o por visita, según la frecuencia); vuelve a aparecer si cambia la ventana.
// Con varias presentaciones se pasan con flechas, puntos, el teclado o deslizando; cada visita arranca por la
// siguiente. El video de YouTube de cada una se carga recién al mostrarla y se corta al pasar a otra.
(() => {
    const d = document.getElementById('modal-ventana');
    if (!d || !d.showModal) return;
    const forzar = d.dataset.forzar === '1';
    let almacen = null;
    try { almacen = d.dataset.frecuencia === 'siempre' ? sessionStorage : localStorage; } catch (e) {}
    let vista = '';
    try { vista = almacen ? almacen.getItem('mfu-ventana') || '' : ''; } catch (e) {}
    if (!forzar && vista === d.dataset.firma) return;
    const slides = [...d.querySelectorAll('.ventana-slide')];
    const puntos = [...d.querySelectorAll('.ventana-puntos button')];
    const quieto = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let actual = -1, escuchar = null;

    const cortarVideo = (s) => {
        s.querySelector('.ventana-video').innerHTML = '';
        s.classList.remove('con-video');
        if (escuchar) { window.removeEventListener('message', escuchar); escuchar = null; }
    };
    const cargarVideo = (s) => {
        const id = s.dataset.youtube;
        if (!id || quieto) return;
        const f = document.createElement('iframe');
        f.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&mute=1&controls=0&loop=1&playlist=' + id
            + '&playsinline=1&modestbranding=1&rel=0&iv_load_policy=3&disablekb=1&fs=0&enablejsapi=1&origin=' + encodeURIComponent(location.origin);
        f.allow = 'autoplay; encrypted-media';
        f.tabIndex = -1;
        f.setAttribute('aria-hidden', 'true');
        // El video aparece recién cuando YouTube avisa que se está reproduciendo. Si el teléfono no lo
        // deja arrancar solo (ahorro de batería, datos), queda la imagen y no un reproductor quieto.
        escuchar = (ev) => {
            if (ev.source !== f.contentWindow) return;
            let m; try { m = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data; } catch (e) { return; }
            const estado = m && (m.event === 'onStateChange' ? m.info : m.info && m.info.playerState);
            if (estado === 1 && slides[actual] === s) setTimeout(() => s.classList.add('con-video'), 400);
        };
        window.addEventListener('message', escuchar);
        f.addEventListener('load', () => f.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), '*'));
        s.querySelector('.ventana-video').appendChild(f);
    };
    const ir = (n) => {
        n = (n + slides.length) % slides.length;
        if (n === actual) return;
        if (actual >= 0) { slides[actual].classList.remove('activa'); cortarVideo(slides[actual]); }
        actual = n;
        slides[n].classList.add('activa');
        puntos.forEach((p, i) => p.setAttribute('aria-current', i === n ? 'true' : 'false'));
        d.setAttribute('aria-labelledby', 'ventana-titulo-' + n);
        if (d.open) cargarVideo(slides[n]);
        try { localStorage.setItem('mfu-ventana-ultima', String(n)); } catch (e) {}
    };
    // Arranca por la siguiente a la que vio la última vez (revisando desde el panel, siempre por la primera)
    let inicio = 0;
    if (!forzar && slides.length > 1) { try { inicio = (+(localStorage.getItem('mfu-ventana-ultima') ?? -1) + 1) % slides.length; } catch (e) {} }
    ir(inicio);

    d.querySelectorAll('[data-paso]').forEach((b) => b.addEventListener('click', () => ir(actual + +b.dataset.paso)));
    puntos.forEach((p) => p.addEventListener('click', () => ir(+p.dataset.ir)));
    d.addEventListener('keydown', (ev) => {
        if (slides.length < 2) return;
        if (ev.key === 'ArrowRight') ir(actual + 1);
        if (ev.key === 'ArrowLeft') ir(actual - 1);
    });
    let x0 = null;
    d.addEventListener('touchstart', (ev) => { x0 = ev.touches[0].clientX; }, { passive: true });
    d.addEventListener('touchend', (ev) => {
        if (x0 === null || slides.length < 2) return;
        const dx = ev.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) ir(actual + (dx < 0 ? 1 : -1));
        x0 = null;
    });

    setTimeout(() => { d.showModal(); cargarVideo(slides[actual]); }, forzar ? 200 : 700);
    d.addEventListener('close', () => {
        try { if (almacen) almacen.setItem('mfu-ventana', d.dataset.firma); } catch (e) {}
        cortarVideo(slides[actual]);
    });
    d.addEventListener('click', (ev) => { if (ev.target === d) d.close(); });   // tocar afuera cierra
    d.querySelectorAll('[data-cerrar]').forEach((b) => b.addEventListener('click', () => d.close()));
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
