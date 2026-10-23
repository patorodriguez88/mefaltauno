<?php
// Variables opcionales: $titulo, $descripcion, $body_class
$cli = cliente();
$titulo_pag = isset($titulo) ? $titulo . ' · MeFaltaUno' : 'MeFaltaUno · Ningún héroe queda atrás';
$descripcion_pag = $descripcion ?? 'Comics, figuras y colecciones de leyenda. Marcá los números que ya tenés y salimos a buscar los que te faltan. Ningún héroe queda atrás.';
$actual = basename($_SERVER['SCRIPT_NAME'], '.php');
// Los buscadores indexan la tienda; el panel y las páginas de la cuenta y la compra, no.
$pagina_privada = str_contains($_SERVER['SCRIPT_NAME'], '/admin/')
    || in_array($actual, ['cuenta', 'carrito', 'checkout', 'pedido', 'ingresar', 'registro', 'baja', 'mailing-baja', 'arrepentimiento'], true);
?>
<?php require __DIR__ . '/head.php'; ?>
<body class="<?= e($body_class ?? '') ?>">

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="<?= url() ?>" aria-label="MeFaltaUno — inicio">
            <img src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="137" height="46">
        </a>

        <nav class="main-nav" id="main-nav">
            <a href="<?= url('colecciones.php') ?>" class="<?= $actual === 'colecciones' && empty($_GET['cat']) ? 'activo' : '' ?>">Colecciones</a>
            <?php foreach (array_filter(categorias(), fn($c) => !empty($c['en_menu'])) as $nav_cat): ?>
                <a href="<?= url('colecciones.php?cat=' . urlencode($nav_cat['slug'])) ?>" class="<?= ($_GET['cat'] ?? '') === $nav_cat['slug'] ? 'activo' : '' ?>"><?= e($nav_cat['nombre']) ?></a>
            <?php endforeach; ?>
            <a href="<?= url('me-falta.php') ?>" class="nav-destacado <?= $actual === 'me-falta' ? 'activo' : '' ?>">¿Te falta uno?</a>
        </nav>

        <div class="header-acciones">
            <?php $cuenta_url = !$cli ? 'ingresar.php' : (es_admin($cli) ? 'admin/' : 'cuenta.php'); ?>
            <a class="icon-btn" href="<?= url($cuenta_url) ?>" aria-label="<?= $cli && es_admin($cli) ? 'Panel de administración' : 'Mi cuenta' ?>" title="<?= $cli ? e($cli['nombre']) : 'Ingresar' ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <span class="icon-label"><?= $cli ? e($cli['nombre']) : 'Ingresar' ?></span>
            </a>
            <?php if ($cli && es_admin($cli)): ?>
                <form method="post" action="<?= url('salir.php') ?>" class="salir-form"><?= csrf_field() ?>
                    <button class="icon-btn" type="submit" title="Cerrar sesión" aria-label="Cerrar sesión">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                        <span class="icon-label">Salir</span>
                    </button>
                </form>
            <?php endif; ?>
            <a class="icon-btn carrito-btn" href="<?= url('carrito.php') ?>" aria-label="Carrito">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20.5" r="1.3"/><circle cx="17" cy="20.5" r="1.3"/></svg>
                <span class="carrito-count" id="carrito-count" <?= carrito_cantidad() ? '' : 'hidden' ?>><?= carrito_cantidad() ?></span>
            </a>
            <button class="icon-btn menu-btn" type="button" aria-label="Menú" aria-controls="main-nav" aria-expanded="false" onclick="toggleMenu(this)">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>
    </div>
</header>

<?php require __DIR__ . '/avisos.php'; ?>

<main>
