---
name: historical-import
description: >
  How to migrate books from a departing gestor into this stack. Covers what
  documents to request, opening balance entry, fixed asset register, importing
  historical invoices without disturbing the running Verifactu chain, and
  reconciling against prior filed tax returns.
triggers:
  - "migrating from another gestor"
  - "import historical invoices"
  - "transfer from prior accounting software"
  - "cierre del ejercicio anterior"
  - Starting a gestor migration for any empresa
---

# Skill: Historical import — migrating from a prior gestor

## Overview

When taking over books from a departing gestor, the goal is to enter the historical
data cleanly so that:

1. The books balance correctly from the opening date.
2. Historical invoices carry their original numbers (no renumbering).
3. Historical invoices do NOT enter the Verifactu chain (they predate it).
4. The system can continue issuing new invoices and filing taxes from the cutover date.

---

## Step 1: Documents to request from the departing gestor

Request all of the following. Be specific — vague requests get vague deliveries.

### Accounting close

- **Balance de situación** at 31-Dec of the last closed year (e.g. 31-Dec-2025)
- **Cuenta de pérdidas y ganancias** for the last closed year
- **Sumas y saldos** (trial balance) at 31-Dec of the last closed year
- **Libro diario** (journal) for the last closed year — ideally as a CSV/Excel export
  from their accounting software (A3, Sage, Contaplus, etc.)

### Fixed assets

- **Fichero de inmovilizado** (fixed asset register) — the most important document
  for companies with real estate or equipment. Must include:
  - Asset description
  - Acquisition date
  - Original cost (broken down: land vs. building for real estate)
  - Depreciation method and annual rate
  - Accumulated depreciation to 31-Dec of the last closed year
  - Net book value (valor neto contable)

### Invoices

- All **facturas emitidas** (customer invoices) from January of the current year to
  the handover date, in PDF format
- All **facturas recibidas** (supplier invoices) for the same period
- The invoice numbering series in use (so we can continue without gaps)

### Tax filings

- Copies of all **models filed** in the current year to date: 303 (each quarter),
  130 or 202 (each quarter), 111/115 if applicable
- The **justificante** (AEAT confirmation number) for each filed model
- The **compensation carryforward** from the last 303 (casilla [66] of the last quarter)

### Open items at handover date

- Outstanding **accounts receivable** (facturas emitidas not yet paid)
- Outstanding **accounts payable** (facturas recibidas not yet paid)
- **Bank balances** on the handover date
- Any **pending tax payments or credits** at AEAT

---

## Step 2: Enter the opening balance in FacturaScripts

The opening balance represents the financial state of the empresa at the start of
the migration period (e.g., 1-Jan-2026 if migrating mid-year).

In FacturaScripts: **Contabilidad → Asientos → Nuevo asiento**.

Create an opening asiento (asiento de apertura) dated 1-Jan of the migration year:
- Debit (Debe) all asset accounts to their opening balance values
- Credit (Haber) all liability and equity accounts

Standard account codes follow Spain's Plan General Contable (PGC):
- 100-199: Non-current assets (210 = Land, 211 = Buildings, 213 = Machinery)
- 200-299: Intangible assets
- 300-499: Current assets (430 = Clients, 572 = Bank)
- 100-119: Equity (100 = Capital social, 129 = Resultado ejercicio anterior)
- 400-499: Liabilities (410 = Suppliers, 475 = AEAT, 476 = SS)

Ask the user to provide the trial balance figures. Enter each account as a line
in the asiento. The asiento must balance (Debe = Haber).

---

## Step 3: Fixed asset register — real estate and improvements

For each fixed asset in the register, enter a dedicated asiento reflecting its
book value at the opening date. Then set up the annual depreciation entry.

### For a building (not land)

Key figures needed from the gestor's fichero de inmovilizado:
- **Land cost** (not depreciated)
- **Building cost** (depreciated)
- **Improvement/renovation cost** (may use different rate than building)
- **Accumulated depreciation** to opening date

Standard depreciation rates (tabla de amortización simplificada, IRPF/IS):
| Asset type | Max annual rate |
|---|---|
| Buildings (estructural) | 3% |
| Electrical installations | 10% |
| HVAC systems | 12% |
| Decorative fixtures | 15% |

Annual depreciation entry (Asiento de amortización):
- Debit 681 (Amortización del inmovilizado material)
- Credit 281 (Amortización acumulada del inmovilizado material)

Amount = cost × annual rate. For a mid-year start, prorate by months.

Renovation works are a separate asset (or sub-asset) with their own rate based on
the type of work. The gestor's fichero should have them classified.

