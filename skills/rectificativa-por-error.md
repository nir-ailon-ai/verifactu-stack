---
name: rectificativa-por-error
description: >
  Emite una factura rectificativa para corregir un error material en una
  factura ya emitida y registrada en AEAT — típicamente porque falta una
  retención de IRPF, se equivocó un importe o el tipo de IVA. Sigue el patrón
  "cancelar + reemitir" (Path A): una rectificativa en serie R que anula la
  original, más una nueva factura correcta en serie A.
triggers:
  - "hay que rectificar la factura X"
  - "olvidé la retención en la factura X"
  - "factura mal emitida"
  - "issue a rectificativa for invoice X"
  - "the retention was missing on invoice X"
---

# Skill: Rectificativa por error material (Path A)

## When to use this skill

The user says one of:

- *"Rectifica la factura 2026-A024, se me olvidó la retención."*
- *"La factura X está mal, hay que corregirla."*
- *"Issue a corrective invoice for 2026-A024, we forgot IRPF."*

Or similar phrasings where an already-issued (and typically already-submitted-to-producción) invoice needs to be corrected.

## Preconditions to verify before starting

Confirm each of these before proceeding. If any is missing, stop and tell the user.

1. **The original invoice exists in FacturaScripts** and its `codigo` is known.
   Query:
   ```
   docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT idfactura, codigo, codserie, fecha, total, totaliva, totalirpf, cifnif, nombrecliente FROM facturascli WHERE codigo='<ORIGINAL_CODE>' LIMIT 1;"
   ```
   If no row: the code is wrong. Ask the user to confirm.

