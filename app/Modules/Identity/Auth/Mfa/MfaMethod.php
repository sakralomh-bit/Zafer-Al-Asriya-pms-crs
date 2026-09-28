<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

/**
 * The second factors this project recognises.
 *
 * `docs/SECURITY.md` §12.1.1 row 12 records the mechanism as
 * `PROPOSED — SECURITY`: TOTP as the primary factor, passkey / WebAuthn
 * preferred where the staff device estate supports it. This enum is where that
 * proposal becomes a set of legal values rather than prose, so that an
 * unsupported factor is refused BY NAME instead of being coerced to whatever the
 * deployment happened to default to.
 *
 * `ADR-0016:23` requires "MFA challenge and failure" to be auditable, and
 * `SEC-001` requires MFA to be available. Neither names a mechanism, so nothing
 * here is a transcribed contract — the two cases are the documented direction.
 *
 * WHAT IS DELIBERATELY ABSENT, and why each omission is a security position
 * rather than an oversight:
 *
 *  - **SMS / `Sms`.** Defeated by SIM swap, by SS7 interception, and by a
 *    cost-based denial of service aimed at the SENDER, which makes a small
 *    property a cheap target. Usable as a break-glass recovery channel under
 *    supervision; never as a factor an attacker can talk a user out of.
 *  - **Email / `Email`.** Ordinarily the same single authenticated session the
 *    credential-reset path already uses, so a mailbox compromise defeats both
 *    and the "second factor" is not a second factor in the sense that matters.
 *  - **A bespoke push / OTP-over-SMS challenge.** No provider is selected
 *    (`B-02`, `B-04`), so there is nothing to verify against and choosing a
 *    vendor would be inventing an infrastructure fact.
 */
enum MfaMethod: string
{
    /** RFC 6238 time-based one-time password. The baseline; no network needed. */
    case Totp = 'totp';

    /**
     * FIDO2 / WebAuthn passkey. Origin-bound and therefore genuinely
     * phishing-resistant, which TOTP is not: a TOTP code is a bearer token an
     * attacker can relay from a phishing proxy. Preferred where supported.
     */
    case Passkey = 'passkey';

    /**
     * A method this project refuses as a FACTOR.
     *
     * Enumerated so that `SecurityPolicy` can name the refusal precisely. An
     * operator who configures SMS is a real scenario, and "unrecognised value"
     * would send them looking for a typo when the actual answer is that the
     * project will not use it.
     */
    case Sms = 'sms';

    case Email = 'email';

    /**
     * Can this method SATISFY a step-up or an MFA challenge?
     *
     * `Sms` and `Email` are reachable but always false. They exist in the enum so
     * the refusal is explicit and testable rather than a value that quietly
     * cannot be selected.
     */
    public function isAcceptableFactor(): bool
    {
        return match ($this) {
            self::Totp, self::Passkey => true,
            self::Sms, self::Email => false,
        };
    }

    /**
     * Why this method is not a factor, or null when it is one.
     *
     * Named rather than returned as a bare boolean, because "why was my setting
     * refused" is the question an operator actually has, and a refusal that
     * cannot explain itself gets worked around.
     */
    public function refusalReason(): ?string
    {
        return match ($this) {
            self::Totp, self::Passkey => null,
            self::Sms => 'SMS is not an acceptable second factor: it is defeated by SIM swap and '
                .'SS7 interception, and a per-message cost makes the sender a denial-of-service target. '
                .'It remains usable only as a supervised break-glass recovery channel.',
            self::Email => 'Email is not an acceptable second factor: it is ordinarily the same '
                .'single authenticated session the credential-reset path already uses, so a mailbox '
                .'compromise defeats both and no second factor is actually present.',
        };
    }
}
