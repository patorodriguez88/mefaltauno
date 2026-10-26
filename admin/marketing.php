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
$plegar = count($por_col) > 4;   // con muchas colecciones arrancan cerradas para ver todas de un vistazo
?>

<div class="section-head">
    <h1 style="margin:0">Marketing</h1>
    <span class="muted small"><?= $bajas ?> personas pidieron no recibir novedades (se excluyen siempre)</span>
</div>

<nav class="adm-tabs" id="mk-tabs">
    <a href="#llegadas" data-tab="llegadas">📦 Llegadas recientes <span class="muted small">(<?= count($llegadas) ?>)</span></a>
    <a href="#coleccion" data-tab="coleccion">🎯 Campaña de una colección</a>
    <a href="#campanas" data-tab="campanas">✉️ Campañas enviadas <span class="muted small">(<?= count($campanas) ?>)</span></a>
</nav>

<section class="mk-panel" id="tab-llegadas">
<form method="post" class="form" id="form-campana">
    <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <div class="mk-armado">
        <div class="card mk-lista">
            <div class="encargue-head">
                <h3 style="margin:0">1. Tildá los números</h3>
                <span class="small">Pasaron de no tener stock a tener (depósito o Brihet) en los últimos
                    <select onchange="location='?dias='+this.value" style="width:auto;padding:4px 8px;font-size:.85rem">
                        <?php foreach ([7, 14, 30, 60] as $d): ?><option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>><?= $d ?> días</option><?php endforeach; ?>
                    </select></span>
            </div>
            <?php if (!$llegadas): ?>
                <p class="muted">No hubo llegadas en ese período. Cuando WePoint o Brihet informen stock de un número que no tenía, aparece acá.</p>
            <?php else: ?>
                <div class="mk-herramientas">
                    <input type="search" id="mk-buscar" placeholder="Buscar colección o número…" autocomplete="off">
                    <button type="button" class="btn-texto" data-todo="1">Tildar todo</button>
                    <button type="button" class="btn-texto" data-todo="0">Destildar</button>
                    <?php if ($plegar): ?><button type="button" class="btn-texto" id="mk-abrir">Abrir todas</button><?php endif; ?>
                </div>
                <div class="llegadas">
                    <?php foreach ($por_col as $nombre => $its): ?>
                        <details class="llegada-col" data-buscar="<?= e(mb_strtolower($nombre)) ?>" <?= $plegar ? '' : 'open' ?>>
                            <summary><input type="checkbox" class="sel-col" aria-label="Tildar todos los de <?= e($nombre) ?>">
                                <b><?= e($nombre) ?></b> <span class="muted small"><span class="col-cnt">0</span>/<?= count($its) ?></span></summary>
                            <div class="llegada-items">
                                <?php foreach ($its as $it): ?>
                                    <label class="llegada-item" data-buscar="<?= e(mb_strtolower($it['titulo'] . ' ' . $it['numero'])) ?>">
                                        <input type="checkbox" name="items[]" value="<?= (int)$it['id'] ?>">
                                        <?= foto($it['imagen']) ?>
                                        <span><b><?= num((int)$it['numero']) ?></b> <?= e(mb_strimwidth($it['titulo'], 0, 40, '…')) ?>
                                            <small><?= (int)$it['stock'] > 0 ? 'Depósito: ' . (int)$it['stock'] : 'Brihet: ' . (int)$it['brihet_stock'] ?> · llegó <?= e(fecha($it['llegada_at'])) ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
                <p class="muted small" id="mk-sin-resultados" hidden>Ninguna llegada coincide con la búsqueda.</p>
            <?php endif; ?>
        </div>

        <?php if ($llegadas): ?>
        <aside class="card mk-mail" id="mk-mail">
            <h3 style="margin:0">2. Armá el mail</h3>
            <p class="mk-contador"><b id="mk-total">0</b> números tildados</p>
            <label class="campo">Título del mail<input type="text" name="titulo" maxlength="160" value="¡Llegaron!"></label>
            <label class="campo">Asunto <small>(lo que se ve en la bandeja)</small><input type="text" name="asunto" maxlength="160" placeholder="¡Llegaron los que estabas esperando! 🦸"></label>
            <label class="campo">Texto de introducción<textarea name="intro" rows="3">Llegaron números nuevos de las colecciones que seguís. Son pocos: si te faltaba alguno, este es el momento.</textarea></label>
            <label class="opcion"><input type="radio" name="audiencia" value="interesados" checked>
                <div><strong>1ª ola · Interesados</strong><span>Suscriptos a esas colecciones, clientes que las tienen en Mis colecciones y quienes buscaban esos números.</span></div></label>
            <label class="opcion"><input type="radio" name="audiencia" value="todos">
                <div><strong>2ª ola · Toda la tienda</strong><span>Todos los suscriptos y clientes (sin repetir a los que ya la recibieron como interesados).</span></div></label>
            <details class="mk-avanzado">
                <summary class="small">Ritmo de envío: <?= CAMPANA_LOTE_DEFECTO ?> mails cada <?= CAMPANA_INTERVALO_DEFECTO ?> min</summary>
                <div class="form-row">
                    <label class="campo">Mails por tanda<input type="number" name="lote" min="10" max="500" value="<?= CAMPANA_LOTE_DEFECTO ?>"></label>
                    <label class="campo">Minutos entre tandas <small>(el hosting limita mails por hora)</small><input type="number" name="intervalo" min="1" max="240" value="<?= CAMPANA_INTERVALO_DEFECTO ?>"></label>
                </div>
            </details>
            <button class="btn btn-primario btn-bloque" type="submit" id="mk-enviar" disabled>Ver el mail antes de enviar →</button>
        </aside>
        <?php endif; ?>
    </div>

    <?php if ($llegadas): ?>
        <div class="mk-barra"><span><b id="mk-total-barra">0</b> tildados</span><a href="#mk-mail" class="btn btn-primario btn-chico">Armar el mail ↓</a></div>
    <?php endif; ?>
