<?php
// Integración con la API de Caddy (envíos): cuando WePoint deja el pedido "Listo para enviar",
// se crea el envío en Caddy hacia el kiosco elegido y se guarda el código de seguimiento.
// Config (config.php): CADDY_URL (sandbox: https://api.caddy.com.ar/sandbox · producción: https://api.caddy.com.ar/api),
// CADDY_USUARIO / CADDY_PASSWORD (la cuenta de MeFaltaUno en plataforma.caddy.com.ar), CADDY_BOX (medidas por defecto)
// y el interruptor CADDY_CREAR_ENVIOS (apagado = nunca se crea nada en Caddy).

function caddy_listo(): bool {
    return defined('CADDY_URL') && CADDY_URL !== '' && defined('CADDY_USUARIO') && CADDY_USUARIO !== '';
}

// Apagado por ahora (16/10/2026): la tienda no crea envíos en Caddy aunque config.php tenga CADDY_CREAR_ENVIOS = true.
// Para volver a usarlo, poner esta constante en true.
const CADDY_TIENDA_HABILITADO = false;

function caddy_envios_activos(): bool {
    return CADDY_TIENDA_HABILITADO && caddy_listo() && defined('CADDY_CREAR_ENVIOS') && CADDY_CREAR_ENVIOS === true;
}

// Petición HTTP cruda. Devuelve [código, cuerpo decodificado].
function caddy_http(string $ruta, array $body, ?string $token = null): array {
    $ch = curl_init(rtrim(CADDY_URL, '/') . '/' . ltrim($ruta, '/'));
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'X-Api-Token: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        // Sin User-Agent el firewall del hosting (mod_security) responde 406
        CURLOPT_USERAGENT      => 'MeFaltaUno/1.0 (+https://www.mefaltauno.com.ar)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new Exception('No se pudo conectar con Caddy: ' . $err);
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new Exception("Caddy respondió algo inesperado (HTTP $code).");
    return [$code, $json];
}

// El token de Caddy no vence: se guarda en ajustes y se renueva solo si la API lo rechaza.
function caddy_token(bool $renovar = false): string {
    if (!$renovar) {
        $t = (string)q("SELECT valor FROM ajustes WHERE clave='caddy_token'")->fetchColumn();
        if ($t !== '') return $t;
    }
    [, $r] = caddy_http('auth', ['usuario' => CADDY_USUARIO, 'password' => trim((string)CADDY_PASSWORD)]);
    $t = (string)($r['result']['token'] ?? '');
    if ($t === '') throw new Exception('Caddy no aceptó el usuario/contraseña: ' . ($r['result']['error_msg'] ?? 'sin detalle'));
    wepoint_ajuste_set('caddy_token', $t);
    return $t;
}

