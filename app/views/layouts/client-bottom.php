    </div><!-- /.client-main-inner -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Notification badge poller
    (function () {
        const badge = document.querySelector('a[href$="/client/notifications"] .badge');
        const url   = <?= json_encode(url('/api/notifications')) ?>;
        if (!url) return;
        setInterval(async () => {
            try {
                const r = await fetch(url, { credentials: 'same-origin' });
                if (!r.ok) return;
                const data = await r.json();
                if (badge) badge.textContent = data.unread > 0 ? data.unread : '';
            } catch (e) { /* ignore */ }
        }, 60000);
    })();
</script>
</body>
</html>