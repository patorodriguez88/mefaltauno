<?php
require __DIR__ . '/inc/bootstrap.php';

$cli = requiere_login();
$p = q("SELECT * FROM pedidos WHERE id=? AND cliente_id=?", [(int)($_GET['id'] ?? 0), $cli['id']])->fetch();
if (!$p) redirect('cuenta.php?tab=pedidos');
$lineas = q("SELECT pi.*, i.imagen FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$p['id']])->fetchAll();
$hist = historial('pedido', (int)$p['id']);
$punto = $p['punto_id'] ? q("SELECT horario, lat, lng FROM puntos_retiro WHERE id=?", [$p['punto_id']])->fetch() : null;

$titulo = 'Pedido #' . $p['id'];
require __DIR__ . '/inc/header.php';
?>

<section class="section">
    <div class="container">
        <p><a href="<?= url('cuenta.php?tab=pedidos') ?>">← Mis pedidos</a></p>
        <div class="section-head">
            <h1 style="margin:0">Pedido #<?= (int)$p['id'] ?></h1>
            <?= badge_estado($p['estado'], ESTADOS_PEDIDO) ?>
        </div>
        <p class="muted"><?= e(ESTADOS_PEDIDO[$p['estado']][1] ?? '') ?></p>

        <div class="layout-2">
            <div class="card">
                <?php foreach ($lineas as $l): ?>
                    <div class="linea">
                        <img src="<?= e($l['imagen'] ?? '') ?>" alt="" loading="lazy">
                        <div>
                            <div class="linea-sub"><?= e($l['coleccion']) ?> · <?= num((int)$l['numero']) ?></div>
                            <div class="linea-titulo"><?= e($l['titulo']) ?></div>
                            <div class="linea-sub"><?= (int)$l['cantidad'] ?> × <?= precio((float)$l['precio']) ?></div>
                        </div>
                        <div class="linea-der"><strong><?= precio($l['precio'] * $l['cantidad']) ?></strong></div>
                    </div>
                <?php endforeach; ?>
                <div class="resumen-fila resumen-total"><span>Total</span><span><?= precio((float)$p['total']) ?></span></div>
            </div>

            <aside>
                <?php if ($p['pago_metodo'] === 'transferencia' && $p['estado'] === 'pendiente'): ?>
                    <div class="card card-destacada">
                        <h3>Datos para transferir</h3>
                        <?= html_datos_bancarios((float)$p['total']) ?>
                        <p class="small" style="margin:12px 0 0">Cuando transfieras, enviá el comprobante respondiendo el mail del pedido. Apenas lo confirmamos, empezamos a prepararlo.</p>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <h3>Retiro y pago</h3>
                    <p class="small" style="margin:0">
                        <b><?= e($p['envio_punto'] ?: (ENVIO_METODOS[$p['envio_metodo']] ?? '')) ?></b><br>
                        <?= e($p['envio_direccion']) ?>, <?= e($p['envio_localidad']) ?><br>
                        <?php if (!empty($punto['horario'])): ?><span class="muted"><?= e($punto['horario']) ?></span><br><?php endif; ?>
                        <?php if (!empty($punto['lat'])): ?><a href="https://www.google.com/maps/search/?api=1&query=<?= e($punto['lat'] . ',' . $punto['lng']) ?>" target="_blank" rel="noopener">Cómo llegar ↗</a><br><?php endif; ?>
                        <br>Retira: <?= e($p['envio_nombre']) ?> · <?= e($p['envio_telefono']) ?><br><br>
                        <b><?= e(PAGO_METODOS[$p['pago_metodo']] ?? '') ?></b>
                    </p>
                </div>
                <div class="card">
                    <h3>Seguimiento</h3>
                    <ul class="timeline">
                        <?php foreach ($hist as $h): ?>
                            <li>
                                <b><?= e(ESTADOS_PEDIDO[$h['estado']][0] ?? $h['estado']) ?></b>
                                <div class="cuando"><?= fecha($h['created_at']) ?></div>
                                <?php if ($h['nota']): ?><div class="small"><?= nl2br(e($h['nota'])) ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
