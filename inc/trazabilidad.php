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

const CAMPOS_SALIDA = ['salida' => 'Fecha de salida', 'salida_inicio' => 'Sale el N° 1', 'periodicidad' => 'Periodicidad', 'en_calle' => 'En la calle'];
const ORIGENES_LLEGADA = ['wepoint' => 'WePoint (depósito)', 'brihet' => 'Brihet (editor)', 'manual' => 'Carga a mano'];

/** Un valor de salidas_cambios para mostrar: fechas en dd/mm/aaaa, periodicidad con su nombre. */
function valor_salida(string $campo, ?string $v): string {
    if ($v === null || $v === '') return '—';
    if ($campo === 'periodicidad') return PERIODICIDADES[$v][0] ?? $v;
    if ($campo === 'en_calle') return $v === '1' ? 'sí' : 'no';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? fecha_dia($v) : $v;
}
