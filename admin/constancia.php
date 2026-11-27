<?php
// Constancia de entrega: la hoja A4 que el cliente firma al retirar en el kiosco. El kiosquero se la devuelve a
// Dinter y con eso se le acredita el pedido. Sale del pedido (no hay que tipear nada) y se imprime o guarda como PDF.
require __DIR__ . '/_inc.php';

// Contacto de cobranzas de Dinter (el mismo de la nota que se usaba antes)
const CONSTANCIA_TEL  = '353-4209280';
const CONSTANCIA_MAIL = 'cobranza@dintersa.com.ar';

/** Código de barras Code 128-B en SVG (lo lee cualquier lector de kiosco). */
function code128_svg(string $txt, int $alto = 44): string {
    static $pat = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212',
        '223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323',
        '131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123',
        '113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113',
        '312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142',
        '121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341',
        '131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232'];
    $vals = [104];
    foreach (str_split($txt) as $ch) $vals[] = max(0, min(94, ord($ch) - 32));
    $chk = 104;
    foreach (array_slice($vals, 1) as $i => $v) $chk += ($i + 1) * $v;
    $vals[] = $chk % 103;
    $anchos = implode('', array_map(fn($v) => $pat[$v], $vals)) . '2331112';
    $x = 10; $rects = '';
    foreach (str_split($anchos) as $i => $w) {
        if ($i % 2 === 0) $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $alto . '"/>';
        $x += (int)$w;
    }
    $total = $x + 10;
    return '<svg class="barras" viewBox="0 0 ' . $total . ' ' . $alto . '" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . e($txt) . '">' . $rects . '</svg>';
}

