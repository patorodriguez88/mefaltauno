<?php
// Trazabilidad de cada número, para no perder cuándo pasó qué aunque alguien cambie fechas a mano:
// - item_llegadas: cada vez que un número pasa de 0 a tener stock (WePoint, Brihet o carga a mano).
// - items.primera_llegada_at: la primera vez que entró al depósito; se anota una sola vez y nunca se pisa.
// - salidas_cambios: quién cambió una fecha de salida, la del N° 1, la periodicidad o "En la calle", y el valor
//   anterior. No se edita ni se borra desde el panel.

function registrar_llegada(int $item_id, string $origen, int $unidades): void {
    q("INSERT INTO item_llegadas (item_id, origen, unidades) VALUES (?,?,?)", [$item_id, $origen, $unidades]);
    // La primera llegada es la del depósito (WePoint o carga a mano); Brihet es stock del editor, no llegó acá
    if ($origen !== 'brihet') q("UPDATE items SET primera_llegada_at = COALESCE(primera_llegada_at, NOW()) WHERE id=?", [$item_id]);
}

function registrar_cambio_salida(int $coleccion_id, ?int $item_id, string $campo, ?string $antes, ?string $despues, string $usuario): void {
    if ((string)$antes === (string)$despues) return;
    q("INSERT INTO salidas_cambios (coleccion_id, item_id, campo, antes, despues, usuario) VALUES (?,?,?,?,?,?)",
      [$coleccion_id, $item_id, $campo, $antes !== '' ? $antes : null, $despues !== '' ? $despues : null, $usuario]);
}

// Movimientos del stock del depósito (stock_movimientos). Se llama después de cambiar items.stock, dentro de la
// misma transacción: el saldo es el stock que quedó. Extras: pedido_id, cliente_id, detalle, usuario.
const TIPOS_MOVIMIENTO = [
    'ingreso'     => 'Ingreso al depósito',
    'salida_wp'   => 'Baja informada por WePoint',
    'venta'       => 'Venta',
    'encargue'    => 'Venta por encargue',
    'cancelacion' => 'Pedido cancelado',
    'manual'      => 'Corrección a mano',
    'inicio'      => 'Inicio del historial',
];

function registrar_movimiento(int $item_id, string $tipo, int $cantidad, array $extra = []): void {
    if ($cantidad === 0 && $tipo !== 'encargue') return;
    q("INSERT INTO stock_movimientos (item_id, tipo, cantidad, saldo, pedido_id, cliente_id, detalle, usuario) VALUES (?,?,?,(SELECT stock FROM items WHERE id=?),?,?,?,?)",
      [$item_id, $tipo, $cantidad, $item_id, $extra['pedido_id'] ?? null, $extra['cliente_id'] ?? null,
       isset($extra['detalle']) ? mb_substr($extra['detalle'], 0, 200) : null, $extra['usuario'] ?? null]);
}

/** Movimientos de un número, del más viejo al más nuevo (los últimos $limite), listos para mostrar. */
function movimientos_item(int $item_id, int $limite = 300): array {
    $filas = q("SELECT * FROM (SELECT m.*, c.nombre, c.apellido FROM stock_movimientos m LEFT JOIN clientes c ON c.id=m.cliente_id
                WHERE m.item_id=? ORDER BY m.created_at DESC, m.id DESC LIMIT $limite) t ORDER BY created_at, id", [$item_id])->fetchAll();
    return array_map(fn($m) => [
        'cuando' => fecha($m['created_at']), 'tipo' => $m['tipo'], 'que' => TIPOS_MOVIMIENTO[$m['tipo']] ?? $m['tipo'],
        'cantidad' => (int)$m['cantidad'], 'saldo' => $m['saldo'] !== null ? (int)$m['saldo'] : null,
        'cliente' => $m['cliente_id'] ? nombre_cliente($m) : null, 'pedido' => $m['pedido_id'] ? (int)$m['pedido_id'] : null,
        'detalle' => $m['detalle'], 'usuario' => $m['usuario'], 'reconstruido' => (bool)$m['reconstruido'],
    ], $filas);
}

const CAMPOS_SALIDA =['salida' => 'Fecha de salida', 'salida_inicio' => 'Sale el N° 1', 'periodicidad' => 'Periodicidad', 'en_calle' => 'En la calle'];
const ORIGENES_LLEGADA = ['wepoint' => 'WePoint (depósito)', 'brihet' => 'Brihet (editor)', 'manual' => 'Carga a mano'];

/** Un valor de salidas_cambios para mostrar: fechas en dd/mm/aaaa, periodicidad con su nombre. */
function valor_salida(string $campo, ?string $v): string {
    if ($v === null || $v === '') return '—';
    if ($campo === 'periodicidad') return PERIODICIDADES[$v][0] ?? $v;
    if ($campo === 'en_calle') return $v === '1' ? 'sí' : 'no';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? fecha_dia($v) : $v;
}
