# Module 12 — CRS

| Field | Value |
|---|---|
| Phase | B |
| Status | availability search, booking engine, guest-facing booking, online capture |
| Spec | `docs/ADR/0017-module-boundaries-within-the-monolith.md` §1 |

## Owns

CRS — DEFERRED to Phase B (D-002). Reuses the SAME inventory, reservation and payment abstractions — it is an ingress path, not a parallel booking engine (ADR-0017 §5)

## Boundary

This directory is a **module boundary**, not a folder convention (`ADR-0017` §2).
A module may not read or write another module's tables directly; cross-module
access goes through an explicit contract interface in this module's
`Contracts/` namespace. `tests/Architecture/ModuleBoundaryTest.php` fails the
build if a file here imports another module's `Models` or `Services`.

## Shared-transaction exception

Per `ADR-0017` §3, only the five enumerated operations may commit across
modules in one transaction. This module participates in **none** of them.

## Task


