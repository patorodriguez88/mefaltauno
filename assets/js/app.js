// MeFaltaUno — JS de la tienda (sin dependencias)

const MFU = {
    base: document.querySelector('meta[name=base]').content,
    csrf: document.querySelector('meta[name=csrf]').content,
};

function toggleMenu(btn) {
    const nav = document.getElementById('main-nav');
    const abierto = nav.classList.toggle('abierto');
    btn.setAttribute('aria-expanded', abierto ? 'true' : 'false');
}

let toastTimer;
function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('visible'), 2600);
}

async function api(accion, datos = {}) {
    const fd = new FormData();
    fd.append('accion', accion);
    for (const [k, v] of Object.entries(datos)) {
        if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
        else fd.append(k, v);
    }
    const res = await fetch(MFU.base + 'api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': MFU.csrf } });
    let data;
    try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Error de conexión. Probá de nuevo.' }; }
    if (data.login) {
        window.location = MFU.base + 'ingresar.php?volver=' + encodeURIComponent(location.pathname + location.search);
        throw new Error('login');
    }
    if (!data.ok) {
        toast(data.error || 'No se pudo completar la acción.');
        throw new Error(data.error);
    }
    if (typeof data.carrito === 'number') actualizarContadorCarrito(data.carrito);
    return data;
}

function actualizarContadorCarrito(n) {
    const el = document.getElementById('carrito-count');
    if (!el) return;
    el.textContent = n;
    el.hidden = !n;
}

async function agregarAlCarrito(itemId, btn) {
    if (btn) btn.disabled = true;
    try {
        const r = await api('carrito_agregar', { item_id: itemId });
        toast(r.mensaje || 'Agregado al carrito');
    } catch (e) { /* toast ya mostrado */ }
    if (btn) btn.disabled = false;
}

