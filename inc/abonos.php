<?php
// Abonos: el coleccionista toca "La colecciono" en una colección vigente (en la calle o Próximamente) y cada
// número nuevo se le guarda (reserva de RESERVA_HORAS) apenas llega al depósito, sin pedirlo uno por uno.
// - Cubre del número siguiente al que va saliendo en adelante (desde_numero). Los anteriores se piden con "Conseguímelo".
// - Solo guarda lo que llega después de abonarse o reactivarlo (activo_desde): lo que ya está en stock se compra directo.
// - Puede sacar números puntuales (abono_excluidos); los que marca "Lo tengo" o ya compró no se le guardan.
// - Tiene prioridad sobre los pedidos sueltos de "Me falta" (reservas_asignar), por orden de abono.
// - Si deja vencer ABONO_VENCIDAS_PAUSA reservas seguidas, se pausa y se le avisa; comprar una lo pone en cero.
// No pasa por la lista de faltantes del operador: es stock que ya está previsto que entre.

const ABONO_VENCIDAS_PAUSA = 2;

function coleccion_vigente(array $col): bool {
    return !empty($col['activa']) && (!empty($col['en_calle']) || !empty($col['proximamente']));
}

function abono_de(?int $cliente_id, int $coleccion_id): ?array {
    if (!$cliente_id) return null;
    return q("SELECT * FROM abonos WHERE cliente_id=? AND coleccion_id=?", [$cliente_id, $coleccion_id])->fetch() ?: null;
}

/** Primer número que cubre un abono que empieza hoy: el siguiente al último que ya salió. */
function abono_desde(array $col): int {
    if (!empty($col['proximamente'])) return 1;
    $sal = salidas_resumen($col);
    if ($sal['va']) return $sal['va'] + 1;
    // Sin fechas cargadas: el siguiente al último número que alguna vez tuvo stock
    $ult = (int)q("SELECT COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=? AND (llegada_at IS NOT NULL OR stock>0 OR COALESCE(brihet_stock,0)>0)", [$col['id']])->fetchColumn();
    return $ult + 1;
}

function abonar(array $cli, array $col): array {
    if (!coleccion_vigente($col)) throw new Exception('Esta colección ya salió completa: pedí los números que te falten con “Conseguímelo”.');
    $ya = abono_de((int)$cli['id'], (int)$col['id']);
    if ($ya) {
        if ($ya['estado'] !== 'activo') q("UPDATE abonos SET estado='activo', vencidas_seguidas=0, activo_desde=NOW() WHERE id=?", [$ya['id']]);
    } else {
        q("INSERT INTO abonos (cliente_id, coleccion_id, desde_numero, activo_desde) VALUES (?,?,?, NOW())", [$cli['id'], $col['id'], abono_desde($col)]);
    }
    q("INSERT IGNORE INTO cliente_colecciones (cliente_id, coleccion_id) VALUES (?,?)", [$cli['id'], $col['id']]);
    return abono_de((int)$cli['id'], (int)$col['id']);
}

function desabonar(array $cli, int $coleccion_id): void {
    q("DELETE FROM abonos WHERE cliente_id=? AND coleccion_id=?", [$cli['id'], $coleccion_id]);
}

/** Saca (o vuelve a poner) un número del abono. */
function abono_numero(array $cli, int $item_id, bool $lo_quiere): void {
    if ($lo_quiere) q("DELETE FROM abono_excluidos WHERE cliente_id=? AND item_id=?", [$cli['id'], $item_id]);
    else q("INSERT IGNORE INTO abono_excluidos (cliente_id, item_id) VALUES (?,?)", [$cli['id'], $item_id]);
}

