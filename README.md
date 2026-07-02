# Verifactu Stack

A self-hosted, zero-third-party-fee Spanish invoicing stack built from open-source parts.
FacturaScripts for invoice management, our own Verifactu submitter for AEAT compliance,
mpdf for customer PDFs, all packaged as three Docker services (`db`, `app`, `nginx`).

Runs on your laptop or a cloud VM. No SaaS subscriptions.

## What it does

Spanish businesses that issue invoices through software must comply with **Verifactu** (Real
Decreto 1007/2023 + Order HAC/1177/2024) starting:

- **1 January 2027** for sociedades (SL, SA, etc.)
- **1 July 2027** for autónomos and other taxpayers

Every invoice must be registered with the AEAT in near-real-time, carry a hash chain
proving nothing was altered after the fact, and include a QR code the recipient can scan
to verify the invoice against AEAT's records.

Most commercial invoicing software adds a monthly fee for this compliance layer. This
project provides the same compliance, running on your own machine, with:

- **FacturaScripts** (GPL) for the invoicing UI, customer/product management, and accounting.
- **Custom PHP CLI scripts** that submit invoices to AEAT via the [josemmo/verifactu-php](https://github.com/josemmo/Verifactu-PHP) library.
- **mPDF** to generate Verifactu-compliant customer PDFs with the QR + CSV embedded.
- **MariaDB** for storage.
- **nginx** as reverse proxy (with hooks for Let's Encrypt when you deploy publicly).

## Features

- Multi-empresa: SLs and autónomos on the same install, each with its own cert and chain.
- Spanish + foreign customers (auto-selects `FiscalIdentifier` vs `ForeignFiscalIdentifier`).
- IVA exempt & non-subject operations mapped to correct AEAT codes (E1–E6, N1–N2).
- Rectificativas (delta and substitution).
- Independent **preproducción** and **producción** environments in the same DB, selectable per invocation via `--env=`.
- Series-based safety: test series (`T`) is preproducción-only; can't accidentally hit producción.
- PDFs include: empresa logo, formatted invoice code, tax breakdown, IRPF retention when applicable, exemption reasons, bank account with polite payment note, QR + "VERI*FACTU" + AEAT CSV.
- Chain integrity: previous-hash reference per empresa per environment, tracked in `verifactu_submissions` sidecar table.

## Architecture

```
                        ┌────────────┐
                        │    You     │
                        │ (browser)  │
                        └─────┬──────┘
                              │  HTTP(S)
                              ▼
        ┌──────────────────────────────────────────────┐
        │                docker-compose                │
        │  ┌────────┐  ┌────────────┐  ┌────────────┐  │
        │  │ nginx  ├──►    app     ├──►     db     │  │
        │  │(alpine)│  │(FS + PHP + │  │ (mariadb)  │  │
        │  │        │  │  scripts)  │  │            │  │
        │  └────────┘  └────────────┘  └────────────┘  │
        └──────────────────────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────┐
                    │   AEAT SOAP     │
                    │ (pre/prod)      │
                    └─────────────────┘
```

## Prerequisites

- Docker Desktop (with WSL2 integration if on Windows).
- A Spanish digital certificate (`.p12` / `.pfx`) for each empresa. Free from the FNMT via video-ID.
- Basic familiarity with the terminal.

## Quick start

```bash
git clone https://github.com/YOURUSER/verifactu-stack.git
cd verifactu-stack

cp .env.example .env
# Edit .env: set MARIADB_ROOT_PASSWORD and MARIADB_PASSWORD

cp secrets/companies.php.example secrets/companies.php
# Edit secrets/companies.php: set empresa NIFs, cert paths, cert passwords, DB pass

# Copy your digital certificates into ./secrets/
cp ~/path-to/sl-cert.p12       secrets/sl-cert.p12
cp ~/path-to/autonomo-cert.p12 secrets/autonomo-cert.p12

chmod 600 .env secrets/companies.php secrets/*.p12

docker compose up -d --build
```

First build takes 3–5 minutes (PHP extensions, Composer, npm). Then open
`http://localhost/` and complete FacturaScripts' first-run installer.

## Configuration

### `.env`

MariaDB credentials. Docker-compose reads this at container start.

```
MARIADB_ROOT_PASSWORD=strong-root-password
MARIADB_DATABASE=facturascripts
MARIADB_USER=fsuser
MARIADB_PASSWORD=strong-user-password
```

### `secrets/companies.php`

The main config file for the Verifactu scripts. Never commit this. Read only inside the container.

Key sections:

- `database` — DB connection (reads from env vars in Docker).
- `sif` — Sistema Informático de Facturación metadata: id, name, version, installation_number, environment default.
- `series` — Per-invoice-series environment allowlist. Test series (`T`) restricted to preproducción.
- `empresas` — Per-empresa (NIF-keyed) identity + cert path + cert password.

See `secrets/companies.php.example` for the shape.

## Usage

Everything runs through `docker compose exec`:

### Access FacturaScripts UI

```
http://localhost/
```

### Submit pending invoices to AEAT

```bash
# Preproducción (safe sandbox — no fiscal effect)
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion

# Producción (LIVE — real fiscal record at AEAT)
docker compose exec app php /verifactu/submit-pending.php --env=produccion

# Dry-run (validates + hashes locally, does not send)
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion --dry-run
```

Each run picks up invoices not yet submitted in the given environment and processes them in
chain order. Series flagged as preproducción-only are skipped in producción runs.

### Generate a customer-facing PDF

```bash
# Auto-picks producción row if it exists, else preproducción
docker compose exec app php /verifactu/make-invoice-pdf.php 2026-A027

# Force environment
docker compose exec app php /verifactu/make-invoice-pdf.php 2026-A027 --env=produccion
```

Output lands in `./verifactu/invoices/` on the host (bind-mounted from the container).

## Environments

The stack maintains **two independent chains** in the same `verifactu_submissions` table, keyed by an
`environment` column. This lets you exercise the full pipeline in preproducción and switch to
producción when ready without wiping local state.

To reduce accident risk:

- **Config default** is preproducción unless explicitly overridden.
- `--env=produccion` prints `LIVE, real fiscal effect` to stderr before submission.
- **Series `T` is preproducción-only**, so test invoices can never accidentally hit producción.

## Data model

- `verifactu_submissions` (created by `verifactu/setup-sidecar.sql`) — one row per invoice per environment. Tracks status, hash, CSV, QR URL/path, chain reference, error message.
- FacturaScripts' own tables — everything else (customers, invoices, empresas, series, sequences).

## Legal disclaimer

**This project is not a certified Sistema Informático de Facturación (SIF) with a declaración
responsable filed at AEAT.** Under Article 13 of RD 1007/2023, a SIF must have a responsible
declaration signed by its developer. Since this stack is self-built by each deployer,
**you** as the deployer are effectively both the developer and the taxpayer, and the legal
responsibility for compliance is entirely yours.

The authors and contributors **bear no responsibility whatsoever** for any tax penalty,
sanction, incorrect submission, data loss, or any other consequence arising from the use of
this software. Everything provided here is **as-is, no warranty**.

**Read [DISCLAIMER.md](DISCLAIMER.md) in full before running any command against AEAT.**
Doing so is a condition of use.

## Producción cutover checklist

- [ ] Cert valid at AEAT for the empresa's NIF.
- [ ] `.env` and `secrets/companies.php` set with real (not test) values.
- [ ] Preproducción exercised for the invoice types you actually issue.
- [ ] Series correctly configured (real series `A`, rectificativas `R`; test series `T` not for producción).
- [ ] Backup strategy for `verifactu_submissions` (mysqldump on a schedule).
- [ ] Understood that once submitted to producción, records cannot be deleted; corrections only via factura rectificativa.
- [ ] `installation_number` bumped if migrating from a prior chain.

## Development / iteration

The `verifactu/` folder is bind-mounted into the container at `/verifactu/`, so you can edit
`submit-pending.php` / `make-invoice-pdf.php` on the host and see changes on the next run.
No rebuild needed.

For changes to the Dockerfile, entrypoint, nginx config, or apache vhost — `docker compose up -d --build`.

## Backups

Not automated in this repo. Recommended:

- `mysqldump` of `verifactu_submissions` daily to a mounted volume outside the DB container.
- Nightly copy of the mounted `MyFiles` (invoices, attached logos) volume.
- Weekly copy of the whole `secrets/` directory to encrypted external storage.

## References

- [Verifactu overview at AEAT sede](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu.html)
- [Real Decreto 1007/2023](https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840)
- [Orden HAC/1177/2024 (technical)](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2024-22138)

## Credits

- **[josemmo/Verifactu-PHP](https://github.com/josemmo/Verifactu-PHP)** — MIT — AEAT SOAP client and record models.
- **[chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)** — MIT — QR PNG rendering.
- **[mpdf/mpdf](https://github.com/mpdf/mpdf)** — GPL-2.0-or-later — HTML-to-PDF.
- **[FacturaScripts](https://github.com/NeoRazorX/facturascripts)** — LGPL — invoicing UI + data model.

## License

This project's own code is released under the MIT license. See `LICENSE`.

Note that some transitive dependencies (mpdf, FacturaScripts) are under LGPL/GPL. If you
distribute a modified build, review those licenses' obligations before doing so. Running
your own instance for your own business is unaffected.

---

Built by someone who didn't want to pay a subscription for something that used to be free.
