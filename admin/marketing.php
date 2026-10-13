<?php
// Marketing: llegadas recientes (números que pasaron de 0 a tener stock) y campañas de mail.
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/inc/vistas.php';
require dirname(__DIR__) . '/inc/marketing.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_exigir();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['items'] ?? []))));
    if (!$ids) {
        flash('error', 'Tildá al menos un número para la campaña.');
        redirect('admin/marketing.php');
    }
    $audiencia = ($_POST['audiencia'] ?? '') === 'todos' ? 'todos' : 'interesados';
    $titulo = trim(mb_substr($_POST['titulo'] ?? '', 0, 160)) ?: '¡Llegaron!';
    q("INSERT INTO campanas (asunto, titulo, intro, item_ids, audiencia, lote, intervalo_min, creada_por) VALUES (?,?,?,?,?,?,?,?)", [
        trim(mb_substr($_POST['asunto'] ?? '', 0, 160)) ?: $titulo,
        $titulo,
        trim(mb_substr($_POST['intro'] ?? '', 0, 2000)) ?: null,
        implode(',', $ids),
        $audiencia,
        max(10, min(500, (int)($_POST['lote'] ?? CAMPANA_LOTE_DEFECTO))),
        max(1, min(240, (int)($_POST['intervalo'] ?? CAMPANA_INTERVALO_DEFECTO))),
        nombre_cliente($admin),
    ]);
    redirect('admin/campana.php?id=' . db()->lastInsertId());
}

// Campaña de una colección elegida a mano (ej. una Próximamente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_coleccion') {
    csrf_exigir();
    $col = q("SELECT * FROM colecciones WHERE id=? AND activa=1", [(int)($_POST['coleccion_id'] ?? 0)])->fetch();
    if (!$col) {
        flash('error', 'Elegí una colección.');
        redirect('admin/marketing.php');
    }
    $titulo = trim(mb_substr($_POST['titulo'] ?? '', 0, 160)) ?: $col['nombre'];
    q("INSERT INTO campanas (asunto, titulo, intro, item_ids, coleccion_id, audiencia, lote, intervalo_min, creada_por) VALUES (?,?,?,?,?,?,?,?,?)", [
        trim(mb_substr($_POST['asunto'] ?? '', 0, 160)) ?: ($col['proximamente'] ? 'Próximamente: ' . $col['nombre'] . ' 🚀' : $col['nombre']),
        $titulo,
        trim(mb_substr($_POST['intro'] ?? '', 0, 2000)) ?: null,
        implode(',', campana_items_coleccion((int)$col['id'])),
        (int)$col['id'],
        ($_POST['audiencia'] ?? '') === 'todos' ? 'todos' : 'interesados',
        max(10, min(500, (int)($_POST['lote'] ?? CAMPANA_LOTE_DEFECTO))),
        max(1, min(240, (int)($_POST['intervalo'] ?? CAMPANA_INTERVALO_DEFECTO))),
        nombre_cliente($admin),
    ]);
    redirect('admin/campana.php?id=' . db()->lastInsertId());
}

// Repetir una campaña de interesados para toda la tienda (segunda ola)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'segunda_ola') {
    csrf_exigir();
    $c = q("SELECT * FROM campanas WHERE id=?", [(int)$_POST['id']])->fetch();
    if ($c) {
        q("INSERT INTO campanas (asunto, titulo, intro, item_ids, coleccion_id, audiencia, lote, intervalo_min, creada_por) VALUES (?,?,?,?,?, 'todos', ?,?,?)",
          [$c['asunto'], $c['titulo'], $c['intro'], $c['item_ids'], $c['coleccion_id'], $c['lote'], $c['intervalo_min'], nombre_cliente($admin)]);
        redirect('admin/campana.php?id=' . db()->lastInsertId());
    }
    redirect('admin/marketing.php');
}

$dias = max(1, min(90, (int)($_GET['dias'] ?? 14)));
$llegadas = llegadas_recientes($dias);
$por_col = [];
foreach ($llegadas as $it) $por_col[$it['coleccion']][] = $it;
$campanas = q("SELECT ca.*, co.nombre AS col_nombre FROM campanas ca LEFT JOIN colecciones co ON co.id=ca.coleccion_id ORDER BY ca.id DESC LIMIT 30")->fetchAll();
$todas_cols = q("SELECT id, nombre, proximamente, lanzamiento FROM colecciones WHERE activa=1 ORDER BY proximamente DESC, nombre")->fetchAll();
$bajas = (int)q("SELECT COUNT(*) FROM mailing_bajas")->fetchColumn();
$ESTADOS = ['borrador' => ['Borrador', 'badge-gris'], 'enviando' => ['Enviando', 'badge-amarillo'], 'pausada' => ['Pausada', 'badge-gris'], 'terminada' => ['Enviada', 'badge-verde']];

