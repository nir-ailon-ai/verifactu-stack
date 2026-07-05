---
name: filing-iva-303
description: >
  How to prepare and submit Modelo 303 (IVA quarterly return) on SEDE electrónica.
  Covers what data to gather from FacturaScripts, which casillas to fill, common
  edge cases (intracomunitaria, IVA-exempt services, compensation carryforward),
  and how to handle sustitutivas.
triggers:
  - "fill out the 303"
  - "file the IVA return"
  - "modelo 303"
  - "declaración trimestral IVA"
  - Starting a quarterly IVA filing for any empresa
---

# Skill: Modelo 303 — quarterly IVA return

## What this model is

Modelo 303 is the quarterly IVA (VAT) self-assessment. It is filed by every business
registered for IVA — both SLs and autónomos. It is **per-quarter** (not cumulative).

Filed on SEDE electrónica with a digital certificate or Cl@ve.

---

## Before you start: gather the data from FacturaScripts

### Outgoing invoices (devengado IVA)

```sql
SELECT codserie, codigo, fecha, neto, totaliva, total, nombrecliente, codpais
FROM facturascli
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<Q_START>' AND '<Q_END>'
ORDER BY fecha;
```

Classify each invoice:
- Spanish client, standard IVA → [01]/[02] (21%), [03]/[04] (10%), [05]/[06] (4%)
- Intracomunitaria service to EU business → [11] (IVA autorepercutida, see below)
- Service to non-EU business (`ES_68_70`) → no IVA charged; report base in [103]
- Export of goods → [21]/[22] or similar exempt section

### Incoming invoices (deducible IVA)

```sql
SELECT codserie, codigo, fecha, neto, totaliva, total, nombreprov
FROM facturasprov
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<Q_START>' AND '<Q_END>'
ORDER BY fecha;
```

Classify each:
- Spanish supplier with IVA → [28]/[29] (21%), [30]/[31] (10%), [32]/[33] (4%)
- Intracomunitaria acquisition (EU supplier, IVA autorepercutida) → [36]/[37]

---

## Key casillas reference

### Devengado (IVA charged / owed)

| Casilla | Meaning |
|---|---|
| [01] / [02] | Base / cuota at 21% — standard Spanish sales |
| [03] / [04] | Base / cuota at 10% — reduced rate |
| [05] / [06] | Base / cuota at 4% — super-reduced rate |
| [10] / [11] | Base / cuota — intracomunitaria acquisitions (autorepercusión) |
| [12] / [13] | Base / cuota — imports with IVA |
| [27] | **Total cuota devengada** (sum of all cuotas above) |
| [103] | Base of non-subject / exempt operations (Art. 20, 68-70 LIVA, etc.) |

### Deducible (IVA paid / recoverable)

| Casilla | Meaning |
|---|---|
| [28] / [29] | Base / cuota — general purchases at 21% |
| [30] / [31] | Base / cuota — purchases at 10% |
| [32] / [33] | Base / cuota — purchases at 4% |
| [36] / [37] | Base / cuota — intracomunitaria purchases (mirrors [10]/[11]) |
| [45] | Total bases deducibles |
| [46] | **Total cuota deducible** |

### Result

| Casilla | Meaning |
|---|---|
| [47] | Cuota diferencial = [27] − [46] |
| [59] / [60] | Operaciones en régimen especial (rarely used) |
| [64] | Resultado (= [47] for most filers) |
| [65] | Porcentaje atribuible (100% for single-activity filers) |
| [66] | Resultado atribuible = [64] × [65] / 100 |
| [110] | Compensación de períodos anteriores (carryforward from prior quarter) |
| [87] | Compensación aplicada (≤ absolute value of [66]) |
| [69] | Resultado tras compensación = [66] + [87] (if negative result) |
| [71] | **Resultado final** |

Result interpretation:
- **Positive [71]** → a ingresar (pay AEAT)
- **Negative [71]** → choose compensar (carry to next quarter) or solicitar devolución
- **Zero** → sin actividad or net zero

---

## Special cases

### Google Workspace and other EU SaaS (intracomunitaria)

An EU supplier (Ireland, Netherlands, etc.) that does NOT charge Spanish IVA on its
invoice triggers the **autorepercusión** rule: you must self-assess the IVA as if
you both charged it (devengado) and paid it (deducible).

- **Devengado**: [10] = base, [11] = 21% of base
- **Deducible**: [36] = base, [37] = 21% of base

Both entries cancel each other in the result — the net effect is zero IVA, but both
casillas must be filled. If you leave [10]/[11] blank, AEAT may flag a discrepancy
with the supplier's recapitulative list (Modelo 349).

### Services to non-EU businesses (ES_68_70)

Under Art. 68-70 LIVA, services provided to businesses established outside the EU
are not subject to Spanish IVA. The invoice carries no IVA. In 303:
- Do NOT put the base in [01]/[02].
- Put the base in **[103]** (operaciones no sujetas).
- [27] is unaffected (no cuota to add).

### TGSS (Seguridad Social contributions)

TGSS autónomo contributions (cuota de autónomo) have **no IVA**. They are a
deductible expense for IRPF (Modelo 130/100) but do not appear anywhere in the 303.

---

## Filing on SEDE electrónica

1. Log in at `sede.agenciatributaria.gob.es` with certificate or Cl@ve PIN.
2. Navigate: **Trámites → Impuestos y tasas → IVA → Modelo 303 → Presentación**.
3. Select ejercicio and período (e.g., 2026 / 2T).
4. **Identificación page** — answer ALL SI/NO questions explicitly. Common ones:
   - *¿Tiene actividad en Canarias/Ceuta/Melilla?* → NO (unless applicable)
   - *¿Sujeto pasivo exonerado de modelo 390?* → depends (YES if filing 303 quarterly covers the annual obligation)
   - *¿Volumen anual de operaciones distinto de cero?* → SÍ
   - *¿Destinatario de operaciones en régimen especial del criterio de caja?* → NO (unless a supplier uses this regime)
   - **If PAR018 error on submission**: a SI/NO question was left unanswered. Go back to Identificación and check every question.
5. Enter the data in the numbered fields.
6. **Resultado page** — choose compensar or devolución.
7. Validate and present.

---

## Compensation carryforward

When the result is negative and you choose **compensar**:
- AEAT stores the credit in their system.
- In the next quarter's 303, field **[110]** is pre-filled with the accumulated credit.
- Field **[87]** = the amount actually applied (cannot exceed the absolute value of [66]).
- If the result is still negative after applying [87], you can choose to compensar again.

The credit accumulates across quarters within the same year. At Q4, if the credit
is still unused, you may switch to solicitar devolución.

---

## When to file a sustitutiva

If you discover an error in a prior quarter's 303 that cannot be corrected in the
current quarter (e.g., wrongly included a non-deductible expense), you must file a
**sustitutiva** for that quarter. See `filing-sustitutiva.md`.

---

## Related skills

- `tax-calendar-spain.md` — filing deadlines.
- `filing-sustitutiva.md` — correcting a prior 303.
- `filing-irpf-130.md` — the autónomo IRPF companion model.
- `database-schema.md` — how to query invoices from FacturaScripts.
