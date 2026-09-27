# ADR-0004: Frontend — Vue 3 + TypeScript + Tailwind (RTL/LTR)
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-002, D-006, docs/PRD.md, docs/ARCHITECTURE.md
## Context
The PMS UI must serve Arabic (RTL) as the primary language and English (LTR) as secondary, with Bengali (LTR) architecturally possible for a future phase without a rewrite. The UI is table-heavy (reservation grids, folio ledgers, room status boards, night audit reports, housekeeping task lists). Requirements: single codebase for both directions; CSS logical properties as the mechanism for one stylesheet; runtime `dir` switching; Arabic numeral/date/currency formatting; accessibility (WCAG target TBD, blocker C-08); TypeScript for correctness; component library compatible with RTL and logical properties.
## Decision
**Vue 3 + TypeScript + Tailwind CSS** with CSS logical properties.
- **Vue 3**: Composition API, `<script setup>`, first-class TypeScript support, Vue I18n v10+ for runtime locale/direction switching, Pinia for state management, Vue Router for navigation guards (property scope enforcement).
- **TypeScript**: Strict mode (`strict: true`), no `any`, exact optional property types. All API contracts shared via OpenAPI-generated types. Money values received as strings from backend (BCMath decimal serialized as string) and rendered via `Intl.NumberFormat` — never parsed as `number`.
- **Tailwind CSS v3.4+**: JIT compiler, `rtl` variant (`rtl:`), and **CSS logical properties** as the primary layout mechanism:
  - `margin-inline-start` / `margin-inline-end` instead of `margin-left` / `margin-right`
  - `padding-inline-start` / `padding-inline-end`
  - `inset-inline-start` / `inset-inline-end` for positioning
  - `border-inline-start` / `border-inline-end`
  - `text-align: start` / `text-align: end` (not `left`/`right`)
  - `float: inline-start` / `float: inline-end`
  - Grid/flex: `justify-items: start`, `justify-content: start`, `align-items: start`
  - This allows **one stylesheet** to serve both RTL and LTR by flipping the root `dir` attribute.
- **RTL/LTR switching**: `<html dir="rtl" lang="ar">` or `dir="ltr" lang="en">` set at app bootstrap via Vue I18n locale. No CSS-in-JS, no separate RTL build. Component library: **PrimeVue** (supports RTL, logical properties, accessible, data tables, calendars, charts) or **Headless UI** + custom components. Decision between them is TBD but both support the logical-properties approach.
- **Arabic-first constraint (D-002)**: All designs, copy, and validation messages authored in Arabic first. English translations derived. Bengali deferred (Phase B/C) but architecture forbids hard-coded direction assumptions — direction is always derived from locale metadata (`locale.dir`).
- **Formatting**: `Intl.NumberFormat('ar-SA', { useGrouping: true, numberingSystem: 'arab' })` for Arabic-Indic digits; `Intl.DateTimeFormat('ar-SA-u-ca-umalqura', ...)` for Hijri calendar (if required); `Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' })` for currency. Backend returns ISO 4217 currency code and decimal string; frontend formats.
- **Table-heavy grids**: TanStack Table (Vue) or PrimeVue DataTable with virtual scrolling, column pinning, RTL column order reversal, and server-side pagination/sorting.
## Criteria Applied
From Prd Maker §73:
- **Correctness**: TypeScript strict mode catches prop/event mismatches at compile time. String-only money from backend eliminates frontend float bugs.
- **Operational simplicity**: Single build, single deploy, single component library. Logical properties eliminate RTL-specific CSS duplication.
- **Testability**: Vitest + Vue Test Utils + Testing Library. RTL/LTR snapshot tests for critical components.
- **Cost**: Vue/TS/Tailwind talent is available in KSA/Gulf. PrimeVue/Headless UI reduce custom component burden.
- **Performance**: Vite dev server, ESBuild/SWC production build, code splitting by route, virtualized tables.
- **Reversibility**: Component logic is framework-agnostic where possible (composables, Pinia stores). Migration to React/Svelte would require rewriting components but not state/logic.
Criteria that did not drive the decision:
- **Ecosystem popularity**: Vue 3 was chosen for Composition API + TS + RTL maturity, not market share.
## Alternatives Considered
1. **React 18 + TypeScript + Tailwind**
   - What it is: Component library with hooks, concurrent features, large ecosystem.
   - Why rejected: React's RTL support relies on `dir` prop propagation or CSS-in-JS (e.g., `styled-components` with `rtl-css-js`). Logical properties work but the ecosystem (MUI, Ant Design, Chakra) often bakes physical properties into component internals, requiring overrides. Vue's single-file components with `<style>` scoped + logical properties is a cleaner one-stylesheet model. Vue I18n's runtime locale/direction reactivity is simpler than React's context-based i18n. Table-heavy grids: TanStack Table works in both, but PrimeVue's DataTable is more feature-complete for PMS grids (row expansion, frozen columns, RTL column order) with less configuration.
   - Deferred trigger: If team composition shifts to React expertise and a logical-properties-compatible component library is verified.

