// Mapas de puntos de retiro (Leaflet + OpenStreetMap). Lo usan el checkout y el admin.

const LEAFLET_TILES = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const LEAFLET_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
const CENTRO_AR = [-31.4201, -64.1888];  // Córdoba, si no hay otra referencia

function crearMapa(el, centro, zoom) {
    const mapa = L.map(el, { scrollWheelZoom: false }).setView(centro || CENTRO_AR, zoom || 12);
    L.tileLayer(LEAFLET_TILES, { maxZoom: 19, attribution: LEAFLET_ATTR }).addTo(mapa);
    return mapa;
}

// Dirección → {lat, lng, nombre} con Nominatim (OpenStreetMap), limitado a Argentina.
async function geocodificar(texto) {
    const u = 'https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ar&limit=1&accept-language=es&q=' + encodeURIComponent(texto);
    const r = await fetch(u, { headers: { 'Accept': 'application/json' } });
    if (!r.ok) throw new Error('No pudimos buscar la dirección. Probá de nuevo en unos segundos.');
    const d = await r.json();
    if (!d.length) return null;
    return { lat: +d[0].lat, lng: +d[0].lon, nombre: d[0].display_name };
}

function distanciaKm(a, b) {
    const R = 6371, rad = x => x * Math.PI / 180;
    const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

function fmtKm(km) {
    return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(km < 10 ? 1 : 0).replace('.', ',') + ' km';
}

function pinIcono(clase) {
    return L.divIcon({ className: 'pin ' + (clase || ''), html: '<span></span>', iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -28] });
}

// ─── Checkout: elegir punto de retiro ──────────────────────────────────────
(function selectorPunto() {
    const cont = document.getElementById('selector-punto');
    if (!cont) return;
    const puntos = JSON.parse(cont.dataset.puntos || '[]');
    const input = document.getElementById('punto-id');
    const lista = document.getElementById('puntos-lista');
    const elegido = document.getElementById('punto-elegido');
    const buscar = document.getElementById('buscar-dir');
    const msg = document.getElementById('buscar-msg');
    if (!puntos.length) return;

    const mapa = crearMapa('mapa-puntos');
    const marcadores = {};
    let yo = null;

    puntos.forEach(p => {
        marcadores[p.id] = L.marker([p.lat, p.lng], { icon: pinIcono() }).addTo(mapa)
            .bindPopup(`<b>${esc(p.nombre)}</b><br>${esc(p.direccion)}, ${esc(p.localidad)}${p.horario ? '<br><small>' + esc(p.horario) + '</small>' : ''}<br><button type="button" class="btn btn-primario btn-chico" style="margin-top:8px" onclick="elegirPunto(${p.id})">Retirar acá</button>`);
        marcadores[p.id].on('click', () => resaltar(p.id));
    });
    mapa.fitBounds(L.latLngBounds(puntos.map(p => [p.lat, p.lng])).pad(0.2), { maxZoom: 14 });

    function render(desde) {
        const orden = puntos.map(p => ({ ...p, km: desde ? distanciaKm(desde, p) : null }))
            .sort((a, b) => desde ? a.km - b.km : a.localidad.localeCompare(b.localidad));
        lista.innerHTML = orden.slice(0, desde ? 6 : 50).map(p => `
            <label class="punto ${+input.value === p.id ? 'activo' : ''}" data-id="${p.id}">
                <input type="radio" name="punto_radio" value="${p.id}" ${+input.value === p.id ? 'checked' : ''}>
                <div>
                    <strong>${esc(p.nombre)}</strong>
                    <span>${esc(p.direccion)}, ${esc(p.localidad)}</span>
                    ${p.horario ? `<span class="muted">${esc(p.horario)}</span>` : ''}
                </div>
                ${p.km !== null ? `<em>${fmtKm(p.km)}</em>` : ''}
            </label>`).join('');
        lista.querySelectorAll('input').forEach(r => r.addEventListener('change', () => elegirPunto(+r.value)));
        lista.hidden = false;
        if (desde) {
            const cerca = orden.slice(0, 3).map(p => [p.lat, p.lng]);
            mapa.fitBounds(L.latLngBounds([[desde.lat, desde.lng], ...cerca]).pad(0.25), { maxZoom: 16 });
        }
    }

    function resaltar(id) {
        Object.entries(marcadores).forEach(([k, m]) => m.setIcon(pinIcono(+k === id ? 'activo' : '')));
        lista.querySelectorAll('.punto').forEach(el => el.classList.toggle('activo', +el.dataset.id === id));
    }

    window.elegirPunto = function (id) {
        const p = puntos.find(x => x.id === id);
        if (!p) return;
        input.value = id;
        resaltar(id);
        const r = lista.querySelector(`input[value="${id}"]`);
        if (r) r.checked = true;
        elegido.innerHTML = `<b>Retirás en:</b> ${esc(p.nombre)} — ${esc(p.direccion)}, ${esc(p.localidad)}${p.horario ? ' · ' + esc(p.horario) : ''}`;
        elegido.hidden = false;
        mapa.closePopup();
        mapa.panTo([p.lat, p.lng]);
    };

    function ubicarme(pos, etiqueta) {
        yo = pos;
        if (window._yoMarker) mapa.removeLayer(window._yoMarker);
        window._yoMarker = L.marker([pos.lat, pos.lng], { icon: pinIcono('yo') }).addTo(mapa).bindTooltip(etiqueta || 'Tu dirección');
        render(pos);
        const masCerca = puntos.map(p => distanciaKm(pos, p)).sort((a, b) => a - b)[0];
        msg.textContent = `El punto más cercano está a ${fmtKm(masCerca)}.`;
    }

    async function buscarDireccion() {
        const q = buscar.value.trim();
        if (q.length < 4) { msg.textContent = 'Escribí una dirección, por ejemplo: Av. Colón 1200, Córdoba.'; return; }
        msg.textContent = 'Buscando…';
        try {
            const r = await geocodificar(q);
            if (!r) { msg.textContent = 'No encontramos esa dirección. Probá agregando la ciudad.'; return; }
            ubicarme(r, q);
        } catch (e) { msg.textContent = e.message; }
    }

    document.getElementById('btn-buscar-dir').addEventListener('click', buscarDireccion);
    buscar.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); buscarDireccion(); } });
    document.getElementById('btn-mi-ubicacion').addEventListener('click', () => {
        if (!navigator.geolocation) { msg.textContent = 'Tu navegador no permite usar la ubicación.'; return; }
        msg.textContent = 'Buscando tu ubicación…';
        navigator.geolocation.getCurrentPosition(
            p => ubicarme({ lat: p.coords.latitude, lng: p.coords.longitude }, 'Estás acá'),
            () => { msg.textContent = 'No pudimos acceder a tu ubicación. Escribí una dirección.'; },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });

    render(null);
    if (input.value) elegirPunto(+input.value);
    if (buscar.value.trim().length >= 4) buscarDireccion();
})();

