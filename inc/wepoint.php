<?php
// Integración con WePoint Warehouse (https://sistema.wepoint.ar/docs/api/).
// - El stock lo maneja WePoint: wepoint_sincronizar_stock() trae "disponible para venta" por SKU.
// - Cuando un pedido se confirma, wepoint_crear_orden() genera la orden de venta (egreso) para que la preparen.
// Autenticación: POST /v2/auth/login (email + contraseña, igual que el portal de WePoint) → token Bearer, que se guarda en `ajustes`.
//
// Config (config.php): WEPOINT_URL (sandbox: https://sandbox.wepoint.ar/api · producción: https://sistema.wepoint.ar/api),
// WEPOINT_EMAIL, WEPOINT_PASSWORD, WEPOINT_ID_TRANSPORTISTA.

// Interruptor de seguridad: las órdenes de venta solo se envían si WEPOINT_CREAR_ORDENES = true
// (así los pedidos de prueba nunca llegan al depósito real).
function wepoint_ordenes_activas(): bool {
    return wepoint_listo() && defined('WEPOINT_CREAR_ORDENES') && WEPOINT_CREAR_ORDENES === true;
}

function wepoint_listo(): bool {
    return defined('WEPOINT_URL') && WEPOINT_URL !== '' && defined('WEPOINT_EMAIL') && WEPOINT_EMAIL !== ''
        && defined('WEPOINT_PASSWORD') && WEPOINT_PASSWORD !== '';
}

function wepoint_ajuste_set(string $k, string $v): void {
    q("REPLACE INTO ajustes (clave, valor) VALUES (?,?)", [$k, $v]);
}

// Petición HTTP cruda. Devuelve [código, cuerpo decodificado].
function wepoint_http(string $metodo, string $ruta, ?array $body = null, ?string $token = null): array {
    $ch = curl_init(rtrim(WEPOINT_URL, '/') . '/' . ltrim($ruta, '/'));
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('No se pudo conectar con WePoint: ' . $err);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    return [$code, is_array($data) ? $data : ['raw' => mb_substr($raw, 0, 500)]];
}

function wepoint_token(bool $renovar = false): string {
    if (!wepoint_listo()) throw new Exception('Faltan los datos de WePoint en config.php (URL, email y contraseña).');
    $t = ajuste('wepoint_token');
    if ($t !== '' && !$renovar) return $t;
    [$code, $r] = wepoint_http('POST', 'v2/auth/login', ['email' => trim(WEPOINT_EMAIL), 'password' => trim(WEPOINT_PASSWORD)]);
    $t = $r['token'] ?? $r['access_token'] ?? $r['data']['token'] ?? $r['data']['access_token'] ?? '';
    if ($code >= 400 || !$t) throw new Exception('WePoint rechazó el login (' . $code . '): ' . ($r['message'] ?? 'sin detalle'));
    wepoint_ajuste_set('wepoint_token', $t);
    return $t;
}

// Llamada autenticada; si el token venció (401) hace login de nuevo una vez.
function wepoint_api(string $metodo, string $ruta, ?array $body = null): array {
    [$code, $r] = wepoint_http($metodo, $ruta, $body, wepoint_token());
    if ($code === 401) [$code, $r] = wepoint_http($metodo, $ruta, $body, wepoint_token(true));
    if ($code >= 400) {
        $det = $r['message'] ?? '';
        if (!empty($r['errors'])) $det .= ' ' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE);
        throw new Exception("WePoint respondió $code en $ruta: " . trim($det));
    }
    return $r;
}

// Lista paginada → filas. Acepta {data:[…]} o {data:{data:[…], last_page}}.
function wepoint_filas(array $r): array {
    $d = $r['data'] ?? $r;
    if (isset($d['data']) && is_array($d['data'])) return [$d['data'], (int)($d['last_page'] ?? 1)];
    return [is_array($d) ? $d : [], 1];
}

function wepoint_disponible_de(array $p): ?int {
    foreach (['disponible_para_venta', 'stock_disponible', 'disponible', 'existencias_a_mano', 'stock_actual', 'stock'] as $k) {
        if (isset($p[$k]) && is_numeric($p[$k])) return (int)$p[$k];
    }
    return null;
}

