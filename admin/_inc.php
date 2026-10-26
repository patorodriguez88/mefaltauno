<?php
// Común a todas las páginas del admin.
require dirname(__DIR__) . '/inc/bootstrap.php';
$admin = requiere_admin();

/** Íconos del menú del panel (trazos de 24×24). */
const ADMIN_ICONOS = [
    'inicio'        => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    'pedidos'       => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20.5" r="1.3"/><circle cx="17" cy="20.5" r="1.3"/>',
    'faltantes'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/><path d="M11 8v6M8 11h6"/>',
    'colecciones'   => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'clientes'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.8c2.2.7 3.5 2.4 3.5 5.2"/>',
    'cupones'       => '<circle cx="12" cy="12" r="9"/><path d="M9 15l6-6"/><circle cx="9.3" cy="9.3" r=".8"/><circle cx="14.7" cy="14.7" r=".8"/>',
    'marketing'     => '<path d="M3 11v2a1 1 0 0 0 1 1h3l6 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M17 9a4 4 0 0 1 0 6"/><path d="M8 14l1.2 5"/>',
    'suscripciones' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'ventana'       => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M10 8v5l4.5-2.5z"/><path d="M8 21h8M12 17v4"/>',
    'puntos'        => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'wepoint'       => '<path d="M3 21V9l9-5 9 5v12"/><path d="M7 21v-8h10v8"/><path d="M7 17h10"/>',
    'ajustes'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'cobrar'        => '<path d="M12 3v18"/><path d="M16.5 7.5c-.8-1.4-2.4-2-4.5-2-2.8 0-4.5 1.3-4.5 3.2 0 4.5 9.5 2.3 9.5 6.8 0 1.9-1.9 3.3-5 3.3-2.3 0-4.1-.8-5-2.3"/>',
    'tienda'        => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    'menu'          => '<path d="M4 7h16M4 12h16M4 17h16"/>',
];

function admin_icono(string $nombre): string {
    return '<svg class="adm-icono" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . (ADMIN_ICONOS[$nombre] ?? '') . '</svg>';
}

// Panel del operador: menú lateral agrupado y barra superior, sin el encabezado ni el pie de la tienda.
function admin_header(string $titulo_pag, string $activo): void {
    global $titulo, $admin, $page_css, $page_scripts;
    $titulo = $titulo_pag . ' · Admin';
    $titulo_pag = $titulo . ' · MeFaltaUno';   // lo que usa inc/head.php
    $descripcion_pag = 'Panel del operador de MeFaltaUno';
    $pagina_privada = true;
    $pend = [
        'pedidos'     => (int)q("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn(),
        'faltantes'   => (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'")->fetchColumn(),
        'colecciones' => wepoint_nuevos_pendientes(),
    ];
    $grupos = [
        '' => [
            'inicio' => ['', 'Inicio'],
        ],
        'Gestión' => [
            'pedidos'       => ['pedidos.php', 'Pedidos'],
            'faltantes'     => ['faltantes.php', 'Me faltan'],
            'colecciones'   => ['colecciones.php', 'Productos y stock'],
            'clientes'      => ['clientes.php', 'Clientes'],
            'cupones'       => ['cupones.php', 'Descuentos'],
            'marketing'     => ['marketing.php', 'Marketing'],
            'ventana'       => ['ventana.php', 'Ventana de inicio'],
            'suscripciones' => ['suscripciones.php', 'Suscripciones'],
        ],
        'Logística' => [
            'puntos'  => ['puntos.php', 'Puntos de retiro'],
            'wepoint' => ['wepoint.php', 'WePoint'],
        ],
    ];
    require dirname(__DIR__) . '/inc/head.php';
    ?>
<body class="adm-body">
<div class="adm">
    <aside class="adm-lateral" id="adm-lateral">
        <a class="adm-marca" href="<?= url('admin/') ?>"><img src="<?= asset('assets/img/logo-sm.png') ?>" alt="MeFaltaUno" width="110" height="37"><span>Panel</span></a>
        <nav class="adm-nav" aria-label="Panel">
            <?php foreach ($grupos as $grupo => $links): ?>
                <?php if ($grupo !== ''): ?><div class="adm-grupo"><?= e($grupo) ?></div><?php endif; ?>
                <?php foreach ($links as $k => [$href, $label]): ?>
                    <a href="<?= url('admin/' . $href) ?>" class="adm-link <?= $k === $activo ? 'activo' : '' ?>" <?= $k === $activo ? 'aria-current="page"' : '' ?>>
                        <?= admin_icono($k) ?><span><?= e($label) ?></span>
                        <?php if (!empty($pend[$k])): ?><b class="adm-badge"><?= (int)$pend[$k] ?></b><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="adm-nav adm-nav-pie">
            <?php if (es_maestro($admin)): ?>
                <a href="<?= url('admin/equipo.php') ?>" class="adm-link <?= $activo === 'equipo' ? 'activo' : '' ?>"><?= admin_icono('clientes') ?><span>Equipo</span></a>
            <?php endif; ?>
            <a href="<?= url('admin/ajustes.php') ?>" class="adm-link <?= $activo === 'ajustes' ? 'activo' : '' ?>"><?= admin_icono('ajustes') ?><span>Configuración</span></a>
            <a href="<?= url() ?>" class="adm-link" target="_blank" rel="noopener"><?= admin_icono('tienda') ?><span>Ver la tienda</span></a>
        </div>
    </aside>
    <div class="adm-fondo" onclick="document.body.classList.remove('adm-menu-abierto')"></div>
    <div class="adm-cuerpo">
        <header class="adm-barra">
            <button class="adm-menu-btn" type="button" aria-label="Menú" aria-controls="adm-lateral" onclick="document.body.classList.toggle('adm-menu-abierto')"><?= admin_icono('menu') ?></button>
            <div class="adm-barra-der">
                <span class="adm-usuario" title="<?= e($admin['email']) ?>"><b><?= e(mb_strtoupper(mb_substr($admin['nombre'] ?: $admin['email'], 0, 1))) ?></b><?= e($admin['nombre'] ?: $admin['email']) ?></span>
                <form method="post" action="<?= url('salir.php') ?>"><?= csrf_field() ?><button class="btn btn-texto btn-chico" type="submit">Salir</button></form>
            </div>
        </header>
        <?php require dirname(__DIR__) . '/inc/avisos.php'; ?>
        <main class="adm-contenido">
    <?php
}

function admin_footer(): void {
    global $page_scripts;
    echo "        </main>\n    </div>\n</div>\n";
    require dirname(__DIR__) . '/inc/final.php';
}

function solicitud_cambiar_estado(int $id, string $nuevo, ?string $respuesta, bool $avisar, string $usuario): void {
    if (!isset(ESTADOS_SOLICITUD[$nuevo])) throw new Exception('Estado inválido.');
    $s = q("SELECT * FROM solicitudes WHERE id=?", [$id])->fetch();
    if (!$s) throw new Exception('Solicitud no encontrada.');
    $respuesta = $respuesta !== null ? trim($respuesta) : null;
    if ($s['estado'] === $nuevo && ($respuesta ?? '') === ($s['respuesta'] ?? '')) return;
    q("UPDATE solicitudes SET estado=?, respuesta=? WHERE id=?", [$nuevo, $respuesta ?: null, $id]);
    registrar_historial('solicitud', $id, $nuevo, $respuesta, $usuario);
    if ($avisar) {
        require_once dirname(__DIR__) . '/inc/notificaciones.php';
        notificar_cambio_estado('solicitud', $id, $nuevo, $respuesta);
    }
}
