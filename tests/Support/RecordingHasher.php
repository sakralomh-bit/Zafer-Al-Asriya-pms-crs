<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;

/**
 * A `Hasher` that counts how many times it was asked to do cryptographic work.
 *
 * WHY THIS EXISTS. `AuthenticationService` is required to make the
 * "no such account" path cost the same as the "wrong password" path, so that
 * the two cannot be told apart by timing. A timing assertion in a test suite is
 * not a test suite — it is a flake generator. What IS deterministic, and what
 * the timing defence actually rests on, is that the same NUMBER of key
 * derivations happens on every one of the three login paths.
 *
 * So this records the derivations instead of timing them, and
 * `AuthenticationTest` asserts all three paths perform exactly the same work.
 * That is a real property: if someone later removes the dummy verification, the
 * count on the unknown-account path drops to zero and the test fails.
 *
 * The inner hasher is the framework's own and is supplied by the caller. This
 * class invents no cryptographic primitive.
 */
final class RecordingHasher implements Hasher
{
    private int $makeCalls = 0;

    private int $checkCalls = 0;

    public function __construct(private readonly Hasher $inner) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function make($value, array $options = [])
    {
        $this->makeCalls++;

        return $this->inner->make($value, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check($value, $hashedValue, array $options = [])
    {
        $this->checkCalls++;

        return $this->inner->check($value, $hashedValue, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function info($hashedValue)
    {
        return $this->inner->info($hashedValue);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }

    /**
     * Number of password VERIFICATIONS performed. A `make()` is a hash being
     * created, not a credential being checked, so it is counted separately.
     */
    public function checkCalls(): int
    {
        return $this->checkCalls;
    }

    /**
     * Number of hashes CREATED, including the throwaway one used to equalise
     * the unknown-account path.
     */
    public function makeCalls(): int
    {
        return $this->makeCalls;
    }

    public function reset(): void
    {
        $this->makeCalls = 0;
        $this->checkCalls = 0;
    }
}
