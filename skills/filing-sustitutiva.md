---
name: filing-sustitutiva
description: >
  How to correct a previously filed tax return (Modelo 303, 130, or others) by
  filing a sustitutiva on SEDE electrónica. Covers when to use a sustitutiva vs.
  correcting in the next quarter, what you need before starting, the SEDE flow,
  and common errors (PAR018).
triggers:
  - "correct a prior filing"
  - "sustitutiva"
  - "declaración sustitutiva"
  - "modificar declaración"
  - "there was an error in last quarter's 303 / 130"
---

# Skill: Filing a sustitutiva (correcting a prior tax return)

## When to use a sustitutiva

A **sustitutiva** replaces a previously filed return for the same model, year, and
quarter. Use it when:

- An error exists in a prior quarter that cannot be corrected by the following quarter's
  normal filing.
- For **Modelo 303**: each quarter is independent. A wrong deductible in Q1 cannot be
  corrected in Q2's 303 — you must file a Q1 sustitutiva.
- For **Modelo 130**: because 130 is cumulative, minor corrections are often absorbed
  automatically in the next quarter (T2 recalculates from Jan 1). File a sustitutiva
  only if the Q1 error is material and you cannot wait for T2 to absorb it.
- For any model: if AEAT has queried the filing and requests correction.

**Do not file a sustitutiva** just because you want to change compensation preference
(compensar ↔ devolución) — that requires a specific rectificativa procedure.

---

## What you need before starting

1. **The original justificante number** — the confirmation number AEAT issued when you
   submitted the original return. Find it on the PDF receipt or in SEDE under
   "Mis expedientes".
2. **The original expediente number** — optional but useful for cross-reference.
3. **The corrected figures** — know exactly what changed and what the correct values are.
4. **The reason** — SEDE requires you to select a motivo de rectificación.

---

## SEDE flow — step by step

### 1. Navigate to the model

Log into `sede.agenciatributaria.gob.es` with your certificate or Cl@ve.

Navigate to the model (e.g., Modelo 303 → Presentación) and select the same
ejercicio and período as the original filing.

### 2. Detected prior filing — choose to modify

SEDE will detect that a return was already filed for this period and present options.
Choose **"Modificar declaración"** (or equivalent — the label varies by model).

You will then see a list of your prior filings for this period. Select the one you
want to replace (verify the justificante number).

### 3. Pre-filled form

All data from the original filing pre-fills. Make your corrections in the relevant
casillas. The form treats your new submission as the definitive one.

### 4. Motivo de rectificación

Near the end of the form, you will be asked to select the reason:

- **"Rectificaciones (excepto incluidas en el motivo siguiente) / Discrepancia criterio administrativo"** — use this for most corrections (data entry errors, removed expenses, corrected amounts).
- **"Declaración extemporánea sin requerimiento previo"** — use if filing late without
  an AEAT request.

### 5. Rectificativa section

Look for a "Rectificativa" section or checkbox. For a sustitutiva (which replaces the
entire original), this is typically **NO** on the rectificativa checkbox — the
sustitutiva is the default replacement mode when you modify a prior declaration.

### 6. Identificación page — answer ALL questions

If the form starts with an Identificación page containing SI/NO questions, every
single question must be answered explicitly before you can submit.

**PAR018 error** ("Es necesario contestar a todas las preguntas del apartado
Identificación") means at least one SI/NO question has no answer selected. Go back
to the Identificación page and check each question carefully. Common missed ones:

- *¿Sujeto pasivo destinatario de operaciones acogidas al régimen especial del
  criterio de caja?* → **NO** (unless a supplier uses this regime)
- *¿Sujeto pasivo con volumen anual de operaciones distinto de cero?* → **SÍ**
- *¿Si se ha dictado auto de declaración de concurso...?* → leave blank if no
  insolvency proceedings; this is conditional and does not need an answer if
  no concurso applies.

### 7. Submit

Validate and present. AEAT issues a new justificante for the sustitutiva.

---

## Effect on compensation pool

When a sustitutiva changes the result of a quarterly 303 from e.g. −€147 to −€113:

- AEAT automatically updates the compensation pool for subsequent quarters.
- The next quarter's 303 casilla [110] will reflect the corrected accumulated credit.
- You do not need to manually notify AEAT or file anything else.

---

## Effect on Modelo 130 (cumulative model)

Because 130 is cumulative, a T1 sustitutiva that changes the T1 payment amount
affects T2's calculation:

- T2's casilla [05] ("pagos de trimestres anteriores") must reflect the T1 amount
  actually paid (based on the original T1 result, not the sustitutiva).
- The sustitutiva's result (if higher than T1 original) may create a debt to pay;
  if lower, AEAT may owe a refund or credit the difference to the compensation pool.

---

## Declaración complementaria vs. sustitutiva

| | Sustitutiva | Complementaria |
|---|---|---|
| **Replaces** the original | Yes — the original is void | No — adds on top of original |
| **Use when** | Correcting any field up or down | Only when you owe MORE than originally declared |
| **Prior justificante required** | Yes | Yes |
| **SEDE flow** | "Modificar declaración" → select original | "Declaración complementaria" checkbox |

Always prefer sustitutiva unless AEAT explicitly requires a complementaria.

---

## Related skills

- `filing-iva-303.md` — casillas reference for 303.
- `filing-irpf-130.md` — casillas reference for 130.
- `tax-calendar-spain.md` — deadlines (sustitutivas can be filed any time, not
  just during the normal presentation window).
