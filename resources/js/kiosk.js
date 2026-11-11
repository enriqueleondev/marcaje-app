// Kiosco Marcaje.
//
// Los lectores NFC y QR son teclados USB (HID): teclean el código y mandan Enter.
// La página escucha el teclado de forma global, sin ningún campo enfocado.
//
// Funciona primero con datos locales: el padrón (código → alumno) se guarda en
// IndexedDB para identificar al alumno al instante aunque no haya red. Cada lectura
// válida entra a una cola en IndexedDB y se envía al servidor en segundo plano.
// El servidor es la autoridad: vuelve a validar la credencial, aplica el
// anti-duplicado y no guarda dos veces la misma lectura (uuid).

const CFG = window.MARCAJE_KIOSK;
const FLASH_MS = 1500;
const SCAN_GAP_MS = 120; // un lector teclea mucho más rápido que una persona
const SYNC_EVERY_MS = 15_000;
const ROSTER_EVERY_MS = 5 * 60_000;
const REQUEST_TIMEOUT_MS = 4000;

const $ = (id) => document.getElementById(id);

// ── IndexedDB ─────────────────────────────────────────────
const db = await new Promise((resolve, reject) => {
    const req = indexedDB.open('marcaje-kiosk', 1);
    req.onupgradeneeded = () => {
        req.result.createObjectStore('kv');
        req.result.createObjectStore('queue', { keyPath: 'uuid' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
});

function tx(store, mode, fn) {
    return new Promise((resolve, reject) => {
        const t = db.transaction(store, mode);
        const result = fn(t.objectStore(store));
        t.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        t.onerror = () => reject(t.error);
    });
}
const kv = {
    get: (k) => tx('kv', 'readonly', (s) => s.get(k)),
    set: (k, v) => tx('kv', 'readwrite', (s) => s.put(v, k)),
    del: (k) => tx('kv', 'readwrite', (s) => s.delete(k)),
};
const queue = {
    add: (item) => tx('queue', 'readwrite', (s) => s.put(item)),
    all: () => tx('queue', 'readonly', (s) => s.getAll()),
    remove: (uuids) => tx('queue', 'readwrite', (s) => uuids.forEach((u) => s.delete(u))),
    count: () => tx('queue', 'readonly', (s) => s.count()),
};

// ── Estado ────────────────────────────────────────────────
const state = {
    token: null,
    timezone: 'America/Mexico_City',
    windowMin: 5,
    roster: { version: null, byValue: new Map(), students: new Map() },
    recent: new Map(), // studentId → { at: ms, time: 'HH:MM' } para el anti-duplicado local
    strip: [],
    today: 0,
    online: navigator.onLine,
    audio: null,
};

// ── API ───────────────────────────────────────────────────
class Unauthorized extends Error {}

async function api(path, { method = 'GET', body } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT_MS);
    try {
        const res = await fetch(CFG.api + path, {
            method,
            headers: { Authorization: `Bearer ${state.token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
            body: body ? JSON.stringify(body) : undefined,
            signal: ctrl.signal,
            cache: 'no-store',
        });
        if (res.status === 401) throw new Unauthorized();
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        setOnline(true);
        return await res.json();
    } catch (e) {
        if (!(e instanceof Unauthorized)) setOnline(false);
        throw e;
    } finally {
        clearTimeout(timer);
    }
}

async function handleUnauthorized() {
    state.token = null;
    await kv.del('token');
    await kv.del('roster');
    showPair('Este kiosco fue desvinculado desde el panel. Vincúlalo otra vez para seguir registrando entradas.');
}

// ── Vinculación ───────────────────────────────────────────
function showPair(message) {
    $('kiosk').hidden = true;
    $('start').hidden = true;
    $('pair').hidden = false;
    if (message) $('pair-message').textContent = message;
    setTimeout(() => $('pair-token').focus(), 50);
}

$('pair-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    await pair($('pair-token').value.trim());
});

async function pair(token) {
    const err = $('pair-error');
    err.hidden = true;
    if (!/^mk_[A-Za-z0-9]{40}$/.test(token)) {
        err.textContent = 'Ese código no tiene el formato correcto. Revisa que esté completo.';
        err.hidden = false;
        return false;
    }
    state.token = token;
    try {
        await api('/estado');
    } catch (e) {
        state.token = null;
        err.textContent = e instanceof Unauthorized
            ? 'El código no es válido o ya se usó otro más nuevo. Genera uno en el panel.'
            : 'No hay conexión con el servidor. Revisa la red e intenta de nuevo.';
        err.hidden = false;
        return false;
    }
    await kv.set('token', token);
    $('pair').hidden = true;
    showStart();
    return true;
}

// ── Inicio (toque para sonido, pantalla completa y pantalla encendida) ──
function showStart() {
    $('start').hidden = false;
    $('start-button').focus();
}

$('start-button').addEventListener('click', async () => {
    try {
        state.audio ??= new (window.AudioContext || window.webkitAudioContext)();
        await state.audio.resume();
    } catch {}
    try { await document.documentElement.requestFullscreen?.(); } catch {}
    keepAwake();
    $('start').hidden = true;
    $('kiosk').hidden = false;
    await boot();
});

let wakeLock = null;
async function keepAwake() {
    try { wakeLock = await navigator.wakeLock?.request('screen'); } catch {}
}
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !$('kiosk').hidden) keepAwake();
});

// ── Arranque ──────────────────────────────────────────────
async function boot() {
    const cached = await kv.get('roster');
    if (cached) applyRoster(cached);
    state.recent = new Map(Object.entries((await kv.get('recent')) ?? {}).map(([k, v]) => [Number(k), v]));
    state.strip = (await kv.get('strip')) ?? [];
    renderStrip();
    await refreshState();
    await refreshRoster();
    await updateQueueCount();
    sync();
    setInterval(sync, SYNC_EVERY_MS);
    setInterval(refreshRoster, ROSTER_EVERY_MS);
    setInterval(tick, 1000);
    tick();
}

async function refreshState() {
    try {
        const s = await api('/estado');
        $('org-name').textContent = s.institution ?? 'Marcaje';
        $('kiosk-name').textContent = [s.kiosk.name, s.kiosk.location].filter(Boolean).join(', ');
        state.timezone = s.timezone;
        state.windowMin = s.duplicate_window_minutes;
        setToday(s.today_count);
        if (!state.strip.some((x) => x.pending)) {
            state.strip = s.recent.map((r) => ({ name: shortName(r.student), group: r.student.g, method: r.method, time: r.time }));
            saveStrip();
        }
    } catch (e) {
        if (e instanceof Unauthorized) return handleUnauthorized();
    }
}

async function refreshRoster() {
    try {
        const r = await api(`/padron?version=${encodeURIComponent(state.roster.version ?? '')}`);
        if (!r.unchanged) {
            applyRoster(r);
            await kv.set('roster', r);
        }
    } catch (e) {
        if (e instanceof Unauthorized) return handleUnauthorized();
    }
}

function applyRoster(r) {
    state.roster.version = r.version;
    state.roster.students = new Map(r.students.map((s) => [s.id, s]));
    state.roster.byValue = new Map(r.credentials.map((c) => [c.v, c]));
}

// ── Lectura global del teclado ───────────────────────────
let buffer = '';
let lastKeyAt = 0;

document.addEventListener('keydown', (e) => {
    if ($('kiosk').hidden || !$('pair').hidden) return; // en la vinculación sí se escribe normal
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    const now = performance.now();
    if (now - lastKeyAt > SCAN_GAP_MS) buffer = '';
    lastKeyAt = now;

    if (e.key === 'Enter') {
        e.preventDefault();
        const code = buffer;
        buffer = '';
        if (code.length >= 4) handleScan(code);
    } else if (e.key.length === 1) {
        e.preventDefault();
        buffer += e.key;
    }
}, true);

const normalize = (raw) => raw.toUpperCase().replace(/[^A-Z0-9]/g, '');
const shortName = (s) => `${s.f} ${s.p}`;

async function handleScan(raw) {
    const code = normalize(raw);
    if (!code) return;
    const now = Date.now();
    const scan = { uuid: crypto.randomUUID(), code, scanned_at: new Date(now).toISOString() };
    const local = state.roster.byValue.get(code);
    const student = local && state.roster.students.get(local.s);

    if (student) {
        const prev = state.recent.get(student.id);
        if (prev && now - prev.at < state.windowMin * 60_000) {
            flash('dup', { student, method: local.t.toUpperCase(), time: prev.time });
            return;
        }
        const time = formatTime(now, true);
        state.recent.set(student.id, { at: now, time: time.slice(0, 5) });
        saveRecent();
        flash('ok', { student, method: local.t.toUpperCase(), time });
        pushStrip({ name: shortName(student), group: student.g, method: local.t.toUpperCase(), time: time.slice(0, 5), pending: true, uuid: scan.uuid });
        setToday(state.today + 1);
        await queue.add(scan);
        await updateQueueCount();
        sync();
        return;
    }

    // No está en el padrón local (p. ej. NFC recién vinculado): preguntar al servidor.
    await queue.add(scan);
    await updateQueueCount();
    const result = await sync(scan.uuid);
    if (!result) {
        flash('pending', {});
        return;
    }
    if (result.status === 'ok') {
        state.recent.set(result.student.id, { at: now, time: result.time.slice(0, 5) });
        saveRecent();
        flash('ok', { student: result.student, method: result.method, time: result.time });
        pushStrip({ name: shortName(result.student), group: result.student.g, method: result.method, time: result.time.slice(0, 5) });
        refreshRoster();
    } else if (result.status === 'duplicate') {
        flash('dup', { student: result.student, method: result.method, time: result.previous_time });
    } else {
        flash('unknown', {});
    }
}

// ── Sincronización de la cola ─────────────────────────────
let syncing = null;

/** Envía la cola. Si se pasa un uuid, devuelve el resultado de esa lectura (o null si no se pudo). */
async function sync(waitFor) {
    if (!state.token) return null;
    if (syncing) {
        await syncing.catch(() => {});
        if (!waitFor) return null;
    }
    let found = null;
    syncing = (async () => {
        const items = (await queue.all()).slice(0, 200);
        if (!items.length) return;
        const res = await api('/lecturas', { method: 'POST', body: { scans: items } });
        const done = res.results.map((r) => r.uuid);
        await queue.remove(done);
        if (waitFor) found = res.results.find((r) => r.uuid === waitFor) ?? null;
        // El servidor no registró estas (duplicado desde otro kiosco o credencial revocada).
        dropFromStrip(res.results.filter((r) => r.status !== 'ok').map((r) => r.uuid));
        markSent(done);
        setToday(res.today_count);
    })();
    try {
        await syncing;
    } catch (e) {
        if (e instanceof Unauthorized) await handleUnauthorized();
    } finally {
        syncing = null;
        const left = await updateQueueCount();
        // Quedaron lecturas (llegaron mientras se enviaba): otro envío en un momento.
        if (left > 0 && state.online && !waitFor) setTimeout(sync, 1000);
    }
    return found;
}

window.addEventListener('online', () => { setOnline(true); sync(); refreshRoster(); });
window.addEventListener('offline', () => setOnline(false));

// ── Pantalla ──────────────────────────────────────────────
function setOnline(on) {
    state.online = on;
    $('net').innerHTML = on ? '<i class="sq on"></i>En línea' : '<i class="sq off"></i>Sin conexión';
    $('net').classList.toggle('warn', !on);
}

async function updateQueueCount() {
    const n = await queue.count();
    $('queue').textContent = n === 0 ? 'Sin pendientes' : n === 1 ? '1 entrada por enviar' : `${n} entradas por enviar`;
    $('queue').classList.toggle('warn', n > 0);
    return n;
}

function setToday(n) {
    state.today = n;
    $('today').textContent = n === 1 ? '1 entrada hoy' : `${n} entradas hoy`;
}

function pushStrip(item) {
    state.strip = [item, ...state.strip].slice(0, 5);
    saveStrip();
    renderStrip();
}

function dropFromStrip(uuids) {
    if (!uuids.length) return;
    const before = state.strip.length;
    state.strip = state.strip.filter((x) => !uuids.includes(x.uuid));
    if (state.strip.length !== before) { saveStrip(); renderStrip(); }
}

function markSent(uuids) {
    let changed = false;
    for (const item of state.strip) {
        if (item.pending && uuids.includes(item.uuid)) { item.pending = false; changed = true; }
    }
    if (changed) { saveStrip(); renderStrip(); }
}

function renderStrip() {
    const el = $('strip');
    if (!state.strip.length) {
        el.innerHTML = '<li class="empty">Aquí aparecerán las últimas entradas.</li>';
        return;
    }
    el.replaceChildren(...state.strip.map((x) => {
        const li = document.createElement('li');
        if (x.pending) li.className = 'pending';
        li.append(span(x.time), bold(x.name), span([x.group, x.method].filter(Boolean).join(', ')));
        return li;
    }));
}
const span = (t) => Object.assign(document.createElement('span'), { textContent: t });
const bold = (t) => Object.assign(document.createElement('b'), { textContent: t });

const saveRecent = () => {
    const cutoff = Date.now() - state.windowMin * 60_000;
    for (const [k, v] of state.recent) if (v.at < cutoff) state.recent.delete(k);
    kv.set('recent', Object.fromEntries(state.recent));
};
const saveStrip = () => kv.set('strip', state.strip);

let flashTimer;
function flash(kind, { student, method, time }) {
    const el = $('flash');
    const text = {
        ok: 'Entrada registrada',
        dup: 'Tu entrada ya estaba registrada',
        unknown: 'Credencial no reconocida',
        pending: 'Lectura guardada',
    }[kind];
    el.className = `flash ${kind}`;
    $('flash-kind').textContent = text;
    $('flash-initials').textContent = student?.i ?? (kind === 'unknown' ? '?' : '…');
    $('flash-name').textContent = student?.n
        ?? (kind === 'unknown' ? 'Pide ayuda en dirección.' : 'Sin conexión: se confirmará al volver la red.');
    $('flash-group').textContent = student?.g ?? 'Sin grupo';
    $('flash-time-label').textContent = kind === 'dup' ? 'Registrada a las' : 'Hora';
    $('flash-time').textContent = time ?? '';
    $('flash-method').textContent = method ?? '';
    el.hidden = false;
    // reinicia la animación del monograma
    const g = $('flash-initials'); g.style.animation = 'none'; void g.offsetWidth; g.style.animation = '';
    beep(kind);
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { el.hidden = true; }, FLASH_MS);
}

function beep(kind) {
    const ctx = state.audio;
    if (!ctx) return;
    const tones = { ok: [[880, 0, .09], [1320, .1, .14]], dup: [[523, 0, .12], [523, .18, .12]], unknown: [[220, 0, .4]], pending: [[660, 0, .12]] }[kind];
    const t0 = ctx.currentTime;
    for (const [freq, at, dur] of tones) {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, t0 + at);
        g.gain.exponentialRampToValueAtTime(0.25, t0 + at + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + dur);
        o.connect(g).connect(ctx.destination);
        o.start(t0 + at);
        o.stop(t0 + at + dur + 0.02);
    }
}

const days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
function formatTime(ms, seconds = false) {
    return new Intl.DateTimeFormat('es-MX', { timeZone: state.timezone, hour: '2-digit', minute: '2-digit', ...(seconds ? { second: '2-digit' } : {}), hourCycle: 'h23' }).format(ms);
}
function tick() {
    const now = Date.now();
    $('clock').textContent = formatTime(now, true);
    $('date').textContent = new Intl.DateTimeFormat('es-MX', { timeZone: state.timezone, weekday: 'long', day: 'numeric', month: 'long' }).format(now);
    const h = Number(new Intl.DateTimeFormat('en-US', { timeZone: state.timezone, hour: 'numeric', hourCycle: 'h23' }).format(now));
    $('greeting').textContent = h >= 5 && h < 12 ? 'Buenos días.' : h >= 12 && h < 19 ? 'Buenas tardes.' : 'Buenas noches.';
}

// ── Inicio de la página ───────────────────────────────────
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(CFG.sw, { scope: '/kiosco' }).catch(() => {});
}

const hashToken = new URLSearchParams(location.hash.slice(1)).get('vincular');
if (hashToken) {
    history.replaceState(null, '', location.pathname); // el token no queda en la barra ni en el historial
    $('pair-token').value = hashToken;
    if (!(await pair(hashToken))) showPair();
} else {
    state.token = await kv.get('token');
    state.token ? showStart() : showPair();
}