// ─── Admin: ubicar un punto en el mapa ─────────────────────────────────────
(function editorPunto() {
    const el = document.getElementById('mapa-editor');
    if (!el) return;
    const lat = document.getElementById('p-lat');
    const lng = document.getElementById('p-lng');
    const msg = document.getElementById('ubicar-msg');
    const tiene = lat.value && lng.value;
    const mapa = crearMapa(el, tiene ? [+lat.value, +lng.value] : CENTRO_AR, tiene ? 16 : 12);
    mapa.scrollWheelZoom.enable();
    let pin = null;

    function poner(la, ln) {
        lat.value = (+la).toFixed(7);
        lng.value = (+ln).toFixed(7);
        if (!pin) {
            pin = L.marker([la, ln], { draggable: true, icon: pinIcono('activo') }).addTo(mapa);
            pin.on('dragend', () => { const p = pin.getLatLng(); poner(p.lat, p.lng); });
        } else {
            pin.setLatLng([la, ln]);
        }
        msg.textContent = 'Ubicado. Si no quedó justo, arrastrá el pin o tocá el lugar exacto en el mapa.';
    }
    if (tiene) poner(lat.value, lng.value);
    mapa.on('click', ev => poner(ev.latlng.lat, ev.latlng.lng));

    document.getElementById('btn-ubicar').addEventListener('click', async () => {
        const f = id => document.querySelector(`[name="${id}"]`).value.trim();
        const q = [f('direccion'), f('localidad'), f('provincia')].filter(Boolean).join(', ');
        if (!f('direccion') || !f('localidad')) { msg.textContent = 'Completá dirección y localidad.'; return; }
        msg.textContent = 'Buscando…';
        try {
            const r = await geocodificar(q);
            if (!r) { msg.textContent = 'No encontramos la dirección: tocá el lugar en el mapa para ubicarlo a mano.'; return; }
            poner(r.lat, r.lng);
            mapa.setView([r.lat, r.lng], 17);
        } catch (e) { msg.textContent = e.message; }
    });
})();