2. **The original has been submitted to AEAT producción** (there's a `submitted` row with `environment='produccion'` in `verifactu_submissions`).
   Query:
   ```
   docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT invoice_code, status, environment, csv FROM verifactu_submissions WHERE invoice_code='<ORIGINAL_CODE>';"
   ```
   If only preproducción rows exist, this is not really a producción rectificativa — clarify with the user before continuing.

3. **The R series and its secuencia exist** in FacturaScripts (Admin → Series should have `R`; Admin → Secuencias de Documentos should have a row for `(R, current year, FacturaCliente)`).
   Query:
   ```
   docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT codserie, descripcion FROM series WHERE codserie='R';"
   ```
   If empty, ask the user to create the R series first. Do not create it silently — this is a legal-classification decision.

4. **The reason for the correction is understood.** Ask the user to confirm the correction reason so the correct `observaciones` text can be written. Common cases:
   - Forgot IRPF retention
   - Wrong tax base or IVA rate
   - Wrong customer details
   - Descuento posterior
   - Total cancellation without replacement

## What we're doing (Path A — cancel + reissue)

We create **two** documents:

1. **Rectificativa in series R** — cancels the original with negative amounts. References the original via `codigorect` / `idfacturarect`.
2. **New correct invoice in series A** — same customer, correct amounts including whatever was missing.

Both get submitted to preproducción first (safety), then producción (real fiscal effect).

Path B (edit the devolución to be delta-only in a single R invoice) is an alternative but slightly more complex to reason about later; prefer Path A unless the user specifically asks.

## Steps

### 1. In FacturaScripts UI: create the rectificativa

Guide the user through:

- Open the original invoice at `http://localhost/EditFacturaCliente?code=<ORIGINAL_CODE>`.
- Click the **Devoluciones** button.
- Save. FS creates a new invoice in series `R` with negative amounts and `codigorect` referencing the original.

Ask the user to confirm the R invoice was created:

```
docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT codigo, codserie, codigorect, idfacturarect, fecha, total FROM facturascli WHERE codserie='R' ORDER BY idfactura DESC LIMIT 1;"
```

The row should show:
- `codserie` = `R`
- `codigorect` = `<ORIGINAL_CODE>`
- `total` = negative of the original's total (full cancellation)

### 2. Write the observaciones for the rectificativa

The `observaciones` field must state the reason for the correction. For the "forgot IRPF" case:

> *Factura rectificativa. Rectifica la factura nº <ORIGINAL_CODE> de fecha <ORIGINAL_DATE>. Motivo: subsanación de error material — se omitió indebidamente la práctica de la retención de IRPF a cuenta que legalmente correspondía aplicar sobre la operación. Se sustituye por nueva factura correcta emitida en la misma fecha.*

For other cases, adapt the "Motivo" clause. The reference to the original invoice's number and date is required (RD 1619/2012 art. 15.2).

Ask the user to paste this into the FS observaciones field on the R invoice, then save.

### 3. In FacturaScripts UI: create the new correct invoice

Guide the user through:

- **Ventas → Facturas de cliente → + Nueva**.
- Same customer as the original.
- Same lines as the original, this time **with** the correction applied (add the IRPF retention, fix the wrong rate, whatever the case is).
- Save. FS assigns it a new codigo in series `A`.

Verify:

```
docker compose exec db mariadb -ufsuser -p"$DB_PASS" facturascripts -e "SELECT codigo, codserie, fecha, neto, totaliva, totalirpf, total, cifnif, nombrecliente FROM facturascli WHERE codserie='A' ORDER BY idfactura DESC LIMIT 1;"
```

The new invoice should have the correct amounts including the fix.

### 4. Test in preproducción — safety check before real fiscal action

```
docker compose exec app php /verifactu/submit-pending.php --env=preproduccion
```

Expected: both the R invoice and the new A invoice show up as pending and get `ACCEPTED. CSV: A-XXXX`.

If either **REJECTED**, stop and diagnose. Do not proceed to producción.

Regenerate the PDFs for a visual check:

```
docker compose exec app php /verifactu/make-invoice-pdf.php <R_CODE> --env=preproduccion
docker compose exec app php /verifactu/make-invoice-pdf.php <NEW_A_CODE> --env=preproduccion
```

Report both PDFs to the user (paths on disk). Ask them to visually confirm:
- R invoice: negative amounts, "Observaciones" text present, QR points to `prewww2.aeat.es`.
- New A invoice: correct amounts with IRPF retention (or whatever the fix was), QR to `prewww2.aeat.es`.

### 5. Submit to producción — REQUIRES EXPLICIT USER CONFIRMATION

**Danger zone**. Do not run `--env=produccion` without the user explicitly saying "yes, submit to producción" or equivalent. Even after they've confirmed the preproducción PDFs, ask one more time:

> *"Voy a enviar la rectificativa y la nueva factura a AEAT producción. Esto tiene efecto fiscal real y no se puede deshacer. ¿Confirmas?"*

If they confirm, run:

```
docker compose exec app php /verifactu/submit-pending.php --env=produccion
```

Both should come back `ACCEPTED` with real CSVs. Report those CSVs to the user — they're the fiscal identifiers.

Regenerate the producción PDFs:

```
docker compose exec app php /verifactu/make-invoice-pdf.php <R_CODE> --env=produccion
docker compose exec app php /verifactu/make-invoice-pdf.php <NEW_A_CODE> --env=produccion
```

Advise the user to send both PDFs to the customer (the R cancels the original invoice; the new A is what they should pay according to its terms, including the retention).

## Verification checklist

After the flow completes, all of these should be true:

- [ ] Original invoice unchanged in `facturascli` (do NOT edit it).
- [ ] R invoice exists with `codserie='R'`, `codigorect=<ORIGINAL_CODE>`, negative total.
- [ ] New A invoice exists with correct amounts.
- [ ] `verifactu_submissions` has four rows for these three invoices: original (already there), R preprod + prod, new A preprod + prod.
- [ ] Both preproducción and producción CSVs recorded for R and new A.
- [ ] PDFs generated for R and new A in producción mode carry real (not `prewww2`) QRs.

## Common pitfalls to avoid

- **Do not submit only the R without the new A.** That leaves the taxpayer having cancelled income with no replacement — a phantom loss on their books.
- **Do not delete the original invoice or its sidecar rows.** The original is still a valid fiscal event; the rectificativa adjusts it, not replaces it in the archive.
- **Do not use `--env=produccion` in the preproducción testing step.** Verify at least once in sandbox before flipping.
- **Do not choose Path B silently.** If the user asks for a delta-only single R invoice, do that explicitly, not by editing lines behind the scenes.
- **Do not modify the digital certificate or `companies.php`** as part of this flow.

## Legal references

- Real Decreto 1619/2012 (Reglamento de Facturación), Article 15 — rectificativas.
- Ley 37/1992 (Ley del IVA), Article 80 — modification of tax base (relevant for bad-debt cases).
- Real Decreto 1007/2023 + Orden HAC/1177/2024 — Verifactu regime.

## Related skills

- `create-invoice.md` — the basic single-invoice workflow.
- `nuevo-cliente-extranjero.md` — onboarding a foreign customer (relevant if the customer's tax status is what needs fixing).
- `iva-quarterly.md` — how the rectificativa flows into the next Modelo 303.
