/**
 * task-timer.js
 * Server-authoritative countdown for the admin processing page.
 *
 * The page exposes:
 *   - #taskTimer            element whose textContent becomes HH:MM:SS
 *   - #taskTimer.data-id    submission id
 *   - #taskTimer.data-server-now  server unix timestamp at page render
 *   - #taskTimer.data-deadline    server deadline unix timestamp at page render
 *
 * Every 15s the script asks /api/task-timer?id=X for a fresh value.
 * When the countdown hits 0, the page is reloaded once so the server-side
 * sweep can return the task to the pool (or keep it if it was completed).
 */
(function () {
    const el = document.getElementById('taskTimer');
    if (!el) return;

    const submissionId = el.dataset.id;
    const timerUrl     = el.dataset.timerUrl;

    let serverNow  = parseInt(el.dataset.serverNow  || '0', 10);
    let deadline   = parseInt(el.dataset.deadline   || '0', 10);
    let skewMs     = Date.now() - serverNow * 1000;
    let expiredAt  = 0;

    function fmt(seconds) {
        if (seconds < 0) seconds = 0;
        const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const m = String(Math.floor(seconds % 3600 / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        return h + ':' + m + ':' + s;
    }

    function tick() {
        const nowServerSec = Math.floor((Date.now() - skewMs) / 1000);
        const remaining    = deadline - nowServerSec;

        el.textContent = fmt(remaining);
        el.classList.toggle('urgent', remaining > 0 && remaining < 3600);

        if (remaining <= 0 && expiredAt === 0) {
            expiredAt = Date.now();
            // Give the server a moment, then reload so the queue/sweep updates.
            setTimeout(() => window.location.reload(), 1500);
        }
    }

    async function reconcile() {
        if (!timerUrl || !submissionId) return;
        try {
            const res = await fetch(timerUrl + '?id=' + encodeURIComponent(submissionId), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.ok) return;

            serverNow = data.server_now_unix;
            deadline  = data.deadline_unix || 0;
            skewMs    = Date.now() - serverNow * 1000;

            // If the server says the task is no longer "processing", refresh.
            if (data.status !== 'processing') {
                window.location.reload();
            }
        } catch (e) { /* ignore */ }
    }

    tick();
    setInterval(tick, 1000);
    setInterval(reconcile, 15000);
})();