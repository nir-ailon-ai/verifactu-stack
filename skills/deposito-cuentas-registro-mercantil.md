---
name: deposito-cuentas-registro-mercantil
description: >
  How to prepare and file the annual depósito de cuentas (SL/SA annual
  accounts) with the Registro Mercantil via the D2 desktop application and
  registradores.org, how to read a calificación de defectos (rejection
  notice), and how subsanación (defect correction) works. This is a
  Registro Mercantil process, separate from AEAT/Modelo 200.
triggers:
  - "depósito de cuentas" / "annual accounts deposit"
  - "D2" / "depósito digital"
  - "notificación de calificación" / "defecto" from a Registro Mercantil
  - "subsanar" / "subsanación" of a depósito de cuentas
  - Preparing the Memoria Abreviada or Certificación de Acuerdos Sociales
---

# Skill: Depósito de cuentas anuales (Registro Mercantil)

## What this is, and how it differs from Modelo 200

**Modelo 200** (AEAT, Impuesto de Sociedades) and the **depósito de
cuentas** (Registro Mercantil) are two separate obligations that happen to
run on similar timelines but go to different institutions:

1. Junta General approves the cuentas anuales (Balance, PyG, Memoria) —
   must happen within 6 months of fiscal year-end (Art. 164 LSC).
2. Depósito de cuentas at the Registro Mercantil — within **1 month** of
   that Junta approval. For a Junta held ~24/07, the deadline is ~24/08.
3. This is entirely independent of the Modelo 200 filing deadline (25
   calendar days after 6 months from fiscal year-end — i.e. 1–25 July for a
   31 December close). Modelo 200 can be filed, corrected, or reformulated
   without touching the Registro Mercantil deadline, and vice versa.

If the underlying accounts change after the Junta already approved them
(e.g. an IS-rate correction discovered later — see
`filing-sustitutiva.md`), do **not** silently edit the original Acta.
Convene a new Junta (Extraordinaria) that explicitly reformulates and
supersedes the specific prior acuerdos, keep both documents on record, and
make sure the depósito that actually gets filed uses the *final* restated
figures. See a real worked example of this exact sequence in
`project-ailon-sl-migration.md`-style memory for any company that's been
through it.

## Required documents before filing

- Balance de Situación, Cuenta de Pérdidas y Ganancias.
- Memoria (Abreviada or Normal per company size) — if none exists (common
  right after a gestor handover), it must be drafted from scratch. Base
  every factual claim in it (revenue sources, related-party terms, rental
  status, etc.) on the actual signed contracts/records, not on assumption —
  a Memoria is a legal document and mistakes here get caught by whoever
  reviews it before signing.
- Acta de la Junta approving the cuentas + a **Certificación de Acuerdos
  Sociales** (a distinct certifying paragraph, normally the last page/
  section of the Acta, stating: date and type of Junta, that it approved
  the accounts by such a vote, the resultado, and the application of that
  resultado — Art. 274 LSC governs the reserva legal split until it reaches
  20% of capital). Get this signed by the Administrador(es) at the same
  time as the Acta.

## The D2 desktop application (Windows-only)

D2 ("Depósito Digital") is the Colegio de Registradores' desktop tool for
building the deposit file. It has its own internal validation engine that
reports errors as decodable pseudo-code boolean formulas (e.g.
`si C8080828=1 entonces C97516=C97526+C97536+C97546`) under "Detalle de la
validación" — read these carefully; the casilla numbers in the formula
don't always refer to the top-level total you'd guess, they can refer to a
one-level-down sub-breakdown instead (a specific mismap that cost real
back-and-forth in one session — always cross-check against the *exact*
sub-row the formula names, not just a same-shaped row elsewhere in the same
table).

### Validation gotchas actually hit in practice

- **Titular Real "fecha" field**: cannot be left blank regardless of
  PRIMERA vs ACTUALIZACIÓN framing (a footnote suggesting otherwise is
  misleading). D2 itself will pop up the correct answer if you get it
  wrong: it wants the **fecha de aprobación de las cuentas** (i.e. the
  Junta/certificación date), not the incorporation date.
- **Tabla I.b (voting rights) duplication**: even when voting rights
  exactly mirror capital ownership (Tabla I.a), D2 still requires I.b to be
  filled in with the same entries — it will not infer it.
- **Sub-total tables with more than one level** (e.g. a top total that
  breaks into an "of which" sub-row, which itself breaks into further
  sub-rows): fill in *every* level's own arithmetic, not just the top one.
- **"Microempresas" field**: must be non-blank even when not applicable.
- **Free-text apartados** (e.g. "Otra información" / a numbered apartado
  under the Memoria section): if left empty, this can silently produce a
  vague "no se han rellenado algunas páginas" warning at huella-digital
  generation time with no page number given — check every free-text
  apartado, not just the ones that looked mandatory in the UI.
- **Páginas presentadas**: needs a manual count matching the actual
  generated PDF once everything above is filled in — re-check it after any
  late-stage edit.

### Generating the huella digital and presenting

