<?php
// Mejorar fotos desde Salvat: por cada número con foto chica (las de Brihet son de 400 px) se buscan candidatos en
// salvat.com y el operador elige cuál corresponde. Las fotos subidas a mano no aparecen (no se reemplazan).
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/inc/vistas.php';
require dirname(__DIR__) . '/inc/salvat.php';

$col = q("SELECT * FROM colecciones WHERE id=?", [(int)($_GET['coleccion'] ?? 0)])->fetch();
if (!$col) redirect('admin/colecciones.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) json_out(['ok' => false, 'error' => 'La sesión expiró: recargá la página.'], 419);
    try {
        $it = q("SELECT * FROM items WHERE id=? AND coleccion_id=?", [(int)($_POST['item_id'] ?? 0), $col['id']])->fetch();
        if (!$it) throw new Exception('Número no encontrado.');
        if (($_POST['accion'] ?? '') === 'buscar') {
            $texto = trim((string)($_POST['q'] ?? ''));
            if ($texto === '') {
                // Automático: por título y por "colección + número de edición", primero los del número exacto
                json_out(['ok' => true, 'resultados' => salvat_candidatos($it['titulo'], $col['nombre'], (int)$it['numero'])]);
            }
            json_out(['ok' => true, 'resultados' => salvat_ordenar(salvat_buscar($texto, 8, $col['nombre']), $col['nombre'], (int)$it['numero'], 4)]);
        }
        if (($_POST['accion'] ?? '') === 'aplicar') {
            $url = (string)($_POST['url'] ?? '');
            if (!preg_match('#^https://www\.salvat\.com/[a-z0-9/-]+-\d+$#', $url)) throw new Exception('Link de Salvat inválido.');
            $n = salvat_aplicar((int)$it['id'], $url);
            $nuevo = q("SELECT imagen FROM items WHERE id=?", [$it['id']])->fetchColumn();
            json_out(['ok' => true, 'fotos' => $n, 'imagen' => img($nuevo)]);
        }
        throw new Exception('Acción no válida.');
    } catch (Exception $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// Tamaño de la foto actual (para saber cuáles conviene mejorar)
function px_foto(?string $ruta): int {
    if (!$ruta || !str_starts_with($ruta, 'uploads/')) return 0;
    $info = @getimagesize(dirname(__DIR__) . '/' . $ruta);
    return $info ? max($info[0], $info[1]) : 0;
}

$todas = !empty($_GET['todas']);
$items = [];
$manuales = 0;
foreach (q("SELECT * FROM items WHERE coleccion_id=? ORDER BY numero", [$col['id']]) as $it) {
    if ($it['foto_origen'] === 'manual') { $manuales++; continue; }
    $it['px'] = px_foto($it['imagen']);
    if (!$todas && $it['foto_origen'] === 'salvat') continue;          // ya mejoradas
    if (!$todas && $it['px'] >= 700) continue;                          // ya tiene buena foto
    $items[] = $it;
}

admin_header('Mejorar fotos · ' . $col['nombre'], 'colecciones');
?>

<p><a href="<?= url('admin/coleccion.php?id=' . (int)$col['id']) ?>">← <?= e($col['nombre']) ?></a></p>
<div class="section-head">
    <h1 style="margin:0">Mejorar fotos desde Salvat</h1>
    <a href="?coleccion=<?= (int)$col['id'] ?><?= $todas ? '' : '&todas=1' ?>"><?= $todas ? 'Ver solo las que conviene mejorar' : 'Ver todos los números' ?></a>
</div>
<div class="aviso" style="margin-bottom:16px;background:var(--teal-50);color:var(--teal-900)">
    Para cada número buscamos en <b>salvat.com</b> el producto que más se parece. <b>Elegí el que corresponde</b> (o “Ninguno”) y tocá “Usar las elegidas”:
    se trae su foto grande como principal y, si tiene, hasta <?= FOTOS_EXTRA_MAX ?> más. Si el candidato no es el correcto, cambiá la búsqueda.
    <?php if ($manuales): ?><br><span class="small"><?= $manuales ?> números tienen foto subida a mano y no se tocan.</span><?php endif; ?>
</div>

<?php if (!$items): ?>
    <div class="card vacio"><h3>No hay fotos para mejorar</h3><p class="muted">Todos los números de esta colección ya tienen foto grande, de Salvat o subida a mano.</p></div>
<?php else: ?>
<div class="tabla-wrap">
    <table class="tabla tabla-salvat" id="tabla-salvat">
        <tr><th>N°</th><th>Foto actual</th><th>Candidatos en Salvat</th></tr>
        <?php foreach ($items as $it): ?>
            <tr data-id="<?= (int)$it['id'] ?>">
                <td><b><?= num((int)$it['numero']) ?></b><div class="small muted"><?= e(mb_strimwidth($it['titulo'], 0, 60, '…')) ?></div></td>
                <td class="foto-actual"><?= foto($it['imagen']) ?><div class="small muted"><?= $it['px'] ? $it['px'] . ' px' : 'sin foto' ?><?= $it['foto_origen'] ? ' · ' . e($it['foto_origen']) : '' ?></div></td>
                <td class="candidatos">
                    <form class="buscar-salvat"><input type="search" placeholder="<?= e(salvat_nucleo($col['nombre']) . ' ' . (int)$it['numero']) ?> · o un título" aria-label="Buscar en Salvat"><button class="btn-texto" type="submit">Buscar</button></form>
                    <div class="lista"><span class="muted small">Buscando…</span></div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<div style="display:flex;gap:12px;align-items:center;margin-top:16px;flex-wrap:wrap">
    <button class="btn btn-primario" type="button" id="btn-usar">Usar las elegidas</button>
    <span class="muted small" id="avance"></span>
</div>
<?php endif; ?>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
const post = async datos => (await fetch(location.href, { method: 'POST', body: new URLSearchParams({ csrf, ...datos }) })).json();
const escH = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

async function buscar(tr, q) {
    const lista = tr.querySelector('.lista');
    lista.innerHTML = '<span class="muted small">Buscando…</span>';
    try {
        const r = await post({ accion: 'buscar', item_id: tr.dataset.id, q: q || '' });
        if (!r.ok) throw new Error(r.error);
        const nombre = 'sel-' + tr.dataset.id;
        lista.innerHTML = r.resultados.map((c, k) => `
            <label class="cand"><input type="radio" name="${nombre}" value="${escH(c.url)}">
                <img src="${escH(c.mini)}" alt="" loading="lazy" referrerpolicy="no-referrer">
                <span>${c.numero_ok ? '<b class="num-ok">N° coincide</b> ' : ''}${escH(c.nombre)}<small>${escH(c.coleccion)}</small></span></label>`).join('')
            + `<label class="cand ninguno"><input type="radio" name="${nombre}" value="" checked><span>Ninguno</span></label>`
            + (r.resultados.length ? '' : '<span class="muted small">Sin resultados: probá con otras palabras.</span>');
    } catch (e) {
        lista.innerHTML = `<span class="small" style="color:var(--red)">${escH(e.message)}</span>`;
    }
}

const filas = [...document.querySelectorAll('#tabla-salvat tr[data-id]')];
filas.forEach(tr => tr.querySelector('.buscar-salvat').addEventListener('submit', ev => { ev.preventDefault(); buscar(tr, ev.target.querySelector('input').value); }));
// De a una para no cargar a Salvat
(async () => { for (const tr of filas) await buscar(tr); })();

document.getElementById('btn-usar')?.addEventListener('click', async ev => {
    const elegidas = filas.map(tr => [tr, tr.querySelector('input[type=radio]:checked')?.value]).filter(([, url]) => url);
    if (!elegidas.length) return toast('Elegí al menos un candidato.');
    ev.target.disabled = true;
    let k = 0;
    for (const [tr, url] of elegidas) {
        document.getElementById('avance').textContent = `Trayendo fotos ${++k} de ${elegidas.length}…`;
        try {
            const r = await post({ accion: 'aplicar', item_id: tr.dataset.id, url });
            if (!r.ok) throw new Error(r.error);
            tr.querySelector('.foto-actual').innerHTML = `<img src="${escH(r.imagen)}" alt=""><div class="small" style="color:var(--green)">✓ ${r.fotos} foto${r.fotos > 1 ? 's' : ''} de Salvat</div>`;
            tr.querySelector('.lista').innerHTML = '<span class="badge badge-verde">Listo</span>';
        } catch (e) {
            tr.querySelector('.lista').insertAdjacentHTML('afterbegin', `<div class="small" style="color:var(--red)">${escH(e.message)}</div>`);
        }
    }
    document.getElementById('avance').textContent = `Listo: ${elegidas.length} números actualizados.`;
    ev.target.disabled = false;
});
</script>

<?php admin_footer(); ?>
