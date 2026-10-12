# Production Deployment

For a Linux server (Ubuntu/Debian/CentOS) with Apache + PHP 8.2+ + MySQL 8 /
MariaDB 10.4+. Windows Server / IIS users: adapt paths; PDO and `.htaccess`
requirements are the same, but you'll use `web.config` instead.

---

## 1. Server prerequisites

| Package | Version |
|---|---|
| Ubuntu 22.04 LTS (or equivalent) | — |
| Apache 2.4 | with `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate` |
| PHP 8.2+ | with `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `json`, `bcmath` |
| MySQL 8 / MariaDB 10.4+ | — |
| Composer | optional (this app has no runtime dependencies, but useful for tooling) |

Install on Ubuntu:
```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-curl \
                    php-mbstring php-xml php-zip php-bcmath php-fileinfo
sudo a2enmod rewrite headers expires deflate
sudo systemctl restart apache2
```

---

## 2. Directory layout

Deploy the application **outside the web root** and point Apache's
`DocumentRoot` only at `/public`:

```
/var/www/servicehub/
├── .env                    # secrets, 0600, owned by www-data
├── app/  config/  cron/  database/  docs/  scripts/  storage/
└── public/                 # ← DocumentRoot
```

Upload the code:
```bash
sudo mkdir -p /var/www/servicehub
sudo rsync -av ./ user@server:/var/www/servicehub/
sudo chown -R www-data:www-data /var/www/servicehub
sudo find /var/www/servicehub -type d -exec chmod 755 {} \;
sudo find /var/www/servicehub -type f -exec chmod 644 {} \;
sudo chmod 600 /var/www/servicehub/.env
sudo chmod -R 775 /var/www/servicehub/storage
```

---

## 3. Create the database and a dedicated user

```sql
CREATE DATABASE servicehub_ng CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'servicehub'@'localhost' IDENTIFIED BY 'USE_A_LONG_RANDOM_STRING';
GRANT ALL PRIVILEGES ON servicehub_ng.* TO 'servicehub'@'localhost';
FLUSH PRIVILEGES;
```

Load schema and seed:
```bash
mysql -u root -p < /var/www/servicehub/database/schema.sql
mysql -u root -p servicehub_ng < /var/www/servicehub/database/seed.sql
```

Create the first real Super Admin:
```bash
sudo -u www-data php /var/www/servicehub/scripts/create_super_admin.php
```

Delete the demo account:
```sql
DELETE FROM super_admins WHERE email='superadmin@example.com';
```

---

## 4. Production `.env`

```env
APP_ENV=production
APP_DEBUG=false
APP_NAME="ServiceHub NG"
APP_URL=https://servicehub.example.ng
APP_TIMEZONE=Africa/Lagos

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=servicehub_ng
DB_USER=servicehub
DB_PASS=USE_A_LONG_RANDOM_STRING

SESSION_NAME=servicehub_session
SESSION_LIFETIME=7200

LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15

PAYSTACK_PUBLIC_KEY=pk_live_xxxxxxxxxxxxxxxx
PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxx
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK-xxxxxxxxxxxxxxxx
FLUTTERWAVE_SECRET_KEY=FLWSECK-xxxxxxxxxxxxxxxx

LOG_LEVEL=warning
```

Never commit `.env`. It's already in `.gitignore`.

---

## 5. Apache vhost

Create `/etc/apache2/sites-available/servicehub.conf`:

```apache
<VirtualHost *:80>
    ServerName servicehub.example.ng
    ServerAdmin admin@example.ng
    Redirect permanent / https://servicehub.example.ng/
</VirtualHost>

<VirtualHost *:443>
    ServerName servicehub.example.ng
    ServerAdmin admin@example.ng

    DocumentRoot /var/www/servicehub/public

    <Directory /var/www/servicehub/public>
        Options -Indexes -ExecCGI -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    # Block anything under these paths even if Apache is misconfigured
    <Directory /var/www/servicehub/app>
        Require all denied
    </Directory>
    <Directory /var/www/servicehub/config>
        Require all denied
    </Directory>
    <Directory /var/www/servicehub/storage>
        Require all denied
    </Directory>

    # Deny access to sensitive files anywhere
    <FilesMatch "\.(env|ini|log|sh|sql|bak|old|swp|conf|md|yml|yaml|lock)$">
        Require all denied
    </FilesMatch>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/servicehub.example.ng/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/servicehub.example.ng/privkey.pem

    # PHP-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Compression for text assets
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json
    </IfModule>

    # Long cache for versioned static assets
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css                "access plus 1 month"
        ExpiresByType application/javascript  "access plus 1 month"
        ExpiresByType image/png               "access plus 1 month"
        ExpiresByType image/jpeg              "access plus 1 month"
        ExpiresByType image/svg+xml           "access plus 1 month"
    </IfModule>

    ErrorLog  /var/log/apache2/servicehub-error.log
    CustomLog /var/log/apache2/servicehub-access.log combined
</VirtualHost>
```

Enable and reload:
```bash
sudo a2ensite servicehub.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

Get a TLS certificate:
```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d servicehub.example.ng
```

---

## 6. PHP-FPM pool (recommended)

Edit `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6

php_admin_value[upload_max_filesize] = 6M
php_admin_value[post_max_size]       = 8M
php_admin_value[memory_limit]        = 256M
php_admin_value[max_execution_time]  = 60
```

