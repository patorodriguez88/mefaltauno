<?php
// Integración con la API de Caddy (envíos): cuando WePoint deja el pedido "Listo para enviar",
// se crea el envío en Caddy hacia el kiosco elegido y se guarda el código de seguimiento.
// Config (config.php): CADDY_URL (sandbox: https://api.caddy.com.ar/sandbox · producción: https://api.caddy.com.ar/api),
// CADDY_USUARIO / CADDY_PASSWORD (la cuenta de MeFaltaUno en plataforma.caddy.com.ar), CADDY_BOX (medidas por defecto)
// y el interruptor CADDY_CREAR_ENVIOS (apagado = nunca se crea nada en Caddy).

function caddy_listo(): bool {
    return defined('CADDY_URL') && CADDY_URL !== '' && defined('CADDY_USUARIO') && CADDY_USUARIO !== '';
}

function caddy_envios_activos(): bool {
    return caddy_listo() && defined('CADDY_CREAR_ENVIOS') && CADDY_CREAR_ENVIOS === true;
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
        CURLOPT_USERAGENT      => 'MeFaltaUno/1.0 (+https://web.mefaltauno.com.ar)',
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
