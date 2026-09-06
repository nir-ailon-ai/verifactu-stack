---
name: gestoria-document-store
description: >
  How the MinIO-backed document archive works: bucket/key convention, the
  gestoria upload UI and its JSON API, and the "notas del gestor" note-taking
  convention. Read before looking for, storing, or reasoning about any
  scanned document, filing receipt, or gestor note for an empresa.
triggers:
  - "is this document already stored?"
  - "find the [invoice/receipt/notification/acta] for <empresa>"
  - "upload this to MinIO" / "put this in the gestoria"
  - "check the notas del gestor"
  - Any task involving a PDF/scan that isn't already in FacturaScripts
---

# Skill: Gestoria document store (MinIO)

## What this is

A self-hosted object store (MinIO, `docker-compose.yml` service `minio`,
ports 9000 API / 9001 console) that holds the actual scanned documents for
each empresa — filings, contracts, bank certificates, actas, correspondence
— fronted by a small upload/browse UI at `incoming/gestoria/index.php`.

**This is the real archive.** It is not the same thing as `incoming/pdfs/`,
which is only a transient drop-zone for brand-new invoices awaiting import
into FacturaScripts (see `incoming-invoice-agent.md`). Do not search
`incoming/pdfs/` to answer "has this document already been filed/stored" —
query MinIO (via the API below) or FacturaScripts directly instead.

## Bucket and key convention

Bucket: **`docs`**. Keys follow:

```
{NIF}/{year}/T{quarter}/{type}/{filename}
{NIF}/{year}/T{quarter}/{type}/{month}/{filename}   (recibidas/emitidas only, optional month subfolder)
{NIF}/{year}/eoy/{filename}                          (cierre anual — no quarter)
{NIF}/{type}/{filename}                              (contratos, notas — no year/quarter at all)
```

Valid `type` values: `recibidas`, `emitidas`, `declaraciones`, `eoy`,
`contratos`, `notas`. `contratos` and `notas` are "company types" — they
never carry a year/quarter segment, they live directly under `{NIF}/`.

## The upload UI (`http://localhost/gestoria/`)

A single PHP page (`incoming/gestoria/index.php`, using
`incoming/MinioClient.php`) with a sidebar (empresa / año / trimestre / tipo
picker building a live key preview) and a drag-drop uploader + file browser.

**The empresa dropdown is sourced exclusively from FacturaScripts'
`empresas` table** (`SELECT cifnif, nombre FROM empresas ORDER BY idempresa`,
via `?api=companies`). There used to be a "+ Añadir empresa…" option that
let someone type an arbitrary NIF and upload documents for a company that
didn't exist in FS — this was removed (see git history,
"Gestoria: make FacturaScripts the sole source of companies"). **A new
company must be created in FacturaScripts first; MinIO can no longer
originate one.** If a future agent is asked to add a "new company" option
back, push back — this was a deliberate fix for a real data-integrity gap.

### JSON API (query param `?api=`)

| api | Method | Params | Notes |
|---|---|---|---|
| `list` | GET | `prefix` | Lists objects under a key prefix. |
| `companies` | GET | — | Companies from FS `empresas` table. |
| `upload` | POST | multipart `files[]`, plus form fields for the key | **One file per request** — sending multiple `-F "files=@..."` in a single curl call only processes the first one. Loop and send them individually. |
| `download` | GET | `key` | Streams the object. |
| `delete` | POST | `key` (form-encoded) | Permanent. |

### Reading/writing from the CLI (agent use)

```bash
# List everything for an empresa
curl -s "http://localhost/gestoria/?api=list&nif=<NIF>"

# Download one object — MUST use -sG --data-urlencode for keys with
# spaces/parentheses (a plain quoted URL silently fails or exit-23s)
curl -sG "http://localhost/gestoria/?api=download" \
  --data-urlencode "key=<NIF>/2025/eoy/some file (2).pdf" \
  -o "local_name.pdf"

# Delete one object
curl -s -X POST "http://localhost/gestoria/?api=delete" \
  --data-urlencode "key=<NIF>/2026/T3/emitidas/foo.pdf"

# Upload — one file per call
curl -s -X POST "http://localhost/gestoria/?api=upload" \
  -F "files=@/local/path/foo.pdf" \
  -F "nif=<NIF>" -F "year=2026" -F "quarter=T3" -F "type=emitidas"
```

Note: `list`'s `year`/`type`/`quarter` filter params have been observed to
be largely ignored by the API (it tends to return the full NIF listing
regardless) — always post-filter the JSON result yourself (e.g. with
`python3 -c "..."` grepping the `key` field) rather than trusting server-side
filtering.

## "Notas del gestor" — `{NIF}/notas/*.md`

A separate, human-readable note-taking convention layered on top of the
same store: numbered markdown files (`00_RESUMEN_EJECUTIVO.md`,
`01_...`, `02_...`, etc.) written in a dated, reviewer-style voice, with
`[[wikilink]]`-style cross-references between notes. This is **distinct
from the agent's own Claude memory** (`~/.claude/projects/.../memory/`) —
notas del gestor are meant for any human or AI who opens this specific
company's folder cold, not for a specific AI assistant's session-to-session
recall.

When a session does meaningful new work for an empresa (files something,
fixes a filing error, resolves an open item), update the relevant notas del
gestor file(s) too — not just this repo's skills or the agent's own memory.
In particular:
- Add a new numbered note for a distinct new episode of work, don't cram
  everything into `00_RESUMEN_EJECUTIVO.md`.
- Go back and strike through / correct any older note that the new work
  makes stale (e.g. "Modelo 200 pendiente" once it's filed) — a stale note
  is worse than no note, since a future reader (or agent) will trust it.
- Download → edit locally → re-upload (there is no in-place edit API).

## Related skills

- `incoming-invoice-agent.md` / `outgoing-invoice-agent.md` — the actual
  invoice import pipelines; `incoming/pdfs/` (not MinIO) is their staging
  area.
- `stack-orientation.md` — where the `minio` service fits in the overall
  compose stack.
