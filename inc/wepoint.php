<?php
// Integración con WePoint Warehouse (https://sistema.wepoint.ar/docs/api/).
// - El stock lo maneja WePoint: wepoint_sincronizar_stock() trae "disponible para venta" de cada edición (o producto simple).
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

// Transportista para las órdenes: el elegido en Admin → WePoint, o el de config.php
function wepoint_transportista(): string {
    $t = ajuste('wepoint_id_transportista');
    if ($t !== '') return $t;
    return defined('WEPOINT_ID_TRANSPORTISTA') ? (string)WEPOINT_ID_TRANSPORTISTA : '';
}

// Busca (o crea) el transportista propio de la empresa en WePoint y lo deja elegido para las órdenes.
// Los transportistas de WePoint (Caddy, Retira Cliente…) dan "No tienes acceso"; uno propio se usa enseguida.
const WEPOINT_TRANSPORTISTA_PROPIO = 'Retiro en kiosco · MeFaltaUno';
function wepoint_transportista_propio(): string {
    // Primero se busca por nombre (puede existir con otro id que el guardado); si no está, se crea
    [$filas] = wepoint_filas(wepoint_api('GET', 'v2/transportistas'));
    foreach ($filas as $t) {
        if (mb_strtolower(trim((string)($t['nombre'] ?? ''))) === mb_strtolower(WEPOINT_TRANSPORTISTA_PROPIO)) {
            $tid = (string)($t['id_transportista'] ?? $t['id'] ?? '');
            if ($tid !== '') {
                wepoint_ajuste_set('wepoint_id_transportista', $tid);
                return $tid;
            }
        }
    }
    $r = wepoint_api('POST', 'v2/transportistas', ['nombre' => WEPOINT_TRANSPORTISTA_PROPIO]);
    $tid = (string)($r['data']['id_transportista'] ?? $r['data']['id'] ?? '');
    if ($tid === '') throw new Exception('WePoint no devolvió el id del transportista: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    wepoint_ajuste_set('wepoint_id_transportista', $tid);
    return $tid;
}

function wepoint_listo(): bool {
    return defined('WEPOINT_URL') && WEPOINT_URL !== '' && defined('WEPOINT_EMAIL') && WEPOINT_EMAIL !== ''
        && defined('WEPOINT_PASSWORD') && WEPOINT_PASSWORD !== '';
}

function wepoint_ajuste_set(string $k, string $v): void {
    q("REPLACE INTO ajustes (clave, valor) VALUES (?,?)", [$k, $v]);
}

// Base de la API. Acepta también la dirección del portal (sandbox-portal…) que muestra WePoint,
// y agrega /api si falta.
function wepoint_base(): string {
    $u = rtrim(trim(WEPOINT_URL), '/');
    $u = preg_replace('#^https?://sandbox-portal\.wepoint\.ar#i', 'https://sandbox.wepoint.ar', $u);
    if (!preg_match('#/api$#', $u)) $u .= '/api';
    return $u;
}

// Petición HTTP cruda. Devuelve [código, cuerpo decodificado].
function wepoint_http(string $metodo, string $ruta, ?array $body = null, ?string $token = null): array {
    $ch = curl_init(wepoint_base() . '/' . ltrim($ruta, '/'));
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

// Unidades que se venden: cada edición de un título (variante) y cada producto simple. Los títulos no se venden.
// GET v2/variantes/operables → {tipo VARIANTE|SIMPLE, id_producto, id_variante, nombre (del título), sku (de la edición),
// numero_edicion, precio_venta, disponible_para_venta}. Devuelve las filas por clave (v:<id_variante> o p:<id_producto>).
function wepoint_clave(?int $id_producto, ?int $id_variante): string {
    return $id_variante ? 'v:' . $id_variante : 'p:' . (int)$id_producto;
}

// Clave WePoint de un número de la web (fila de items con wepoint_id y wepoint_variante_id)
function wepoint_clave_item(array $it): string {
    return wepoint_clave((int)$it['wepoint_id'], !empty($it['wepoint_variante_id']) ? (int)$it['wepoint_variante_id'] : null);
}

function wepoint_unidades(): array {
    $todas = [];
    $pagina = 1;
    do {
        [$filas, $ultima] = wepoint_filas(wepoint_api('GET', 'v2/variantes/operables?per_page=100&page=' . $pagina));
        foreach ($filas as $u) {
            if (($u['tipo'] ?? '') === 'PADRE' || empty($u['id_producto'])) continue;
            $u['id_variante'] = !empty($u['id_variante']) ? (int)$u['id_variante'] : null;
            $todas[wepoint_clave((int)$u['id_producto'], $u['id_variante'])] = $u;
        }
        $pagina++;
    } while ($pagina <= $ultima && $pagina <= 200);
    return $todas;
}

// Trae las unidades de WePoint y actualiza stock y vínculo de cada número. Busca en este orden:
// la edición vinculada, el SKU, y el título vinculado + el número (para los que se vincularon cuando eran productos simples).
function wepoint_sincronizar_stock(): array {
    $todas = wepoint_unidades();
    $por_sku = [];
    $por_titulo_numero = [];
    foreach ($todas as $u) {
        $sku = strtoupper(trim((string)($u['sku'] ?? '')));
        if ($sku !== '') $por_sku[$sku] = $u;
        if ($u['id_variante'] && !empty($u['numero_edicion'])) $por_titulo_numero[$u['id_producto'] . '#' . (int)$u['numero_edicion']] = $u;
    }

    $res = ['wepoint' => count($todas), 'actualizados' => 0, 'sin_sku' => 0, 'no_encontrados' => [], 'sin_dato_stock' => 0];
    foreach (q("SELECT i.id, i.sku, i.wepoint_id, i.wepoint_variante_id, i.numero, c.nombre FROM items i JOIN colecciones c ON c.id=i.coleccion_id")->fetchAll() as $it) {
        $sku = strtoupper(trim((string)$it['sku']));
        $u = null;
        if ($it['wepoint_variante_id']) $u = $todas[wepoint_clave(null, (int)$it['wepoint_variante_id'])] ?? null;
        if (!$u && $sku !== '') $u = $por_sku[$sku] ?? null;
        if (!$u && $it['wepoint_id']) {
            $u = $todas[wepoint_clave((int)$it['wepoint_id'], null)] ?? $por_titulo_numero[$it['wepoint_id'] . '#' . (int)$it['numero']] ?? null;
        }
        if (!$u) {
            if ($sku === '' && !$it['wepoint_id']) $res['sin_sku']++;
            else $res['no_encontrados'][] = $it['nombre'] . ' ' . num((int)$it['numero']) . ($sku !== '' ? " ($sku)" : '');
            continue;
        }
        $disp = wepoint_disponible_de($u);
        if ($disp === null) { $res['sin_dato_stock']++; continue; }
        // llegada_at primero: compara con el stock anterior (sin stock en ningún lado → ahora hay)
        q("UPDATE items SET llegada_at = IF(stock<=0 AND COALESCE(brihet_stock,0)<=0 AND ?>0, NOW(), llegada_at), stock=?, wepoint_id=?, wepoint_variante_id=?, stock_sync_at=NOW() WHERE id=?",
          [max(0, $disp), max(0, $disp), (int)$u['id_producto'], $u['id_variante'], $it['id']]);
        $res['actualizados']++;
    }
    $res['nuevos'] = wepoint_registrar_nuevos($todas);
    wepoint_ajuste_set('wepoint_ultima_sync', date('Y-m-d H:i:s'));
    wepoint_ajuste_set('wepoint_ultimo_resultado', json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

// Todo lo que hay en la cuenta de WePoint es para vender en la web: cada edición (o producto simple) que todavía
// no es un número de la web va a la bandeja "Por publicar". Devuelve cuántas hay pendientes.
function wepoint_registrar_nuevos(array $todas): int {
    $en_web = [];
    foreach (q("SELECT sku, wepoint_id, wepoint_variante_id FROM items WHERE wepoint_id IS NOT NULL OR (sku IS NOT NULL AND sku<>'')")->fetchAll() as $it) {
        if ($it['wepoint_id']) $en_web[wepoint_clave((int)$it['wepoint_id'], $it['wepoint_variante_id'] ? (int)$it['wepoint_variante_id'] : null)] = true;
        if ((string)$it['sku'] !== '') $en_web['sku:' . strtoupper(trim($it['sku']))] = true;
    }
    foreach ($todas as $clave => $u) {
        $sku = strtoupper(trim((string)($u['sku'] ?? '')));
        if (isset($en_web[$clave]) || ($sku !== '' && isset($en_web['sku:' . $sku]))) continue;
        $precio = $u['precio_venta'] ?? null;
        q("INSERT INTO wepoint_nuevos (clave, wepoint_id, wepoint_variante_id, sku, nombre, numero_edicion, precio, stock, visto_at) VALUES (?,?,?,?,?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE sku=VALUES(sku), nombre=VALUES(nombre), numero_edicion=VALUES(numero_edicion), precio=VALUES(precio), stock=VALUES(stock), visto_at=NOW()",
          [$clave, (string)$u['id_producto'], $u['id_variante'], $sku ?: null, mb_substr((string)($u['nombre'] ?? ''), 0, 255) ?: null,
           !empty($u['numero_edicion']) ? (int)$u['numero_edicion'] : null, is_numeric($precio) && $precio > 0 ? (float)$precio : null, max(0, (int)wepoint_disponible_de($u))]);
    }
    // Salen de la bandeja las que ya son números de la web y las que ya no están en WePoint
    q("DELETE n FROM wepoint_nuevos n JOIN items i ON (i.wepoint_id=n.wepoint_id AND i.wepoint_variante_id <=> n.wepoint_variante_id) OR (n.sku IS NOT NULL AND UPPER(i.sku)=n.sku)");
    if ($todas) {
        $marcas = implode(',', array_fill(0, count($todas), '?'));
        q("DELETE FROM wepoint_nuevos WHERE clave NOT IN ($marcas)", array_keys($todas));
    }
    return wepoint_nuevos_pendientes();
}

function wepoint_nuevos_pendientes(): int {
    return (int)q("SELECT COUNT(*) FROM wepoint_nuevos")->fetchColumn();
}

// SKU MFU-<id colección>-<número> → [coleccion_id, numero] (si tiene ese formato)
function wepoint_sku_partes(?string $sku): array {
    return preg_match('/^MFU-(\d+)-(\d+)$/i', trim((string)$sku), $m) ? [(int)$m[1], (int)$m[2]] : [null, null];
}

// ─── Sugerencia de colección y número para "Por publicar" ───────────────────
// WePoint no tiene número de entrega: el depósito lo pone al final del nombre ("AUTOS DE COLECCION 1/60 2",
// "… -2", "… N° 2") y el 1 suele ir sin número. La escala (1/60, 1:24) no es parte del nombre de la colección.

// Minúsculas, sin acentos (sin iconv: en macOS deja "colecci'on"), sin la escala y solo letras y números
function wepoint_normalizar(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'º' => ' ', '°' => ' ']);
    $s = preg_replace('#\b\d+\s*[/:]\s*\d+\b#', ' ', $s);   // escala 1/60, 1:24
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

// Nombre de WePoint → [nombre de la colección normalizado, número de entrega o null]
function wepoint_nombre_partes(string $nombre): array {
    $n = wepoint_normalizar($nombre);
    if (preg_match('/^(.*?)\s+(?:(?:n|nro|no|num|numero|entrega|vol|tomo)\s+)?0*(\d{1,3})$/', $n, $m) && trim($m[1]) !== '') {
        return [trim($m[1]), (int)$m[2] ?: null];
    }
    return [$n, null];
}

function wepoint_palabras(string $norm): array {
    // "dgp": Dinter termina los nombres de los títulos con su código ("UNE LOS PUNTOS-DGP")
    static $vacias = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'en', 'a', 'e', 'dgp'];
    return array_values(array_diff(array_unique(explode(' ', $norm)), $vacias, ['']));
}

// Propone [coleccion_id, numero] para un producto de WePoint. $cols: filas de colecciones (id, nombre, brihet_id, proximamente).
// Gana el nombre idéntico; si no, el que comparte más palabras (al menos 3 de cada 4). Ante empate, la importada de Brihet.
function wepoint_sugerir(array $nv, array $cols): array {
    [$col_id, $num] = wepoint_sku_partes($nv['sku'] ?? null);
    if ($col_id) return [$col_id, $num];
    [$base, $num] = wepoint_nombre_partes((string)($nv['nombre'] ?? ''));
    // Una edición de WePoint trae su número; el nombre es el del título
    if (!empty($nv['numero_edicion'])) [$base, $num] = [wepoint_normalizar((string)($nv['nombre'] ?? '')), (int)$nv['numero_edicion']];
    // Sin número en el nombre: los SKU del depósito terminan en el número de entrega (…5200001 → 1, …5200002 → 2)
    if ($num === null && preg_match('/^\d{8,}$/', (string)($nv['sku'] ?? '')) && (int)substr($nv['sku'], -3) > 0) {
        $num = (int)substr($nv['sku'], -3);
    }
    $pal = wepoint_palabras($base);
    $mejor = null;
    $mejor_puntaje = 0.0;
    foreach ($cols as $c) {
        $cn = wepoint_normalizar($c['nombre']);
        if ($cn === $base) $puntaje = 1.0;
        else {
            $pc = wepoint_palabras($cn);
            $union = count(array_unique(array_merge($pal, $pc)));
            $puntaje = $union ? count(array_intersect($pal, $pc)) / $union * 0.99 : 0;
        }
        if ($puntaje < 0.75) continue;
        // desempate: Brihet antes que cargada a mano, publicada antes que "Próximamente"
        $puntaje += ($c['brihet_id'] ? 0.002 : 0) + (empty($c['proximamente']) ? 0.001 : 0);
        if ($puntaje > $mejor_puntaje) [$mejor, $mejor_puntaje] = [(int)$c['id'], $puntaje];
    }
    return [$mejor, $num];
}

// Crea la orden de venta en WePoint para un pedido confirmado. Guarda el id o el error en el pedido.
function wepoint_crear_orden(int $pedido_id): string {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email, pr.cp AS punto_cp, pr.provincia AS punto_provincia
            FROM pedidos p JOIN clientes c ON c.id=p.cliente_id LEFT JOIN puntos_retiro pr ON pr.id=p.punto_id WHERE p.id=?", [$pedido_id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    $p['envio_cp'] = $p['envio_cp'] ?: $p['punto_cp'];
    $p['envio_provincia'] = $p['envio_provincia'] ?: $p['punto_provincia'];
    if ($p['wepoint_orden_id']) return $p['wepoint_orden_id'];
    if (!wepoint_ordenes_activas()) throw new Exception('El envío de órdenes a WePoint está apagado (WEPOINT_CREAR_ORDENES en config.php).');
    try {
        if (wepoint_transportista() === '') throw new Exception('Falta elegir el transportista en Admin → WePoint.');
        if (!$p['envio_cp']) throw new Exception('El punto de retiro “' . $p['envio_punto'] . '” no tiene código postal: cargalo en Puntos de retiro y reenviá.');
        $detalle = [];
        foreach (q("SELECT pi.*, i.wepoint_id, i.wepoint_variante_id, i.sku FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=?", [$pedido_id]) as $l) {
            if (!$l['wepoint_id']) throw new Exception($l['coleccion'] . ' ' . num((int)$l['numero']) . ' no está vinculado a WePoint (falta SKU o sincronizar stock).');
            // Con ediciones, la línea lleva la edición: WePoint no acepta un título sin edición
            $linea = ['id_producto' => (int)$l['wepoint_id'], 'cantidad' => (int)$l['cantidad'], 'precio' => max(0.01, (float)$l['precio'])];
            if ($l['wepoint_variante_id']) $linea['id_variante'] = (int)$l['wepoint_variante_id'];
            $detalle[] = $linea;
        }
        $orden = [
            'no_referencia'       => 'MFU-' . $pedido_id,
            'fecha'               => date('Y-m-d'),
            'id_transportista'    => wepoint_transportista(),
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
        ];
        try {
            $r = wepoint_api('POST', 'v2/egresos/productos', $orden);
        } catch (Exception $e) {
            // El sandbox de WePoint se borra cada noche y se lleva el transportista propio:
            // se vuelve a crear y se reintenta una vez.
            if (stripos($e->getMessage(), 'acceso a este transportista') === false) throw $e;
            $orden['id_transportista'] = wepoint_transportista_propio();
            $r = wepoint_api('POST', 'v2/egresos/productos', $orden);
        }
        $id = (string)($r['data']['id_orden_venta'] ?? $r['data']['id'] ?? $r['id'] ?? 'MFU-' . $pedido_id);
        q("UPDATE pedidos SET wepoint_orden_id=?, wepoint_error=NULL WHERE id=?", [$id, $pedido_id]);
        registrar_historial('pedido', $pedido_id, $p['estado'], 'Orden de venta creada en WePoint: ' . $id, 'WePoint');
        return $id;
    } catch (Exception $e) {
        q("UPDATE pedidos SET wepoint_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 1000), $pedido_id]);
        throw $e;
    }
}

// ─── Estado de las órdenes ──────────────────────────────────────────────────
// WePoint: Emitida → Empaquetada → Listo para enviar → (en camino) → Entregada/Completada.
// En la web: confirmado → preparando ("Preparando tu pedido") → enviado ("Listo para retirar").
// Cada cambio de estado en WePoint queda en el historial; el mail solo sale cuando cambia el estado de la web.

function wepoint_estado_web(array $ov): ?string {
    $e = mb_strtolower(trim((string)($ov['estado'] ?? '')));
    if (in_array($e, ['completada', 'entregada', 'entregado', 'cerrada'], true)) return 'enviado';
    if ($e !== 'emitida' || !empty($ov['picking_existe'])) return 'preparando';
    return null;
}

// Texto para el cliente según el estado de WePoint
function wepoint_texto_estado(array $ov): string {
    $e = mb_strtolower(trim((string)($ov['estado'] ?? '')));
    $paq = $ov['paquetes'][0]['nro_paquete'] ?? null;
    if ($e === 'empaquetada') return 'Empaquetamos tu pedido' . ($paq ? " (paquete $paq)" : '') . '.';
    if (strpos($e, 'listo para enviar') !== false) return 'Tu pedido está listo para salir hacia el punto de encuentro.';
    if (strpos($e, 'transportista') !== false) return 'Tu pedido salió del depósito y va en camino al punto de encuentro.';
    if (preg_match('/enviad|despachad|camino|transito|tránsito/u', $e)) return 'Tu pedido va en camino al punto de encuentro.';
    if (in_array($e, ['completada', 'entregada', 'entregado', 'cerrada'], true)) return 'Tu pedido llegó al punto de encuentro. ¡Pasá a buscarlo!';
    return 'Actualización del depósito: ' . ($ov['estado'] ?? '—') . '.';
}

// Recorre los pedidos enviados a WePoint que siguen en curso y los actualiza.
function wepoint_sincronizar_pedidos(): array {
    require_once __DIR__ . '/notificaciones.php';
    $orden = ['confirmado' => 1, 'preparando' => 2, 'enviado' => 3];
    $res = ['revisados' => 0, 'actualizados' => 0, 'errores' => []];
    foreach (q("SELECT id, estado, wepoint_orden_id, wepoint_estado FROM pedidos WHERE wepoint_orden_id IS NOT NULL AND estado IN ('confirmado','preparando')")->fetchAll() as $p) {
        $res['revisados']++;
        try {
            $ov = wepoint_api('GET', 'v2/egresos/productos/' . rawurlencode($p['wepoint_orden_id']))['data'] ?? [];
            $estado_wp = trim((string)($ov['estado'] ?? ''));
            if ($estado_wp === '' || $estado_wp === $p['wepoint_estado']) continue;   // sin cambios en WePoint
            $texto = wepoint_texto_estado($ov);
            $nuevo = wepoint_estado_web($ov);
            $avanza = $nuevo && $orden[$nuevo] > $orden[$p['estado']];   // nunca retrocede
            $estado_web = $avanza ? $nuevo : $p['estado'];
            q("UPDATE pedidos SET estado=?, wepoint_estado=? WHERE id=?", [$estado_web, $estado_wp, $p['id']]);
            registrar_historial('pedido', (int)$p['id'], $estado_web, $texto, 'WePoint · ' . $estado_wp);
            if ($avanza) notificar_cambio_estado('pedido', (int)$p['id'], $nuevo, $nuevo === 'enviado' ? null : $texto);
            $res['actualizados']++;
            // Listo para enviar en el depósito → se crea el envío en Caddy hacia el kiosco
            if (stripos($estado_wp, 'listo para enviar') !== false && caddy_envios_activos()) {
                try {
                    caddy_crear_envio((int)$p['id']);
                } catch (Exception $e) {
                    $res['errores'][] = '#' . $p['id'] . ' Caddy: ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $res['errores'][] = '#' . $p['id'] . ': ' . $e->getMessage();
        }
    }
    wepoint_ajuste_set('wepoint_ultima_sync_pedidos', date('Y-m-d H:i:s'));
    return $res;
}
