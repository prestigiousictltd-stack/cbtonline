# ServiceHub NG

A production-ready, multi-role PHP/MySQL service-processing platform with a
client wallet, admin task queue, atomic task locking, commission ledger,
withdrawal workflow, super-admin back office, and pluggable payment gateways.

**Stack:** PHP 8.2+ · MySQL 8 / MariaDB 10.4+ · Bootstrap 5 · jQuery (CDN) · PDO.

---

## Feature highlights

| Area | What's included |
|---|---|
| **Clients** | Register, sign in, wallet, funding (bank transfer + online gateways), browse services, submit dynamic forms, live tracking, printable receipts, notifications, password reset |
| **Services** | Fully DB-driven; each service has its own dynamic form built from `service_fields` — new services and fields need no code changes |
| **Submissions** | Atomic wallet debit, snapshot pricing, gap-free tracking IDs (`PREFIX-YYYY-NNNNNN`) |
| **Admin portal** | Separate authentication, per-service task queue, atomic claim, server-authoritative countdown, completion with document upload, error reporting, commission, withdrawals |
| **Super Admin** | Platform-wide dashboard, clients, admins, services, submissions, payments, wallets, withdrawals, reports (CSV export), notifications, audit logs, settings |
| **Payments** | `PaymentGatewayInterface` abstraction — Paystack and Flutterwave implemented, bank transfer with proof verification |
| **Security** | Three isolated auth guards, CSRF, prepared statements everywhere, secure uploads, signed webhooks, audit trail, hardened `.htaccess` |
| **Observability** | Full audit trail (`audit_logs`), internal notifications, ledger reconciliation |

---

## Quick start (XAMPP)

1. **Install XAMPP** with PHP 8.2+ and MariaDB 10.4+.
2. **Place the project** at `C:\xampp\htdocs\servicehub`.
3. **Create the database and schema:**
   ```bash
   mysql -u root < database/schema.sql
   mysql -u root servicehub_ng < database/seed.sql
   ```
4. **Copy `.env.example` to `.env`** and adjust `APP_URL` if needed.
5. **Create a real Super Admin** (recommended; delete the demo one first):
   ```bash
   mysql -u root servicehub_ng -e "DELETE FROM super_admins WHERE email='superadmin@example.com';"
   php scripts/create_super_admin.php
   ```
6. **Start Apache** in XAMPP and open:
   ```
   http://localhost/servicehub/public/
   ```

For the full setup, see [`docs/INSTALL.md`](docs/INSTALL.md).

---

## Project layout

```
servicehub/
├── .env                    # Secrets — outside web root, never committed
├── config/                 # Constants loaded from .env
├── app/                    # Application code (PSR-4 style)
│   ├── Core/               # DB, Session, CSRF, Auth, Validator, Logger, Settings
│   ├── Repositories/       # One class per table
│   ├── Services/           # Business logic — atomic across DB transactions
│   ├── Payments/           # Gateway interface + adapters + manager
│   ├── Support/            # helpers, constants, format, dynamic form
│   └── Views/              # Layout + partials shared by pages
├── public/                 # ◄── DocumentRoot (only this folder is web-reachable)
│   ├── client/  admin/  superadmin/  api/  payment/
│   └── assets/             # css, js, img, vendor
├── storage/                # Logs + uploads (deny-all, outside web root)
├── cron/                   # CLI sweeper scripts
├── database/               # schema.sql, seed.sql
├── scripts/                # CLI helpers (create_super_admin, security_check, verify)
└── docs/                   # This documentation set
```

Full tree with every file's purpose: see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## Documentation index

| Document | Purpose |
|---|---|
| [INSTALL.md](docs/INSTALL.md) | Local XAMPP setup, first-run checklist, common errors |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment: vhost, HTTPS, permissions, backups |
| [CRON.md](docs/CRON.md) | Scheduled tasks and how to enable them |
| [SECURITY.md](docs/SECURITY.md) | Full hardening checklist + OWASP mapping |
| [BUSINESS_RULES.md](docs/BUSINESS_RULES.md) | The 15 core rules and where each is enforced |
| [TEST_PLAN.md](docs/TEST_PLAN.md) | Manual test matrix across every module |
| [CHANGELOG.md](docs/CHANGELOG.md) | What each of the 17 build steps delivered |

---

## Support scripts

```bash
php scripts/create_super_admin.php     # First-time / recovery super admin
php scripts/security_check.php         # Static analysis — run before each deploy
php scripts/verify_all.php             # Ledger + task + storage integrity
php scripts/smoke_test.php             # End-to-end happy-path in a fresh DB
```

---

## License

Proprietary. All rights reserved.