1. `Formulario → Comprobar validaciones` until zero obligatory errors.
2. `Formulario → Generar huella digital` — this locks the data and produces
   a hash (shown as a barcode + string), plus a **CAC** (Certificado de
   Aprobación de Cuentas) PDF stub containing that hash.
3. `Formulario → Presentación depósito digital` → choose **Presentación
   Telemática** → **Completa** (send everything from D2 directly) or
   **Mixta** (portal + paper backup) → select certificates (one for
   signing the files, one for the envío itself) → confirm → send.
4. This produces, in the D2 working directory's `Envio/` folder: the data
   zip, the CAC pdf, an acuse de recibo, and assorted routing XML. Keep all
   of these — upload them to the gestoria store under
   `{NIF}/{year}/eoy/` (see `gestoria-document-store.md`).

**Open question, not fully resolved as of this writing**: exactly what
content the CAC PDF needs to contain in the *current* D2 version (observed:
17.6.0.11) before being signed. Colegio de Registradores' own 2019 manual
shows the CAC as a substantive "CERTIFICO: que en fecha X se reunió la
Junta... aprobó las cuentas..." document with the huella transcribed into
free text and then signed — but the CAC that current D2 actually generates
is a bare fixed-field form (Sociedad/NIF/Ejercicio/nombre/huella/firma box)
with **no free-text field to add that certifying language into**. Whether
the modern flow expects the bare form + signature to be sufficient (Art.
366.1.3º RRM arguably supports this: "la identificación... se realizará
mediante firma electrónica del fichero que las contiene"), or expects the
certifying substance to already live in structured casillas elsewhere in
the cuestionario, was **not resolved through documentation alone** — it
needed a direct question to the Registro Mercantil (email or in person).
**Don't assume a settled answer here; verify current-version D2 behavior
or ask the registro directly before advising confidently on a real
filing.**

## Reading a "notificación de calificación" (defect notice)

If the registrador finds a problem, you get a PDF with this shape:
- `HECHOS`: Diario/Asiento, F. Presentación, Entrada (format `Libro/Año/Número`,
  e.g. `2/2026/842807` = Libro 2, Año 2026, Número 842807), Sociedad,
  Ejercicio depósito, Hoja registral.
- `FUNDAMENTOS DE DERECHO (DEFECTOS)`: the actual problem, usually citing
  RRM articles. A defect phrased as *"...Subsanado el error se completará
  esta calificación"* is **subsanable** — not a rejection of the accounts,
  just a documentary gap. No refiling from scratch needed.
- Appeal routes are listed (cuadro de sustituciones, recurso ante DGSJFP,
  vía judicial) but these are for genuinely disputing the calificación —
  irrelevant for a routine subsanable defect.

**Timing**: per current practice, a subsanación filed within **5 months**
of the *original* presentation date is still treated as presented on that
original date (no extemporaneidad). There is no need to rush a routine
subsanación, but no reason to sit on it either.

## Subsanación process

Per the Colegio de Registradores' "Manual de Ayuda de Subsanación
Telemática": from `registradores.org` → **Presentación Telemática de
Documentos** → **Presentar cuentas**, check the **"Subsanación/
Complementario"** box, and supply the *original* entry's **Libro** (2 for
cuentas anuales), **Año** (year presented, not the fiscal year), and
**Número de entrada** — all readable straight off the defect notice's
`ENTRADA` field. Attach whatever corrected/completed document the defect
actually requires (see the open question above — confirm what's actually
missing before resubmitting, ideally by contacting the registro directly
rather than guessing a second time).

## Getting a direct answer from the registro

- **Cita previa**: `registradores.org` → *Información al ciudadano* → *Cita
  previa* → *Solicitar cita previa*, select the specific office (the portal
  requires picking a "sede" — it's an interactive form, can't be
  pre-filled from a script). Registradores.org explicitly supports booking
  a cita to "consult how to resolve a defect noted in a qualification
  memo."
- **Email**: the *notification* itself (not the PDF alone — check the
  actual email it arrived in) typically carries a reply address at
  `registro.electronico@registradores.org` for this kind of query. Always
  reference the Diario/Asiento/Entrada numbers so they can pull the file
  immediately.
- Each specific Registro Mercantil office (e.g. Madrid) also has its own
  phone line and a `Contactar` web form on its own site (e.g.
  `rmercantilmadrid.com`) — but check before assuming that form supports
  attachments; it may be a plain text-only contact form.
- **Do not guess or fabricate a direct email address for a specific
  Registro Mercantil office from search-engine summaries** — verify the
  domain actually matches the office's real site before using it; a
  plausible-looking address on a mismatched domain is worth more scrutiny
  than trust.

## Related skills

- `filing-sustitutiva.md` — correcting a 303/130-style filing after the
  fact. A Modelo 200 rectificativa + Junta reformulación (the pattern
  referenced above) is a similar idea but a distinct mechanism — not yet
  covered by that skill.
- `gestoria-document-store.md` — where to archive every document this
  process produces.
- `tax-calendar-spain.md` — where the depósito de cuentas deadline sits
  relative to Modelo 200 and the rest of the annual calendar.
