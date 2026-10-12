            </div><!-- /.admin-main-inner -->
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Notification badge poller
    (function () {
        const badge = document.querySelector('a[href$="/admin/notifications"] .badge');
        const url   = <?= json_encode(url('/admin/api/notifications')) ?>;
        if (!url) return;
        setInterval(async () => {
            try {
                const r = await fetch(url, {credentials: 'same-origin'});
                if (!r.ok) return;
                const data = await r.json();
                if (badge) badge.textContent = data.unread > 0 ? data.unread : '';
            } catch (e) {}
        }, 60000);
    })();

    // Server-time-based countdown timers
    (function () {
        const els = document.querySelectorAll('[data-deadline-utc]');
        if (!els.length) return;

        function fmt(seconds) {
            if (seconds < 0) seconds = 0;
            const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
            const m = String(Math.floor(seconds % 3600 / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            return h + ':' + m + ':' + s;
        }

        function tick() {
            const now = Math.floor(Date.now() / 1000);
            els.forEach(el => {
                const deadlineUtc = parseInt(el.dataset.deadlineUtc, 10);
                if (!deadlineUtc) return;
                el.textContent = fmt(deadlineUtc - now);
            });
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>
</body>
</html>