admin_header('Marketing', 'marketing');
?>

<div class="section-head">
    <h1 style="margin:0">Marketing</h1>
    <span class="muted small"><?= $bajas ?> personas pidieron no recibir novedades (se excluyen siempre)</span>
</div>

<form method="post" class="card form" id="form-campana">
    <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <div class="encargue-head">
        <h3 style="margin:0">📦 Llegadas recientes</h3>
        <span class="small">Números que pasaron de no tener stock a tener (depósito o Brihet) en los últimos
            <select onchange="location='?dias='+this.value" style="width:auto;padding:4px 8px;font-size:.85rem">
                <?php foreach ([7, 14, 30, 60] as $d): ?><option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>><?= $d ?> días</option><?php endforeach; ?>
            </select></span>
    </div>
    <?php if (!$llegadas): ?>
        <p class="muted">No hubo llegadas en ese período. Cuando WePoint o Brihet informen stock de un número que no tenía, aparece acá.</p>
    <?php else: ?>
        <div class="llegadas">
            <?php foreach ($por_col as $nombre => $its): ?>
                <fieldset class="llegada-col">
                    <legend><label><input type="checkbox" class="sel-col"> <b><?= e($nombre) ?></b> <span class="muted small">(<?= count($its) ?>)</span></label></legend>
                    <div class="llegada-items">
                        <?php foreach ($its as $it): ?>
                            <label class="llegada-item">
                                <input type="checkbox" name="items[]" value="<?= (int)$it['id'] ?>">
                                <?= foto($it['imagen']) ?>
                                <span><b><?= num((int)$it['numero']) ?></b> <?= e(mb_strimwidth($it['titulo'], 0, 40, '…')) ?>
                                    <small><?= (int)$it['stock'] > 0 ? 'Depósito: ' . (int)$it['stock'] : 'Brihet: ' . (int)$it['brihet_stock'] ?> · llegó <?= e(fecha($it['llegada_at'])) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <h3 style="margin:18px 0 0">✉️ Armar la campaña</h3>
        <div class="form-row">
            <label class="campo">Título del mail<input type="text" name="titulo" maxlength="160" value="¡Llegaron!"></label>
            <label class="campo">Asunto <small>(lo que se ve en la bandeja)</small><input type="text" name="asunto" maxlength="160" placeholder="¡Llegaron los que estabas esperando! 🦸"></label>
        </div>
        <label class="campo">Texto de introducción<textarea name="intro" rows="3">Llegaron números nuevos de las colecciones que seguís. Son pocos: si te faltaba alguno, este es el momento.</textarea></label>
        <div class="form-row">
            <label class="opcion"><input type="radio" name="audiencia" value="interesados" checked>
                <div><strong>1ª ola · Interesados</strong><span>Suscriptos a esas colecciones, clientes que las tienen en Mis colecciones y quienes buscaban esos números.</span></div></label>
            <label class="opcion"><input type="radio" name="audiencia" value="todos">
                <div><strong>2ª ola · Toda la tienda</strong><span>Todos los suscriptos y clientes (sin repetir a los que ya la recibieron como interesados).</span></div></label>
        </div>
        <div class="form-row">
            <label class="campo">Mails por tanda<input type="number" name="lote" min="10" max="500" value="<?= CAMPANA_LOTE_DEFECTO ?>"></label>
            <label class="campo">Minutos entre tandas <small>(el hosting limita mails por hora)</small><input type="number" name="intervalo" min="1" max="240" value="<?= CAMPANA_INTERVALO_DEFECTO ?>"></label>
        </div>
        <button class="btn btn-primario" type="submit">Ver el mail antes de enviar →</button>
    <?php endif; ?>
</form>

