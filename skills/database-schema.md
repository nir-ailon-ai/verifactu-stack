---
name: database-schema
description: >
  Foundation skill. Explains how to reach the database, what tables live
  there, how the FacturaScripts data model connects to our verifactu_submissions
  sidecar, and which columns are safe to read, safe to update, and never to
  touch. All task-level skills should assume this file has been read.
triggers:
  - "How do I query the invoices?"
  - "What tables are in the database?"
  - "Show me the customer schema"
  - Any workflow that requires SQL against FS or the sidecar.
---

# Skill: Database schema

## Connecting to the DB

MariaDB runs in the `db` service. The only correct way to talk to it from
outside the container is via `docker compose exec`:

```
docker compose exec db mariadb -ufsuser -p"$(grep MARIADB_PASSWORD ~/verifactu-stack/.env | cut -d= -f2)" facturascripts -e "SELECT ..."
```

Notes:
- `fsuser` is the application user (created on first boot by MariaDB env vars).
- The password comes from `.env` — resolve it inline as shown above, don't paste it.
- The database is always `facturascripts`.
- For multi-statement or interactive work, drop the `-e "..."` and it opens a REPL.

**Never use the `root` MariaDB account** for routine work. `fsuser` has the right grants scoped to the `facturascripts` database. Root is only for emergencies (creating users, altering grants).

## The two data domains

Two logical layers coexist in the same schema:

1. **FacturaScripts' native tables** — everything the FS UI operates on. Do NOT alter the schema of these; upgrades assume the shape FS ships. Reads and controlled UPDATEs on well-known columns are fine.
2. **Our sidecar** — `verifactu_submissions`. Owned by this project, safe to alter, safe to add columns to via migrations under `verifactu/*.sql`.

Always be clear which one you're querying.

## FacturaScripts core tables

### `empresas` — the issuing entities

The taxpayers in this installation. Each row is one SL / autónomo / other.

| Column | Meaning |
|---|---|
| `idempresa` (PK) | Internal ID. Referenced by everything else. |
| `cifnif` | Tax ID (`B00000000` for SLs, DNI for autónomos). This is the primary key used everywhere in Verifactu. |
| `nombre` | Legal name. |
| `direccion`, `codpostal`, `ciudad`, `provincia`, `codpais` | Registered address. |
| `email`, `telefono1` | Contact. |
| `idlogo` | Foreign key → `attached_files.idfile`. The logo image. |
| `personafisica` | `1` if autónomo, `0` if legal entity. |
| `regimeniva` | IVA regime. |
| `tipoidfiscal` | Type of fiscal ID (NIF, CIF, etc.). Rarely relevant. |

Safe operations:
- `SELECT` freely.
- `UPDATE` only via FS UI. Direct SQL updates risk breaking FS's caches.

### `clientes` — customers

| Column | Meaning |
|---|---|
| `codcliente` (PK, string) | Customer code. |
| `cifnif` | Customer's tax ID. For foreign customers, their national or intra-EU VAT ID. |
| `nombre`, `razonsocial` | Name. |
| `tipoidfiscal` | ID type: `NIF`, `NIF-IVA`, `Pasaporte`, `Documento oficial`, `Cert. residencia`, `Otro doc.`. Rows come from the `idsfiscales` table. Custom types can be added there. |
| `personafisica` | Individual vs. company. |
| `regimeniva` | Their IVA regime. |
| `codpais` | Their country. FS stores 3-letter codes (`ESP`, `ISR`, `DEU`). Our submitter translates to ISO 3166-1 alpha-2 automatically. |

The address (calle, código postal, país) lives in `contactos`, not `clientes` — clientes only has the ID/name/tax fields.

Safe operations:
- `SELECT` freely.
- `UPDATE tipoidfiscal, cifnif` via FS UI only; via SQL, only when correcting a data entry error and never for a customer with issued invoices in producción.

### `facturascli` — customer invoices (main invoicing table)

The heart of Spanish invoicing lives here. Each row is one factura de cliente.

