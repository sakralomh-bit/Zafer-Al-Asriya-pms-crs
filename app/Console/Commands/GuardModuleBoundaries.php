<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Architecture\PhpSourceScanner;
use Illuminate\Console\Command;

/**
 * `AC-T-001-05`: the 14 module directories exist and are empty of cross-module
 * imports.
 *
 * `ADR-0017` §2: "The test for a boundary violation is simple and mechanical: if
 * adding `use App\Finance\` to a file in `Modules\Rooms\` would not be obviously
 * wrong to a reviewer, the boundary is not real."
 *
 * The rule this enforces, and its one deliberate exception:
 *
 *   A module may NOT import another module's `Models\` or `Services\`.
 *   A module MAY import another module's `Contracts\`.
 *
 * The exception is the entire point of `ADR-0017` §1: "Cross-module access goes
 * through an explicit contract interface owned by the providing module." Without
 * it there would be no way for a module to use another at all, and the guard
 * would simply push developers to route around it.
 */
final class GuardModuleBoundaries extends Command
{
    protected $signature = 'zafer:guard-modules';

    protected $description = 'Fail on cross-module imports outside the Contracts namespace.';

    /**
     * The fourteen modules of `ADR-0017` §1.
     *
     * @var list<string>
     */
    private const MODULES = [
        'Identity',
        'Organization',
        'Rooms',
        'Reservations',
        'Guests',
        'FrontDesk',
        'Housekeeping',
        'Financials',
        'Payments',
        'Tax',
        'NightAudit',
        'Crs',
        'ChannelManager',
        'Pos',
    ];

    /**
     * The namespaces each module exposes to the other thirteen, beyond its own
     * internals.
     *
     * `Contracts\` is universal and is the `ADR-0017` §1 rule proper: a module
     * is used only through interfaces the providing module owns.
     *
     * `Authorization\` is Identity's second, narrow exposure, and it is
     * deliberate rather than an oversight. `Identity\Authorization\Permission` is
     * a backed ENUM of capability NAMES. Every other module must name those
     * capabilities when it declares what it protects — there is no way for Rooms
     * to say "this transition needs `room.status.update`" without naming the
     * permission, and duplicating the string literal per module is precisely
     * how a permission matrix drifts.
     *
     * `Authorization\PermissionScope` is a real enum and `Authorization\Role` is a
     * value object, so they belong to the same exposure.
     *
     * What is still BANNED from Identity is the part with behaviour and
     * persistence: `Identity\Models\*` (including `User`) and
     * `Identity\Services\*` (including `AuthorizationService` and
     * `PropertyScopeResolver`). That is the boundary that actually matters, and
     * it is the one the module list below keeps enforcing for all fourteen.
     *
     * The map is keyed by module rather than written as a blanket rule so that
     * adding a second exposure to any module is a visible, reviewable edit here
     * instead of a silent widening of the guard.
     *
     * @var array<string, list<string>>
     */
    private const EXPOSED_NAMESPACES = [
        'Identity' => ['Contracts', 'Authorization'],
    ];

    /**
     * The namespaces that are NEVER exposed by any module, whatever the map
     * above says. `Models` and `Services` are the implementation behind the
     * contracts; reaching into them bypasses the interface and is the exact
     * failure `ADR-0017` §2 describes.
     *
     * @var list<string>
     */
    private const NEVER_EXPOSED = ['Models', 'Services'];

    public function handle(): int
    {
        $root = base_path();
        $modulesRoot = base_path('app/Modules');

        $missing = array_values(array_filter(
            self::MODULES,
            static fn (string $module): bool => ! is_dir($modulesRoot.'/'.$module),
        ));

        $violations = [];

        if ($missing !== []) {
            $violations[] = 'ADR-0017 requires 14 module directories; missing: '
                .implode(', ', $missing);
        }

        foreach (self::MODULES as $module) {
            $violations = array_merge(
                $violations,
                $this->scanModule($modulesRoot.'/'.$module, $module, $root),
            );
        }

        if ($violations !== []) {
            $this->error(sprintf('Module boundary guard failed with %d violation(s):', count($violations)));
            foreach ($violations as $violation) {
                $this->line('  - '.$violation);
            }
            $this->error('Cross-module access must go through the providing module\'s Contracts namespace.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'module boundary guard: %d modules present, no cross-module imports outside Contracts.',
            count(self::MODULES),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function scanModule(string $moduleRoot, string $module, string $root): array
    {
        $violations = [];

        foreach (PhpSourceScanner::phpFilesIn($moduleRoot) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/^\s*use\s+(App\\\\Modules\\\\[^;\s]+)\s*;/m', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $imported) {
                if ($this->isPermitted($imported, $module)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s imports %s — Modules\\%s may not reach into another module\'s %s (ADR-0017 §2). Use that module\'s Contracts or its declared exposures instead.',
                    PhpSourceScanner::relative($file, $root),
                    $imported,
                    $module,
                    implode('/', self::NEVER_EXPOSED),
                );
            }
        }

        return $violations;
    }

    /**
     * A module may import another module's EXPOSED_NAMESPACES, and may always
     * import its own internals and the shared (non-module) namespaces.
     */
    private function isPermitted(string $imported, string $owningModule): bool
    {
        $parts = explode('\\', $imported);

        // App\Modules\<Something>
        if (count($parts) < 3) {
            return true;
        }

        $targetModule = $parts[2];

        // Same module, or a shared (non-module) namespace.
        if ($targetModule === $owningModule) {
            return true;
        }

        if (! in_array($targetModule, self::MODULES, true)) {
            return true;
        }

        $subNamespace = implode('\\', array_slice($parts, 3, 2));

        // Implementation namespaces stay closed regardless of the map. This is
        // checked FIRST so a future edit to EXPOSED_NAMESPACES cannot quietly
        // re-open `Models\` or `Services\`.
        foreach (self::NEVER_EXPOSED as $forbidden) {
            if (str_starts_with($subNamespace, $forbidden)) {
                return false;
            }
        }

        foreach (self::EXPOSED_NAMESPACES[$targetModule] ?? ['Contracts'] as $exposed) {
            if (str_starts_with($subNamespace, $exposed)) {
                return true;
            }
        }

        return false;
    }
}