Restart:
```bash
sudo systemctl restart php8.2-fpm
```

---

## 7. Cron jobs

See [`CRON.md`](CRON.md) for the exact lines. On Ubuntu:

```bash
sudo crontab -u www-data -e
```

```cron
*/2 * * * *   /usr/bin/php /var/www/servicehub/cron/release_expired_tasks.php   >> /var/log/servicehub-cron.log 2>&1
*/15 * * * *  /usr/bin/php /var/www/servicehub/cron/deadline_warnings.php       >> /var/log/servicehub-cron.log 2>&1
0 3 * * *     /usr/bin/php /var/www/servicehub/scripts/verify_all.php           >> /var/log/servicehub-cron.log 2>&1
```

---

## 8. Log rotation

Create `/etc/logrotate.d/servicehub`:

```
/var/www/servicehub/storage/logs/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    create 0640 www-data www-data
}
```

Test:
```bash
sudo logrotate -d /etc/logrotate.d/servicehub
```

---

## 9. Backups

**Database** — nightly dump at 02:00:
```bash
sudo crontab -u root -e
```
```cron
0 2 * * * /usr/bin/mysqldump --single-transaction --routines --triggers \
            servicehub_ng | gzip > /var/backups/servicehub_$(date +\%Y\%m\%d).sql.gz
0 3 * * 0 find /var/backups/servicehub_*.sql.gz -mtime +30 -delete
```

**Uploads** — nightly rsync to an off-site location:
```bash
0 4 * * * /usr/bin/rsync -az /var/www/servicehub/storage/uploads/ \
            backup@remote:/backups/servicehub/uploads/
```

Restore test at least quarterly.

---

## 10. Deployment workflow (updates)

```bash
cd /var/www/servicehub

# 1. Put the site into maintenance mode (create public/maintenance.html
#    and add a redirect in the vhost, OR temporarily point DocumentRoot
#    to a maintenance page).

# 2. Back up the DB
mysqldump --single-transaction servicehub_ng | gzip > /var/backups/pre-deploy-$(date +%s).sql.gz

# 3. Pull the new code
sudo -u www-data git pull origin main

# 4. Apply any migrations (none — schema is stable; if you add one, run it)
# mysql -u root -p servicehub_ng < database/migrations/2026_01_01_add_xyz.sql

# 5. Clear caches (system_settings is cached per-request; no persistent cache)

# 6. Fix permissions on any new files
sudo chown -R www-data:www-data /var/www/servicehub
sudo find /var/www/servicehub -type f -exec chmod 644 {} \;
sudo find /var/www/servicehub -type d -exec chmod 755 {} \;
sudo chmod 600 /var/www/servicehub/.env
sudo chmod -R 775 /var/www/servicehub/storage

# 7. Run the security and integrity checks
sudo -u www-data php scripts/security_check.php
sudo -u www-data php scripts/verify_all.php

# 8. Reload PHP-FPM
sudo systemctl reload php8.2-fpm

# 9. Take the site out of maintenance mode
```

---

## 11. Health checks

Configure an external monitor (UptimeRobot, BetterStack, etc.) against:

| URL | Expected |
|---|---|
| `https://servicehub.example.ng/` | 200 |
| `https://servicehub.example.ng/login` | 200 |
| `https://servicehub.example.ng/api/release-expired?token=<sweep_token>` | 200 with `{"ok":true,...}` |

Fetch the sweep token once:
```sql
SELECT setting_value FROM system_settings WHERE setting_key='sweep_token';
```

You can also monitor disk, DB connections, and cron log rotation.

---

## 12. Production go-live checklist

- [ ] `.env` — `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL=https://...`
- [ ] `.env` — `chmod 600`, owned by `www-data`
- [ ] Demo Super Admin deleted; real Super Admin created
- [ ] Super Admin password changed from the CLI-created temporary one
- [ ] `admin_commission <= price` verified on every service
- [ ] HTTPS certificate installed and auto-renewing (`certbot renew --dry-run`)
- [ ] HSTS active (check response header on `/`)
- [ ] CSP active (check DevTools)
- [ ] `storage/`, `config/`, `app/`, `cron/`, `database/`, `scripts/` unreachable from the web
- [ ] Cron entries installed and running (check the log file)
- [ ] Logrotate configured for `storage/logs/*.log`
- [ ] Nightly DB backup + uploads sync running
- [ ] Payment gateway webhooks configured for the production URL
- [ ] `security_check.php` returns zero HIGH findings
- [ ] `verify_all.php` returns zero discrepancies
- [ ] `smoke_test.php` passes against a scratch DB
- [ ] Legal pages (Terms, Privacy) in place
- [ ] Support email configured in `system_settings`

---

## 13. Rollback procedure

If a deployment misbehaves:

```bash
# 1. Stop accepting writes — put up maintenance mode
# 2. Restore code
cd /var/www/servicehub
sudo -u www-data git reset --hard <previous-commit-sha>
# 3. Restore DB only if the change included a schema migration
gunzip -c /var/backups/pre-deploy-<timestamp>.sql.gz | mysql -u root -p servicehub_ng
# 4. Fix perms
sudo chown -R www-data:www-data /var/www/servicehub
# 5. Reload PHP-FPM
sudo systemctl reload php8.2-fpm
# 6. Come out of maintenance mode
```