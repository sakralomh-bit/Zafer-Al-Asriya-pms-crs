<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Phase 0 seeds the authorization catalogue only.
 *
 * NO ORGANIZATION, PROPERTY, USER, ROOM, OR TASK DATA IS SEEDED.
 *
 * `docs/TEST-STRATEGY.md` §10 requires synthetic data only in every
 * non-production environment, and that it be "plausible-shaped but obviously
 * synthetic" — "Test Test" and "aaa@aaa.com" will not surface encoding,
 * collation, normalization, or length defects. Rather than invent seed data
 * that looks plausible, the fixtures live in `tests/Fixtures` where they are
 * explicitly labelled and rebuilt per test run, and the reference corpus that
 * must resemble real Arabic and mixed-script data is still owed
 * (`docs/TEST-STRATEGY.md` §7.1, gated on `B-05` for its size).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthorizationCatalogueSeeder::class,
        ]);
    }
}