| Column | Meaning | Notes for the agent |
|---|---|---|
| `idfactura` (PK) | Internal auto-increment. | Not the invoice number. |
| `codigo` | Formatted invoice code — what the customer sees ("2026-A024"). | Built by the secuencia's pattern. |
| `codserie` | Series (`A`, `T`, `R`, ...). | Used for tax classification and safety filters. |
| `numero` | Bare counter, integer (24). | Not customer-facing. |
| `codejercicio` | Fiscal year. | |
| `codigorect`, `idfacturarect` | Reference to the invoice this one rectifies. Non-null → this is a rectificativa. | Both set by "Devoluciones" or manual entry. |
| `codcliente` | Link to `clientes`. | |
| `cifnif` | Recipient tax ID (denormalized copy of clientes.cifnif at creation time). | This is what our submitter sends as recipient NIF. |
| `nombrecliente` | Recipient name (denormalized). | |
| `codpais` | Recipient country (denormalized). | Drives Spanish vs. foreign identifier logic in submitter. |
| `fecha` | Invoice date. | Legally the fecha de expedición. |
| `neto` | Total base imponible. | |
| `totaliva`, `totalirpf`, `totalrecargo` | Tax amounts. | `totalirpf` is IRPF retention withheld. |
| `total` | Final total to charge. | |
| `observaciones` | Free-text notes. Appears on the PDF via our generator. | Used for rectificativa reason text. |
| `idempresa` | Which empresa issued it. | |
| `idestado` | Status: draft, issued, paid, cancelled. | FS-internal enum. |
| `pagada` | Boolean shortcut. | |

Safe operations:
- `SELECT` freely.
- `UPDATE observaciones` occasionally OK (adding a note post-hoc).
- **Never** delete a row that has a corresponding `submitted` entry in `verifactu_submissions` for producción. That would create a fiscal ghost.
- Draft invoices (idestado = draft) can be deleted from the FS UI. Issued invoices should be corrected via rectificativa, not deleted.

### `lineasfacturascli` — invoice line items

Each row is one line of one invoice.

| Column | Meaning |
|---|---|
| `idlinea` (PK) | |
| `idfactura` | FK to `facturascli`. |
| `cantidad` | Quantity. |
| `pvpunitario` | Unit price before discount. |
| `pvpsindto` | Price before discount (per unit × qty). |
| `dtopor` | Discount percentage. |
| `pvptotal` | Line total (net of discount, before tax). |
| `iva` | IVA rate on this line (e.g. 21). |
| `irpf` | IRPF retention rate on this line (e.g. 15). |
| `recargo` | Recargo de equivalencia rate. |
| `excepcioniva` | Exemption code: `ES_20`, `ES_21`, `ES_25`, `ES_68_70`, `ES_84`, etc. Drives the AEAT operation type. See rectificativa skill or the source of `operationTypeForExempt()` in `submit-pending.php`. |
| `referencia`, `descripcion` | What the customer bought. |

Safe operations:
- `SELECT` freely.
- Line-level `UPDATE`s: only through the FS UI.

### `series` and `secuencias_documentos`

- `series` — the invoice series themselves (`A`, `R`, `T`).
- `secuencias_documentos` — the pattern + counter per `(serie, ejercicio, tipo_documento)` combo. Where you configure `{EJE}-{0NUM}` etc.

Rarely queried by the agent directly. Adjustments happen through FS UI.

### `idsfiscales` — tax ID types

Small lookup table. Rows are pulled into the `tipoidfiscal` dropdown when editing a customer.

Default seed contains only Spanish types (NIF, CIF, DNI, NIE, Pasaporte, VAT). If invoicing foreign customers, add rows for `Documento oficial`, `Cert. residencia`, `Otro doc.` (names must contain specific keywords our `mapForeignIdType()` recognizes — see `submit-pending.php`).

### `attached_files`

FS's generic file storage. Referenced by `empresas.idlogo` for the logo.

| Column | Meaning |
|---|---|
| `idfile` (PK) | |
| `filename` | Original filename. |
| `mimetype` | e.g. `image/png`. |
| `path` | Path relative to FS root. Concrete file at `/var/www/html/facturas/<path>`. |
| `size`, `date`, `hour` | Metadata. |

### Accounting (contabilidad) tables

These are only relevant when the empresa is set to auto-post asientos.

- `ejercicios` — fiscal years.
- `cuentas` — parent accounts in the plan general contable.
- `subcuentas` — leaf accounts, one per (empresa, ejercicio, codigo).
- `asientos` — journal entries (libro diario rows).
- `partidas` — individual lines of each asiento (each debe / haber against a subcuenta).

For libro diario reports, join `asientos` and `partidas` filtered by `codejercicio`. For libro mayor, group by `codsubcuenta` and order by fecha.

### `recibospagoscli` — receipts / vencimientos

Each row is one payment schedule entry against an invoice.

