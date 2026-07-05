---
name: tax-calendar-spain
description: >
  Spanish tax filing deadlines for all models relevant to this stack: 303, 130,
  202, 111, 115, 347, 390, 180, 190, 200. Covers autónomos vs. SLs and typical
  payment methods. Reference before starting any filing workflow.
triggers:
  - "when is the deadline for X?"
  - "what do I need to file this quarter?"
  - "what models apply to an autónomo / SL?"
  - Planning a filing session for any quarter
---

# Skill: Spanish tax calendar

## Quarterly deadlines (most common models)

All quarterly models share the same presentation window: **the first 20 days of the
month following the end of the quarter**, except Q4 which runs into January.

| Period | Quarter | Presentation window |
|---|---|---|
| 1T (Jan–Mar) | Q1 | 1–20 April |
| 2T (Apr–Jun) | Q2 | 1–20 July |
| 3T (Jul–Sep) | Q3 | 1–20 October |
| 4T (Oct–Dec) | Q4 | 1–30 January (following year) |

**Domiciliación bancaria** (automatic debit): must be requested by day 15 of the
presentation month (i.e., 5 days before the deadline). After day 15, payment via NRC
(obtained from a bank) or at a branch.

---

## Models by entity type

### Autónomo (estimación directa)

| Model | What | Frequency | Deadline |
|---|---|---|---|
| **303** | IVA quarterly | Quarterly | See table above |
| **130** | IRPF pago fraccionado | Quarterly | Same as 303 |
| **390** | IVA annual summary | Annual | 1–30 January |
| **347** | Third-party transactions > €3,005.06 | Annual | 1–28 February |
| **100** | IRPF annual declaration | Annual | Apr–Jun (following year) |
| **111** | IRPF retenciones paid to professionals | Quarterly (if applicable) | Same as 303 |
| **115** | IRPF retenciones on rent paid | Quarterly (if applicable) | Same as 303 |

Notes:
- **390** may be exempt if the autónomo files 303 quarterly AND is on SII. Check each year.
- **111** only applies if the autónomo pays other professionals with IRPF withholding.
- **115** only applies if the autónomo pays rent to a landlord (arrendador) who requires withholding.

### SL / SA (Sociedad de Capital)

| Model | What | Frequency | Deadline |
|---|---|---|---|
| **303** | IVA quarterly | Quarterly | See table above |
| **202** | IS pago fraccionado | Quarterly (3×/year) | See below |
| **390** | IVA annual summary | Annual | 1–30 January |
| **347** | Third-party transactions > €3,005.06 | Annual | 1–28 February |
| **200** | Impuesto de Sociedades (annual) | Annual | See below |
| **111** | IRPF retenciones on professionals/employees | Quarterly | Same as 303 |
| **115** | IRPF retenciones on rent paid | Quarterly (if applicable) | Same as 303 |
| **190** | Annual summary of 111 | Annual | 1–30 January |
| **180** | Annual summary of 115 | Annual | 1–30 January |

Notes:
- SLs do **not** file Modelo 130; they file Modelo 202 instead.
- SLs do **not** file Modelo 100; they file Modelo 200 instead.

---

## Modelo 202 — IS pago fraccionado (SLs)

Three installments per year, not four:

| Installment | Period | Deadline |
|---|---|---|
| 1P | — | 1–20 April |
| 2P | — | 1–20 October |
| 3P | — | 1–20 December |

The 202 base is calculated from the prior year's IS (Modelo 200). The standard method
(modalidad art. 40.2): 18% of the prior year's cuota íntegra × prior year's months in
the period. An alternative method based on current-year result is available on request.

---

## Modelo 200 — Impuesto de Sociedades (annual, SLs)

Presentation window: **25 calendar days after 6 months from the close of the fiscal year**.

For a fiscal year ending 31 December: **1–25 July** of the following year.

The 200 requires the full annual P&L and balance sheet, so it depends on the books
being closed first. Prepare in coordination with the annual accounts filing.

---

## Modelo 347 — Third-party operations

Annual. Lists all clients and suppliers with whom the empresa transacted more than
€3,005.06 in the calendar year. Deadline: **1–28 February** of the following year.

Note: transactions already reported via SII may be exempt from 347.

---

## Annual accounts — Registro Mercantil (SLs only)

Not an AEAT model, but a legal obligation. SLs must file annual accounts
(balance de situación, cuenta de pérdidas y ganancias, memoria) with the
Registro Mercantil within **3 months of the general shareholders' meeting**, which
must be held within 6 months of fiscal year end. Effective deadline: around September
for a December fiscal year.

---

## Quick reference: "what do I file this quarter?"

**For an autónomo with no employees, no professional payments, and no rent paid:**
- 303 (IVA)
- 130 (IRPF)

**For an SL with no employees but paying professional fees or rent:**
- 303 (IVA)
- 202 (IS installment, if in an applicable month)
- 111 (if paying professionals with IRPF withholding)
- 115 (if paying rent)

**Annual (January):**
- 390 (IVA resumen)
- 190 (if 111 was filed during the year)
- 180 (if 115 was filed during the year)

**Annual (February):**
- 347 (if any client/supplier > €3,005.06)

---

## Verifactu timeline

- **1 January 2027** — mandatory for sociedades (SL, SA, etc.)
- **1 July 2027** — mandatory for autónomos and other taxpayers

Until those dates, Verifactu submission is voluntary. This stack supports both
preproducción testing and production submission today; the `verifactu_submissions`
table tracks which invoices have been submitted and when.

---

## Related skills

- `filing-iva-303.md` — how to fill and submit Modelo 303.
- `filing-irpf-130.md` — how to fill and submit Modelo 130.
- `filing-sustitutiva.md` — how to correct a prior filing.
- `stack-orientation.md` — where and how to run the Verifactu scripts.
