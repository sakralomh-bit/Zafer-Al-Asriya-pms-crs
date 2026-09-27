# ADR-0015: Localization and RTL Architecture

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-002`, `D-006`, `docs/ARCHITECTURE.md` §9, `docs/PRD.md` §24

## Context

The product is Arabic-first and must ship an Arabic RTL interface and an English LTR interface in Phase A. Bengali is architecturally possible but **not** in Phase A UI scope, and is recorded as explicitly deferred rather than silently omitted.

The risk in a hotel PMS is subtle and expensive: a UI written with physical left/right assumptions has to be rewritten — not merely re-themed — when a second direction is added. In a reservation grid where a user scans for a "next available room" to the right, a mirrored or non-mirrored direction produces **operational errors**, not just cosmetic ones. Direction correctness is therefore a correctness concern, not a styling preference.

`Prd_Maker.md` §20.8 requires language list, RTL/LTR, date/time format, timezone, calendar, currency, number format, translations, pluralization, and locale-specific validation to be specified.

## Decision

1. **One component set, one stylesheet, two directions.** Direction is a runtime property of the rendered tree, derived from locale metadata — never a build-time fork. No component may be duplicated per language.
2. **CSS logical properties are mandatory for all layout.** `margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `text-align: start/end`, `border-inline-start`. Physical `left`/`right`/`margin-left`/`text-align: left` are prohibited in application code. Logical properties are what make one stylesheet correct in both directions.
3. **Direction is a data attribute on the document root**, driven by the active locale. Iconography that encodes direction (chevrons, arrows, "next day") must be mirrored explicitly and must be enumerated, not left to chance.
4. **Translation catalogue discipline.** No user-facing string is hard-coded in a component. All strings live in per-locale catalogues, and CI fails on a missing or untranslated key. The default catalogue is Arabic; English is a full peer, not a partial fallback.
5. **Bengali feasibility is a design constraint, not a feature.** A future LTR third language (Bengali) must require **no component rewrite**. This means: no hard-coded direction assumptions anywhere; text direction derived only from locale metadata; no locale-specific layout fork; number/date/currency formatting always delegated to a locale-aware formatter.
6. **Formatting is delegated, never hand-built.** Dates, times, numbers, and currency are formatted through locale-aware formatters with explicit locale. `Prd_Maker.md` §59 forbids frontend code from determining authoritative financial amounts — a currency string rendered in the UI is a display artifact only, never the source of a posting.
7. **Time is stored canonically, displayed per locale.** All timestamps are stored in a single canonical representation (UTC) with an explicit business date where a business date applies. Display honours the user's timezone and the property timezone. Business date and calendar date are **not** interchangeable.
8. **Calendar.** Gregorian is the default. Hijri display is `TBD` — `Prd_Maker.md` §58 says "Hijri/Gregorian requirements **where relevant**", and the relevance for a hotel PMS is **unconfirmed**. It is not assumed. See `C-05` and the open question in `docs/PRD.md` §39.
9. **Pluralization** uses a locale-aware plural rule, not an `if (n === 1)` branch. Arabic plural rules differ from English and this must not be approximated.

## Criteria Applied

Correctness (a mirrored reservation grid causes booking errors), operational simplicity (one component set, not two), maintainability (extract discipline enforced in CI), reversibility (adding a language is configuration plus a catalogue), testability (direction is a renderable, assertable property).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Two separate builds per language | Rejected | Doubles maintenance surface permanently and guarantees drift between the two code paths. Direction is a runtime property; a build fork is not needed. |
| CSS Framework direction plugins only | Rejected as sufficient | A framework helper does not prevent a developer from writing `margin-left`. The prohibition must be a code rule enforced in review and CI, not a convenience feature. |
| Right-to-left support added after English ships | Rejected | Retrofitting direction into a large UI is the expensive path and is where operational errors get introduced. Both directions are Phase A. |
| Server-rendered translations only, no client catalog | Deferred | Not a locale problem. A server-rendered application shell is a separate rendering decision covered by `ADR-0004`. |
| Shipping Bengali in Phase A | Deferred | Not in the approved scope. Kept architecturally possible; the cost of carrying it now is real and the benefit is unconfirmed. |

## Consequences

- Every new component must be direction-correct from its first commit. This is a review and CI obligation, not a phase.
- Mixed-direction content (an Arabic label containing a Latin room number, a Latin brand name) requires explicit bidi handling; unreviewed bidi can visually reorder numbers and produce wrong readings. Number isolation is treated as a correctness concern.
- Translation completeness is a release gate, not a best effort.
- Adding Bengali later is a catalogue plus a locale-metadata entry, and testing.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A physical CSS property reintroduces direction dependence | Medium | High | Code review rule + CI lint ban on physical properties in application styles |
| Unreviewed bidi reorders a numeric identifier | Medium | High | Explicit number isolation for identifiers; visual + DOM-order tests in both directions |
| Translation key drift produces mixed-language UI | Medium | Medium | CI fails on missing keys; locale-completeness test |
| A right-to-left defect is missed because only one direction is tested | Medium | High | Direction is a matrix dimension in the test strategy for every UI flow |
| Unreviewed Hijri requirement surfaces late | Low | Medium | `TBD` recorded with an owner; calendar is isolated behind the formatter |

## Reversibility

Low cost. The direction architecture is a set of code rules and a CI check; relaxing it later would mean re-auditing the whole stylesheet, which is why it is cheap to keep and expensive to undo. Adding a language later is additive.

## References

`Prd_Maker.md` §20.8 (Localization), §57 (Search), §58 (Time, Date, Calendar, Timezone), §59 (Money, Tax, Rounding), §66 (Output Style), `D-002`, `D-006`, `docs/PRD.md` §24, `docs/STATE-MACHINES.md` (business date).
