<?php
// Una campaña: vista previa del mail tal cual llega, prueba al operador y envío por tandas con avance en pantalla.
require __DIR__ . '/_inc.php';
require_once dirname(__DIR__) . '/inc/vistas.php';
require dirname(__DIR__) . '/inc/marketing.php';

$id = (int)($_GET['id'] ?? 0);
$camp = q("SELECT * FROM campanas WHERE id=?", [$id])->fetch();
if (!$camp) redirect('admin/marketing.php');

// Vista previa (la usa el iframe)
if (isset($_GET['vista'])) {
    echo mail_layout($camp['titulo'], campana_html($camp, $admin['email'], nombre_cliente($admin)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acc = $_POST['accion'] ?? '';
    try {
        if ($acc === 'prueba') {
            campana_enviar_uno($camp, $admin['email'], nombre_cliente($admin));
            flash('ok', 'Te mandamos una prueba a ' . $admin['email'] . (MAIL_MODO === 'log' ? ' (en local queda en storage/mail.log)' : '') . '.');
        } elseif ($acc === 'editar' && $camp['estado'] === 'borrador') {
            q("UPDATE campanas SET asunto=?, titulo=?, intro=?, lote=?, intervalo_min=? WHERE id=?", [
                trim(mb_substr($_POST['asunto'] ?? '', 0, 160)) ?: $camp['asunto'], trim(mb_substr($_POST['titulo'] ?? '', 0, 160)) ?: $camp['titulo'],
                trim(mb_substr($_POST['intro'] ?? '', 0, 2000)) ?: null,
                max(10, min(500, (int)$_POST['lote'])), max(1, min(240, (int)$_POST['intervalo'])), $id]);
            flash('ok', 'Campaña actualizada.');
        } elseif ($acc === 'iniciar') {
            campana_iniciar($id);
            json_out(['ok' => true] + campana_estado($id, campana_procesar_lote($id, true)));
        } elseif ($acc === 'seguir') {
            json_out(['ok' => true] + campana_estado($id, campana_procesar_lote($id)));
        } elseif ($acc === 'pausar') {
            q("UPDATE campanas SET estado='pausada' WHERE id=? AND estado='enviando'", [$id]);
            flash('ok', 'Campaña en pausa. La retomás cuando quieras.');
        } elseif ($acc === 'borrar' && $camp['estado'] === 'borrador') {
            q("DELETE FROM campanas WHERE id=?", [$id]);
            flash('ok', 'Campaña borrada.');
            redirect('admin/marketing.php');
        }
    } catch (Exception $e) {
        if (in_array($acc, ['iniciar', 'seguir'], true)) json_out(['ok' => false, 'error' => $e->getMessage()]);
        flash('error', $e->getMessage());
    }
    redirect('admin/campana.php?id=' . $id);
}

function campana_estado(int $id, array $r): array {
    $c = $r['camp'];
    $tandas = $c['total'] ? (int)ceil($c['total'] / $c['lote']) : 0;
    $hechas = $c['lote'] ? (int)ceil(q("SELECT COUNT(*) FROM campana_destinos WHERE campana_id=? AND estado<>'pendiente'", [$id])->fetchColumn() / $c['lote']) : 0;
    $espera = $c['estado'] === 'enviando' && $c['ultimo_lote_at'] ? max(0, strtotime($c['ultimo_lote_at']) + $c['intervalo_min'] * 60 - time()) : 0;
    return ['estado' => $c['estado'], 'enviados' => (int)$c['enviados'], 'total' => (int)$c['total'], 'tanda' => $hechas, 'tandas' => $tandas, 'espera' => $espera];
}

$audiencia = $camp['estado'] === 'borrador' ? count(campana_audiencia($camp)) : (int)$camp['total'];
$est = campana_estado($id, ['camp' => $camp]);
$items = campana_items($camp);

admin_header('Campaña #' . $id, 'marketing');
?>

<p><a href="<?= url('admin/marketing.php') ?>">← Marketing</a></p>
<div class="section-head">
    <h1 style="margin:0"><?= e($camp['asunto']) ?></h1>
    <span class="badge <?= ['borrador' => 'badge-gris', 'enviando' => 'badge-amarillo', 'pausada' => 'badge-gris', 'terminada' => 'badge-verde'][$camp['estado']] ?? 'badge-gris' ?>"><?= e(['borrador' => 'Borrador', 'enviando' => 'Enviando', 'pausada' => 'Pausada', 'terminada' => 'Enviada'][$camp['estado']] ?? $camp['estado']) ?></span>
</div>

<div class="layout-2">
    <div class="card" style="padding:0;overflow:hidden">
        <div style="padding:12px 16px;border-bottom:1px solid var(--line)" class="small"><b>Así le llega:</b> <?= e($camp['asunto']) ?> · de MeFaltaUno</div>
        <iframe src="<?= url('admin/campana.php?id=' . $id . '&vista=1') ?>" title="Vista previa del mail" style="width:100%;height:760px;border:0;background:#f4f6f6"></iframe>
    </div>
    <aside>
        <div class="card">
            <h3>Envío</h3>
            <p style="margin:0 0 8px"><b><?= $audiencia ?></b> destinatarios · <?= $camp['audiencia'] === 'todos' ? 'toda la tienda' : ($camp['coleccion_id'] ? 'interesados en la colección' : 'interesados en estas colecciones') ?></p>
            <p class="muted small" style="margin:0 0 12px">De a <?= (int)$camp['lote'] ?> mails cada <?= (int)$camp['intervalo_min'] ?> min<?= $audiencia ? ' · ' . (int)ceil($audiencia / $camp['lote']) . ' tandas, unas ' . round(ceil($audiencia / $camp['lote']) * $camp['intervalo_min'] / 60, 1) . ' h en total' : '' ?>. Sigue solo con el cron aunque cierres esta ventana.</p>
            <div class="progreso" style="margin-bottom:6px"><span id="barra" style="width:<?= $est['total'] ? round($est['enviados'] * 100 / $est['total']) : 0 ?>%"></span></div>
            <div class="small" id="avance-camp"><?= $est['total'] ? "Tanda {$est['tanda']} de {$est['tandas']} · {$est['enviados']}/{$est['total']} enviados" : 'Todavía no se envió.' ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <?php if (in_array($camp['estado'], ['borrador', 'pausada', 'enviando'], true)): ?>
                    <button class="btn btn-primario" type="button" id="btn-enviar" <?= !$audiencia ? 'disabled' : '' ?>><?= $camp['estado'] === 'borrador' ? 'Empezar envío' : ($camp['estado'] === 'pausada' ? 'Retomar envío' : 'Ver avance') ?></button>
                <?php endif; ?>
                <?php if ($camp['estado'] === 'enviando'): ?>
                    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="accion" value="pausar"><button class="btn btn-linea" type="submit">Pausar</button></form>
                <?php endif; ?>
            </div>
            <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="accion" value="prueba"><button class="btn-texto" type="submit">✉️ Enviarme una prueba</button></form>
        </div>
        <?php if ($camp['estado'] === 'borrador'): ?>
            <form method="post" class="card form">
                <?= csrf_field() ?><input type="hidden" name="accion" value="editar">
                <h3>Editar</h3>
                <label class="campo">Asunto<input type="text" name="asunto" value="<?= e($camp['asunto']) ?>" maxlength="160"></label>
                <label class="campo">Título<input type="text" name="titulo" value="<?= e($camp['titulo']) ?>" maxlength="160"></label>
                <label class="campo">Introducción<textarea name="intro" rows="4"><?= e($camp['intro']) ?></textarea></label>
                <div class="form-row">
                    <label class="campo">Por tanda<input type="number" name="lote" value="<?= (int)$camp['lote'] ?>" min="10" max="500"></label>
                    <label class="campo">Minutos<input type="number" name="intervalo" value="<?= (int)$camp['intervalo_min'] ?>" min="1" max="240"></label>
                </div>
                <div style="display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap">
                    <button class="btn btn-teal btn-chico" type="submit">Guardar y ver</button>
                    <button class="btn-texto" type="submit" name="accion" value="borrar" style="color:var(--red)" onclick="return confirm('¿Borrar este borrador?')">Borrar</button>
                </div>
            </form>
        <?php endif; ?>
        <div class="card">
            <h3><?= $camp['coleccion_id'] ? 'Números de muestra' : 'Números' ?> (<?= count($items) ?>)</h3>
            <?php foreach ($items as $it): ?><div class="small"><?= e($it['coleccion']) ?> <?= num((int)$it['numero']) ?> · <?= e($it['titulo']) ?></div><?php endforeach; ?>
        </div>
    </aside>
</div>

<script>
const csrfC = <?= json_encode(csrf_token()) ?>;
const avance = document.getElementById('avance-camp'), barra = document.getElementById('barra'), btn = document.getElementById('btn-enviar');
let timer = null;
const mmss = s => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
async function paso(accion) {
    const r = await (await fetch(location.href, { method: 'POST', body: new URLSearchParams({ csrf: csrfC, accion }) })).json();
    if (!r.ok) { avance.textContent = 'Error: ' + r.error; return; }
    barra.style.width = (r.total ? Math.round(r.enviados * 100 / r.total) : 0) + '%';
    if (r.estado === 'terminada') { avance.innerHTML = `<b>¡Listo!</b> ${r.enviados}/${r.total} enviados.`; btn && btn.remove(); return; }
    if (r.estado !== 'enviando') { avance.textContent = `En pausa · ${r.enviados}/${r.total} enviados.`; return; }
    let resta = r.espera;
    clearInterval(timer);
    timer = setInterval(() => {
        avance.textContent = `Tanda ${r.tanda} de ${r.tandas} · ${r.enviados}/${r.total} enviados · próxima en ${mmss(Math.max(0, resta))}`;
        if (--resta < 0) { clearInterval(timer); paso('seguir'); }
    }, 1000);
}
btn?.addEventListener('click', () => {
    if (btn.textContent.includes('Empezar') && !confirm('¿Empezar a enviar la campaña a <?= $audiencia ?> destinatarios?')) return;
    btn.disabled = true;
    paso(btn.textContent.includes('Ver') ? 'seguir' : 'iniciar');
});
<?php if ($camp['estado'] === 'enviando'): ?>paso('seguir');<?php endif; ?>
</script>

<?php admin_footer(); ?>
