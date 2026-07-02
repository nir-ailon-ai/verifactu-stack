---
name: command-safety
description: >
  Foundation skill. The safety rulebook for operating the Verifactu Stack.
  Classifies every action into safe-to-run, needs-user-confirmation, or
  forbidden. All task-level skills should route their operations through this
  classification.
triggers:
  - Read at the start of any session.
  - Reference before running any command that touches producción, secrets, or
    the certificate.
---

# Skill: Command safety

The Verifactu Stack manages real fiscal records once flipped to producción.
This skill exists to keep the agent from causing avoidable harm.

## The classification

Every action falls into one of three tiers:

### Tier 1 — Safe by default

The agent can run these without asking, provided they're within the current
task scope.

- `SELECT` from any table.
- Any `docker compose exec app php /verifactu/<script>.php --env=preproduccion` command.
- `--dry-run` flag on any script — never touches network.
- Any `docker compose logs`, `docker compose ps`.
- Reading files inside `/verifactu/` (not `/secrets/`).
- Generating PDFs (`make-invoice-pdf.php` with any `--env` — PDF generation itself has no fiscal effect).
- Regenerating a QR PNG from a stored URL (no AEAT call).
- Creating a customer, invoice, or line item **as a draft** in FacturaScripts.

### Tier 2 — Requires explicit user confirmation

The agent must present the specific action to the user and get an affirmative
before running. Confirmation must be per-run; a general "yes go ahead" from
earlier in the conversation does not carry forward.

- **Any command with `--env=produccion`**. Frame the confirmation clearly:
  > "About to submit invoice `<code>` to AEAT producción. This has real fiscal effect and cannot be undone. Confirm?"
- Marking a `rejected` row as `submitted` in `verifactu_submissions` to skip retries.
- `INSERT`, `UPDATE`, `DELETE` on FacturaScripts tables via SQL (bypassing the UI).
- Restarting a service (`docker compose restart <svc>`).
- Reloading the FS Dinamic cache (`Plugins::deploy()`).
- Adding new rows to `idsfiscales` or `paises`.
- Bumping `installation_number` in `secrets/companies.php`.
- Flipping `sif.environment` in `secrets/companies.php` from `preproduccion` to `produccion`.
- Modifying invoice `observaciones` for an invoice already submitted to producción.
- Creating a rectificativa in the UI (walk the user through it, but the click is theirs).
- Running `composer update` — it can silently pull a breaking library version.

### Tier 3 — Forbidden without human intervention

The agent should never execute these on its own initiative, even with a
general "you have permission". If the user explicitly requests one of these,
push back once, explain the risk, and only proceed if the user re-confirms
with awareness of what will happen.

- `DELETE FROM verifactu_submissions ...` — destroys the audit trail.
- `DROP TABLE`, `TRUNCATE TABLE`, `ALTER TABLE ... DROP COLUMN`.
- Modifying `hash`, `prev_hash`, or `hashed_at` values in the sidecar.
- Deleting an issued invoice from `facturascli` (as opposed to a draft).
- Writing anything to `/secrets/`, including `companies.php`.
- Reading and echoing the contents of any `.p12` file or cert password.
- Running the submitter for producción in a loop (batch of many invoices at once).
- `docker compose down -v` (removes named volumes → wipes DB).
- Force-pushing to git (`git push --force`).
- Committing `.env`, cert files, or a real (non-example) `companies.php`.

## Operational patterns

### Before any AEAT submission

Always dry-run first if the code path has changed since last successful run:

```
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion --dry-run
```

Then submit to preproducción. Then, and only after user confirmation, to producción.

### When a row keeps getting picked up as pending

The invoice is likely in `verifactu_submissions` with `status = 'rejected'` from a past attempt. Options in order of preference:

1. **Retry**: fix whatever caused the rejection, run submit-pending again. The `ON DUPLICATE KEY UPDATE` in `persist()` overwrites the same row.
2. **Skip**: if AEAT has already accepted this invoice (e.g. duplicate error) and we can't get the CSV back, mark it `submitted` with a note. Tier-2 action.
   ```sql
   UPDATE verifactu_submissions
   SET status='submitted', error_message=CONCAT('(handled) ', COALESCE(error_message,''))
   WHERE invoice_code='<CODE>' AND environment='<ENV>';
   ```
3. **Never** just `DELETE` the row.

### When something looks corrupt

- Don't try to fix by manual SQL against `facturascli` or `verifactu_submissions`.
- Snapshot the DB (`mysqldump`), preserve logs, then decide.
- If the corruption is in the local sidecar only, AEAT still has the source of truth (via ConsultaFactu). The user can rebuild from there.

### Secret handling

Secrets flow one-way: files on host → mounted read-only into container → read by PHP scripts. The agent's role:

- Confirm existence and readability with `ls -la` inside the container.
- Confirm file mode (`chmod 600` for `.p12` and `companies.php`).
- **Never** print the file contents.
- **Never** echo a cert password.
- **Never** commit anything from `/secrets/`.

### Testing discipline

- Preproducción: hammer freely. Anything you can do, do here first.
- Producción: one action at a time, each with confirmation.
- Series `T` invoices: never used in producción (safety filter enforces this).

## Escalation ladder

If the agent is unsure about a specific action:

1. Restate the intent in plain language to the user.
2. State which tier it falls into.
3. State the concrete side effect (row inserted, row updated, network call fired, fiscal record created).
4. Ask for confirmation with a specific phrasing that includes the identifier being touched.

Example:

> "You want me to submit `R-2026-001` and `2026-A027` to AEAT producción. Both currently have status `submitted` in preproducción only. Producción submission is Tier 2 — needs your explicit go-ahead. Two records will be permanently registered at AEAT. Confirm to proceed."

## When in doubt

Do less. A missed opportunity to help is recoverable; a wrong producción
submission is not.

## Related skills

- `stack-orientation.md` — background on the environments and services this ruleset governs.
- `database-schema.md` — the tables against which "safe" SQL is defined.
- Task-level skills call back to specific tier classifications here rather than restating them.
