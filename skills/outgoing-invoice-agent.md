---
name: outgoing-invoice-agent
description: >
  How to process an outgoing customer invoice (factura emitida) when the user
  uploads or pastes one in chat. Covers extraction, currency conversion, IVA
  exemption codes, and import via process-sale.php. Parallels incoming-invoice-agent.md
  but for ventas instead of compras.
triggers:
  - User uploads or mentions a customer invoice / factura emitida / factura de cliente
  - "import this sales invoice"
  - "add this income invoice"
  - "registra esta factura emitida"
  - Invoice is from a non-Spanish client (USD, GBP, etc.)
---

# Skill: Outgoing invoice — agent pipeline

## Overview

Customer invoices (facturas emitidas / facturas de cliente) are imported via
`process-sale.php`, which lives in `incoming/` alongside its supplier counterpart.
The script writes to `facturascli` + `lineasfacturascli` and maintains an
`outgoing_invoice_exports` audit table for SHA-256 deduplication.

Unlike supplier invoices, outgoing invoices often:
- Come from foreign clients (USD, GBP — must convert to EUR)
- Are IVA-exempt (services to non-EU businesses)
- Use a custom series and sequence per empresa

---

## Step 1: Locate and read the file

The invoice PDF must be on the host filesystem. Ask the user for the path, or suggest:

> "Drop the PDF into `incoming/pdfs/` and tell me the filename."

Read it with the Read tool:
```
Read("/home/<user>/verifactu-stack/incoming/pdfs/<filename>.pdf")
```

---

## Step 2: Extract the data

Produce this JSON from what you see:

```json
{
  "issuer": {
    "nif":  "B12345678",
    "name": "MI EMPRESA SL"
  },
  "client": {
    "name":         "ACME CORP",
    "nif":          "US-123456789",
    "address":      "123 Main St",
    "city":         "New York, NY 10001",
    "country_code": "US"
  },
  "invoice": {
    "number":        "2026-A001",
    "date":          "2026-01-29",
    "currency_orig": "USD",
    "exchange_rate": 1.1644,
    "total_orig":    3450.00,
    "due_date":      "2026-02-28"
  },
  "lines": [
    {
      "description":  "Professional services — January 2026",
      "quantity":     20.0,
      "unit_price":   128.82,
      "iva_rate":     0.0,
      "irpf_rate":    0.0,
      "excepcioniva": "ES_68_70"
    }
  ],
  "confidence": "high"
}
```

### Field rules

- `issuer.nif` — the empresa emitting the invoice (must match an `empresas.cifnif` row).
- `client.country_code` — ISO 3166-1 alpha-2 (`US`, `IL`, `GB`, etc.).
- `client.nif` — foreign tax ID; format as-is (no country prefix stripping needed here).
- `invoice.currency_orig` — original currency on the invoice (`USD`, `GBP`, `EUR`).
- `invoice.exchange_rate` — ECB rate on the invoice date (see Currency conversion below).
- `invoice.total_orig` — total in original currency.
- `lines[].unit_price` — **always in EUR** (already converted). The script stores EUR.
- `lines[].iva_rate` — 0.0 for non-subject / exempt operations.
- `lines[].excepcioniva` — exemption code (see IVA exemption section below).
- `confidence` — `"high"` / `"medium"` / `"low"`.

**Show the extracted data to the user and ask for confirmation before importing.**
Confirm that `issuer.nif` matches the correct empresa.

---

## Step 3: Currency conversion

When the invoice is in a foreign currency (USD, GBP, etc.):

1. Look up the **ECB reference rate on the invoice date** at:
   `https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/`
   (or ask the user to provide it)

2. Convert: `unit_price_EUR = unit_price_orig / exchange_rate`

3. Record in `observaciones`:
   ```
   USD 3450.00 converted to EUR at ECB rate 1.1644 USD/EUR (2026-01-29)
   ```
   Add any client reference after a pipe:
   ```
   USD 3450.00 converted to EUR at ECB rate 1.1644 USD/EUR (2026-01-29) | Ref. cliente: CLIENT_REF_001
   ```

If the invoice is already in EUR, `exchange_rate` = 1.0 and `currency_orig` = `"EUR"`.

---

## IVA exemption codes

