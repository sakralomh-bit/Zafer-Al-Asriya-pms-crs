<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The CI guards are themselves code, and they were wrong.
 *
 * Four defects were found in this repository's guard layer on the pass that
 * first ran it, three of them in the no-float guard alone:
 *
 *   1. It referenced `T_FLOAT`, removed in PHP 8. It crashed with
 *      `Undefined constant` on the first file it scanned.
 *   2. Renaming that to `T_DNUMBER` "fixed" the crash and made things WORSE.
 *      `T_DNUMBER` is emitted only for literals like `1.5`; a `float` type hint
 *      tokenizes as `T_STRING`. The guard reported CLEAN on a file containing
 *      `function f(float $x)`. A loud crash had become a permanent false pass.
 *   3. `(float) $x` emits `T_DOUBLE_CAST` — neither a string nor a number
 *      token — so explicit casts slipped through even after (2).
 *   4. The DDL check regexed raw text, so the English word "real" in a docblock
 *      failed the build. A guard that fires on prose gets ignored, which is how
 *      a real violation gets waved through.
 *
 * None of these were caught by running the guard, because running the guard is
 * exactly what the guard does, and it was reporting success. A control that
 * asserts nothing needs a test that asserts the control works.
 *
 * So every rule below is verified in BOTH directions: a planted violation must
 * FAIL the guard, and the clean repository must PASS it. A guard that can only
 * pass is not a guard.
 *
 * `AC-T-001-02` — "A static check FAILS the build when float or double is used
 * in any code path that handles a monetary value."
 */
final class GuardSelfTest extends TestCase
{
    /**
     * Files planted during a test, removed in tearDown even on failure.
     *
     * A test that leaves a `float` in `app/` would break the next build with a
     * violation nobody wrote, so cleanup is unconditional rather than a
     * trailing assertion.
     *
     * @var list<string>
     */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->planted = [];