// Crea el envío en Caddy para un pedido (destino: el kiosco). Guarda el seguimiento o el error en el pedido.
function caddy_crear_envio(int $pedido_id): string {
    $p = q("SELECT p.*, c.nombre, c.apellido, c.email FROM pedidos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?", [$pedido_id])->fetch();
    if (!$p) throw new Exception('Pedido no encontrado.');
    if ($p['caddy_seguimiento']) return $p['caddy_seguimiento'];
    if (!caddy_envios_activos()) throw new Exception('La creación de envíos en Caddy está apagada (CADDY_CREAR_ENVIOS en config.php).');
    try {
        if (!$p['envio_cp']) throw new Exception('El punto de retiro “' . $p['envio_punto'] . '” no tiene código postal.');
        $box = defined('CADDY_BOX') ? CADDY_BOX : ['Length' => 20, 'Width' => 15, 'Height' => 10, 'Weight' => 1];
        $datos = [
            'NombreCompleto' => mb_substr($p['envio_punto'] . ' · retira ' . ($p['envio_nombre'] ?: nombre_cliente($p)), 0, 100),
            // La API toma la localidad de "Calle Número, Localidad, Provincia"
            'Direccion'      => implode(', ', array_filter([$p['envio_direccion'], $p['envio_localidad'], $p['envio_provincia']])),
            'Ciudad'         => (string)$p['envio_localidad'],
            'CodigoPostal'   => (string)$p['envio_cp'],
            'Telefono'       => (string)$p['envio_telefono'],
            'Mail'           => (string)$p['email'],
            'EnviarMail'     => false,
            'Cantidad'       => 1,
            'ValorDeclarado' => (string)round((float)$p['total']),
            'Cobranza'       => '0',
            'idProveedor'    => 'MFU-' . $pedido_id,
            'Observaciones'  => 'Pedido MeFaltaUno MFU-' . $pedido_id . ' · retiro en kiosco ' . $p['envio_punto'],
            'Box'            => [array_map('strval', $box)],
        ];
        [$code, $r] = caddy_http('servicios', $datos, caddy_token());
        if ($code === 401) [$code, $r] = caddy_http('servicios', $datos, caddy_token(true));
        $seg = (string)($r['result']['Codigo_Seguimiento'] ?? '');
        if ($seg === '') throw new Exception('Caddy no creó el envío: ' . ($r['result']['error_msg'] ?? json_encode($r['result'] ?? $r, JSON_UNESCAPED_UNICODE)));
        q("UPDATE pedidos SET caddy_seguimiento=?, caddy_error=NULL, caddy_creado_at=NOW() WHERE id=?", [$seg, $pedido_id]);
        registrar_historial('pedido', $pedido_id, $p['estado'], 'Envío creado en Caddy: ' . $seg, 'Caddy');
        return $seg;
    } catch (Exception $e) {
        q("UPDATE pedidos SET caddy_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 1000), $pedido_id]);
        throw $e;
    }
}

// ─── Avisos de Caddy (caddy-webhook.php) ────────────────────────────────────
// Caddy le avisa a MFU cada cambio del paquete (webhook del cliente MeFaltaUno en sistema.caddy: EndPoint + Token).
// MFU solo recibe información del servicio (código de seguimiento y estado) y es quien le escribe al cliente:
//   retirado, en tránsito… → en_camino ("En camino a tu kiosco")
//   Entregado              → enviado ("Llegó a tu kiosco", con el kiosco, quién lo atiende y el aviso del DNI)
//   devuelto, rechazado…   → no cambia el estado: queda en el historial y se avisa al operador.
//   En Origen, A Retirar…  → solo historial.
// El pedido se busca por el código de seguimiento o por la referencia MFU-<id> (idProveedor / no_referencia),
// así sirve tanto si el envío lo creó MFU como si lo creó WePoint con su integración: en ese caso el código se
// anota en el pedido con el primer aviso. El estado de la web nunca retrocede.

// Formato viejo de Caddy (texto plano): "<estado> <AAAA-MM-DDTHH:MMZ> <idProveedor>"
function caddy_aviso_desde_texto(string $raw): array {
    if (!preg_match('/^(.+?)\s+(\d{4}-\d{2}-\d{2}T[\d:]+Z?)\s+(\S+)$/u', trim($raw), $m)) return [];
    return ['estado' => $m[1], 'fecha' => $m[2], 'idProveedor' => $m[3]];
}

// Estados de Caddy (tabla Estados, slug entre paréntesis): En Origen / A Retirar (todavía no lo tiene) →
// solo historial; Retirado, Colectado, En Tránsito, Hoja de Ruta, Redespachado → en_camino; Entregado → enviado;
// No se pudo entregar / retirar, Devuelto, Rechazado → problema (avisa al operador, no cambia el estado).
function caddy_estado_web(string $estado, string $slug = ''): ?string {
    $e = mb_strtolower($estado);
    if (preg_match('/devuel|no entreg|no se pudo|rechaz|anulad|elimin|siniestr|extravi|cancel/u', $e)
        || in_array($slug, ['1st_visit_fail', 'returned_to_origin', 'declined'], true)) return 'problema';
    if (strpos($e, 'entregad') !== false || $slug === 'delivered') return 'enviado';
    if (preg_match('/retirad|colectad|tr[aá]nsito|camino|hoja de ruta|redespach/u', $e)
        || in_array($slug, ['pickup_ready', 'pickup_scanned', 'last_mile', 'redispatched'], true)) return 'en_camino';
    return null;
}

function caddy_procesar_aviso(array $d): array {
    $codigo = trim((string)($d['codigo_seguimiento'] ?? $d['CodigoSeguimiento'] ?? $d['idCaddy'] ?? ''));
    $ref    = trim((string)($d['idProveedor'] ?? $d['id_proveedor'] ?? $d['no_referencia'] ?? ''));
    $estado = trim((string)($d['estado'] ?? $d['state'] ?? $d['Estado'] ?? ''));
    $estimada = (string)($d['fecha_entrega'] ?? $d['fecha_estimada'] ?? $d['FechaEntrega'] ?? '');
    if ($estado === '') return ['ok' => 0, 'error' => 'El aviso no trae estado.'];

    $p = $codigo !== '' ? q("SELECT * FROM pedidos WHERE caddy_seguimiento=? LIMIT 1", [$codigo])->fetch() : null;
    if (!$p && preg_match('/^MFU-(\d+)$/i', $ref, $m)) $p = q("SELECT * FROM pedidos WHERE id=?", [(int)$m[1]])->fetch();
    if (!$p) return ['ok' => 0, 'error' => 'Pedido no encontrado (' . ($codigo ?: $ref ?: 'sin código') . ').'];
    $id = (int)$p['id'];
    if ($estado === (string)$p['caddy_estado'] && ($codigo === '' || $codigo === (string)$p['caddy_seguimiento'])) {
        return ['ok' => 1, 'pedido' => $id, 'accion' => 'repetido'];   // Caddy reintenta hasta recibir un 200
    }

    $seg = $p['caddy_seguimiento'] ?: ($codigo ?: null);
    q("UPDATE pedidos SET caddy_estado=?, caddy_estado_at=NOW(), caddy_seguimiento=? WHERE id=?", [mb_substr($estado, 0, 80), $seg, $id]);
    if (!$p['caddy_seguimiento'] && $codigo !== '') registrar_historial('pedido', $id, $p['estado'], 'Envío de Caddy: ' . $codigo, 'Caddy');

    $nuevo = caddy_estado_web($estado, (string)($d['slug'] ?? ''));
    $nota = 'Caddy: ' . $estado . ($nuevo !== 'enviado' && $estimada !== '' && strtotime($estimada) ? ' · llegada estimada ' . fecha($estimada, false) : '');
    $orden = ['confirmado' => 1, 'preparando' => 2, 'en_camino' => 3, 'enviado' => 4];

    if ($nuevo === 'problema') {
        registrar_historial('pedido', $id, $p['estado'], $nota, 'Caddy');
        mail_enviar(MAIL_OPERADOR, "Pedido #$id: Caddy informó \"$estado\"",
            '<p>Caddy informó <b>' . e($estado) . '</b> para el pedido #' . $id . ($seg ? ' (seguimiento ' . e($seg) . ')' : '')
            . '. El estado del pedido no cambió: revisalo.</p>' . mail_link("admin/pedido.php?id=$id", 'Ver en el panel'));
        return ['ok' => 1, 'pedido' => $id, 'accion' => 'problema'];
    }
    if ($nuevo && isset($orden[$p['estado']]) && $orden[$nuevo] > $orden[$p['estado']]) {
        pedido_cambiar_estado($id, $nuevo, $nota, false, 'Caddy');
        require_once __DIR__ . '/notificaciones.php';
        notificar_cambio_estado('pedido', $id, $nuevo, $nuevo === 'en_camino' && $estimada !== '' && strtotime($estimada)
            ? 'Llegada estimada a tu kiosco: ' . fecha($estimada, false) . '.' : null);
        return ['ok' => 1, 'pedido' => $id, 'accion' => $nuevo];
    }
    registrar_historial('pedido', $id, $p['estado'], $nota, 'Caddy');
    return ['ok' => 1, 'pedido' => $id, 'accion' => 'historial'];
}
