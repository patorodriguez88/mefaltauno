<?php
// Común a todas las páginas del admin.
require dirname(__DIR__) . '/inc/bootstrap.php';
$admin = requiere_admin();

function admin_header(string $titulo_pag, string $activo): void {
    global $titulo, $admin, $page_css, $page_scripts;
    $titulo = $titulo_pag . ' · Admin';
    require dirname(__DIR__) . '/inc/header.php';
    $pend_sol = (int)q("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'")->fetchColumn();
    $pend_ped = (int)q("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn();
    $pend_pub = wepoint_nuevos_pendientes();
    $links = [
        'inicio'      => ['', 'Resumen'],
        'pedidos'     => ['pedidos.php', 'Pedidos' . ($pend_ped ? " ($pend_ped)" : '')],
        'faltantes'   => ['faltantes.php', 'Me faltan' . ($pend_sol ? " ($pend_sol)" : '')],
        'colecciones' => ['colecciones.php', 'Stock' . ($pend_pub ? " ($pend_pub)" : '')],
        'puntos'      => ['puntos.php', 'Puntos de retiro'],
        'clientes'    => ['clientes.php', 'Clientes'],
        'suscripciones' => ['suscripciones.php', 'Suscripciones'],
        'marketing'   => ['marketing.php', 'Marketing'],
        'cupones'     => ['cupones.php', 'Descuentos'],
        'wepoint'     => ['wepoint.php', 'WePoint'],
        'ajustes'     => ['ajustes.php', 'Ajustes'],
    ];
    echo '<div class="admin-bar"><div class="container"><b style="color:#fff">Admin</b><nav class="admin-bar-links">';
    foreach ($links as $k => [$href, $label]) {
        echo '<a href="' . url('admin/' . $href) . '" class="' . ($k === $activo ? 'activo' : '') . '">' . e($label) . '</a>';
    }
    echo '</nav><span class="admin-bar-der">' . e($admin['email']) . '</span>';
    echo '</div></div><section class="section" style="padding-top:28px"><div class="container">';
}

function admin_footer(): void {
    global $page_scripts;
    echo '</div></section>';
    require dirname(__DIR__) . '/inc/footer.php';
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