$id = (int)($_GET['id'] ?? 0);
$p = q("SELECT p.*, c.nombre, c.apellido, c.email, c.telefono,
               pr.nombre AS punto_nombre, pr.responsable AS punto_responsable, pr.direccion AS punto_direccion,
               pr.localidad AS punto_localidad, pr.provincia AS punto_provincia, pr.horario AS punto_horario
        FROM pedidos p JOIN clientes c ON c.id=p.cliente_id LEFT JOIN puntos_retiro pr ON pr.id=p.punto_id
        WHERE p.id=?", [$id])->fetch();
if (!$p) redirect('admin/pedidos.php');
$lineas   = q("SELECT pi.*, i.imagen FROM pedido_items pi LEFT JOIN items i ON i.id=pi.item_id WHERE pi.pedido_id=? ORDER BY pi.coleccion, pi.numero", [$id])->fetchAll();
$unidades = (int)array_sum(array_column($lineas, 'cantidad'));
$hist     = historial('pedido', $id);

$ref     = 'MFU-' . $id;
$titular = nombre_cliente($p);
$retira  = trim((string)$p['envio_nombre']) ?: $titular;
$primer  = mb_convert_case(mb_strtolower(explode(' ', trim($p['nombre']))[0] ?: $titular), MB_CASE_TITLE);

// Kiosco: "Kiosco 0016" es el cliente 16 de Dinter
$kiosco = $p['punto_nombre'] ?: ($p['envio_punto'] ?: 'Punto de retiro');
$cliente_dinter = preg_match('/^Kiosco\s+0*(\d+)$/i', trim($kiosco), $m) ? $m[1] : null;
$kiosco_dir = $p['punto_direccion']
    ? trim($p['punto_direccion'] . ', ' . $p['punto_localidad'] . ($p['punto_provincia'] ? ', ' . $p['punto_provincia'] : ''))
    : trim($p['envio_direccion'] . ', ' . $p['envio_localidad'] . ' ' . $p['envio_provincia'], ' ,');

// Recorrido del pedido: cada paso con la primera fecha en que pasó por ahí
$pasos = ['pendiente' => 'Recibido', 'confirmado' => 'Pagado', 'preparando' => 'Preparado',
          'en_camino' => 'En camino', 'enviado' => 'En el kiosco', 'entregado' => 'Entregado'];
$cuando = [];
foreach ($hist as $h) if (!isset($cuando[$h['estado']])) $cuando[$h['estado']] = $h['created_at'];
$cuando['pendiente'] ??= $p['created_at'];
$orden_pasos = array_keys($pasos);
$idx_actual  = array_search($p['estado'], $orden_pasos, true);
$cancelado   = $p['estado'] === 'cancelado';

$pago = PAGO_METODOS[$p['pago_metodo']] ?? $p['pago_metodo'];
$legal = array_filter([ajuste('razon_social', 'Dinter S.A.'), ajuste('cuit') ? 'CUIT ' . ajuste('cuit') : '', ajuste('domicilio')]);
$estado_txt = ESTADOS_PEDIDO[$p['estado']][0] ?? $p['estado'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Constancia <?= e($ref) ?> · <?= e($titular) ?></title>
<link rel="icon" href="<?= asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800;900&family=Bangers&display=swap" rel="stylesheet">
<style>
    :root {
        --teal: #006368; --teal-900: #00393c; --teal-700: #004b4f; --teal-100: #e3f0f0; --teal-50: #f2f8f8;
        --yellow: #f1e52f; --ink: #1c2626; --muted: #5f6f70; --line: #dfe7e7; --green: #1f9d55; --red: #c53030;
    }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { margin: 0; }
    body { background: #d9e1e1; color: var(--ink); font: 400 10pt/1.45 'Inter', system-ui, sans-serif; }

    /* Barra de pantalla (no se imprime) */
    .barra { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: center;
             padding: 12px; background: var(--teal-900); color: #fff; font-size: 14px; }
    .barra a, .barra button { font: 600 14px 'Inter', sans-serif; border-radius: 999px; padding: 9px 18px; border: 0; cursor: pointer; text-decoration: none; }
    .barra a { color: #fff; background: rgba(255,255,255,.12); }
    .barra button { background: var(--yellow); color: var(--teal-900); }
    .barra span { opacity: .75; }

    .hoja { position: relative; width: 210mm; min-height: 297mm; margin: 24px auto; background: #fff; overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 57, 60, .25); display: flex; flex-direction: column; }

    /* Encabezado */
    .cabeza { position: relative; background: var(--teal-900); color: #fff; padding: 5.5mm 14mm 6mm; display: flex; justify-content: space-between; align-items: flex-start; gap: 10mm; overflow: hidden; }
    .cabeza::before { content: ''; position: absolute; right: -30mm; top: -38mm; width: 110mm; height: 110mm; border-radius: 50%;
                      background: radial-gradient(circle, rgba(241,229,47,.16) 0, rgba(241,229,47,0) 62%); }
    .cabeza::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2.2mm;
                     background: repeating-linear-gradient(-45deg, var(--yellow) 0 4mm, var(--teal-900) 4mm 8mm); }
    .logo { width: 46mm; display: block; position: relative; }
    .bajada { position: relative; margin-top: 2mm; font-size: 8.5pt; color: rgba(255,255,255,.72); letter-spacing: .02em; }
    .ident { position: relative; text-align: right; }
    .kicker { font-family: 'Bangers', 'Nunito', sans-serif; font-size: 13.5pt; letter-spacing: .12em; color: var(--yellow); line-height: 1; }
    .nro { font: 900 24pt/1 'Nunito', sans-serif; margin: .8mm 0 2mm; letter-spacing: -.01em; }
    .nro small { font-size: 12pt; font-weight: 800; opacity: .7; margin-right: 1mm; }
    .chip-barras { display: inline-block; background: #fff; border-radius: 2.5mm; padding: 2mm 3mm 1.2mm; }
    .barras { display: block; width: 46mm; height: 8.5mm; fill: var(--teal-900); }
    .chip-barras div { font: 600 7pt 'Inter', monospace; color: var(--teal-900); letter-spacing: .3em; text-align: center; margin-top: .6mm; }

    .cuerpo { padding: 5.5mm 14mm 0; flex: 1; display: flex; flex-direction: column; }

    /* Saludo + estado */
    .saludo { display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm; }
    .saludo h1 { font: 900 17pt/1.1 'Nunito', sans-serif; color: var(--teal-900); margin: 0 0 1.2mm; }
    .saludo h1 em { font-style: normal; background: linear-gradient(transparent 58%, var(--yellow) 58% 92%, transparent 92%); padding: 0 .5mm; }
    .saludo p { margin: 0; color: var(--muted); font-size: 9pt; }
    .estado { flex: none; text-align: right; font-size: 7.5pt; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
    .estado b { display: inline-block; margin-top: 1mm; font: 800 10pt 'Nunito', sans-serif; letter-spacing: 0; text-transform: none;
                color: var(--teal-900); background: var(--teal-100); border-radius: 999px; padding: 1.2mm 3.5mm; }

    /* Recorrido */
    .recorrido { display: grid; grid-template-columns: repeat(6, 1fr); margin: 3.5mm 0 3mm; position: relative; }
    .paso { text-align: center; position: relative; font-size: 7.5pt; color: var(--muted); }
    .paso::before { content: ''; position: absolute; top: 3.2mm; left: -50%; right: 50%; height: .7mm; background: var(--line); }
    .paso:first-child::before { display: none; }
    .paso.hecho::before { background: var(--teal); }
    .punto { position: relative; z-index: 1; width: 7mm; height: 7mm; margin: 0 auto 1.6mm; border-radius: 50%; background: #fff;
             border: .7mm solid var(--line); display: grid; place-items: center; font: 900 8pt 'Nunito', sans-serif; color: var(--line); }
    .hecho .punto { background: var(--teal); border-color: var(--teal); color: #fff; }
    .actual .punto { background: var(--yellow); border-color: var(--teal-900); color: var(--teal-900); box-shadow: 0 0 0 1.2mm rgba(241,229,47,.35); }
    .paso b { display: block; font-weight: 700; color: var(--ink); font-size: 8pt; }
    .paso:not(.hecho):not(.actual) b { color: var(--muted); font-weight: 500; }

    /* Fichas */
    .fichas { display: grid; grid-template-columns: 1fr 1.15fr 1fr; gap: 4mm; }
    .ficha { border: .3mm solid var(--line); border-radius: 3mm; padding: 2.8mm 4mm; background: var(--teal-50); }
    .ficha.kiosco { background: #fff; border: .5mm solid var(--teal); position: relative; }
    .ficha h3 { display: flex; align-items: center; gap: 1.6mm; margin: 0 0 2mm; font: 800 7.5pt 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .1em; color: var(--teal); }
    .ficha h3 svg { width: 4mm; height: 4mm; }
    .ficha .grande { font: 800 11.5pt/1.2 'Nunito', sans-serif; color: var(--teal-900); }
    .ficha .linea { font-size: 8.3pt; color: var(--muted); margin-top: .8mm; word-break: break-word; }
    .ficha .linea strong { color: var(--ink); font-weight: 600; }
    .dinter { position: absolute; top: -2.6mm; right: 3mm; background: var(--yellow); color: var(--teal-900); font: 800 7.5pt 'Inter', sans-serif;
              border-radius: 999px; padding: .8mm 2.6mm; letter-spacing: .02em; }

    /* Productos */
    .titulo-sec { display: flex; justify-content: space-between; align-items: baseline; margin: 3.5mm 0 1mm; }
    .titulo-sec h2 { margin: 0; font: 900 12.5pt 'Nunito', sans-serif; color: var(--teal-900); }
    .titulo-sec span { font-size: 8pt; color: var(--muted); }
    table { width: 100%; border-collapse: collapse; }
    thead th { font: 700 7pt 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); text-align: left;
               padding: 0 2mm 1.6mm; border-bottom: .5mm solid var(--teal-900); }
    tbody td { padding: .6mm 2mm; border-bottom: .3mm solid var(--line); vertical-align: middle; }
    tbody tr { break-inside: avoid; }
    .tapa { width: 7mm; height: 9.3mm; border-radius: 1mm; object-fit: cover; background: var(--teal-100); display: block; box-shadow: 0 .3mm 1mm rgba(0,0,0,.18); }
    .tapa.vacia { display: grid; place-items: center; font-size: 10pt; box-shadow: none; }
    .col { line-height: 1.2; font-size: 7pt; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
    .tit { font-weight: 600; line-height: 1.25; }
    .numero { display: inline-block; font: 900 9pt 'Nunito', sans-serif; background: var(--yellow); color: var(--teal-900); border-radius: 1.2mm; padding: .2mm 1.6mm; white-space: nowrap; }
    .der { text-align: right; white-space: nowrap; }
    .check { width: 4.5mm; height: 4.5mm; border: .4mm solid var(--teal); border-radius: 1mm; margin: 0 auto; }
    .totales { display: flex; justify-content: flex-end; gap: 8mm; padding: 2mm 2mm 0; font-size: 8.5pt; color: var(--muted); }
    .totales b { font: 900 12pt 'Nunito', sans-serif; color: var(--teal-900); margin-left: 2mm; }

    /* Conformidad */
    .conformidad { margin-top: 3.5mm; border: .5mm solid var(--teal-900); border-radius: 3.5mm; overflow: hidden; break-inside: avoid; }
    .conformidad-head { background: var(--teal-900); color: #fff; padding: 2mm 5mm; display: flex; justify-content: space-between; align-items: center; }
    .conformidad-head h2 { margin: 0; font: 900 11.5pt 'Nunito', sans-serif; }
    .conformidad-head span { font-size: 7.8pt; opacity: .8; }
    .conformidad p { margin: 2.5mm 5mm 0; font-size: 8.8pt; }
    .firmas { display: grid; grid-template-columns: 1.2fr 1fr; grid-template-rows: repeat(3, auto); gap: 4.2mm 7mm; padding: 4.5mm 5mm 3mm; }
    .firma { border-top: .35mm solid var(--ink); padding-top: 1.2mm; font-size: 7.3pt; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
    .firma.ancha { grid-row: 1 / span 3; display: flex; flex-direction: column; justify-content: flex-end; border-top: 0; padding-top: 0; }
    .firma.ancha .area { flex: 1; min-height: 12mm; border: .35mm dashed #b9c7c7; border-radius: 2mm; margin-bottom: 1.2mm; }

    /* Aviso al kiosco */
    .aviso { margin-top: 3.5mm; display: flex; gap: 4mm; align-items: center; background: var(--yellow); border-radius: 3.5mm; padding: 2.8mm 5mm; break-inside: avoid; }
    .aviso .ico { flex: none; width: 10mm; height: 10mm; border-radius: 50%; background: var(--teal-900); color: var(--yellow); display: grid; place-items: center; }
    .aviso .ico svg { width: 5.5mm; height: 5.5mm; }
    .aviso b { display: block; font: 900 10.5pt 'Nunito', sans-serif; color: var(--teal-900); }
    .aviso div { font-size: 8.4pt; color: var(--teal-900); }

    .aviso { margin-bottom: 5mm; }

    .pie { margin: auto -14mm 0; padding: 1.8mm 14mm; background: var(--teal-50); border-top: .3mm solid var(--line); display: flex; justify-content: space-between;
           font-size: 7pt; color: var(--muted); }
    .pie b { color: var(--teal); }

    /* Sello de estado final */
    .sello-estado { flex: none; margin: -2mm 2mm 0 0; transform: rotate(-8deg); border: .9mm solid currentColor; border-radius: 3mm;
                    padding: 1.3mm 4mm 1mm; font: 400 17pt/1 'Bangers', sans-serif; letter-spacing: .08em; text-align: center; opacity: .85; }
    .sello-estado small { display: block; font: 700 7pt 'Inter', sans-serif; letter-spacing: .15em; margin-top: .8mm; }
    .sello-estado.ok { color: var(--green); }
    .sello-estado.no { color: var(--red); }

    @page { size: A4; margin: 0; }
    @media print {
        body { background: #fff; }
        .barra { display: none; }
        .hoja { margin: 0; box-shadow: none; min-height: 296.5mm; }
    }

    /* Los kioscos la imprimen en blanco y negro: en papel va una versión monocromática, sin bloques llenos que
       gastan tóner y salen rayados, y sin amarillo (en gris queda lavado). En pantalla sigue en color. */
    @media print {
        :root { --teal: #000; --teal-900: #000; --teal-700: #000; --ink: #000; --muted: #444; --line: #999; --teal-50: #fff; --teal-100: #fff; --yellow: #fff; }
        .cabeza { background: #fff; color: #000; padding-bottom: 5mm; }
        .cabeza::before { display: none; }
        .cabeza::after { height: 1.6mm; background: repeating-linear-gradient(-45deg, #000 0 1.6mm, #fff 1.6mm 3.6mm); }
        .logo { filter: brightness(0); }
        .bajada { color: #444; }
        .kicker { color: #000; }
        .nro small { opacity: 1; color: #444; }
        .chip-barras { padding: 0; }
        .barras { fill: #000; }
        .saludo h1 em { background: none; border-bottom: .6mm solid #000; padding: 0; }
        .estado b { background: #fff; border: .35mm solid #000; }
        .paso::before, .punto { border-color: #999; }
        .hecho .punto { background: #000; border-color: #000; }
        .actual .punto { background: #fff; border: .7mm solid #000; color: #000; box-shadow: none; }
        .ficha { border-color: #999; }
        .ficha.kiosco { border: .5mm solid #000; }
        .dinter { background: #fff; color: #000; border: .35mm solid #000; }
        .tapa { box-shadow: none; border: .2mm solid #999; }
        .numero { background: #fff; border: .35mm solid #000; }
        .conformidad-head { background: #fff; color: #000; border-bottom: .5mm solid #000; }
        .conformidad-head span { opacity: 1; color: #444; }
        .firma.ancha .area { border-color: #777; }
        .aviso { background: #fff; border: .5mm solid #000; }
        .aviso .ico { background: #000; color: #fff; }
        .pie { background: #fff; border-top-color: #999; }
        .sello-estado.ok, .sello-estado.no { color: #000; }
    }
    @media screen and (max-width: 820px) {
        .hoja { transform-origin: top left; zoom: .5; }
    }
</style>
</head>
<body>

<div class="barra">
    <a href="<?= url('admin/pedido.php?id=' . $id) ?>">← Volver al pedido</a>
    <button type="button" onclick="window.print()">Imprimir o guardar PDF</button>
    <span>Hoja A4 · en papel sale en blanco y negro, lista para cualquier impresora</span>
</div>

<article class="hoja">
    <header class="cabeza">
        <div>
            <img class="logo" src="<?= asset('assets/img/logo.png') ?>" alt="MeFaltaUno">
            <div class="bajada">mefaltauno.com.ar</div>
        </div>
        <div class="ident">
            <div class="kicker">Constancia de entrega</div>
            <div class="nro"><small>Pedido N°</small><?= $id ?></div>
            <div class="chip-barras"><?= code128_svg($ref) ?><div><?= e($ref) ?></div></div>
        </div>
    </header>

    <div class="cuerpo">
        <section class="saludo">
            <div>
                <h1><?= e($primer) ?>, <em>¡ya no te falta!</em></h1>
                <p>Al retirarlo, revisá que esté completo y firmá esta constancia de entrega.</p>
            </div>
            <?php if ($p['estado'] === 'entregado'): ?>
                <div class="sello-estado ok">¡Misión cumplida!<small>Entregado el <?= e(fecha($cuando['entregado'] ?? $p['updated_at'], false)) ?></small></div>
            <?php elseif ($cancelado): ?>
                <div class="sello-estado no">Pedido anulado<small>No entregar</small></div>
            <?php else: ?>
                <div class="estado">Estado<br><b><?= e($estado_txt) ?></b></div>
            <?php endif; ?>
        </section>

        <?php if (!$cancelado): ?>
        <section class="recorrido" aria-label="Recorrido del pedido">
            <?php foreach ($orden_pasos as $i => $k):
                // El último paso alcanzado va resaltado en amarillo; "Entregado" ya es un paso cumplido
                $alcanzado = $idx_actual !== false && $i <= $idx_actual;
                $actual    = $idx_actual === $i && $k !== 'entregado';
                $tilde     = $alcanzado && !$actual; ?>
                <div class="paso <?= $alcanzado ? 'hecho' : '' ?> <?= $actual ? 'actual' : '' ?>">
                    <div class="punto"><?= $tilde ? '✓' : $i + 1 ?></div>
                    <b><?= e($pasos[$k]) ?></b>
                    <?= $alcanzado && isset($cuando[$k]) ? e(date('d/m H:i', strtotime($cuando[$k]))) : '&nbsp;' ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php else: ?><div style="height:6mm"></div><?php endif; ?>

        <section class="fichas">
            <div class="ficha">
                <h3><?= admin_icono('clientes') ?> Cliente</h3>
                <div class="grande"><?= e($titular) ?></div>
                <?php if (mb_strtolower($retira) !== mb_strtolower($titular)): ?><div class="linea">Retira: <strong><?= e($retira) ?></strong></div><?php endif; ?>
                <div class="linea"><?= e($p['email']) ?></div>
                <?php if ($p['envio_telefono'] ?: $p['telefono']): ?><div class="linea">Tel. <?= e($p['envio_telefono'] ?: $p['telefono']) ?></div><?php endif; ?>
            </div>
            <div class="ficha kiosco">
                <?php if ($cliente_dinter): ?><span class="dinter">Cliente Dinter N° <?= e($cliente_dinter) ?></span><?php endif; ?>
                <h3><?= admin_icono('puntos') ?> Lugar de retiro</h3>
                <div class="grande"><?= e($kiosco) ?></div>
                <?php if ($p['punto_responsable']): ?><div class="linea">Atiende: <strong><?= e($p['punto_responsable']) ?></strong></div><?php endif; ?>
                <div class="linea"><?= e($kiosco_dir) ?></div>
                <?php if ($p['punto_horario']): ?><div class="linea"><?= e($p['punto_horario']) ?></div><?php endif; ?>
            </div>
            <div class="ficha">
                <h3><?= admin_icono('pedidos') ?> Operación</h3>
                <div class="grande"><?= e($ref) ?></div>
                <div class="linea">Comprado el <strong><?= e(fecha($p['created_at'], false)) ?></strong></div>
                <div class="linea"><?= e($pago) ?><?= $p['mp_pago_id'] ? ' · op. ' . e($p['mp_pago_id']) : '' ?></div>
                <?php if ($p['caddy_seguimiento']): ?><div class="linea">Envío Caddy <strong><?= e($p['caddy_seguimiento']) ?></strong></div>
                <?php elseif ($p['wepoint_orden_id']): ?><div class="linea">Orden depósito <strong><?= e($p['wepoint_orden_id']) ?></strong></div><?php endif; ?>
            </div>
        </section>

        <div class="titulo-sec">
            <h2>Lo que retirás</h2>
            <span><?= $unidades ?> <?= $unidades === 1 ? 'unidad' : 'unidades' ?> · <?= count($lineas) ?> <?= count($lineas) === 1 ? 'número' : 'números' ?></span>
        </div>
        <table>
            <thead><tr><th style="width:13mm"></th><th>Colección y título</th><th>Número</th><th class="der">Cant.</th><th class="der">Importe</th><th style="width:12mm;text-align:center">Recibí</th></tr></thead>
            <tbody>
            <?php foreach ($lineas as $l): ?>
                <tr>
                    <td><?php if ($l['imagen']): ?><img class="tapa" src="<?= e(img($l['imagen'])) ?>" alt=""><?php else: ?><span class="tapa vacia">📚</span><?php endif; ?></td>
                    <td><div class="col"><?= e($l['coleccion']) ?></div><div class="tit"><?= e($l['titulo']) ?></div></td>
                    <td><span class="numero"><?= e(num((int)$l['numero'])) ?></span></td>
                    <td class="der"><b><?= (int)$l['cantidad'] ?></b></td>
                    <td class="der"><?= precio((float)$l['precio'] * (int)$l['cantidad']) ?></td>
                    <td><div class="check"></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="totales">
            <?php if ((float)$p['descuento'] > 0): ?><span>Descuento <?= e($p['cupon_codigo']) ?> −<?= precio((float)$p['descuento']) ?></span><?php endif; ?>
            <?php if ((float)$p['recargo'] > 0): ?><span>Recargo interior +<?= precio((float)$p['recargo']) ?></span><?php endif; ?>
            <span>Total abonado<b><?= precio((float)$p['total']) ?></b></span>
        </div>

        <section class="conformidad">
            <div class="conformidad-head"><h2>Conformidad de recepción</h2><span>Pedido <?= e($ref) ?> · <?= e($kiosco) ?></span></div>
            <p>Recibí el pedido detallado arriba, completo y en buen estado. Firmo como constancia de la entrega.</p>
            <div class="firmas">
                <div class="firma ancha"><div class="area"></div>Firma de quien retira</div>
                <div class="firma">Aclaración</div>
                <div class="firma">DNI</div>
                <div class="firma">Fecha de retiro</div>
            </div>
        </section>

        <div class="aviso">
            <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h13"/><path d="M12 7l5 5-5 5"/><path d="M20 4v16"/></svg></div>
            <div><b>Kiosquero: devolvé esta hoja firmada a Dinter</b>
                Con la constancia firmada te acreditamos el pedido<?= $cliente_dinter ? ' en tu cuenta de cliente N° ' . e($cliente_dinter) : '' ?>. Consultas: <strong><?= CONSTANCIA_TEL ?></strong> · <strong><?= CONSTANCIA_MAIL ?></strong></div>
        </div>

        <footer class="pie">
            <span><b>MeFaltaUno</b> · <?= e(implode(' · ', $legal)) ?></span>
            <span>Emitida el <?= date('d/m/Y H:i') ?> · <?= e($ref) ?></span>
        </footer>
    </div>
</article>
</body>
</html>
