# Verifactu Stack

**An open-source, self-hosted AI gestoria for Spanish businesses and autónomos.**

Import invoices by dropping PDFs into a folder. File quarterly taxes through a guided
conversation with an AI agent. Run on your own hardware or a cloud VM. No subscriptions,
no third-party access to your data.

---

## Vision

A traditional gestor charges €150–500/month to perform tasks that are, at their core,
mechanical: reading invoices, entering numbers, submitting forms to AEAT. Most of that
cost is human time spent on repetitive work that software can handle.

Verifactu Stack replaces the mechanical layer with an AI agent — [Claude Code](https://claude.ai/code)
— that reads PDFs, understands Spanish tax law, enters data into FacturaScripts, and walks
you through AEAT filings step by step. A human — you, or a gestor using this system —
stays in the loop for review and approval.

The system has three operating modes:

| Mode | Who | What happens |
|---|---|---|
| **AI Autopilot** | Business owner with Claude Code | Drop PDFs, chat with the agent, file taxes guided by conversation |
| **CLI** | Semi-technical user | Run PHP scripts directly against the DB and AEAT |
| **Manual** | Anyone | Use FacturaScripts' full web UI |

All three modes write to the same database and are fully interchangeable.

---

## What it handles

- **Invoicing** — outgoing (facturas emitidas) and incoming (facturas recibidas), including
  foreign clients, multi-currency, and IVA-exempt operations.
- **Verifactu** — AEAT real-time invoice registration (mandatory from 2027), with hash
  chains and QR codes on every invoice.
- **Quarterly filings** — Modelo 303 (IVA), Modelo 130 (IRPF, autónomos), Modelo 202
  (IS pago fraccionado, SLs). Guided by the AI agent.
- **Corrective invoices** — facturas rectificativas following the correct legal pattern.
- **Multi-empresa** — multiple SLs and autónomos on the same install, each with its own
  AEAT certificate and independent Verifactu hash chain.

---

## Architecture

```
          ┌─────────────────────────────────────────────┐
          │           AI Agent (Claude Code)             │
          │  reads PDFs · imports invoices · files taxes │
          │  answers tax questions · guides migrations   │
          └─────────────────────┬───────────────────────┘
                                │ tool calls (Bash, Read, Edit)
          ┌─────────────────────▼───────────────────────┐
          │               docker-compose                 │
          │                                              │
          │  ┌──────────┐  ┌──────────────┐  ┌───────┐  │
          │  │  nginx   ├──►     app      ├──►  db   │  │
          │  │ (proxy)  │  │ FS + PHP CLI │  │MariaDB│  │
          │  └──────────┘  └──────┬───────┘  └───────┘  │
          └─────────────────────┬─┴──────────────────────┘
                           browser   SOAP
                                │       │
                           ┌────▼┐  ┌───▼─────────┐
                           │ You │  │    AEAT      │
                           │(UI) │  │ pre / prod   │
                           └─────┘  └─────────────┘
```

---

## Tech stack

| Component | Role |
|---|---|
| [FacturaScripts](https://github.com/NeoRazorX/facturascripts) (LGPL) | Invoicing UI, customer/product management, double-entry accounting |
| Custom PHP CLI scripts | Verifactu submitter, invoice importer, PDF generator |
| [josemmo/verifactu-php](https://github.com/josemmo/Verifactu-PHP) (MIT) | AEAT SOAP client and Verifactu record models |
| [mPDF](https://github.com/mpdf/mpdf) (GPL-2.0) | Verifactu-compliant PDF generation with QR + CSV |
| MariaDB 11 | Storage |
| nginx + Apache | Reverse proxy + PHP runtime |
| [Claude Code](https://claude.ai/code) | AI agent — the gestoria brain |

---

## Prerequisites

- Docker Desktop (with WSL2 integration on Windows).
- A Spanish digital certificate (`.p12` / `.pfx`) for each empresa. Obtainable free from
  the FNMT via video-ID appointment.
- [Claude Code](https://claude.ai/code) for the AI autopilot layer (optional for CLI/manual modes).
- Basic comfort with the terminal.

---

## Quick start

```bash
git clone https://github.com/YOURUSER/verifactu-stack.git
cd verifactu-stack

cp .env.example .env
# Edit: set MARIADB_ROOT_PASSWORD and MARIADB_PASSWORD

cp secrets/companies.php.example secrets/companies.php
# Edit: add empresa NIF, cert path, cert password

cp ~/path-to/cert.p12 secrets/
chmod 600 .env secrets/companies.php secrets/*.p12

docker compose up -d --build
# First build: 3–5 minutes (PHP extensions, Composer, npm)
```

Open `http://localhost/` and complete FacturaScripts' first-run installer.

For a complete guided setup including FacturaScripts initialization, series/sequences,
and your first Verifactu test submission, open Claude Code in this directory and say:

> "Help me set up Verifactu Stack from scratch."

The agent will run the `onboarding` skill and walk you through every step.

---

## Skills — the agent's instruction set

The `skills/` directory contains Markdown files that tell the AI agent how to perform
specific tasks. Think of them as a team handbook: before starting a task, the agent reads
the relevant skill so it knows the correct steps, safety rules, and edge cases.

| Skill | What it covers |
|---|---|
| `onboarding.md` | First-time setup: Docker, FacturaScripts, series, certs, first submission |
| `stack-orientation.md` | Architecture, containers, bind-mounts, how the pieces connect |
| `database-schema.md` | Full table reference for FacturaScripts + sidecar tables |
| `command-safety.md` | What the agent runs freely vs. what needs explicit user approval |
| `incoming-invoice-agent.md` | Reading and importing supplier invoices from PDFs |
| `outgoing-invoice-agent.md` | Reading and importing customer invoices (facturas emitidas) |
| `tax-calendar-spain.md` | Filing deadlines for all relevant Spanish tax models |
| `filing-iva-303.md` | Modelo 303 quarterly IVA return — field-by-field guide |
| `filing-irpf-130.md` | Modelo 130 quarterly IRPF payment (autónomos) |
| `filing-sustitutiva.md` | Correcting a prior filing with a sustitutiva on SEDE |
| `rectificativa-por-error.md` | Issuing a corrective invoice (factura rectificativa) |
| `historical-import.md` | Migrating books from a prior gestor |

---

## Environments

The stack maintains two independent Verifactu chains in the same database:

- **Preproducción** — AEAT sandbox, no fiscal effect. Safe to test with real data.
- **Producción** — live AEAT. Every submission has permanent fiscal effect.

The agent defaults to preproducción and always asks for explicit confirmation before
touching producción. See `command-safety.md` for the full ruleset.

---

## Legal

Read [DISCLAIMER.md](DISCLAIMER.md) before running any submission against AEAT.

This project is not a certified Sistema Informático de Facturación (SIF) with a
declaración responsable filed at AEAT. Under Article 13 of RD 1007/2023, legal
responsibility for compliance rests entirely with the deployer.

The authors bear no responsibility for tax penalties, incorrect submissions, data loss,
or any other consequence of using this software. Everything is provided as-is.

---

## Philosophy

The author of this repository believes that gestores and accountants have to adapt to
artificial intelligence and accordingly work more efficiently, adjusting their prices
accordingly. I would personally prefer to hire a gestor who uses a system like this —
one that reduces the time it takes to perform bookkeeping tasks and passes those savings
on to the client.

This project is a bet that the right role for a professional accountant is review,
judgement, and client relationship — not data entry.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Credits

- **[josemmo/Verifactu-PHP](https://github.com/josemmo/Verifactu-PHP)** — MIT
- **[chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)** — MIT
- **[mpdf/mpdf](https://github.com/mpdf/mpdf)** — GPL-2.0-or-later
- **[FacturaScripts](https://github.com/NeoRazorX/facturascripts)** — LGPL

## License

This project's own code is released under the MIT license. See `LICENSE`.

Transitive dependencies (mpdf, FacturaScripts) carry LGPL/GPL obligations for
distribution. Running your own instance for your own business is unaffected.

---
---

# Verifactu Stack *(en español)*

**Una gestoría IA de código abierto y autoalojada para empresas y autónomos españoles.**

Importa facturas soltando PDFs en una carpeta. Presenta declaraciones trimestrales en
una conversación guiada con un agente de IA. Todo en tu propio hardware o servidor.
Sin suscripciones, sin que tus datos pasen por terceros.

---

## Visión

Un gestor tradicional cobra entre 150 y 500 €/mes por tareas que son, en esencia,
mecánicas: leer facturas, introducir números y presentar modelos a la AEAT. La mayor
parte de ese coste es tiempo humano dedicado a trabajo repetitivo que el software puede
realizar.

Verifactu Stack reemplaza esa capa mecánica con un agente de IA — [Claude Code](https://claude.ai/code)
— que lee PDFs, entiende la normativa fiscal española, introduce datos en FacturaScripts
y te guía paso a paso en las presentaciones ante la AEAT. Un humano — tú, o un gestor
que use este sistema — supervisa y aprueba cada acción relevante.

El sistema tiene tres modos de operación:

| Modo | Usuario | Qué ocurre |
|---|---|---|
| **Piloto automático IA** | Empresario con Claude Code | Suelta PDFs, chatea con el agente, presenta modelos guiado por la conversación |
| **CLI** | Usuario técnico | Ejecuta scripts PHP directamente contra la BD y la AEAT |
| **Manual** | Cualquiera | Usa directamente la interfaz web de FacturaScripts |

Los tres modos escriben en la misma base de datos y son completamente intercambiables.

---

## Qué cubre

- **Facturación** — emitidas y recibidas, incluyendo clientes extranjeros, multidivisa
  y operaciones exentas de IVA.
- **Verifactu** — registro en tiempo real de facturas en la AEAT (obligatorio desde 2027),
  con cadena de hashes y códigos QR en cada factura.
- **Declaraciones trimestrales** — Modelo 303 (IVA), Modelo 130 (IRPF, autónomos),
  Modelo 202 (IS pago fraccionado, SLs). Guiado por el agente IA.
- **Facturas rectificativas** — siguiendo el patrón legal correcto.
- **Multi-empresa** — varias SL y autónomos en la misma instalación, cada uno con su
  propio certificado y cadena Verifactu independiente.

---

## Inicio rápido

```bash
git clone https://github.com/YOURUSER/verifactu-stack.git
cd verifactu-stack

cp .env.example .env
# Edita: pon MARIADB_ROOT_PASSWORD y MARIADB_PASSWORD

cp secrets/companies.php.example secrets/companies.php
# Edita: añade NIF de la empresa, ruta al certificado y contraseña

cp ~/ruta/certificado.p12 secrets/
chmod 600 .env secrets/companies.php secrets/*.p12

docker compose up -d --build
```

Abre `http://localhost/` y completa el asistente de primera ejecución de FacturaScripts.

Para una guía completa, abre Claude Code en este directorio y di:

> "Ayúdame a configurar Verifactu Stack desde cero."

---

## Aviso legal

Lee [DISCLAIMER.md](DISCLAIMER.md) antes de ejecutar cualquier envío contra la AEAT.
Este proyecto no es un SIF certificado con declaración responsable presentada ante la
AEAT; la responsabilidad legal del cumplimiento es enteramente tuya.

---

## Filosofía

El autor de este repositorio cree que los gestores y contables tienen que adaptarse a
la inteligencia artificial y, en consecuencia, trabajar de forma más eficiente,
ajustando sus honorarios en la misma medida. Personalmente, preferiría contratar a un
gestor que use un sistema como este — uno que reduzca el tiempo necesario para llevar
mi contabilidad y que traslade ese ahorro al cliente.

Este proyecto apuesta a que el papel correcto del contable profesional es la revisión,
el criterio y la relación con el cliente — no la introducción de datos.
