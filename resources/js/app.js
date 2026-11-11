// Tema claro/oscuro: el script en línea del <head> aplica la preferencia antes
// de pintar; aquí solo se maneja el botón.
const THEME_KEY = 'marcaje.theme';

function currentTheme() {
    const set = document.documentElement.dataset.theme;
    if (set) return set;
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function syncThemeButtons() {
    const next = currentTheme() === 'dark' ? 'claro' : 'oscuro';
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
        b.textContent = `Tema ${next}`;
    });
}

// Confirmaciones e impresión sin manejadores en línea (la CSP no los permite).
document.addEventListener('submit', (e) => {
    const msg = e.target.dataset?.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
});

document.addEventListener('click', (e) => {
    if (e.target.closest('[data-print]')) window.print();

    const toggle = e.target.closest('[data-theme-toggle]');
    if (toggle) {
        const theme = currentTheme() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try { localStorage.setItem(THEME_KEY, theme); } catch {}
        syncThemeButtons();
    }

    const copy = e.target.closest('[data-copy]');
    if (copy) {
        const text = document.querySelector(copy.dataset.copy)?.textContent.trim();
        navigator.clipboard?.writeText(text).then(() => {
            copy.textContent = 'Copiada';
            setTimeout(() => { copy.textContent = 'Copiar'; }, 1600);
        });
    }

    const nav = e.target.closest('[data-nav-toggle]');
    if (nav) {
        setNav(!document.querySelector('.app')?.classList.contains('nav-open'));
    } else if (!e.target.closest('.side')) {
        setNav(false);
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setNav(false);
});

function setNav(open) {
    const app = document.querySelector('.app');
    if (!app) return;
    app.classList.toggle('nav-open', open);
    document.querySelector('[data-nav-toggle]')?.setAttribute('aria-expanded', String(open));
}

syncThemeButtons();

// Alumnos en móvil: al elegir uno, llevar la vista a su detalle.
document.addEventListener('livewire:init', () => {
    window.Livewire.on('student-selected', () => {
        if (matchMedia('(max-width: 900px)').matches) {
            requestAnimationFrame(() => document.querySelector('.insp')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        }
    });
});
