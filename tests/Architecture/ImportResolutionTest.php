<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Shared\Architecture\PhpSourceScanner;
use Tests\TestCase;

/**
 * Every `App\` import in this project must resolve to a file that exists.
 *
 * `php -l` proves a file PARSES. It does not prove that the classes it imports
 * exist, because a missing class is only fatal when that line executes. Two
 * real instances survived linting in this repository:
 *
 *   1. `ScopeGrantService` imported `App\Shared\Authorization\Permission`,
 *      which does not exist. `Permission` lives in
 *      `Identity\Authorization\Permission`. Calling `grant()` or `revoke()`
 *      would have fataled on the first call — in production, on a
 *      security-critical path.
 *   2. `config/auth.php` still pointed the `web` guard at Laravel's stock
 *      `App\Models\User`, which this project also does not have. The auth
 *      provider fails to resolve on the first login attempt rather than at
 *      boot, so CI would have stayed green right up to that.
 *
 * Both were invisible to the test suite because the test suite was empty, and
 * invisible to the lint pass because the files parse. This check is cheap and
 * mechanical, so there is no reason to discover the next one at runtime.
 *
 * It also enforces the inverse of the module boundary rule: a class may not be
 * imported from `app/Modules\Other\`, which is what
 * `tests/Architecture/GuardSelfTest` covers at the command level. This file
 * only asks a simpler question — does the thing exist?
 */
