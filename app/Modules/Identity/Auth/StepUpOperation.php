<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

/**
 * The canonical step-up operation set — SEVEN, and closed.
 *
 * Transcribed, not chosen. The three concordant sources are:
 *
 *   - `docs/SECURITY.md` §6.1, the normative table (`PRD.md:811` makes
 *     `SECURITY.md` normative for security).
 *   - `docs/API-SPEC.md` §3.11.1, which carries the same seven with endpoint
 *     mappings.
 *   - `PRD.md` `SEC-018`, which names the same seven by name in a formal
 *     security requirement: "Privileged actions (refund, configuration, scope
 *     grant, business-date reopen, export, impersonation, identity-document
 *     reveal) require step-up and produce an audit event."
 *
 * WHY THIS IS AN ENUM AND THEREFORE A CLOSED SET. "Sensitive" must not be
 * re-decided by whoever writes the next endpoint — a control whose trigger is
 * left to each caller is not a control. An enum makes the set finite and
 * exhaustively checkable: `StepUpOperation::cases()` is the whole list, adding an
 * operation is a visible code change, and `SECURITY.md` §6.1 says in as many
 * words that *"An operation not on this list does not require step-up. Adding an
 * eighth is a change to this table, not an implementation detail."*
 *
 * WHAT IS DELIBERATELY NOT HERE, and why each omission is a recorded governance
 * question rather than an oversight.
 *
 * `docs/STATE-MACHINES.md` requires step-up for three operations that are not on
 * the list: reservation cancellation (`A-T16`, line 78), raising `OUT_OF_ORDER`
 * (line 155), and `FORCED_CLOSE` (line 492). They are absent because that
 * document is subordinate and self-declared: `PRD.md:811` makes `SECURITY.md`
 * normative, and `STATE-MACHINES.md:7` calls itself "Draft — specification only.
 * Nothing is implemented", with §0 stating its contents are requirements for
 * `TASKS.md` rather than descriptions of behaviour. A draft specification does
 * not add to a normative control set. See `docs/SECURITY.md` §12.1.7 "Conflict C".
 *
 * The consequence is stated so nobody has to infer it: **if the PM decides those
 * three operations require step-up, they must be added to `SECURITY.md` §6.1,
 * `API-SPEC.md` §3.11.1, and `PRD.md` `SEC-018` first, and this enum with them.**
 * Adding a case here without that would make the code diverge from the
 * normative document while looking more complete.
 *
 * `ADR-0014` §6 is NOT the source. It is a separation-of-duties matrix of nine
 * rows and only one of them — Refund — names step-up at all. `AC-T-004-05`
 * points at it as though it were the catalogue; that cross-reference is recorded
 * as a defect in `docs/SECURITY.md` §12.1.7 "Conflict A" and the ADR itself is
 * not modified.
 *
 * @see docs/SECURITY.md §6.1 — the normative table
 * @see docs/API-SPEC.md §3.11.1
 */
enum StepUpOperation: string
{
    /** `ADR-0014` §6: the approver must not be the requester. */
    case Refund = 'refund';

    /** `SEC-006`, `TH-16`: a config change rewrites every future statement. */
    case Configuration = 'configuration';

    /** `ADR-0014` §6: the widest privilege in the system; self-granting is barred. */
    case ScopeGrant = 'scope_grant';

    /** `ADR-0014` §6: rewrites already-closed accounting periods. */
    case BusinessDateReopen = 'business_date_reopen';

    /** `TH-12`: bulk extraction of guest PII. */
    case Export = 'export';

    /** `ADR-0014` §6: act-as is the strongest privilege of all. Default DENY. */
    case Impersonation = 'impersonation';

    /** `ADR-0012` §4.1: defeats masking, which is the control on that data. */
    case IdentityDocumentReveal = 'identity_document_reveal';

    /**
     * Resolve an operation name that may have come from a route, a form field,
     * or a config value.
     *
     * Returns null for anything outside the set rather than throwing, because
     * the caller is almost always deciding whether to REFUSE, and a refusal
     * path that first has to catch an exception is a refusal path that can be
     * forgotten. An unknown name is therefore treated as "not a step-up
     * operation that this build recognises" and the gate refuses it.
     */
    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }

    /**
     * Every operation, for documentation and for tests that assert the set is
     * exactly seven.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
