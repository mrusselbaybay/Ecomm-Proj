// After a deploy, a tab still running the previous build asks for hashed
// chunks that no longer exist (the server answers with HTML → "disallowed
// MIME type"). Vite fires `vite:preloadError` for that; reload once so the
// tab picks up the new manifest. Guarded so a genuinely missing chunk
// can't cause a reload loop.
const KEY = 'btw:chunk-reload-at';
const WINDOW_MS = 60 * 1000;

window.addEventListener('vite:preloadError', (event) => {
    let last = 0;
    try {
        last = Number(sessionStorage.getItem(KEY)) || 0;
    } catch {
        // storage blocked — fall through and reload at most this once
    }

    if (Date.now() - last < WINDOW_MS) {
        return; // already reloaded recently; let the error surface
    }

    event.preventDefault();
    try {
        sessionStorage.setItem(KEY, String(Date.now()));
    } catch {
        // ignore
    }
    window.location.reload();
});