### Rental income from owned property

If the empresa leases its real estate to a third party:
- Issue rental invoices in FacturaScripts (monthly, with IVA if commercial property)
- The IVA on rental income appears in 303 devengado [01]/[02]
- The tenant (if an empresa) withholds IRPF via Modelo 115 — the landlord empresa
  does NOT file 115; the tenant does. The empresa receives a retenciones certificate
  from the tenant at year-end, which becomes a credit on Modelo 200.

---

## Step 4: Import historical invoices

### Flag in process-sale.php / process-invoice.php

Use the `--historical` flag when importing any invoice that:
- Predates the start of the Verifactu chain, OR
- Has a fixed number that must not be changed, OR
- Should not be submitted to AEAT (already handled by the prior gestor's SIF, or
  predating the Verifactu obligation)

```bash
# Historical customer invoice
docker compose exec app php /incoming/process-sale.php \
  --json-file=/incoming/.agent-extract-sale.json \
  --empresa=B12345678 \
  --historical

# Historical supplier invoice
docker compose exec app php /incoming/process-invoice.php \
  /incoming/pdfs/factura-ene-2026.pdf \
  --json-file=/incoming/.agent-extract.json \
  --empresa=B12345678 \
  --historical
```

`--historical` does two things:
1. Uses the invoice number from the JSON as-is (no sequence counter touched)
2. Inserts a row in `verifactu_submissions` with `status='exempt'` so the invoice
   is permanently skipped by `submit-pending.php`

### Import order

Import invoices in chronological order (oldest first) so that sequence counters —
if accidentally touched — advance in the correct direction.

### Collision check before importing

Before starting the batch import, verify that the historical invoice numbers do not
collide with any invoices already in the system:

```sql
SELECT codigo FROM facturascli
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND codigo IN ('<CODE1>', '<CODE2>', '<CODE3>');
```

If any collide, investigate before importing. Never silently overwrite.

---

## Step 5: Reconcile against prior tax filings

After importing all historical invoices, verify that the numbers match what the
prior gestor declared.

### 303 reconciliation

For each quarter the gestor filed:

```sql
-- Sum of outgoing invoice IVA for a quarter
SELECT SUM(neto) AS base, SUM(totaliva) AS iva
FROM facturascli
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<Q_START>' AND '<Q_END>';

-- Sum of incoming invoice IVA for the same quarter
SELECT SUM(neto) AS base, SUM(totaliva) AS iva
FROM facturasprov
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<Q_START>' AND '<Q_END>';
```

Compare these figures to the gestor's filed 303. Discrepancies indicate:
- Invoices missing from the import (ask gestor for the missing ones)
- Invoices in the wrong period
- Expenses the gestor included that don't have corresponding invoices (e.g., Uber
  receipts mixed in — evaluate whether to keep or remove)

### 130 reconciliation

For each quarter:
```sql
SELECT SUM(neto) AS ingresos FROM facturascli
WHERE fecha BETWEEN '2026-01-01' AND '<Q_END>'
  AND idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>');

SELECT SUM(neto) AS gastos FROM facturasprov
WHERE fecha BETWEEN '2026-01-01' AND '<Q_END>'
  AND idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>');
```

Compare to the gestor's filed 130 [01] and [02]. The 130 is cumulative, so use
Jan 1 to the end of each quarter.

---

## Step 6: Determine the Verifactu cutover date

Confirm with the user the first invoice in the current system that was (or should be)
submitted to AEAT via Verifactu. All invoices before that date get `--historical`.
All invoices from that date forward go into the normal `submit-pending.php` flow.

Query to find the earliest submitted invoice in the chain:
```sql
SELECT invoice_code, issue_date, status, environment
FROM verifactu_submissions
WHERE empresa_nif = '<NIF>' AND status = 'submitted'
ORDER BY issue_date ASC LIMIT 1;
```

Invoices issued before `issue_date` → use `--historical`.

---

## Step 7: Continue from the cutover date

Once the opening balance is entered, all historical invoices are imported, and
reconciliation passes:

1. Create new invoices in FacturaScripts normally (series `A`, `R` as applicable).
2. Run `submit-pending.php --env=preproduccion` after each batch, then producción
   after user confirmation.
3. File 303 and 130/202 for the current quarter using the imported data as the basis.

---

## Related skills

- `outgoing-invoice-agent.md` — JSON schema and `--historical` flag details.
- `incoming-invoice-agent.md` — supplier invoice import.
- `filing-iva-303.md` — 303 after migration.
- `filing-irpf-130.md` — 130 after migration.
- `database-schema.md` — query patterns for reconciliation.