| Column | Meaning |
|---|---|
| `idrecibo` (PK) | |
| `idfactura` | FK to facturascli. |
| `numero` | 1, 2, 3 for multi-installment invoices. |
| `codpago` | Payment method code. |
| `importe` | Amount due. |
| `vencimiento` | Due date. |
| `fechapago` | Actual payment date. |
| `pagado`, `vencido` | Booleans. |

## Our sidecar table

### `verifactu_submissions`

Our own creation. Tracks every submission attempt per invoice per AEAT environment.

| Column | Meaning | Safe to update? |
|---|---|---|
| `id` (PK auto) | | Never. |
| `empresa_nif` | Issuer NIF. | Never. |
| `environment` | `preproduccion` or `produccion`. | Never (would corrupt chain). |
| `idfactura` | FK to `facturascli.idfactura`. | Never. |
| `invoice_code` | Copy of `facturascli.codigo` at submission time. | Never. |
| `issue_date` | Copy of the invoice's fecha. | Never. |
| `total_amount` | Copy of the invoice's total. | Never. |
| `status` | `pending`, `submitted`, `rejected`. | Yes — safe to flip to force a retry, or to `submitted` to skip a stuck row. |
| `csv` | AEAT confirmation code, set on success. | Rarely — only if you're manually reconciling with AEAT's ConsultaFactu. |
| `hash` | SHA-256 hash of this record. Anchor of the chain. | **Never.** Modifying breaks the chain. |
| `hashed_at` | ISO datetime the hash was computed. | Never. |
| `qr_url` | The AEAT URL the QR encodes. | Rarely — only if regenerating QR after fixing a bug. |
| `qr_png_path` | Path on disk to the PNG. | Rarely. |
| `prev_invoice_code`, `prev_invoice_date`, `prev_hash` | Reference to the previous invoice in the chain. | Never. |
| `error_message` | If rejected, AEAT's error text. | Yes — safe to prefix with "(handled)" when acknowledging. |
| `created_at`, `submitted_at` | Timestamps. | Never. |

**Key insight**: the chain is per `(empresa_nif, environment)`. Look up the tail
by ordering the latest `status = 'submitted'` row for that pair. The submitter
does this every run.

**Never `DELETE` from this table.** Even a rejected row is evidence for future debugging. If you truly need to clear it, do so via a migration script in `verifactu/*.sql` with explicit justification in the SQL comments.

## Common queries the agent will run

**List invoices pending in producción for the SL:**
```sql
SELECT f.idfactura, f.codigo, f.fecha, f.total, f.cifnif, f.nombrecliente
FROM facturascli f
INNER JOIN empresas e ON e.idempresa = f.idempresa
LEFT JOIN verifactu_submissions vs
       ON vs.idfactura = f.idfactura
      AND vs.status = 'submitted'
      AND vs.environment = 'produccion'
WHERE vs.id IS NULL
  AND e.cifnif = '<EMPRESA_NIF>'
ORDER BY f.fecha, f.idfactura;
```

**Show the chain tail (last accepted invoice) for an empresa in an environment:**
```sql
SELECT invoice_code, issue_date, csv, hash, submitted_at
FROM verifactu_submissions
WHERE empresa_nif = '<EMPRESA_NIF>' AND environment = '<ENV>' AND status = 'submitted'
ORDER BY submitted_at DESC, id DESC
LIMIT 1;
```

**Reconstruct the libro diario for the current year:**
```sql
SELECT a.fecha, a.numero, a.concepto, p.codsubcuenta, p.debe, p.haber
FROM asientos a
JOIN partidas p ON p.idasiento = a.idasiento
WHERE a.codejercicio = '2026'
ORDER BY a.fecha, a.numero, p.orden;
```

**Find the observaciones text on a rectificativa:**
```sql
SELECT codigo, codigorect, observaciones
FROM facturascli
WHERE codserie = 'R' AND codigo = '<R_CODE>';
```

## What the agent should never do

- `DROP TABLE` anything.
- `TRUNCATE` anything.
- `DELETE FROM verifactu_submissions` for any reason.
- `UPDATE facturascli SET codigo = ...` (breaks the AEAT chain reference).
- Modify a hash, prev_hash, or hashed_at in the sidecar.
- Modify the schema of FS's tables (adding columns to `facturascli` etc.).
- Run queries as root MariaDB.

## Related skills

- `stack-orientation.md` — the containers, files, and environments this DB lives in.
- `command-safety.md` — which SQL is safe and which needs human confirmation.
- Task-level skills reference specific tables from here.