// ─── Página de colección ────────────────────────────────────────────────────
(function coleccion() {
    const grid = document.getElementById('grid-items');
    if (!grid) return;
    const logueado = grid.dataset.logueado === '1';
    const coleccionId = grid.dataset.coleccion;
    const barra = document.getElementById('barra-sel');
    const items = () => [...grid.querySelectorAll('.item')];

    // Filtros: todos / tengo / me faltan
    document.querySelectorAll('.filtro').forEach(f => f.addEventListener('click', () => {
        document.querySelectorAll('.filtro').forEach(x => x.classList.toggle('activo', x === f));
        const v = f.dataset.filtro;
        items().forEach(it => {
            const est = it.dataset.estado;
            const ver = v === 'todos' || (v === 'tengo' && est === 'tengo') || (v === 'faltan' && est !== 'tengo');
            it.classList.toggle('oculto', !ver);
        });
    }));

    function contar() {
        const all = items();
        const tengo = all.filter(i => i.dataset.estado === 'tengo').length;
        const total = all.length;
        const pct = total ? Math.round(tengo * 100 / total) : 0;
        const bar = document.getElementById('prog-bar');
        if (bar) bar.style.width = pct + '%';
        const txt = document.getElementById('prog-txt');
        if (txt) txt.innerHTML = `Tenés <strong>${tengo}</strong> de ${total}` + (total - tengo ? ` · te faltan <strong>${total - tengo}</strong>` : ' · ¡Colección completa! 🎉');
        document.querySelectorAll('[data-cnt]').forEach(el => {
            const k = el.dataset.cnt;
            el.textContent = k === 'todos' ? total : k === 'tengo' ? tengo : total - tengo;
        });
    }

    function pintar(it) {
        const est = it.dataset.estado;
        it.classList.toggle('es-tengo', est === 'tengo');
        it.classList.toggle('es-falta', est !== 'tengo');
        const sel = it.querySelector('.sel-toggle');
        if (sel) sel.hidden = est !== 'falta';
        if (est !== 'falta') {
            it.classList.remove('seleccionado');
            const cb = it.querySelector('.sel-toggle input');
            if (cb) cb.checked = false;
        }
    }

    // "Lo tengo"
    grid.addEventListener('change', async ev => {
        const it = ev.target.closest('.item');
        if (!it) return;
        if (ev.target.matches('.tengo-toggle input')) {
            const tengo = ev.target.checked;
            try {
                const r = await api('tengo', { item_id: it.dataset.id, tengo: tengo ? 1 : 0 });
                it.dataset.estado = r.estado;
                const chip = it.querySelector('.item-estado');
                if (chip) chip.innerHTML = r.chip || '';
                pintar(it);
                contar();
                actualizarBarra();
                const seguir = document.getElementById('btn-seguir');
                if (seguir && r.siguiendo) { seguir.dataset.siguiendo = '1'; seguir.textContent = '✓ En mis colecciones'; }
            } catch (e) {
                ev.target.checked = !tengo;
            }
        }
        if (ev.target.matches('.sel-toggle input')) {
            it.classList.toggle('seleccionado', ev.target.checked);
            actualizarBarra();
        }
    });

    function seleccionados() {
        return items().filter(i => i.classList.contains('seleccionado'));
    }

    function actualizarBarra() {
        if (!barra) return;
        const sel = seleccionados();
        const disp = sel.filter(i => +i.dataset.stock > 0);
        const sinStock = sel.filter(i => +i.dataset.stock <= 0);
        barra.classList.toggle('visible', sel.length > 0);
        document.body.classList.toggle('con-barra', sel.length > 0);
        const total = disp.reduce((a, i) => a + +i.dataset.precio, 0);
        barra.querySelector('.info').innerHTML =
            `<strong>${sel.length}</strong> seleccionado${sel.length === 1 ? '' : 's'}` +
            (disp.length ? ` · ${disp.length} disponible${disp.length === 1 ? '' : 's'} (${fmt(total)})` : '') +
            (sinStock.length ? ` · ${sinStock.length} para conseguir` : '');
        const bc = document.getElementById('btn-sel-carrito');
        const bs = document.getElementById('btn-sel-solicitar');
        bc.hidden = !disp.length;
        bc.textContent = `Agregar ${disp.length} al carrito`;
        bs.hidden = !sinStock.length;
        bs.textContent = `Conseguímel${sinStock.length === 1 ? 'o' : 'os'} (${sinStock.length})`;
    }

    window.seleccionarFaltantes = function () {
        const faltan = items().filter(i => i.dataset.estado === 'falta');
        const todos = faltan.every(i => i.classList.contains('seleccionado'));
        faltan.forEach(i => {
            i.classList.toggle('seleccionado', !todos);
            const cb = i.querySelector('.sel-toggle input');
            if (cb) cb.checked = !todos;
        });
        actualizarBarra();
        if (!faltan.length) toast('No te falta ninguno disponible para pedir 🙌');
    };

    window.limpiarSeleccion = function () {
        seleccionados().forEach(i => {
            i.classList.remove('seleccionado');
            const cb = i.querySelector('.sel-toggle input');
            if (cb) cb.checked = false;
        });
        actualizarBarra();
    };

    window.selAlCarrito = async function (btn) {
        const ids = seleccionados().filter(i => +i.dataset.stock > 0).map(i => i.dataset.id);
        btn.disabled = true;
        try {
            const r = await api('carrito_agregar_varios', { item_ids: ids });
            toast(r.mensaje);
            seleccionados().filter(i => +i.dataset.stock > 0).forEach(i => {
                i.classList.remove('seleccionado');
                i.querySelector('.sel-toggle input').checked = false;
            });
            actualizarBarra();
        } catch (e) { }
        btn.disabled = false;
    };

    window.selSolicitar = async function (btn) {
        const sel = seleccionados().filter(i => +i.dataset.stock <= 0);
        if (!sel.length) return;
        const lista = sel.map(i => {
            const img = i.querySelector('.item-img').style.backgroundImage.replace(/^url\(["']?|["']?\)$/g, '');
            return `<li>${img ? `<img src="${esc(img)}" alt="">` : ''}<b>${esc(i.querySelector('.item-num').textContent)}</b> ${esc(i.querySelector('.item-titulo').textContent)}</li>`;
        }).join('');
        const uno = sel.length === 1;
        const nota = await modal({
            icono: '🔎',
            titulo: uno ? '¿Te lo conseguimos?' : `¿Te conseguimos estos ${sel.length}?`,
            cuerpo: `<p style="margin:0">${uno ? 'No lo tenemos en stock, pero lo vamos a buscar' : 'No los tenemos en stock, pero los vamos a buscar'} y te avisamos por mail apenas haya novedades.</p><ul>${lista}</ul>`,
            texto: 'Comentario (opcional)',
            placeholder: 'Ej: lo necesito antes de fin de mes, me sirve usado…',
            ok: uno ? 'Pedir que me lo consigan' : 'Pedir que me los consigan',
        });
        if (nota === null) return;
        btn.disabled = true;
        try {
            const r = await api('solicitar', { item_ids: sel.map(i => i.dataset.id), mensaje: nota });
            sel.forEach(i => {
                i.dataset.estado = 'buscando';
                const chip = i.querySelector('.item-estado');
                if (chip) chip.innerHTML = r.chip || '';
                i.classList.remove('seleccionado');
                pintar(i);
            });
            contar();
            actualizarBarra();
            const ver = await modal({
                icono: '🙌',
                titulo: '¡Listo, lo vamos a buscar!',
                cuerpo: `<p style="margin:0">Tu pedido quedó <b>pendiente</b>. Te mandamos un mail con el detalle y te avisamos cuando ${uno ? 'lo consigamos' : 'los consigamos'}.</p>`,
                ok: 'Ver mis faltantes',
                cancelar: 'Seguir mirando',
            });
            if (ver !== null) location = MFU.base + 'cuenta.php?tab=faltantes';
        } catch (e) { }
        btn.disabled = false;
    };

    window.toggleSeguir = async function (btn) {
        const seguir = btn.dataset.siguiendo !== '1';
        try {
            await api('seguir', { coleccion_id: coleccionId, seguir: seguir ? 1 : 0 });
            btn.dataset.siguiendo = seguir ? '1' : '0';
            btn.textContent = seguir ? '✓ En mis colecciones' : '+ Sumar a mis colecciones';
            toast(seguir ? 'La sumamos a tus colecciones' : 'La quitamos de tus colecciones');
        } catch (e) { }
    };

    items().forEach(pintar);
    contar();
})();

function fmt(n) {
    return '$' + Math.round(n).toLocaleString('es-AR');
}

// Descripción larga: "Ver más"
document.querySelectorAll('[data-vermas]').forEach(btn => {
    const el = document.getElementById(btn.dataset.vermas);
    if (!el || el.scrollHeight <= el.clientHeight + 4) { btn.hidden = true; return; }
    btn.addEventListener('click', () => { el.classList.remove('recortada'); btn.hidden = true; });
});

// ─── Carrito ────────────────────────────────────────────────────────────────
async function cambiarCantidad(itemId, delta, actual) {
    try {
        await api('carrito_cantidad', { item_id: itemId, cantidad: actual + delta });
        location.reload();
    } catch (e) { }
}

// ─── Modal ──────────────────────────────────────────────────────────────────
// modal({titulo, cuerpo (HTML), icono, texto (label del campo), placeholder, ok, cancelar})
// Devuelve una promesa: el texto ingresado ('' si no hay campo) al aceptar, o null al cancelar.
function modal(o) {
    const dlg = document.getElementById('modal');
    const $ = id => document.getElementById(id);
    $('modal-titulo').textContent = o.titulo || '';
    $('modal-cuerpo').innerHTML = o.cuerpo || '';
    $('modal-icono').hidden = !o.icono;
    $('modal-icono').textContent = o.icono || '';
    $('modal-texto-wrap').hidden = !o.texto;
    $('modal-texto-label').textContent = o.texto || '';
    $('modal-texto').value = '';
    $('modal-texto').placeholder = o.placeholder || '';
    $('modal-ok').textContent = o.ok || 'Aceptar';
    $('modal-cancelar').textContent = o.cancelar || 'Cancelar';
    return new Promise(resolve => {
        dlg.addEventListener('close', () => {
            resolve(dlg.returnValue === 'ok' ? $('modal-texto').value.trim() : null);
        }, { once: true });
        dlg.returnValue = '';
        dlg.showModal();
        (o.texto ? $('modal-texto') : $('modal-ok')).focus();
    });
}

// Cerrar tocando afuera de la caja
document.getElementById('modal').addEventListener('click', ev => {
    if (ev.target.id === 'modal') ev.target.close('cancelar');
});

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