        parent::tearDown();
    }

    // =====================================================================
    // zafer:guard-money — must REJECT
    // =====================================================================

    public function test_it_rejects_a_float_type_hint(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Money;

        final class Probe
        {
            public function bad(float $amount): string
            {
                return (string) $amount;
            }
        }
        PHP);

        $this->assertMoneyGuardFails('a float type hint');
    }

    public function test_it_rejects_a_float_literal(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Money;

        final class Probe
        {
            public function bad(string $amount): string
            {
                return $amount . (0.5);
            }
        }
        PHP);

        $this->assertMoneyGuardFails('a float literal');
    }

    public function test_it_rejects_a_double_cast(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Money;

        final class Probe
        {
            public function bad(string $amount): string
            {
                return (float) $amount;
            }
        }
        PHP);

        $this->assertMoneyGuardFails('a (float) cast');
    }

    public function test_it_rejects_number_format(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Money;

        final class Probe
        {
            public function bad(string $amount): string
            {
                return number_format($amount, 2);
            }
        }
        PHP);

        $this->assertMoneyGuardFails('number_format()');
    }

    public function test_it_rejects_parse_float(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Money;

        final class Probe
        {
            public function bad(string $amount): string
            {
                return (string) parseFloat($amount);
            }
        }
        PHP);

        $this->assertMoneyGuardFails('parseFloat()');
    }

    public function test_it_rejects_a_float_column_type_in_the_schema(): void
    {
        $this->plant('database/migrations/9999_01_01_000000_probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration
        {
            public function up(): void
            {
                \Illuminate\Support\Facades\Schema::create('probe', function ($table) {
                    $table->float('amount');
                });
            }
        };
        PHP);

        $this->assertMoneyGuardFails('a FLOAT column');
    }

    // =====================================================================
    // zafer:guard-money — must NOT fire on prose
    // =====================================================================

    /**
     * Regression for defect (4).
     *
     * The DDL check used to grep raw text, so this file failed the build
     * because a docblock said "resemble real Arabic data". The rule is about
     * column TYPES, and a guard that cannot tell prose from code teaches the
     * team to disable it.
     */
    public function test_it_does_not_fire_on_float_mentioned_in_prose(): void
    {
        $this->plant('database/seeders/ProbeSeeder.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        /**
         * Seed data should resemble real traffic, not look synthetic.
         *
         * A float literal of 0.5 would be a REAL column in disguise, and
         * parseFloat / floatval / number_format are all prohibited here.
         */
        final class ProbeSeeder extends Seeder
        {
            public function run(): void
            {
                //
            }
        }
        PHP);

        $this->assertMoneyGuardPasses();
    }

    public function test_the_money_guard_passes_on_the_clean_repository(): void
    {
        $this->assertMoneyGuardPasses();
    }

    // =====================================================================
    // zafer:guard-strict-types
    // =====================================================================

    public function test_it_rejects_a_file_without_strict_types(): void
    {
        $this->plant('app/Shared/Money/Probe.php', <<<'PHP'
        <?php

        namespace App\Shared\Money;

        final class Probe
        {
        }
        PHP);

        $this->assertSame(1, Artisan::call('zafer:guard-strict-types'));
    }

    public function test_the_strict_types_guard_passes_on_the_clean_repository(): void
    {
        $this->assertSame(0, Artisan::call('zafer:guard-strict-types'));
    }

    // =====================================================================
    // zafer:guard-modules
    // =====================================================================

    public function test_it_rejects_a_cross_module_model_import(): void
    {
        $this->plant('app/Modules/Rooms/Services/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Rooms\Services;

        use App\Modules\Identity\Models\User;

        final class Probe
        {
            public function bad(User $user): string
            {
                return (string) $user->getKey();
            }
        }
        PHP);

        $this->assertSame(1, Artisan::call('zafer:guard-modules'));
    }

    public function test_it_rejects_a_cross_module_service_import(): void
    {
        $this->plant('app/Modules/Rooms/Services/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Rooms\Services;

        use App\Modules\Identity\Services\AuthorizationService;

        final class Probe
        {
            public function bad(AuthorizationService $service): void
            {
                $service->authorize();
            }
        }
        PHP);

        $this->assertSame(1, Artisan::call('zafer:guard-modules'));
    }

    public function test_it_rejects_a_cross_module_model_import_inside_another_module(): void
    {
        $this->plant('app/Modules/Housekeeping/Services/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Housekeeping\Services;

        use App\Modules\Rooms\Models\PhysicalRoom;

        final class Probe
        {
            public function bad(PhysicalRoom $room): string
            {
                return (string) $room->getKey();
            }
        }
        PHP);

        $this->assertSame(1, Artisan::call('zafer:guard-modules'));
    }

    /**
     * `ADR-0017` §1: contracts are the cross-module surface. Reaching through
     * one must be allowed, or the guard would force developers to route around
     * it rather than through it.
     */
    public function test_it_allows_a_contracts_import(): void
    {
        $this->plant('app/Modules/Rooms/Services/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Rooms\Services;

        use App\Modules\Identity\Contracts\Actor;
        use App\Modules\Identity\Contracts\AuthorizesRequests;

        final class Probe
        {
            public function good(AuthorizesRequests $authorization, Actor $actor): void
            {
                $authorization->authorize($actor);
            }
        }
        PHP);

        $this->assertSame(0, Artisan::call('zafer:guard-modules'));
    }

    /**
     * A module must be able to NAME the capability it protects, which means
     * importing Identity's `Permission` enum. Rejecting that would push
     * developers toward duplicating permission strings per module, which is
     * how a permission matrix drifts.
     */
    public function test_it_allows_the_identity_permission_enum(): void
    {
        $this->plant('app/Modules/Rooms/Services/Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Rooms\Services;

        use App\Modules\Identity\Authorization\Permission;

        final class Probe
        {
            public function good(): Permission
            {
                return Permission::RoomStatusUpdate;
            }
        }
        PHP);

        $this->assertSame(0, Artisan::call('zafer:guard-modules'));
    }

    public function test_the_module_guard_passes_on_the_clean_repository(): void
    {
        $this->assertSame(0, Artisan::call('zafer:guard-modules'));
    }

    // =====================================================================

    private function assertMoneyGuardFails(string $because): void
    {
        $exitCode = Artisan::call('zafer:guard-money');
        $output = Artisan::output();

        $this->assertSame(
            1,
            $exitCode,
            "Expected the no-float guard to FAIL on {$because}. Output was:\n{$output}",
        );
    }

    private function assertMoneyGuardPasses(): void
    {
        $exitCode = Artisan::call('zafer:guard-money');
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "Expected the no-float guard to PASS. Output was:\n{$output}",
        );
    }

    /**
     * @param  string  $relativePath  forward-slashed, relative to the project root
     */
    private function plant(string $relativePath, string $contents): void
    {
        $path = base_path(str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        $this->assertTrue(is_dir(dirname($path)), "Missing directory for probe: {$relativePath}");

        file_put_contents($path, $contents."\n");

        $this->planted[] = $path;
    }
}
