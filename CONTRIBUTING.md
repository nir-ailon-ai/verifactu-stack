# Contributing to Verifactu Stack

Thanks for looking. This document explains the direction the project is heading
and how to help.

## The long-term vision

**An automatic gestor for autónomos and small companies in Spain**, running
entirely on the taxpayer's own infrastructure, with no third-party SaaS fees
and no data leaving the owner's control.

Verifactu compliance is the first piece of that vision because it's the most
imminent legal requirement (mandatory from January 2027 for sociedades and
July 2027 for autónomos). But the goal is broader: over time this project
should cover most of what a small business currently pays a monthly gestor
to handle.

Concrete areas we want to grow into:

- **Verifactu SIF** ✓ (current state — invoice registration, hash chain, QR
  codes, rectificativas).
- **Monthly / quarterly IVA** — Modelo 303 automatic preparation from `verifactu_submissions` + invoice data.
- **Annual IVA summary** — Modelo 390.
- **IRPF retentions** — Modelos 111 (professional retentions), 190 (annual
  summary), 115/180 (rent retentions).
- **Corporate tax** — Modelo 200 for sociedades (harder; involves accounting adjustments).
- **Third-party summary** — Modelo 347.
- **Intra-EU operations** — ROI registration, Modelo 349.
- **Cuentas anuales** — depósito en el Registro Mercantil.
- **Payroll** — nóminas, TC1/TC2 (or RNT/RLC), contracts.
- **Notifications / requerimientos** — auto-detect and surface AEAT
  communications rather than letting them sit unread in the sede.

Each of these is a discrete contribution opportunity.

## AI agent skill files

An intentional part of the vision is that a Spanish business owner should be
able to **converse with an AI agent** ("run modelo 303 for this quarter", "issue
a rectificativa on invoice 2026-A024 because I forgot the retention", "what's
my forecast IVA payable at the end of Q3?") and have the agent operate the
stack correctly.

To that end, contributions of **skill files** (markdown documents describing
how to perform specific tasks against this stack) are especially welcome. Live
under `skills/` (create the directory when contributing your first).

Each skill should:

- **Name and describe the task** in plain language, in both Spanish and English
  ideally.
- **List preconditions** — what state must the FS DB be in, what config keys
  must exist, what user permissions are needed.
- **Provide step-by-step operations** an agent should perform, ideally as
  concrete `docker compose exec` commands, SQL queries, or FS UI actions.
- **Describe expected outputs** — what "success" looks like, what error patterns
  to recognize and how to recover.
- **Flag danger** — anything with real fiscal effect (`--env=produccion`,
  irreversible submissions, deletions of tracked invoices) should be explicit.

Example skill categories we'd love PRs for:

- `create-invoice.md` — walking an agent through creating an invoice for a customer.
- `rectificativa-por-error.md` — handling corrections after the fact.
- `iva-quarterly.md` — building modelo 303 numbers from the sidecar and FS data.
- `payment-received.md` — marking an invoice as paid and reconciling the bank line.
- `nuevo-cliente-extranjero.md` — onboarding a non-EU customer with the right tax settings.

Skills should be **safe by default**: never invoke `--env=produccion` without
an explicit confirmation step the agent must obtain from the human. Never
delete rows from `verifactu_submissions`. Never modify the digital
certificate. When in doubt, escalate.

## Contributing code

Standard flow:

1. Fork the repo.
2. Create a feature branch.
3. Make your change locally against `--env=preproduccion` — never against real
   producción.
4. Test end-to-end: submit a few invoices, verify AEAT accepts, verify PDFs
   render, verify the sidecar row is correct.
5. Open a PR with a description of what changed, why, and how you tested.

### Things to include

- Bug fixes.
- New modelo submitters, wired to the sidecar + FS.
- New CLI flags or options that make the current tools more flexible.
- Documentation improvements (README, comments, examples).
- Skill files as described above.

### Things not to include

- **Real cert files, real passwords, real tax IDs.** Sanitize everything.
  When in doubt, use `B00000000`, `NOMBRE APELLIDOS`, `EMPRESA EJEMPLO SL`.
- Real customer data in test invoices you commit as examples.
- Anything that submits to producción without an explicit user gate.
- Vendor dependencies (`composer install` / `npm install` run at build time; don't commit `vendor/` or `node_modules/`).

### Testing

The current test discipline is manual and preproducción-only:

1. Create a test invoice in FacturaScripts, series `T`.
2. Run `docker compose exec app php /verifactu/submit-pending.php --env=preproduccion`.
3. Verify accepted CSV comes back.
4. Run `docker compose exec app php /verifactu/make-invoice-pdf.php <code> --env=preproduccion`.
5. Open the PDF, scan the QR, verify AEAT preproducción responds.

Automated tests (PHPUnit against mocked AEAT responses, docker-compose smoke
tests via GitHub Actions) are on the wishlist — happy to accept PRs that
introduce them.

### Code style

- PHP 8.2+ features are fair game (enums, match, readonly, first-class callable syntax, etc.).
- Prefer property assignment over positional constructor args (avoids the
  library-version-drift issues we hit early on).
- Comments in English or Spanish are both fine — this is a Spanish tax project,
  and Spanish terminology often reads clearer for anyone actually maintaining it.
- Match the existing style: `?:` for defaults, `match ()` for enum dispatch,
  named PDO placeholders, one blank line between logical sections.

### Documentation

- If you add a new CLI flag, update the README's usage section.
- If you add a new empresa-level config knob, update `secrets/companies.php.example`.
- If your PR requires DB migration, add a `.sql` file under `verifactu/`
  with idempotent statements (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... IF NOT EXISTS`, etc.).

## Reporting issues

Include:

- The command you ran and its exact output (including error messages).
- Whether you were in preproducción or producción.
- Your empresa NIF format (SL / autónomo / other) — not the actual value.
- Which library versions you have (`docker compose exec app cat /verifactu/composer.lock | grep -A 1 verifactu-php`).

Do **not** include:

- Actual cert files or their passwords.
- Real customer NIFs (redact them).
- Real CSVs from producción submissions (those are fiscal identifiers).

## Legal reminder

This project is not a certified SIF with a declaración responsable filed at
AEAT. Anyone deploying it is responsible for compliance. Contributors should
understand that changes affecting the AEAT submission path — the hash
calculation, the fields sent in a `RegistrationRecord`, the environment
selection logic — need especially careful review because a mistake here
translates directly into incorrectly filed tax records for the deployer.

When you're not sure, mark the PR as draft and ask for review before merging.

## Governance

Right now the project has one maintainer. As it grows, decision-making should
happen openly on issues and PRs. If you want to become a co-maintainer,
demonstrate it through consistent, thoughtful contributions and just ask.

## Thanks

Every improvement — a bug fix, a modelo submitter, a translated skill file,
better docs — moves Spanish self-hosted business software forward. If you're
scratching your own itch, you're probably helping a lot of other autónomos too.
