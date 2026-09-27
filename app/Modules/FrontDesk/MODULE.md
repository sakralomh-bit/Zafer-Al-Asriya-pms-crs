# Module 6 — Front Desk

| Field | Value |
|---|---|
| Phase | A |
| Status | check-in, check-out, room transfer, stay extension, no-show, arrival/departure |
| Spec | `docs/ADR/0017-module-boundaries-within-the-monolith.md` §1 |

## Owns

Front Desk — T-011 — NOT STARTED (blocked by T-008, T-005, T-009)

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


