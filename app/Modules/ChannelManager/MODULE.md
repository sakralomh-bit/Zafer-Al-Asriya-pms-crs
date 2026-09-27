# Module 13 — Channel Manager

| Field | Value |
|---|---|
| Phase | C |
| Status | OTA adapters, channel mapping, sync health, reconciliation |
| Spec | `docs/ADR/0017-module-boundaries-within-the-monolith.md` §1 |

## Owns

Channel Manager — DEFERRED to Phase C (D-002). ALL partner behaviour is UNKNOWN and must not be invented

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


