---
name: filing-irpf-130
description: >
  How to prepare and submit Modelo 130 (IRPF pago fraccionado) for autónomos in
  estimación directa on SEDE electrónica. Covers cumulative income/expense
  calculation, how prior quarter payments are deducted, and the correct SEDE form
  to use (not the predeclaración path).
triggers:
  - "fill out the 130"
  - "modelo 130"
  - "pago fraccionado IRPF"
  - "declaración trimestral IRPF autónomo"
  - Starting a quarterly IRPF filing for an autónomo
---

# Skill: Modelo 130 — IRPF pago fraccionado (autónomos)

## What this model is

Modelo 130 is the quarterly IRPF installment payment for autónomos in **estimación
directa** (normal or simplificada). It prepays 20% of net income throughout the year.

**Key difference from 303**: Modelo 130 is **cumulative** — each quarter's figures
include ALL income and expenses from January 1 to the last day of the quarter.

SLs do NOT file 130; they file Modelo 202 instead.

---

## Who files it

- Autónomos in estimación directa (normal or simplificada).
- Not required for autónomos in módulos (estimación objetiva).
- Not required if the autónomo has ≥ 70% of their income subject to IRPF withholding
  by clients (Spanish clients retaining 15% IRPF on invoices). In practice, if income
  comes mostly from foreign clients (who don't withhold Spanish IRPF), 130 is required.

---

## Before you start: gather the data

### Cumulative income (Jan 1 → end of quarter)

```sql
SELECT SUM(neto) AS total_ingresos
FROM facturascli
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<YEAR>-01-01' AND '<Q_END>';
```

### Cumulative deductible expenses (Jan 1 → end of quarter)

```sql
SELECT SUM(neto) AS total_gastos
FROM facturasprov
WHERE idempresa = (SELECT idempresa FROM empresas WHERE cifnif = '<NIF>')
  AND fecha BETWEEN '<YEAR>-01-01' AND '<Q_END>';
```

Include only fiscally deductible expenses: TGSS autónomo quota, professional services,
office supplies, software subscriptions, co-working, travel (with limits). Do not
include personal expenses or non-deductible items.

### Prior quarter payments

From the T1 Modelo 130 filing: the amount in **casilla [07]** of the T1 return is
the payment already made. This becomes **casilla [05]** in T2. Add each subsequent
quarter's payment in [05] cumulatively.

### Retenciones soportadas

IRPF withheld by Spanish clients on your invoices (box 06). For autónomos with
exclusively non-Spanish clients, this is 0.

---

## Casillas reference

| Casilla | Meaning | Formula |
|---|---|---|
| [01] | Ingresos computables (cumulative Jan–end of Q) | Sum of all net income |
| [02] | Gastos fiscalmente deducibles (cumulative) | Sum of all deductible expenses |
| [03] | Rendimiento neto | [01] − [02] |
| [04] | 20% de [03] (cuota íntegra) | [03] × 0.20 (0 if [03] is negative) |
| [05] | Pagos de trimestres anteriores del mismo ejercicio | Sum of [07] from all prior quarters this year |
| [06] | Retenciones soportadas (cumulative) | IRPF withheld by clients Jan–end of Q |
| [07] | **Pago fraccionado previo** | [04] − [05] − [06] (0 if negative) |
| [12] | Suma pagos fraccionados (= [07] + [11]) | [11] is for agr/ganadería; usually [12] = [07] |
| [14] | Diferencia | [12] − [13] (art. 110.3c deduction; usually [13] = 0) |
| [17] | Total | [14] − [15] − [16] (negative results, housing deduction; usually [17] = [14]) |
| [18] | Deducir autoliquidaciones complementarias | 0 unless filing a complementaria |
| **[19]** | **Resultado** | [17] − [18] = amount to pay |

If [07] would be negative, enter 0 — you do not get a refund via 130.

---

## Example: T2 calculation

| | T1 (Jan–Mar) | T2 (Jan–Jun cumulative) |
|---|---|---|
| [01] Ingresos | 4.447,21 | 8.615,95 |
| [02] Gastos | 1.083,25 | 2.677,78 |
| [03] Rendimiento neto | 3.363,96 | 5.938,17 |
| [04] 20% | 672,79 | 1.187,63 |
| [05] Prior quarter payments | 0 | 672,79 ← T1's [07] |
| [06] Retenciones | 0 | 0 |
| [07] / [19] Result | **672,79** | **514,84** |

T2 pays only the incremental amount: 1.187,63 − 672,79 = 514,84.

---

## Filing on SEDE electrónica

### Which form to use

On SEDE there are typically two options for 130. **Always use:**

> **"Modelo 130. Ejercicio XXXX. Presentación y servicio de ayuda Pre 130"**

**Do NOT use** "Formulario para su presentación (predeclaración)". That path generates
a paper PDF that must be printed, signed, and taken to a bank in person. The "servicio
de ayuda" path allows electronic presentation with domiciliación bancaria.

### Steps

1. Log in at `sede.agenciatributaria.gob.es`.
2. Navigate: **Trámites → Impuestos y tasas → IRPF → Modelo 130 → Presentación**.
3. Select ejercicio and período.
4. When asked "¿Declaración complementaria?" → **NO** (unless adding to a prior T2).
5. Enter the casillas as calculated above.
6. **Validar declaración** — fix any errors.
7. **Seleccionar ingreso/devolución** → choose **Domiciliación bancaria** and enter
   the IBAN. The debit occurs on the last day of the presentation period (e.g., July 20
   for T2). Domiciliación must be set up before day 15 of the presentation month.
8. **Presentar declaración**.

### Payment via NRC (if domiciliación window has passed)

If filing after day 15, you must get an NRC (Número de Referencia Completo) from your
bank (online banking or branch), then enter it in the "Ingreso con NRC" field on SEDE.

---

## TGSS in the expense base

TGSS autónomo contributions (cuota mensual) are fully deductible for IRPF [02] even
though they carry no IVA. Include the cumulative TGSS paid from January to the end of
the quarter.

If the prior gestor's T1 filing did not include TGSS for those months, the T2 130
automatically absorbs the correction because T2 uses cumulative figures — the full
Jan–Jun TGSS will be in [02] for T2 regardless of what was in T1.

---

## Related skills

- `tax-calendar-spain.md` — filing deadlines (same as 303).
- `filing-iva-303.md` — the IVA companion filed on the same dates.
- `filing-sustitutiva.md` — correcting a prior 130 if cumulative absorption is not
  sufficient (e.g., a T1 error that overstated income).
