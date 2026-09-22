# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Start here

Before doing anything in this repo, read `skills/stack-orientation.md` (architecture,
services, bind mounts, environments) and `skills/command-safety.md` (the safe /
needs-confirmation / forbidden action ruleset). Every other skill in `skills/` builds on
those two. The `skills/` directory as a whole is the primary knowledge base for this
project — task-specific skills exist for filing taxes, importing invoices, the document
store, cloud deployment, etc. Read the relevant one before starting a task rather than
guessing at the workflow.

## What this is

A self-hosted, Docker-based Spanish invoicing stack with AEAT Verifactu compliance built
in (RD 1007/2023 + HAC/1177/2024) — FacturaScripts (PHP ERP/invoicing) plus custom
Verifactu submission and invoice-import scripts, no SaaS subscriptions or per-invoice
fees. Runs equally well as a single local install or on a shared cloud server hosting
multiple projects behind one reverse proxy (see `skills/cloud-deployment.md`).

## Commands

There is no build step, test suite, or linter in this repo — it's a Docker Compose
deployment of PHP scripts and a third-party PHP application (FacturaScripts), not a
compiled/tested codebase in the traditional sense.

```bash
# Bring the stack up (first run: 3-5 min; add --build after editing app/Dockerfile)
docker compose up -d --build

# Recreate one service after editing a bind-mounted config file (nginx, php.ini, etc.)
# — a bind-mounted file change alone does NOT get picked up by a running container;
# it must be force-recreated (or the in-container process explicitly reloaded)
docker compose up -d --force-recreate <service>

# Logs
docker compose logs -f app

# Query the DB directly
docker compose exec db mariadb -ufsuser -p"$(grep MARIADB_PASSWORD .env | cut -d= -f2)" facturascripts -e "SELECT ..."

# Run one of the CLI scripts (always dry-run first for anything AEAT-facing)
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion --dry-run
docker compose exec app php /verifactu/make-invoice-pdf.php <INVOICE_CODE> --env=preproduccion
docker compose exec app php /incoming/process-invoice.php --json-file=... --empresa=<NIF> --dry-run
docker compose exec app php /incoming/process-sale.php --json-file=... --empresa=<NIF> --dry-run
```

`--env=produccion` on the verifactu scripts has real, irreversible fiscal effect at
AEAT — see `skills/command-safety.md` before ever running it.

## Architecture

Three core services (`docker-compose.yml`): `db` (MariaDB — FacturaScripts' native
schema plus custom sidecar tables), `app` (PHP 8.3 + Apache, serving both
FacturaScripts itself and the small custom scripts under `/incoming/`), `nginx`
(reverse proxy in front of `app`). Two more support it: `minio` (S3-compatible object
store for scanned documents, entirely separate from FacturaScripts' own DB-stored
data — see `skills/gestoria-document-store.md`) and `oauth2-proxy` (Google sign-in
gate in front of the whole site, an addition on top of FacturaScripts' own login, not
a replacement for it).

**Two independent script pipelines**, both under bind mounts (not baked into the
image, so they can be edited live without a rebuild):
- `verifactu/` — submits issued invoices to AEAT (`submit-pending.php`) and generates
  Verifactu-compliant customer PDFs (`make-invoice-pdf.php`). Both take invoices
  already sitting in FacturaScripts' `facturascli` table.
- `incoming/` — imports invoices *into* FacturaScripts from PDFs (`process-invoice.php`
  for supplier invoices, `process-sale.php` for customer invoices), using an LLM to
  extract structured data from the PDF first. Also hosts the `gestoria/` document
  upload UI, an entirely separate concern from invoice import that happens to share
  this bind mount.

**Two AEAT environments coexist in the same database** via an `environment` column on
the `verifactu_submissions` sidecar table — `preproduccion` (AEAT sandbox, no fiscal
effect) and `producción` (live, permanent fiscal effect) are tracked independently, so
an invoice can be "submitted" in one and still "pending" in the other. Scripts default
to preproducción; producción always requires an explicit `--env=produccion` flag.

**Multi-empresa by design**: `secrets/companies.php` maps each empresa's NIF to its own
digital certificate and cert password; FacturaScripts' own `empresas` table and
`secuencias_documentos` (invoice numbering) are likewise keyed per-empresa, so several
unrelated companies/autónomos can run on one install with fully independent AEAT
identities and invoice-numbering sequences.

**Secrets never live in the repo or the image** — `.env`, `secrets/companies.php`, and
`secrets/*.p12` are all gitignored and bind-mounted read-only at runtime from the host.
`.env.example` and `secrets/companies.php.example` are the committed templates.

**Shared-host deployment**: this project's own `nginx` binds to a configurable
`NGINX_BIND_ADDR`/`NGINX_HTTP_PORT`/`NGINX_HTTPS_PORT` (default `0.0.0.0:80`/`443` for
a standalone install) so it can instead bind to `127.0.0.1:<some port>` when running
behind a shared host-level reverse proxy alongside other unrelated projects on the same
server — see `skills/cloud-deployment.md` for the full pattern, including two different
ways to get a real HTTPS certificate for that host-level proxy.
