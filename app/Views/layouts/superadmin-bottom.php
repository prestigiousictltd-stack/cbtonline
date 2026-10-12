            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        const badge = document.querySelector('a[href$="/superadmin/notifications"] .badge');
        const url   = <?= json_encode(url('/superadmin/api/notifications')) ?>;
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
</script>
</body>
</html>