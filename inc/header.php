<?php
// Variables opcionales: $titulo, $descripcion, $body_class
$cli = cliente();
$titulo_pag = isset($titulo) ? $titulo . ' · MeFaltaUno' : 'MeFaltaUno · Ningún héroe queda atrás';
$descripcion_pag = $descripcion ?? 'Comics, figuras y colecciones de leyenda. Marcá los números que ya tenés y salimos a buscar los que te faltan. Ningún héroe queda atrás.';
$actual = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titulo_pag) ?></title>
    <meta name="description" content="<?= e($descripcion_pag) ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <meta name="base" content="<?= e(BASE_URL) ?>">
    <link rel="icon" href="<?= asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800;900&family=Bangers&display=swap" rel="stylesheet">
    <?php foreach ($page_css ?? [] as $css): ?><link rel="stylesheet" href="<?= e($css) ?>"><?php endforeach; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="<?= e($body_class ?? '') ?>">

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="<?= url() ?>" aria-label="MeFaltaUno — inicio">
            <img src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="137" height="46">
        </a>

        <nav class="main-nav" id="main-nav">
            <a href="<?= url('colecciones.php') ?>" class="<?= $actual === 'colecciones' && empty($_GET['cat']) ? 'activo' : '' ?>">Colecciones</a>
            <?php foreach (categorias() as $nav_cat): ?>
                <a href="<?= url('colecciones.php?cat=' . urlencode($nav_cat['slug'])) ?>" class="<?= ($_GET['cat'] ?? '') === $nav_cat['slug'] ? 'activo' : '' ?>"><?= e($nav_cat['nombre']) ?></a>
            <?php endforeach; ?>
            <a href="<?= url('me-falta.php') ?>" class="nav-destacado <?= $actual === 'me-falta' ? 'activo' : '' ?>">¿Te falta uno?</a>
        </nav>

        <div class="header-acciones">
            <a class="icon-btn" href="<?= url($cli ? 'cuenta.php' : 'ingresar.php') ?>" aria-label="Mi cuenta" title="<?= $cli ? e($cli['nombre']) : 'Ingresar' ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <span class="icon-label"><?= $cli ? e($cli['nombre']) : 'Ingresar' ?></span>
            </a>
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

<?php $fl = flashes(); if ($fl): ?>
<div class="container flashes">
    <?php foreach ($fl as $f): ?>
        <div class="flash flash-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<main>