<form method="post" class="card form" style="margin-top:16px">
    <?= csrf_field() ?><input type="hidden" name="accion" value="crear_coleccion">
    <h3 style="margin:0">🎯 Campaña de una colección</h3>
    <p class="muted small" style="margin:4px 0 12px">Elegí a mano cualquier colección (por ejemplo una <b>Próximamente</b>). El mail muestra la colección con su foto y descripción, y hasta 6 números de muestra.</p>
    <label class="campo">Colección
        <select name="coleccion_id" required>
            <option value="">Elegí…</option>
            <?php $grupo = null; foreach ($todas_cols as $tc): $g = $tc['proximamente'] ? '🚀 Próximamente' : 'Publicadas'; ?>
                <?php if ($g !== $grupo): ?><?= $grupo !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($g) ?>"><?php $grupo = $g; endif; ?>
                <option value="<?= (int)$tc['id'] ?>"><?= e($tc['nombre']) ?><?= $tc['proximamente'] && $tc['lanzamiento'] ? ' · ' . e($tc['lanzamiento']) : '' ?></option>
            <?php endforeach; ?><?= $grupo !== null ? '</optgroup>' : '' ?>
        </select>
    </label>
    <div class="form-row">
        <label class="campo">Título del mail <small>(vacío: el nombre de la colección)</small><input type="text" name="titulo" maxlength="160"></label>
        <label class="campo">Asunto <small>(vacío: se arma solo)</small><input type="text" name="asunto" maxlength="160" placeholder="Próximamente: … 🚀"></label>
    </div>
    <label class="campo">Texto de introducción<textarea name="intro" rows="3">Se viene una colección nueva y queremos que seas de los primeros en enterarte. Sumala a Mis colecciones y te avisamos apenas salga.</textarea></label>
    <div class="form-row">
        <label class="opcion"><input type="radio" name="audiencia" value="interesados">
            <div><strong>Interesados</strong><span>Suscriptos a la colección y clientes que la tienen en Mis colecciones.</span></div></label>
        <label class="opcion"><input type="radio" name="audiencia" value="todos" checked>
            <div><strong>Toda la tienda</strong><span>Todos los suscriptos y clientes.</span></div></label>
    </div>
    <div class="form-row">
        <label class="campo">Mails por tanda<input type="number" name="lote" min="10" max="500" value="<?= CAMPANA_LOTE_DEFECTO ?>"></label>
        <label class="campo">Minutos entre tandas<input type="number" name="intervalo" min="1" max="240" value="<?= CAMPANA_INTERVALO_DEFECTO ?>"></label>
    </div>
    <button class="btn btn-primario" type="submit">Ver el mail antes de enviar →</button>
</form>

<h3 style="margin-top:24px">Campañas</h3>
<div class="tabla-wrap">
    <table class="tabla">
        <tr><th>#</th><th>Asunto</th><th>Audiencia</th><th>Enviados</th><th>Estado</th><th>Creada</th><th></th></tr>
        <?php foreach ($campanas as $c): [$lbl, $cls] = $ESTADOS[$c['estado']] ?? [$c['estado'], 'badge-gris']; ?>
            <tr class="clic" onclick="location='<?= url('admin/campana.php?id=' . (int)$c['id']) ?>'">
                <td><?= (int)$c['id'] ?></td>
                <td><b><?= e($c['asunto']) ?></b><div class="small muted"><?= $c['col_nombre'] ? '🎯 ' . e($c['col_nombre']) : count(array_filter(explode(',', $c['item_ids']))) . ' números' ?></div></td>
                <td class="small"><?= $c['audiencia'] === 'todos' ? 'Toda la tienda' : 'Interesados' ?></td>
                <td><?= (int)$c['enviados'] ?><?= $c['total'] ? ' / ' . (int)$c['total'] : '' ?></td>
                <td><span class="badge <?= $cls ?>"><?= e($lbl) ?></span></td>
                <td class="small"><?= e(fecha($c['created_at'])) ?></td>
                <td onclick="event.stopPropagation()"><?php if ($c['audiencia'] === 'interesados' && $c['estado'] === 'terminada'): ?>
                    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="accion" value="segunda_ola"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-linea btn-chico" type="submit">2ª ola: toda la tienda</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$campanas): ?><tr><td colspan="7" class="muted">Todavía no hay campañas.</td></tr><?php endif; ?>
    </table>
</div>

<script>
// Tildar una colección tilda todos sus números
document.querySelectorAll('.sel-col').forEach(cb => cb.addEventListener('change', () =>
    cb.closest('fieldset').querySelectorAll('input[name="items[]"]').forEach(i => i.checked = cb.checked)));
</script>

<?php admin_footer(); ?>
