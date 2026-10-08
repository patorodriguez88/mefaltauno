<?php
// Herramientas de WePoint: estado, transportistas y (solo en SANDBOX) carga de productos y stock de prueba.
require __DIR__ . '/_inc.php';

$sandbox = wepoint_listo() && stripos(wepoint_base(), 'sandbox') !== false;
$log = [];

function wp_id(array $r): ?int {
    foreach ([$r['data']['id_producto'] ?? null, $r['data']['id'] ?? null, $r['data']['id_orden_compra'] ?? null, $r['id'] ?? null] as $v) {
        if (is_numeric($v)) return (int)$v;
    }
    return null;
}

// SKU estable para cada número: MFU-<id colección>-<número>
function sku_demo(array $it): string {
    return sprintf('MFU-%02d-%02d', $it['coleccion_id'], $it['numero']);
}

// Consulta de solo lectura a la API (diagnóstico): ?consultar=v2/ingresos/productos/19
if (isset($_GET['consultar'])) {
    $ruta = (string)$_GET['consultar'];
    if (!preg_match('#^v2/[a-z0-9_/\-]+(\?[a-z0-9_=&\-]*)?$#i', $ruta)) json_out(['ok' => false, 'error' => 'Ruta inválida'], 400);
    try {
        json_out(['ok' => true, 'ruta' => $ruta, 'respuesta' => wepoint_api('GET', $ruta)]);
    } catch (Exception $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $accion = $_POST['accion'] ?? '';
    try {
        if (in_array($accion, ['crear_productos', 'cargar_stock'], true) && !$sandbox) {
            throw new Exception('Solo se pueden crear datos de prueba con WEPOINT_URL apuntando al sandbox.');
        }
        if ($accion === 'crear_productos') {
            $items = q("SELECT i.*, c.nombre AS coleccion FROM items i JOIN colecciones c ON c.id=i.coleccion_id ORDER BY i.coleccion_id, i.numero")->fetchAll();
            $n = 0;
            foreach ($items as $it) {
                // Siempre se verifica por SKU: el sandbox se borra todas las noches a las 03:00
                $sku = $it['sku'] ?: sku_demo($it);
                $id = null;
                // ¿Ya existe en WePoint ese SKU?
                [$filas] = wepoint_filas(wepoint_api('GET', 'v2/productos?per_page=5&sku=' . urlencode($sku)));
                foreach ($filas as $p) if (strcasecmp((string)($p['sku'] ?? ''), $sku) === 0) $id = (int)($p['id_producto'] ?? $p['id']);
                if (!$id) {
                    $r = wepoint_api('POST', 'v2/productos', [
                        'sku' => $sku,
                        'nombre' => mb_substr($it['coleccion'] . ' ' . num((int)$it['numero']) . ' — ' . $it['titulo'], 0, 190),
                        'precio_venta' => (float)$it['precio'],
                        'descripcion' => 'Producto de prueba MeFaltaUno',
                    ]);
                    $id = wp_id($r);
                    if (!$id) throw new Exception("WePoint no devolvió el id del producto $sku: " . json_encode($r, JSON_UNESCAPED_UNICODE));
                }
                q("UPDATE items SET sku=?, wepoint_id=? WHERE id=?", [$sku, $id, $it['id']]);
                $n++;
            }
            $log[] = "Productos vinculados en WePoint: $n";
        }

        if ($accion === 'cargar_stock') {
            // Orden de compra con cantidades ficticias (algunos en 0 para ver "sin stock")
            $items = q("SELECT id, numero, wepoint_id, precio FROM items WHERE wepoint_id IS NOT NULL ORDER BY coleccion_id, numero")->fetchAll();
            if (!$items) throw new Exception('Primero creá los productos en WePoint.');
            $detalle = [];
            foreach ($items as $i => $it) {
                $cant = ($i % 6 === 5) ? 0 : [3, 8, 15, 25, 40][$i % 5];
                if ($cant) $detalle[] = ['id_producto' => (int)$it['wepoint_id'], 'cantidad' => $cant, 'precio' => max(0.01, round((float)$it['precio'] * 0.5, 2))];
            }
            $ref = 'MFU-DEMO-' . date('YmdHis');
            $r = wepoint_api('POST', 'v2/ingresos/productos', ['no_referencia' => $ref, 'fecha' => date('Y-m-d'), 'proveedor' => 'Proveedor de prueba', 'notas' => 'Stock ficticio para la demo', 'detalle_orden_compra' => $detalle]);
            $oc = wp_id($r);
            if (!$oc) throw new Exception('WePoint no devolvió el id de la orden de compra: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            $log[] = "Orden de compra $ref creada (id $oc) con " . count($detalle) . ' productos.';
            // Recepción total de la orden: así el stock queda disponible
            $oc_det = wepoint_api('GET', 'v2/ingresos/productos/' . $oc);
            $lineas = $oc_det['data']['detalle_orden_compra'] ?? $oc_det['data']['detalles'] ?? $oc_det['data']['detalle'] ?? [];
            $rec = [];
            foreach ($lineas as $l) {
                $rec[] = ['id_detalle_orden_compra' => (int)($l['id_detalle_orden_compra'] ?? $l['id']), 'cantidad' => (int)$l['cantidad']];
            }
            if (!$rec) throw new Exception('No encontré las líneas de la orden de compra para recibirlas: ' . mb_substr(json_encode($oc_det['data'] ?? $oc_det, JSON_UNESCAPED_UNICODE), 0, 600));
            wepoint_api('POST', 'v2/recepciones/productos/' . $oc, ['fecha' => date('Y-m-d'), 'notas' => 'Recepción de prueba', 'detalle_orden_recepcion' => $rec]);
            $log[] = 'Recepción registrada: ' . count($rec) . ' líneas.';
        }

        if ($accion === 'pedidos') {
            $s = wepoint_sincronizar_pedidos();
            $log[] = "Pedidos revisados: {$s['revisados']} · actualizados: {$s['actualizados']}" . ($s['errores'] ? ' · ' . implode(' | ', $s['errores']) : '');
        }
        if (in_array($accion, ['cargar_stock', 'sincronizar'], true)) {
            $s = wepoint_sincronizar_stock();
            $log[] = "Stock sincronizado: {$s['actualizados']} números actualizados · {$s['sin_dato_stock']} sin dato de stock · " . count($s['no_encontrados']) . ' SKU sin coincidencia · ' . $s['nuevos'] . ' productos nuevos por publicar.';
        }
        if ($accion === 'crear_transportista') {
            $nombre = trim(mb_substr($_POST['nombre'] ?? '', 0, 100)) ?: 'Retiro en kiosco · MeFaltaUno';
            $r = wepoint_api('POST', 'v2/transportistas', ['nombre' => $nombre]);
            $tid = $r['data']['id_transportista'] ?? $r['data']['id'] ?? null;
            if (!$tid) throw new Exception('WePoint no devolvió el id del transportista: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            q("REPLACE INTO ajustes (clave, valor) VALUES ('wepoint_id_transportista', ?)", [(string)$tid]);
            $log[] = "Transportista “{$nombre}” creado en WePoint (id $tid) y elegido para las órdenes.";
        }
        if ($accion === 'transportista') {
            $t = preg_replace('/\D/', '', $_POST['id_transportista'] ?? '');
            q("REPLACE INTO ajustes (clave, valor) VALUES ('wepoint_id_transportista', ?)", [$t]);
            $log[] = $t !== '' ? "Transportista para las órdenes: id $t" : 'Transportista: se usa el de config.php';
        }
        if ($accion === 'probar') {
            $pw = trim(WEPOINT_PASSWORD);
            $log[] = 'Diagnóstico: email «' . trim(WEPOINT_EMAIL) . '» · contraseña de ' . mb_strlen($pw) . ' caracteres'
                . (preg_match('/[\'"\\\\$\s]/', $pw) ? ' · contiene comillas, barra, $ o espacios' : '') . ' · API ' . wepoint_base();
            wepoint_token(true);
            $log[] = 'Login OK en ' . wepoint_base();
        }
    } catch (Exception $e) {
        $log[] = 'ERROR: ' . $e->getMessage();
    }
    if (($_GET['formato'] ?? '') === 'json') json_out(['ok' => !preg_grep('/^ERROR/', $log), 'log' => $log]);
    foreach ($log as $l) flash(strpos($l, 'ERROR') === 0 ? 'error' : 'ok', $l);
    redirect('admin/wepoint.php');
}

// Transportistas (para WEPOINT_ID_TRANSPORTISTA) y una muestra de productos
$transportistas = [];
$muestra = [];
$error = null;
if (wepoint_listo()) {
    try {
        [$transportistas] = wepoint_filas(wepoint_api('GET', 'v2/transportistas'));
        [$muestra] = wepoint_filas(wepoint_api('GET', 'v2/productos?per_page=5'));
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$vinculados = (int)q("SELECT COUNT(*) FROM items WHERE wepoint_id IS NOT NULL")->fetchColumn();
$total = (int)q("SELECT COUNT(*) FROM items")->fetchColumn();

admin_header('WePoint', 'wepoint');
?>

<h1>WePoint</h1>

<?php if (!wepoint_listo()): ?>
    <div class="aviso">Faltan WEPOINT_URL, WEPOINT_EMAIL y WEPOINT_PASSWORD en config.php.</div>
<?php else: ?>
<div class="layout-2">
    <div>
        <div class="card">
            <h3>Conexión</h3>
            <p class="small">
                <?= $sandbox ? '<span class="badge badge-amarillo">SANDBOX (pruebas)</span>' : '<span class="badge badge-rojo">PRODUCCIÓN</span>' ?>
                <span class="muted"><?= e(wepoint_base()) ?></span>
                <?= wepoint_ordenes_activas() ? '<span class="badge badge-azul">Envía órdenes</span>' : '<span class="badge badge-gris">Órdenes apagadas</span>' ?>
            </p>
            <?php if ($error): ?><div class="aviso" style="background:var(--red-100);color:var(--red)"><?= e($error) ?></div><?php endif; ?>
            <p class="small muted">Números vinculados a WePoint: <b><?= $vinculados ?></b> de <?= $total ?>.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="probar"><button class="btn btn-linea btn-chico">Probar conexión</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><button class="btn btn-teal btn-chico">Sincronizar stock</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="pedidos"><button class="btn btn-teal btn-chico">Actualizar estados de pedidos</button></form>
            </div>
        </div>

        <?php if ($sandbox): ?>
        <div class="card card-destacada">
            <h3>Datos de prueba (solo sandbox)</h3>
            <p class="small">1) Crea en WePoint un producto por cada número de la web (SKU <code>MFU-colección-número</code>) y los vincula.<br>
               2) Carga stock ficticio con una orden de compra + recepción (algunos quedan en 0 para ver “sin stock”) y sincroniza.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="crear_productos"><button class="btn btn-primario btn-chico">1 · Crear productos en WePoint</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="cargar_stock"><button class="btn btn-primario btn-chico">2 · Cargar stock de prueba</button></form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h3>Muestra de productos en WePoint</h3>
            <?php if (!$muestra): ?><p class="muted small">Sin productos (o no se pudieron leer).</p><?php endif; ?>
            <?php foreach ($muestra as $p): ?>
                <div class="small" style="padding:6px 0;border-bottom:1px dashed var(--line)">
                    <b><?= e($p['sku'] ?? '—') ?></b> · <?= e($p['nombre'] ?? '') ?> · disponible: <b><?= e((string)(wepoint_disponible_de($p) ?? '¿?')) ?></b>
                </div>
            <?php endforeach; ?>
            <?php if ($muestra): ?><details class="small" style="margin-top:8px"><summary>Ver datos crudos del primero</summary><pre style="white-space:pre-wrap;font-size:.75rem"><?= e(json_encode($muestra[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details><?php endif; ?>
        </div>
    </div>
    <aside class="card">
        <h3>Transportistas</h3>
        <p class="muted small">Elegí con cuál se crean las órdenes de venta. Actual: <b><?= e(wepoint_transportista() ?: 'ninguno') ?></b></p>
        <form method="post" style="display:flex;gap:8px;margin-bottom:12px">
            <?= csrf_field() ?><input type="hidden" name="accion" value="transportista">
            <select name="id_transportista" style="flex:1">
                <?php foreach ($transportistas as $t): $tid = (string)($t['id_transportista'] ?? $t['id'] ?? ''); ?>
                    <option value="<?= e($tid) ?>" <?= $tid === wepoint_transportista() ? 'selected' : '' ?>><?= e($tid . ' · ' . ($t['nombre'] ?? $t['razon_social'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-teal btn-chico" type="submit">Usar</button>
        </form>
        <form method="post" style="display:flex;gap:8px;margin-bottom:12px">
            <?= csrf_field() ?><input type="hidden" name="accion" value="crear_transportista">
            <input type="text" name="nombre" placeholder="Retiro en kiosco · MeFaltaUno" style="flex:1">
            <button class="btn btn-linea btn-chico" type="submit">Crear propio</button>
        </form>
        <p class="muted small">Los transportistas de WePoint necesitan que ellos los habiliten; uno propio de la empresa se puede usar enseguida.</p>
        <?php foreach ($transportistas as $t): ?>
            <div class="small" style="padding:6px 0;border-bottom:1px dashed var(--line)"><b>id <?= e((string)($t['id_transportista'] ?? $t['id'] ?? '?')) ?></b> · <?= e($t['nombre'] ?? $t['razon_social'] ?? json_encode($t, JSON_UNESCAPED_UNICODE)) ?></div>
        <?php endforeach; ?>
        <?php if (!$transportistas): ?><p class="muted small">No hay transportistas cargados (o no se pudieron leer).</p><?php endif; ?>
    </aside>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