// Trae todos los productos de WePoint y actualiza stock + id por SKU. Devuelve un resumen.
function wepoint_sincronizar_stock(): array {
    $por_sku = [];
    $pagina = 1;
    do {
        [$filas, $ultima] = wepoint_filas(wepoint_api('GET', 'v2/productos?per_page=100&page=' . $pagina));
        foreach ($filas as $p) {
            $sku = strtoupper(trim((string)($p['sku'] ?? '')));
            if ($sku !== '') $por_sku[$sku] = $p;
        }
        $pagina++;
    } while ($pagina <= $ultima && $pagina <= 100);

    $res = ['wepoint' => count($por_sku), 'actualizados' => 0, 'sin_sku' => 0, 'no_encontrados' => [], 'sin_dato_stock' => 0];
    foreach (q("SELECT i.id, i.sku, i.numero, c.nombre FROM items i JOIN colecciones c ON c.id=i.coleccion_id")->fetchAll() as $it) {
        $sku = strtoupper(trim((string)$it['sku']));
        if ($sku === '') { $res['sin_sku']++; continue; }
        if (!isset($por_sku[$sku])) { $res['no_encontrados'][] = $it['nombre'] . ' ' . num((int)$it['numero']) . " ($sku)"; continue; }
        $p = $por_sku[$sku];
        $disp = wepoint_disponible_de($p);
        if ($disp === null) { $res['sin_dato_stock']++; continue; }
        q("UPDATE items SET stock=?, wepoint_id=?, stock_sync_at=NOW() WHERE id=?", [max(0, $disp), $p['id_producto'] ?? $p['id'] ?? null, $it['id']]);
        $res['actualizados']++;
    }
    wepoint_ajuste_set('wepoint_ultima_sync', date('Y-m-d H:i:s'));
    wepoint_ajuste_set('wepoint_ultimo_resultado', json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

// Crea la orden de venta en WePoint para un pedido confirmado. Guarda el id o el error en el pedido.
function wepoint_crear_orden(int $pedido_id): string {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$pedido_id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    if ($p['wepoint_orden_id']) return $p['wepoint_orden_id'];
    if (!wepoint_ordenes_activas()) throw new Exception('El envío de órdenes a WePoint está apagado (WEPOINT_CREAR_ORDENES en config.php).');
    try {
        if (!defined('WEPOINT_ID_TRANSPORTISTA') || WEPOINT_ID_TRANSPORTISTA === '') throw new Exception('Falta WEPOINT_ID_TRANSPORTISTA en config.php.');
        $detalle = [];
        foreach (q("SELECT pi.*, i.wepoint_id, i.sku FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$pedido_id]) as $l) {
            if (!$l['wepoint_id']) throw new Exception($l['coleccion'] . ' ' . num((int)$l['numero']) . ' no está vinculado a WePoint (falta SKU o sincronizar stock).');
            $detalle[] = ['id_producto' => (int)$l['wepoint_id'], 'cantidad' => (int)$l['cantidad'], 'precio' => max(0.01, (float)$l['precio'])];
        }
        $r = wepoint_api('POST', 'v2/egresos/productos', [
            'no_referencia'       => 'MFU-' . $pedido_id,
            'fecha'               => date('Y-m-d'),
            'id_transportista'    => (string)WEPOINT_ID_TRANSPORTISTA,
            'notas'               => 'Retiro en ' . $p['envio_punto'] . ' (' . $p['envio_direccion'] . ', ' . $p['envio_localidad'] . ')' . ($p['notas'] ? ' · ' . $p['notas'] : ''),
            'destinatario'        => [
                'nombre'        => mb_substr($p['envio_nombre'] ?: nombre_cliente($p), 0, 100),
                'telefono'      => mb_substr((string)$p['envio_telefono'], 0, 50),
                'email'         => mb_substr($p['email'], 0, 100),
                'direccion'     => $p['envio_punto'] . ' — ' . $p['envio_direccion'],
                'ciudad'        => mb_substr((string)$p['envio_localidad'], 0, 100),
                'provincia'     => $p['envio_provincia'],
                'codigo_postal' => $p['envio_cp'],
            ],
            'detalle_orden_venta' => $detalle,
        ]);
        $id = (string)($r['data']['id_orden_venta'] ?? $r['data']['id'] ?? $r['id'] ?? 'MFU-' . $pedido_id);
        q("UPDATE pedidos SET wepoint_orden_id=?, wepoint_error=NULL WHERE id=?", [$id, $pedido_id]);
        registrar_historial('pedido', $pedido_id, $p['estado'], 'Orden de venta creada en WePoint: ' . $id, 'WePoint');
        return $id;
    } catch (Exception $e) {
        q("UPDATE pedidos SET wepoint_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 1000), $pedido_id]);
        throw $e;
    }
}