</form>
</section>

<section class="mk-panel" id="tab-coleccion" hidden>
<form method="post" class="card form" style="max-width:760px">
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
    <details class="mk-avanzado">
        <summary class="small">Ritmo de envío: <?= CAMPANA_LOTE_DEFECTO ?> mails cada <?= CAMPANA_INTERVALO_DEFECTO ?> min</summary>
        <div class="form-row">
            <label class="campo">Mails por tanda<input type="number" name="lote" min="10" max="500" value="<?= CAMPANA_LOTE_DEFECTO ?>"></label>
            <label class="campo">Minutos entre tandas<input type="number" name="intervalo" min="1" max="240" value="<?= CAMPANA_INTERVALO_DEFECTO ?>"></label>
        </div>
    </details>
    <button class="btn btn-primario" type="submit">Ver el mail antes de enviar →</button>
</form>
</section>

<section class="mk-panel" id="tab-campanas" hidden>
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
</section>

<script>
// Pestañas: la elegida queda en la dirección (#coleccion) para volver a ella
const mkTabs = document.querySelectorAll('#mk-tabs a');
function mkMostrar(tab) {
    if (!document.getElementById('tab-' + tab)) tab = 'llegadas';
    mkTabs.forEach(a => a.classList.toggle('activo', a.dataset.tab === tab));
    document.querySelectorAll('.mk-panel').forEach(p => p.hidden = p.id !== 'tab-' + tab);
}
mkTabs.forEach(a => a.addEventListener('click', ev => { ev.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tab); mkMostrar(a.dataset.tab); }));
mkMostrar(location.hash.slice(1));

// Llegadas: contadores, tildar por colección, buscar y botón habilitado solo con algo tildado
const mkItems = () => document.querySelectorAll('#form-campana input[name="items[]"]');
function mkContar() {
    let total = 0;
    document.querySelectorAll('#form-campana .llegada-col').forEach(col => {
        const its = col.querySelectorAll('input[name="items[]"]'), n = [...its].filter(i => i.checked).length;
        total += n;
        col.querySelector('.col-cnt').textContent = n;
        const sel = col.querySelector('.sel-col');
        sel.checked = n === its.length;
        sel.indeterminate = n > 0 && n < its.length;
    });
    ['mk-total', 'mk-total-barra'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = total; });
    const btn = document.getElementById('mk-enviar');
    if (btn) btn.disabled = !total;
}
document.querySelectorAll('.sel-col').forEach(cb => cb.addEventListener('change', () => {
    cb.closest('details').querySelectorAll('input[name="items[]"]').forEach(i => i.checked = cb.checked);
    mkContar();
}));
mkItems().forEach(i => i.addEventListener('change', mkContar));
document.querySelectorAll('[data-todo]').forEach(b => b.addEventListener('click', () => {
    // Tilda o destilda solo lo que se ve con la búsqueda actual
    document.querySelectorAll('#form-campana .llegada-item:not([hidden]) input').forEach(i => i.checked = b.dataset.todo === '1');
    mkContar();
}));
const mkAbrir = document.getElementById('mk-abrir');
if (mkAbrir) mkAbrir.addEventListener('click', () => {
    const abrir = mkAbrir.textContent === 'Abrir todas';
    document.querySelectorAll('#form-campana .llegada-col').forEach(d => d.open = abrir);
    mkAbrir.textContent = abrir ? 'Cerrar todas' : 'Abrir todas';
});
const mkBuscar = document.getElementById('mk-buscar');
if (mkBuscar) mkBuscar.addEventListener('input', () => {
    const t = mkBuscar.value.trim().toLowerCase();
    let alguna = false;
    document.querySelectorAll('#form-campana .llegada-col').forEach(col => {
        const enCol = !t || col.dataset.buscar.includes(t);
        let visibles = 0;
        col.querySelectorAll('.llegada-item').forEach(it => {
            it.hidden = !(enCol || it.dataset.buscar.includes(t));
            if (!it.hidden) visibles++;
        });
        col.hidden = !visibles;
        if (t && visibles) col.open = true;
        alguna = alguna || visibles > 0;
    });
    document.getElementById('mk-sin-resultados').hidden = alguna;
});
mkContar();
</script>

<?php admin_footer(); ?>
