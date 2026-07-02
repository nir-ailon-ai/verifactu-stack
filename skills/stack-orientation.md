---
name: stack-orientation
description: >
  Foundation skill. Explains what the Verifactu Stack is, what services run,
  where secrets live, where scripts live, and how the two AEAT environments
  (preproducción / producción) are separated. All other skills assume the
  agent has read this one.
triggers:
  - Read at the start of any session where the agent will operate the stack.
  - Reference when the user asks "what is this system?" or "how does it work?"
---

# Skill: Stack orientation

## What this project is

A self-hosted, Docker-based Spanish invoicing stack with Verifactu (AEAT
compliance) built in. Runs on the user's own machine or a cloud VM. No SaaS
subscriptions.

The stack packages three services with `docker compose`:

| Service | Image | Purpose |
|---|---|---|
| `db` | `mariadb:11` | Holds FacturaScripts data + the `verifactu_submissions` sidecar |
| `app` | Custom PHP 8.3 + Apache | FacturaScripts (invoicing UI) + our CLI scripts |
| `nginx` | `nginx:alpine` | Reverse proxy — port 80/443 to `app:80` |

Ports exposed to the host:
- **80** and **443** on `nginx` → user opens `http://localhost/` in a browser to reach FS.
- `db` and `app` are internal to the docker network; not reachable from the host directly.

## Project directory layout

```
verifactu-stack/
├── docker-compose.yml         # 3-service composition
├── .env                       # DB passwords (NEVER commit)
├── .env.example               # template
├── secrets/
│   ├── companies.php          # SIF metadata, cert paths, per-empresa config (NEVER commit)
│   └── *.p12                  # digital certificates (NEVER commit)
├── app/                       # built into the app image
│   ├── Dockerfile
│   ├── entrypoint.sh
│   ├── apache-vhost.conf
│   └── php.ini
├── nginx/
│   ├── nginx.conf
│   └── default.conf
├── verifactu/                 # bind-mounted into container at /verifactu/
│   ├── submit-pending.php     # CLI: submit new invoices to AEAT
│   ├── make-invoice-pdf.php   # CLI: generate customer PDF
│   ├── setup-sidecar.sql      # migration for verifactu_submissions table
│   ├── composer.json / composer.lock
│   ├── vendor/                # composer deps (gitignored)
│   ├── qr/                    # generated QR PNGs (gitignored)
│   └── invoices/              # generated PDFs (gitignored)
└── skills/                    # this directory (agent instruction files)
```

Bind mounts you should know:
- `./secrets` → `/secrets` (read-only inside container). Cert files + companies.php.
- `./verifactu` → `/verifactu` (read/write). Scripts + generated artifacts.
- `fs_myfiles` (named volume) → `/var/www/html/facturas/MyFiles`. FS uploads (logos, attachments).

## How the pieces talk

```
User → browser → nginx (port 80) → app:80 (Apache) → FS PHP → db:3306 (MariaDB)
                                                     │
                                                     └→ /verifactu/submit-pending.php
                                                         │
                                                         └→ SOAP → AEAT (prewww1 or www1)
```

CLI scripts (`submit-pending.php`, `make-invoice-pdf.php`) also run inside the
`app` container, invoked from the host via `docker compose exec app php ...`.

## AEAT environments — the critical distinction

Two completely separate AEAT systems:

- **Preproducción** (sandbox): endpoints at `prewww1.aeat.es` (SOAP) and `prewww2.aeat.es` (QR verifier). **No fiscal effect whatsoever.** Safe to hammer with test data.
- **Producción** (live): endpoints at `www1.agenciatributaria.gob.es` and `www2.agenciatributaria.gob.es`. **Every submission has real fiscal effect.** Cannot be undone.

Environment is chosen at CLI invocation time via `--env=`:

```
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion
docker compose exec app php /verifactu/submit-pending.php --env=produccion
```

Default (no `--env`) reads `secrets/companies.php` → `sif.environment`.

The `verifactu_submissions` table has an `environment` column, so both chains
coexist independently in the same DB. An invoice submitted to preproducción is
still "pending" in producción until submitted there separately.

Safety features:
- Producción runs print `LIVE, real fiscal effect` to stderr.
- Invoice series with a `preproduccion`-only entry in `companies.php` → `series`
  are skipped in producción runs. Convention: series `T` is preproducción-only.

## Frequently-used commands the agent should know

| Purpose | Command |
|---|---|
| See running services | `docker compose ps` |
| Watch app logs | `docker compose logs -f app` |
| Shell into app container | `docker compose exec app bash` |
| Run PHP CLI in container | `docker compose exec app php /verifactu/<script>.php [args]` |
| Query DB directly | `docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT ..."` |
| Restart app after Dockerfile change | `docker compose up -d --build app` |
| Restart config-only change | `docker compose restart app` |
| Push updated script into container | `docker compose exec -T app bash -c 'cat > /verifactu/<file>' < ./verifactu/<file>` |

Note: `-T` on `docker compose exec` disables TTY allocation, which is needed
when piping stdin (e.g. `< source-file`).

## Where secrets live

Never in the repository. Never in the Docker image. Always bind-mounted at
runtime from the host's `./secrets/` directory as read-only.

- `secrets/companies.php` — SIF config, cert paths, cert passwords, database password (or reads from env).
- `secrets/*.p12` — digital certificates for each empresa.
- `.env` — MariaDB user/password, database name.

If the user asks about "the passwords" or "the certs", the agent should not
paste values from these files. It can confirm existence, ownership, and
permissions, but never contents.

## What the agent should never do without explicit human confirmation

- Run any command with `--env=produccion`.
- `DELETE` from `verifactu_submissions` or any FS table.
- Modify `secrets/companies.php` or any file under `secrets/`.
- Restart the whole stack (`docker compose down`) if it affects live services.
- Push code to the git remote.
- Bump `installation_number` in `companies.php` (this resets the AEAT chain).

See `command-safety.md` for the complete gate ruleset.

## Related skills

- `database-schema.md` — the DB tables and columns you'll query.
- `command-safety.md` — what's safe, what needs confirmation, what's forbidden.
- Task-level skills (`rectificativa-por-error.md`, `create-invoice.md`, etc.) build on all three foundations.
