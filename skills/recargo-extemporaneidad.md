---
name: recargo-extemporaneidad
description: >
  How to read and respond to an AEAT "propuesta de liquidación de recargo
  por presentación fuera de plazo" (surcharge for a late voluntary filing,
  Art. 27 LGT) — how the surcharge is calculated, how it differs from a
  sanción, and how to accept it (conformidad expresa) via sede electrónica.
triggers:
  - "recargo por extemporaneidad" / "presentación fuera de plazo"
  - AEAT notification proposing a surcharge on a late Modelo (111, 303, etc.)
  - "is this a fine?" about an AEAT letter
---

# Skill: Recargo por presentación extemporánea (Art. 27 LGT)

## What this is (and isn't)

When a return is filed late **voluntarily** (i.e. before AEAT sends any
requerimiento asking where it is), the consequence is a **recargo**
(surcharge), not a **sanción** (penalty). This distinction matters:

- A recargo is close to automatic and objective — it doesn't turn on intent
  or fault. "The gestor forgot to file it" is not a valid ground to get it
  waived, and there is no route through AEAT to redirect this liability to
  a third party (the gestor) — that would be a separate, private
  reimbursement conversation, unrelated to what's owed to AEAT.
- No interés de demora and no sanción apply on top of a pure recargo case
  (interest only kicks in once the delay exceeds 12 months).

## How the surcharge is calculated

Current regime (post Ley 11/2021 reform, Art. 27 LGT): **1% flat, plus 1%
more per each full additional month of delay**, with no surcharge charged
for the fraction under a full month, up to a cap. E.g. a filing between 8
and 9 months late → 9% recargo. Base = the resultado a ingresar on the late
autoliquidación itself (not some separate new debt — the letter will quote
that figure only to show its arithmetic, don't mistake it for an
additional amount owed).

**25% reduction** is available if, within the payment window opened by the
recargo notification:
1. You pay the *remaining* recargo amount (or follow an approved
   aplazamiento/fraccionamiento), **and**
2. You've paid (or are paying per an approved schedule) the full
   underlying autoliquidación debt.

In practice, if the underlying tax was already paid via NRC at the time of
the late filing (check the actual filed Modelo's PDF for an
`INGRESAR / NRC: ... IMPORTE: ...` line — an NRC only exists once a bank
has actually processed the payment), condition 2 is already satisfied, and
accepting promptly gets the 25%-reduced amount.

## What the letter actually is

A **"propuesta de liquidación"** is not final — it's a pre-resolution
notice giving **15 business days** to respond, with three options:
1. Do nothing → a resolución confirming the proposal gets issued later.
2. **Conformidad expresa** — accept it outright, locking in the reduced
   amount and getting an immediate carta de pago.
3. **Alegaciones** — dispute it (rarely worth it for a routine, genuinely
   late filing; there's no fault-based defense against a pure recargo).

## How to accept it (conformidad expresa)

Via `sede.agenciatributaria.gob.es`, logged in with the company's
certificado digital (or Cl@ve/DNIe):

**Área personal → Mis expedientes (EN TRAMITACIÓN) → Otros procedimientos
tributarios → Otros → Liquidación de recargos por presentación fuera de
plazo de declaraciones y autoliquidaciones**

1. Select the expediente (match by the letter's `Referencia` or CSV).
2. Under "Servicios disponibles" → **Prestar conformidad**.
3. Then **Generar Resolución** (may not be instantly available for every
   expediente — the letter itself warns of this).
4. Then **Pagar** to actually settle the reduced amount.

## When it's genuinely worth pursuing the gestor

For a trivial amount (a few tens of euros), it's essentially never worth a
formal reimbursement claim against a prior gestor on its own — the effort
outweighs the recovery, and AEAT doesn't care who's "at fault" anyway. If a
gestor handover is turning up a *pattern* of this kind of lapse, it can be
worth keeping a running tally (in project memory, not here) to raise
together at some later reckoning, rather than chasing each one
individually as it surfaces.

## Related skills

- `tax-calendar-spain.md` — the deadlines whose miss triggers this.
- `filing-sustitutiva.md` — correcting a filing's *content*, as opposed to
  its lateness (a different problem with a different fix).