final class ImportResolutionTest extends TestCase
{
    public function test_every_app_import_resolves_to_an_existing_file(): void
    {
        $root = base_path();
        $unresolved = [];

        foreach ($this->scannedFiles() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $source, $matches);

            foreach ($matches[1] as $fqcn) {
                $path = $root . '/' . str_replace('\\', '/', $fqcn) . '.php';

                if (! is_file($path)) {
                    $unresolved[] = sprintf(
                        '%s imports %s, but %s does not exist.',
                        PhpSourceScanner::relative($file, $root),
                        $fqcn,
                        str_replace('\\', '/', $fqcn) . '.php',
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "Unresolvable App imports:\n - " . implode("\n - ", $unresolved),
        );
    }

    /**
     * Guards against the check itself becoming vacuous. If the regex ever stops
     * matching (a formatting change, a `use` written differently), this test
     * would still pass while checking nothing — which is precisely the failure
     * that made the no-float guard report success on code it should have
     * rejected.
     */
    public function test_the_import_scanner_actually_finds_imports(): void
    {
        $found = 0;

        foreach ($this->scannedFiles() as $file) {
            preg_match_all('/^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]+)\s*;/m', (string) file_get_contents($file), $matches);
            $found += count($matches[1]);
        }

        $this->assertGreaterThan(
            50,
            $found,
            'The import scanner found suspiciously few App imports. It is probably not matching, '
            . 'which would make the resolution test pass while checking nothing.',
        );
    }

    /**
     * The auth provider must name a model this project actually has. It is a
     * config file rather than a module, so the module boundary guard does not
     * cover it, and the stock skeleton value shipped pointing at a class that
     * does not exist here.
     */
    public function test_the_auth_provider_resolves_to_the_identity_user_model(): void
    {
        $model = config('auth.providers.users.model');

        $this->assertSame(
            \App\Modules\Identity\Models\User::class,
            $model,
            'The web guard must resolve to Identity\Models\User, not the stock App\Models\User.',
        );

        $this->assertTrue(class_exists($model), "The configured auth model [{$model}] does not exist.");
    }

    /**
     * Every `new SomeClass(...)` must target an INSTANTIABLE class.
     *
     * A missing class is fatal when the line runs. An ABSTRACT class, or an
     * interface, is exactly as fatal and just as invisible to `php -l`. Twelve
     * sites in this repository did exactly that: `new DomainFailure(...)`, where
     * `DomainFailure` is `abstract`. Every invalid room-state transition, every
     * housekeeping validation refusal, and the Support time-bound grant rule
     * raised `Cannot instantiate abstract class` instead of the documented
     * error code — so `AC-T-005-03` ("every invalid transition returns its
     * documented deterministic error code") was failing 100% of the time, on
     * every error path, and no test existed to see it.
     *
     * This is a cheap static check that turns that whole class of latent fatal
     * into a build failure.
     */
    public function test_nothing_instantiates_an_abstract_class_or_an_interface(): void
    {
        $root = base_path();
        $offenders = [];

        foreach ($this->scannedFiles() as $file) {
            foreach ($this->newClassInstantiations($file) as $hit) {
                $fqcn = $this->resolveClass($file, $short = $hit['class']);

                if ($fqcn === null || ! class_exists($fqcn)) {
                    continue;
                }

                $reflection = new \ReflectionClass($fqcn);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    $offenders[] = sprintf(
                        '%s:%d instantiates %s, which is %s.',
                        PhpSourceScanner::relative($file, $root),
                        $hit['line'],
                        $fqcn,
                        $reflection->isInterface() ? 'an interface' : 'abstract',
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Abstract/interface instantiation:\n - " . implode("\n - ", $offenders),
        );
    }

    /**
     * Every `new ClassName(` in a file, found at the TOKEN level.
     *
     * A regex over raw source matches this codebase's own explanatory
     * comments — the very comments that record why the check exists. Matching
     * prose about `new DomainFailure(...)` in a comment that says it fatals is
     * exactly the false positive that makes a guard get switched off, so the
     * scan goes through `PhpSourceScanner::codeTokens`, which drops comments and
     * string literals entirely.
     *
     * @return list<array{class: string, line: int}>
     */
    private function newClassInstantiations(string $file): array
    {
        $tokens = PhpSourceScanner::codeTokens($file);
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['type'] !== T_NEW) {
                continue;
            }

            // `new` may be followed by a namespace separator (`new \App\Foo()`),
            // and `codeTokens` KEEPS whitespace tokens, so both are skipped
            // before the class name. Skipping only the separator made this loop
            // stop on the space in `new DomainFailure(` and find nothing — a
            // check that reported clean on exactly the code it exists to catch.
            $j = $i + 1;

            while ($j < $count && in_array(
                $tokens[$j]['type'],
                [T_WHITESPACE, T_NS_SEPARATOR, '\\'],
                true,
            )) {
                $j++;
            }

            if ($j >= $count || $tokens[$j]['type'] !== T_STRING) {
                // `new class(...)` — an anonymous class. Valid, and intentionally
                // not checked: it declares its own shape at the call site.
                continue;
            }

            $class = $tokens[$j]['text'];
            $line = $tokens[$j]['line'];

            $k = $j + 1;
            while ($k < $count && $tokens[$k]['type'] === T_WHITESPACE) {
                $k++;
            }

            if ($k < $count && $tokens[$k]['type'] === '(') {
                $hits[] = ['class' => $class, 'line' => $line];
            }
        }

        return $hits;
    }

    /**
     * Resolve a short class name to its fully-qualified name using the `use`
     * statements of the file it appears in.
     *
     * Every `use` is indexed by its SHORT name, including explicit `as` aliases.
     * An earlier version took "the first `use` in the file" as the answer when
     * there was no alias, which resolved `new RuntimeException(...)` to whatever
     * happened to be imported first — `Migration` in the audit migration — and
     * then reported a phantom violation. A resolver that guesses is worse than
     * no resolver, because it produces confident nonsense.
     *
     * Returns null only for `self`/`static`/`parent` and for a bare global class
     * that is neither imported nor in this file's namespace.
     */
    private function resolveClass(string $file, string $short): ?string
    {
        // `self`, `static`, `parent` are resolved by PHP, not by a `use`.
        if (in_array(strtolower($short), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $source = (string) file_get_contents($file);

        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? '';
            $shortName = $alias !== '' ? $alias : substr(strrchr('\\' . $fqcn, '\\'), 1);

            if ($shortName === $short) {
                return $fqcn;
            }
        }

        // Not imported, so it is either declared in THIS file's namespace or is
        // a global class. The namespace is the right default: a class used
        // without a `use` inside `namespace App\Modules\Rooms\Domain;` refers to
        // that namespace, and treating it as global made the check silently miss
        // every same-namespace instantiation — which is where `DomainFailure`
        // actually lives.
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $match) === 1) {
            return $match[1] . '\\' . $short;
        }

        return $short;
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        return array_merge(
            PhpSourceScanner::phpFilesIn(base_path('app')),
            PhpSourceScanner::phpFilesIn(base_path('config')),
            PhpSourceScanner::phpFilesIn(base_path('database')),
            PhpSourceScanner::phpFilesIn(base_path('routes')),
            PhpSourceScanner::phpFilesIn(base_path('tests')),
        );
    }
}
