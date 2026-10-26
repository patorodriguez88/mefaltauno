<?php
// Salidas en kioscos. Una colección "en la calle" sale en kioscos con periodicidad (semanal, quincenal…);
// las que no están en la calle ya salieron: se consiguen, pero no sale nada nuevo.
// La fecha de cada número es la cargada a mano o, si no tiene, la del último número con fecha anterior
// (o la del Nº 1 de la colección) más la periodicidad. Así, corrigiendo un número, se corren los siguientes.

const PERIODICIDADES = ['semanal' => ['Semanal', 7], 'quincenal' => ['Quincenal', 14], 'mensual' => ['Mensual', 0]];

/** [numero => ['fecha' => 'Y-m-d', 'manual' => bool]] de la colección, del Nº 1 hasta el total (o el último cargado). */
function salidas_coleccion(array $col): array {
    $manual = [];
    foreach (q("SELECT numero, salida FROM items WHERE coleccion_id=? AND salida IS NOT NULL", [$col['id']])->fetchAll() as $r) {
        $manual[(int)$r['numero']] = $r['salida'];
    }
    $ultimo = (int)q("SELECT COALESCE(MAX(numero),0) FROM items WHERE coleccion_id=?", [$col['id']])->fetchColumn();
    $hasta = max(coleccion_total_salidas($col) ?? 0, $ultimo, $manual ? max(array_keys($manual)) : 0);
    $per = PERIODICIDADES[$col['periodicidad'] ?? ''] ?? null;
    $base = $col['salida_inicio'] ? [1, $col['salida_inicio']] : null;   // [número, fecha] desde donde se cuenta
    $out = [];
    for ($n = 1; $n <= $hasta; $n++) {
        if (isset($manual[$n])) {
            $base = [$n, $manual[$n]];
            $out[$n] = ['fecha' => $manual[$n], 'manual' => true];
        } elseif ($base && ($n === $base[0] || $per)) {
            $pasos = $n - $base[0];
            $f = new DateTime($base[1]);
            if ($pasos) $f->modify($per[1] ? '+' . ($pasos * $per[1]) . ' days' : "+$pasos months");
            $out[$n] = ['fecha' => $f->format('Y-m-d'), 'manual' => false];
        }
    }
    return $out;
}

// El total de la colección completa, si se conoce (coleccion_total de inc/vistas.php, sin depender de ese archivo)
function coleccion_total_salidas(array $col): ?int {
    if (function_exists('coleccion_total')) return coleccion_total($col);
    return !empty($col['total_entregas']) ? (int)$col['total_entregas'] : null;
}

/** Resumen para mostrar: por qué número va, cuál es el próximo y cuándo termina. */
function salidas_resumen(array $col, ?array $salidas = null): array {
    $salidas = $salidas ?? salidas_coleccion($col);
    $hoy = date('Y-m-d');
    $va = $prox = null;
    foreach ($salidas as $n => $s) {
        if ($s['fecha'] <= $hoy) $va = $n;
        elseif ($prox === null) $prox = $n;
    }
    $fin = $salidas ? array_key_last($salidas) : null;
    return [
        'va'        => $va,
        'proximo'   => $prox,
        'prox_fecha'=> $prox ? $salidas[$prox]['fecha'] : null,
        'fin'       => $fin,
        'fin_fecha' => $fin ? $salidas[$fin]['fecha'] : null,
        // Según las fechas ya salió el último número (solo si se sabe cuántos tiene la colección)
        'terminada' => $prox === null && $fin && ($t = coleccion_total_salidas($col)) && $fin >= $t,
    ];
}

function fecha_dia(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '';
}