2. **Svelte 5 + TypeScript + Tailwind**
   - What it is: Compiler-based framework with runes, fine-grained reactivity.
   - Why rejected: RTL/component library maturity is lower. SvelteKit's SSR is excellent but the PMS is a SPA behind auth (SEO not a concern). Gulf/KSA Svelte talent is scarce. Migration risk higher.
   - Deferred trigger: Not applicable for Phase A.

3. **Blazor (WASM) + C#**
   - What it is: Full-stack .NET in the browser.
   - Why rejected: Bundle size, WASM debugging maturity, RTL component library gaps, Gulf/KSA talent pool for Blazor is minimal. Backend is PHP (ADR-0002); sharing types across PHP/TS is easier than PHP/C#.
   - Deferred trigger: Not applicable.
## Consequences
- All UI components must use logical properties exclusively. Physical properties (`left`, `right`, `margin-left`, etc.) are prohibited by lint rule (`stylelint` with `stylelint-plugin-logical-css`).
- Direction is never hard-coded. `dir` comes from `locale.dir` (derived from `navigator.language` or user preference, stored in Pinia).
- Arabic numerals are opt-in per locale, not global. `numberingSystem: 'arab'` for `ar` locales; `latn` for `en`/`bn`.
- Bengali support: Add `bn_BD` locale with `dir: 'ltr'`, `numberingSystem: 'beng'`, calendar `gregory`. No component changes required if direction/logical properties are used correctly.
- Accessibility: `lang` attribute on `<html>`, `dir` on `<html>`, focus management in RTL, ARIA labels in Arabic. Target WCAG version/level TBD (blocker C-08).
## Risks
- **Component library RTL bugs**: PrimeVue/Headless UI may have edge cases in RTL (e.g., calendar navigation, dropdown positioning). Mitigation: RTL smoke tests in CI for every component used.
- **Tailwind logical properties coverage**: Some utilities (e.g., `rotate`, `skew`) have no logical equivalent. Use physical properties only where no logical alternative exists, documented in a `rtl-exceptions.css` file with justification.
- **Font rendering**: Arabic fonts (Noto Sans Arabic, Tajawal) must be self-hosted or loaded via Saudi-region CDN. Font fallback chain must include Arabic glyphs.
- **Hijri calendar**: If legally required for guest-facing dates, `Intl.DateTimeFormat` with `ca-umalqura` is used. Backend stores Gregorian; frontend formats.
## Reversibility
Reversible at the component level. The composables (state, formatting, API clients) are framework-agnostic. Migration to React/Svelte would rewrite `.vue` files but preserve Pinia stores, API types, and formatting logic. Estimated effort: 3–4 months. Trigger: documented evidence that Vue 3 cannot meet a verified requirement (e.g., mandated framework by parent organization).
## References
- D-002 (Phased release, Arabic-first), D-006 (Vue 3 + TS + Tailwind)
- Prd Maker §20.7 (Accessibility), §20.8 (Localization)
- ADR-0006 (money representation — frontend receives strings), ADR-0015 (localization/RTL architecture)