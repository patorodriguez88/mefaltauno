<?php
// <head> común de la tienda (inc/header.php) y del panel (admin/_inc.php).
// Usa $titulo_pag, $descripcion_pag, $pagina_privada y, si hay, $page_css.
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($pagina_privada): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?= e($titulo_pag) ?></title>
    <meta name="description" content="<?= e($descripcion_pag) ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <meta name="base" content="<?= e(BASE_URL) ?>">
    <link rel="icon" href="<?= asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800;900&family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <?php foreach ($page_css ?? [] as $css): ?><link rel="stylesheet" href="<?= e($css) ?>"><?php endforeach; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
