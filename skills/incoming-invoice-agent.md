---
name: incoming-invoice-agent
description: >
  How to process an incoming supplier invoice when the user uploads or pastes
  one in chat. Covers the two parallel pipelines — CLI (API key required) and
  agent-native (no API key, extraction done in conversation) — and when to
  suggest upgrading to the API-key path.
triggers:
  - User uploads, pastes, or mentions an incoming invoice / factura de proveedor
  - "process this invoice"
  - "add this bill"
  - "registra esta factura de proveedor"
  - User drops a PDF or image in the conversation and asks for it to be recorded
---

# Skill: Incoming invoice — agent pipeline

## Two parallel pipelines

Both pipelines write to the same FacturaScripts tables and share the same
SHA-256 dedup logic, so they can be used interchangeably on the same install.

| | CLI pipeline | Agent pipeline (this skill) |
|---|---|---|
| **Trigger** | User runs `process-invoice.php` | User chats / uploads in conversation |
| **Extraction** | Claude API call (separate HTTP request) | Claude reads file in conversation context |
| **Requires API key** | Yes (`ANTHROPIC_API_KEY` in `.env`) | No |
| **Cost per invoice** | ~€0.01 | ~€0.03–0.08 (conversation context overhead) |
| **Best for** | Batch, scheduled, high-volume | Ad-hoc, first-time users, no key yet |

The agent pipeline costs more because the full conversation context counts
toward tokens on every turn. For occasional use it's fine; above ~30 invoices/month
the API key easily pays back the five-minute setup effort.

---

## Agent pipeline — step by step

### Step 1: Locate the file

The invoice file must be readable by the Read tool on the host filesystem.
Ask the user for the path, or suggest:

> "Please drop the PDF into `incoming/pdfs/` on your machine (the same folder
> as the project). Then tell me the filename and I'll handle the rest."

If the user pasted an **image** directly into the chat (no file path), ask them
to save it first — the DB write step needs a file path for deduplication.

If the stack is on a remote server, the user can `scp` or `rsync` the file into
`incoming/pdfs/` on the server and give you the remote path.

### Step 2: Read the file

Use the Read tool on the full host path, e.g.:

```
Read("/home/<user>/verifactu-stack/incoming/pdfs/factura-enero.pdf")
```

Claude Code reads PDFs and images multimodally — you will see the invoice.

### Step 3: Extract the data

From what you see in the document, produce this JSON object:

```json
{
  "recipient": {
    "nif":  "B98765432",
    "name": "EMPRESA COMPRADORA SL"
  },
  "supplier": {
    "nif":         "B12345678",
    "name":        "EMPRESA PROVEEDORA SL",
    "address":     "Calle Mayor 1, 2º",
    "postal_code": "28001",
    "city":        "Madrid",
    "email":       null,
    "phone":       null
  },
  "invoice": {
    "number":   "2024-001",
    "date":     "2024-01-15",
    "due_date": null
  },
  "lines": [
    {
      "description": "Consultoría mensual",
      "quantity":    1.0,
      "unit_price":  1000.00,
      "iva_rate":    21.0,
      "irpf_rate":   15.0
    }
  ],
  "confidence": "high"
}
```

Field rules:
- `recipient`: the company or person the invoice is billed **to** (the buyer / destinatario — NOT the issuer). Extract their NIF and name.
- `recipient.nif` and `supplier.nif`: digits and letters only, no formatting, no country prefix (e.g. `"B12345678"` not `"ESB-12.345.678"`). Use `null` if not legible.
- `date` / `due_date`: YYYY-MM-DD. Use `null` when absent.
- `irpf_rate`: 0.0 when IRPF is not shown.
- `confidence`: `"high"` = all fields clearly legible; `"medium"` = some uncertainty; `"low"` = poor scan / key fields missing.

**Always show the extracted data to the user and ask for confirmation before
writing to the database.** Specifically confirm that the `recipient` matches the
empresa it should be imported for — this is the key safety check.

If confidence is `"low"`, flag the uncertain fields explicitly and ask the user
to verify them before proceeding.

### Step 4: Write the extracted JSON to a staging file

Use the Write tool to save the confirmed JSON to the staging location:

```
Write("/home/<user>/verifactu-stack/incoming/.agent-extract.json", <json>)
```

(This file is inside the bind-mounted `incoming/` directory, so it appears at
`/incoming/.agent-extract.json` inside the container.)

### Step 5: Import to FacturaScripts

```bash
docker compose exec app php /incoming/process-invoice.php \
  /incoming/pdfs/<filename> \
  --json-file=/incoming/.agent-extract.json
```

The script auto-selects the empresa by matching `recipient.nif` from the JSON
against the `empresas` table — so no `--empresa=` flag is needed as long as
`recipient.nif` is correctly extracted.

Add `--empresa=<NIF>` only to override (e.g. recipient NIF was unreadable).
Add `--dry-run` to preview without writing.

The script will:
1. SHA-256 dedup — safe to re-run; won't double-import
2. Upsert supplier in `proveedores` (creates a new row if NIF is new)
3. Assign the next internal sequence number (`FP-YYYY-NNNNN`)
4. Insert into `facturasprov` and `lineasfacturasprov`
5. Record in `incoming_invoice_imports` audit log

### Step 6: Suggest the API key (if not configured)

After a successful import, check whether the API key is set:

```bash
docker compose exec app printenv ANTHROPIC_API_KEY | head -c 10
```

If the output is empty, deliver this message to the user (adapt the tone):

> "Done — invoice imported. One note: each time you want to add an invoice
> this way, it costs a little more than the automated path (roughly €0.03–0.08
> vs €0.01 per invoice) because I need to read your whole conversation to
> process each request.
>
> If you'd like to cut that cost, add an Anthropic API key to your `.env`:
>
> ```
> ANTHROPIC_API_KEY=sk-ant-...
> ```
>
> Then restart the stack (`docker compose restart app`) and you can process
> invoices with a single command:
>
> ```bash
> docker compose exec app php /incoming/process-invoice.php /incoming/pdfs/<file>
> ```
>
> You can get an API key at console.anthropic.com — free tier included."

Do **not** repeat this suggestion every run. Once per session is enough.

---

## CLI pipeline — quick reference

When the user has `ANTHROPIC_API_KEY` configured, the fully automated path is:

```bash
# Single invoice
docker compose exec app php /incoming/process-invoice.php /incoming/pdfs/factura.pdf

# Entire directory
docker compose exec app php /incoming/process-invoice.php --dir=/incoming/pdfs

# Dry run (see what would be imported, no DB writes)
docker compose exec app php /incoming/process-invoice.php /incoming/pdfs/factura.pdf --dry-run
```

Review the import log:
```bash
docker compose exec app php /incoming/list-imports.php
docker compose exec app php /incoming/list-imports.php --status=error
```

The sidecar table (run once to create):
```bash
docker compose exec db mariadb -ufsuser \
  -p"$(grep MARIADB_PASSWORD ~/verifactu-stack/.env | cut -d= -f2)" \
  facturascripts < incoming/setup-sidecar.sql
```

---

## Related skills

- `database-schema.md` — `proveedores`, `facturasprov`, `lineasfacturasprov` table reference
- `stack-orientation.md` — container layout, bind mounts, `docker compose exec` patterns
