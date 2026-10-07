<?php
// Códigos de descuento: por porcentaje o por importe fijo.

function cupon_buscar(string $codigo): ?array {
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '') return null;
    return q("SELECT * FROM cupones WHERE codigo=?", [$codigo])->fetch() ?: null;
}

// Valida un código para un cliente y un subtotal. Devuelve ['cupon'=>…, 'descuento'=>…] o lanza Exception.
function cupon_validar(string $codigo, ?array $cliente, float $subtotal): array {
    $c = cupon_buscar($codigo);
    if (!$c || !$c['activo']) throw new Exception('Ese código no existe o ya no está activo.');
    if ($c['vence'] && $c['vence'] < date('Y-m-d')) throw new Exception('Ese código venció el ' . fecha($c['vence'], false) . '.');
    if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) throw new Exception('Ese código ya alcanzó su límite de usos.');
    if ($c['minimo'] !== null && $subtotal < (float)$c['minimo']) throw new Exception('Ese código es para compras desde ' . precio((float)$c['minimo']) . '.');
    if ($c['cliente_email'] && (!$cliente || strcasecmp($cliente['email'], $c['cliente_email']) !== 0)) {
        throw new Exception($cliente ? 'Ese código es personal y no corresponde a tu cuenta.' : 'Ese código es personal: ingresá con tu cuenta para usarlo.');
    }
    if ($c['uno_por_cliente'] && $cliente) {
        $ya = q("SELECT 1 FROM pedidos WHERE cliente_id=? AND cupon_codigo=? AND estado<>'cancelado' LIMIT 1", [$cliente['id'], $c['codigo']])->fetchColumn();
        if ($ya) throw new Exception('Ya usaste ese código en otra compra.');
    }
    return ['cupon' => $c, 'descuento' => cupon_descuento($c, $subtotal)];
}

function cupon_descuento(array $c, float $subtotal): float {
    $d = $c['tipo'] === 'porcentaje' ? round($subtotal * (float)$c['valor'] / 100) : (float)$c['valor'];
    return max(0, min($d, $subtotal));
}

function cupon_etiqueta(array $c): string {
    return $c['tipo'] === 'porcentaje' ? rtrim(rtrim(number_format((float)$c['valor'], 2, ',', ''), '0'), ',') . '% off' : precio((float)$c['valor']) . ' off';
}