| Code | Legal basis | When to use |
|---|---|---|
| `ES_68_70` | Art. 68-70 LIVA | Services provided to businesses outside the EU (US, Israel, UK post-Brexit, etc.) |
| `ES_20` | Art. 20 LIVA | Domestic exempt operations (health, education, insurance, etc.) |
| `ES_21` | Art. 21 LIVA | Exports of goods outside the EU |
| `ES_25` | Art. 25 LIVA | Intracomunitaria deliveries |

For professional services billed to non-EU companies (US, Israel, etc.):
**always use `ES_68_70`**.

To verify that an existing empresa uses `ES_68_70`, query:
```sql
SELECT excepcioniva, descripcion FROM lineasfacturascli
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
LIMIT 5;
```

---

## Step 4: Write the staging file

```bash
cat > /home/<user>/verifactu-stack/incoming/.agent-extract-sale.json << 'EOF'
{ ...the confirmed JSON... }
EOF
```

(The Write tool requires a prior Read on the path; use a bash heredoc instead.)

---

## Step 5: Import via process-sale.php

### Dry run first
```bash
docker compose exec app php /incoming/process-sale.php \
  --json-file=/incoming/.agent-extract-sale.json \
  --empresa=B12345678 \
  --dry-run
```

Review the output. If it looks correct, import for real:

```bash
docker compose exec app php /incoming/process-sale.php \
  --json-file=/incoming/.agent-extract-sale.json \
  --empresa=B12345678
```

### Historical import (from a prior gestor)

For invoices being migrated from a previous system that already have fixed numbers
and must not disturb the running Verifactu chain:

```bash
docker compose exec app php /incoming/process-sale.php \
  --json-file=/incoming/.agent-extract-sale.json \
  --empresa=B12345678 \
  --historical
```

`--historical` does two things:
1. Uses `invoice.number` from the JSON directly (bypasses `secuencias_documentos`)
2. Inserts an `exempt` row in `verifactu_submissions` so the invoice is never queued
   for AEAT submission

Use `--historical` for any invoice predating the start of the Verifactu chain.

---

## What the script does

1. SHA-256 dedup — safe to re-run; won't double-import the same invoice.
2. Upserts the client in `clientes` (tries by NIF, then by name).
3. Assigns the next sequence number via `SELECT FOR UPDATE` on `secuencias_documentos`
   (skipped when `--historical`).
4. Inserts into `facturascli` with `coddivisa='EUR'`, `tasaconv=1.0`.
5. Inserts lines into `lineasfacturascli` with `excepcioniva` set.
6. Writes `observaciones` with currency conversion note.
7. Records in `outgoing_invoice_exports` audit log.

---

## Verify the import

```bash
docker compose exec db mariadb -ufsuser \
  -p"$(grep MARIADB_PASSWORD ~/verifactu-stack/.env | cut -d= -f2)" \
  facturascripts \
  -e "SELECT codigo, fecha, nombrecliente, neto, totaliva, total, observaciones
      FROM facturascli ORDER BY idfactura DESC LIMIT 3;"
```

---

## Series and sequences

Each empresa has its own series and sequence. A common setup:

| Serie | Use | Environment |
|---|---|---|
| `A` | Production customer invoices | Producción |
| `R` | Rectificativas | Producción |
| `T` | Test invoices | Preproducción only |

If the sequence for `(empresa, serie A, year)` doesn't exist yet, create it in
FacturaScripts UI: **Admin → Secuencias de documentos → Nueva**.

Pattern example: `{EJE}-A{0NUM}` → produces `2026-A001`, `2026-A002`, etc.

The sequence is per-empresa (keyed by `idempresa` in `secuencias_documentos`), so
different empresas on the same install have independent counters.

---

## Verifactu and outgoing invoices

Outgoing invoices submitted to producción via `submit-pending.php` are included in
the Verifactu chain. Rules:
- Invoices with `--historical` are marked exempt and never queued.
- All other new invoices in serie `A` are automatically picked up by `submit-pending.php`.
- Do not run `submit-pending.php --env=produccion` immediately after a historical import
  without verifying the exempt rows are in place.

---

## Related skills

- `incoming-invoice-agent.md` — the supplier invoice counterpart.
- `database-schema.md` — `facturascli`, `lineasfacturascli`, `secuencias_documentos`.
- `historical-import.md` — full gestor migration workflow.
- `rectificativa-por-error.md` — correcting an issued customer invoice.