/** Números de la colección que el abono le va a guardar a este cliente: [item_id => true]. Excluidos: [item_id => true]. */
function abono_cubiertos(array $abono): array {
    $cub = $exc = [];
    foreach (q("SELECT i.id, (ae.item_id IS NOT NULL) AS excluido FROM items i
                LEFT JOIN abono_excluidos ae ON ae.item_id=i.id AND ae.cliente_id=?
                WHERE i.coleccion_id=? AND i.numero>=?", [$abono['cliente_id'], $abono['coleccion_id'], $abono['desde_numero']]) as $r) {
        if ($r['excluido']) $exc[(int)$r['id']] = true;
        else $cub[(int)$r['id']] = true;
    }
    return [$cub, $exc];
}

/** Guarda los números con stock libre a los abonados que les toca. Devuelve cuántas reservas creó. */
function abonos_asignar(): int {
    $candidatos = q("SELECT i.id AS item_id, a.id AS abono_id, a.cliente_id
                     FROM abonos a
                     JOIN colecciones c ON c.id=a.coleccion_id AND c.activa=1 AND c.proximamente=0
                     JOIN items i ON i.coleccion_id=a.coleccion_id AND i.numero>=a.desde_numero AND i.activo=1 AND i.congelado=0 AND i.stock>0
                                  AND i.llegada_at >= a.activo_desde
                     WHERE a.estado='activo'
                       AND NOT EXISTS (SELECT 1 FROM abono_excluidos ae WHERE ae.cliente_id=a.cliente_id AND ae.item_id=i.id)
                       AND NOT EXISTS (SELECT 1 FROM cliente_items ci WHERE ci.cliente_id=a.cliente_id AND ci.item_id=i.id)
                       AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.item_id=i.id AND r.cliente_id=a.cliente_id AND (r.abono_id=a.id OR r.estado='activa'))
                       AND NOT EXISTS (SELECT 1 FROM pedido_items pi JOIN pedidos p ON p.id=pi.pedido_id
                                       WHERE pi.item_id=i.id AND p.cliente_id=a.cliente_id AND p.estado<>'cancelado')
                     ORDER BY i.id, a.created_at, a.id")->fetchAll();
    $avisos = [];
    foreach ($candidatos as $c) {
        $it = q("SELECT id, stock, congelado, limite FROM items WHERE id=?", [$c['item_id']])->fetch();
        if (reservas_libres($it) < 1) continue;
        $rid = reserva_crear((int)$c['item_id'], (int)$c['cliente_id'], null, 'abono', 'Abono');
        q("UPDATE reservas SET abono_id=? WHERE id=?", [$c['abono_id'], $rid]);
        $avisos[(int)$c['cliente_id']][] = $rid;
    }
    foreach ($avisos as $cliente_id => $rids) abono_mail_reservas($cliente_id, $rids);
    return array_sum(array_map('count', $avisos));
}

// Un solo mail por cliente con todo lo que se le guardó en esta pasada
function abono_mail_reservas(int $cliente_id, array $rids): void {
    $cli = q("SELECT nombre, email FROM clientes WHERE id=?", [$cliente_id])->fetch();
    if (!$cli) return;
    $in = implode(',', array_map('intval', $rids));
    $filas = q("SELECT r.vence_at, i.numero, i.titulo, c.nombre AS coleccion, c.slug FROM reservas r
                JOIN items i ON i.id=r.item_id JOIN colecciones c ON c.id=i.coleccion_id WHERE r.id IN ($in) ORDER BY c.nombre, i.numero")->fetchAll();
    if (!$filas) return;
    $uno = count($filas) === 1;
    $lista = mail_tabla(array_map(fn($f) => ['<b>' . e($f['coleccion']) . ' ' . num((int)$f['numero']) . '</b><br><span style="color:#5f6f70">' . e($f['titulo']) . '</span>'], $filas));
    mail_enviar($cli['email'], $uno ? '¡Llegó el ' . num((int)$filas[0]['numero']) . ' de ' . $filas[0]['coleccion'] . '! Te lo guardamos' : '¡Llegaron números de tus colecciones! Te los guardamos',
        '<p>Hola ' . e($cli['nombre']) . ', como coleccionás ' . ($uno ? 'esta colección' : 'estas colecciones') . ', ' . ($uno ? 'te lo guardamos' : 'te los guardamos')
        . ' hasta el <b>' . e(fecha($filas[0]['vence_at'])) . '</b>. Pasado ese plazo ' . ($uno ? 'vuelve' : 'vuelven') . ' a la venta.</p>'
        . $lista . mail_link('coleccion.php?c=' . urlencode($filas[0]['slug']), $uno ? 'Comprarlo ahora' : 'Comprarlos ahora')
        . '<p style="color:#5f6f70;font-size:13px">¿No querés algún número? Destildalo en la colección y no te lo guardamos más.</p>');
}

// Una reserva de abono venció sin compra: suma una; al llegar al tope el abono se pausa y se le avisa
function abono_reserva_vencida(int $abono_id): void {
    q("UPDATE abonos SET vencidas_seguidas = vencidas_seguidas + 1 WHERE id=? AND estado='activo'", [$abono_id]);
    $a = q("SELECT a.*, c.nombre AS coleccion, c.slug, cl.nombre, cl.email FROM abonos a JOIN colecciones c ON c.id=a.coleccion_id
            JOIN clientes cl ON cl.id=a.cliente_id WHERE a.id=?", [$abono_id])->fetch();
    if (!$a || $a['estado'] !== 'activo' || (int)$a['vencidas_seguidas'] < ABONO_VENCIDAS_PAUSA) return;
    q("UPDATE abonos SET estado='pausado' WHERE id=?", [$abono_id]);
    mail_enviar($a['email'], 'Pausamos tu abono a ' . $a['coleccion'],
        '<p>Hola ' . e($a['nombre']) . ', te guardamos los últimos ' . ABONO_VENCIDAS_PAUSA . ' números de <b>' . e($a['coleccion'])
        . '</b> pero no llegaste a comprarlos, así que pausamos tu abono para no retener stock que otros coleccionistas están buscando.</p>'
        . '<p>Si querés seguir recibiéndolos, reactivalo con un toque:</p>' . mail_link('coleccion.php?c=' . urlencode($a['slug']), 'Reactivar mi abono'));
}

/** Para el panel: los abonados de la colección, con cuántos números sacaron y cuántas reservas tienen ahora. */
function abonos_de_coleccion(int $coleccion_id): array {
    return q("SELECT a.*, c.nombre, c.apellido, c.email,
                     (SELECT COUNT(*) FROM abono_excluidos ae JOIN items i ON i.id=ae.item_id WHERE ae.cliente_id=a.cliente_id AND i.coleccion_id=a.coleccion_id) AS excluidos,
                     (SELECT COUNT(*) FROM reservas r WHERE r.abono_id=a.id AND r.estado='activa' AND r.vence_at > NOW()) AS reservas
              FROM abonos a JOIN clientes c ON c.id=a.cliente_id WHERE a.coleccion_id=? ORDER BY a.estado, a.created_at", [$coleccion_id])->fetchAll();
}

/** Para el panel: abonados de la colección [activos, pausados]. */
function abonos_conteo(int $coleccion_id): array {
    $r = q("SELECT SUM(estado='activo') AS activos, SUM(estado='pausado') AS pausados FROM abonos WHERE coleccion_id=?", [$coleccion_id])->fetch();
    return [(int)$r['activos'], (int)$r['pausados']];
}

// SQL: el número todavía no salió (colección Próximamente, o en la calle y nunca llegó stock). Para marcar en
// "Me faltan" los pedidos que no hay que salir a buscar: se reservan solos cuando llegan.
const SQL_POR_SALIR = "(c.proximamente=1 OR (c.en_calle=1 AND i.llegada_at IS NULL AND i.stock<=0 AND COALESCE(i.brihet_stock,0)<=0))";
