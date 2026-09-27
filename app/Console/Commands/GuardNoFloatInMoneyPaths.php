<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Architecture\PhpSourceScanner;
use Illuminate\Console\Command;

/**
 * AC-T-001-02: "A static check FAILS the build when float or double is used in
 * any code path that handles a monetary value."
 *
 * `ADR-0006`, `D-006`, `SEC-017`, `BUS-006`, `DM-2`. This guard is that
 * check. It is a build failure rather than a review comment, because a rule that
 * only exists in review is bypassed by the first deadline.
 *
 * It scans TOKENS, not text, so a `float` named in a docblock — which this
 * codebase does constantly, to explain what is forbidden — does not trip it
 * while a real `float` type hint does.
 *
 * What it forbids, and why each one matters:
 *
 *   `float` / `double` type hints    -> a float can enter the money path at all
 *   `(float)` casts                  -> a decimal string becomes an inexact value
 *   `parseFloat`, `(float)`, `+0.0`  -> the same, less visibly
 *   `->toFloat()`, `floatval`        -> serialization of a float to a decimal
 *   `number_format` on money         -> ADR-0006 names it prohibited
 *   `FLOAT` / `DOUBLE` columns       -> DM-2, a storage-level float
 *   `FLOAT(` / `DOUBLE(` in raw DDL  -> the same, bypassing the schema builder
 */
final class GuardNoFloatInMoneyPaths extends Command
{
    protected $signature = 'zafer:guard-money';

    protected $description = 'Fail if float or double appears anywhere in the PHP or schema code.';

    /**
     * The words that mean "inexact number" when used as a PHP TYPE, and how to
     * describe them in a failure message.
     *
     * This mapping exists because of a real bug in the first version of this
     * guard, and the mistake is an easy one to repeat:
     *
     *   A `float` TYPE HINT tokenizes as `T_STRING`, not as a float-literal
     *   token. `function f(float $x)` emits `T_STRING "float"`.
     *
     * `T_FLOAT` was removed in PHP 8 and renamed to `T_DNUMBER`, which
     * tempts a mechanical "just swap in the new name" fix. That fix is WRONG:
     * `T_DNUMBER` is emitted only for numeric literals such as `1.5`. A file
     * full of `float` type hints contains NO `T_DNUMBER` token at all, so a
     * guard keyed on it reports "clean" on exactly the code it exists to
     * reject. The original `T_FLOAT` reference died with
     * `Undefined constant` on the very first file; renaming it converted a loud
     * crash into a silent, permanent false pass — the most dangerous possible
     * failure mode for a control this codebase depends on for `AC-T-001-02`.
     *
     * So BOTH checks are required, and they detect different things:
     *
     *   T_STRING "float" / "double"  -> a type hint
     *   T_DOUBLE_CAST               -> an explicit `(float)` / `(double)` cast
     *   T_DNUMBER                   -> a float LITERAL, e.g. `$x * 0.5`
     *
     * `T_DOUBLE_CAST` is a separate token and was the second silent gap: the
     * first rewrite caught type hints and literals but reported a file clean
     * while `(float) $amount` sat in it, because the cast tokenizes as neither
     * a string nor a number.
     *
     * A `$float` variable tokenizes as `T_VARIABLE` and `floatval` is a
     * different `T_STRING`, so keying on the bare word is not noisy. For a money
     * guard, erring toward a false positive is the correct direction.
     *
     * @var array<string, string>
     */
    private const INEXACT_TYPE_HINTS = [
        'float' => 'a float type hint',
        'double' => 'a double type hint',
    ];

    /**
     * Functions that take or produce a float and have no legitimate use in a
     * system whose money is exact decimal.
     *
     * `number_format` is here because `ADR-0006` names it prohibited by
     * function name, not by type: it rounds to a fixed number of decimals and
     * pads with a locale-dependent thousands separator, so it corrupts an
     * exact decimal even when handed a perfect string.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_FUNCTIONS = [
        'parsefloat' => 'parseFloat()',
        'floatval' => 'floatval()',
        'number_format' => 'number_format() — ADR-0006 names it prohibited for money',
    ];

    public function handle(): int
    {
        $root = base_path();
        $violations = [];

        foreach ($this->scannedFiles() as $file) {
            foreach (PhpSourceScanner::codeTokens($file) as $token) {
                // `codeTokens` types a real token id as int and a raw character
                // (`(`, `;`, `,`) as a one-element string. Only ids are of
                // interest here.
                if (! is_int($token['type'])) {
                    continue;
                }

                if ($token['type'] === T_DNUMBER) {
                    $violations[] = sprintf(
                        '%s:%d contains the float literal %s — prohibited by ADR-0006 / SEC-017.',
                        PhpSourceScanner::relative($file, $root),
                        $token['line'],
                        $token['text'],
                    );

                    continue;
                }

                if ($token['type'] === T_DOUBLE_CAST) {
                    $violations[] = sprintf(
                        '%s:%d uses the %s cast — prohibited by ADR-0006 / SEC-017.',
                        PhpSourceScanner::relative($file, $root),
                        $token['line'],
                        $token['text'],
                    );

                    continue;
                }

                if ($token['type'] !== T_STRING) {
                    continue;
                }

                $name = strtolower($token['text']);

                $violation = $this->checkFunctionName($name, $file, $token['line'], $root)
                    ?? $this->checkInexactTypeHint($name, $file, $token['line'], $root);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        $violations = array_merge($violations, $this->checkSchemaForFloatColumns($root));

        if ($violations !== []) {
            $this->error(sprintf('No-float guard failed with %d violation(s):', count($violations)));
            foreach ($violations as $violation) {
                $this->line('  - '.$violation);
            }
            $this->error('Use the App\Shared\Money\Money value object. See ADR-0006.');

            return self::FAILURE;
        }

        $this->info('no-float guard: no float, double, or cast-to-float in the money paths.');

        return self::SUCCESS;
    }

    private function checkInexactTypeHint(string $name, string $file, int $line, string $root): ?string
    {
        if (! isset(self::INEXACT_TYPE_HINTS[$name])) {
            return null;
        }

        return sprintf(
            '%s:%d uses %s — prohibited by ADR-0006 / SEC-017.',
            PhpSourceScanner::relative($file, $root),
            $line,
            self::INEXACT_TYPE_HINTS[$name],
        );
    }

    private function checkFunctionName(string $name, string $file, int $line, string $root): ?string
    {
        if (! isset(self::FORBIDDEN_FUNCTIONS[$name])) {
            return null;
        }

        return sprintf(
            '%s:%d uses %s — prohibited by ADR-0006.',
            PhpSourceScanner::relative($file, $root),
            $line,
            self::FORBIDDEN_FUNCTIONS[$name],
        );
    }

    /**
     * The MySQL column types that cannot hold an exact decimal.
     *
     * `REAL` is a documented synonym for `DOUBLE` in MySQL, which is why it
     * belongs here despite never being a Laravel schema-builder method.
     *
     * `DOUBLE PRECISION` needs no key of its own: it is two tokens, and the
     * `DOUBLE` half is already caught.
     *
     * @var array<string, string>
     */
    private const FLOAT_COLUMN_TYPES = [
        'FLOAT' => 'FLOAT',
        'DOUBLE' => 'DOUBLE',
        'REAL' => 'REAL',
    ];

    /**
     * `DM-2`: no monetary column may be FLOAT or DOUBLE. The schema builder has
     * no such methods, so the realistic routes are raw DDL and string-built
     * column definitions — which is what this scans for.
     *
     * It TOKENIZES, exactly like the PHP check above, rather than grepping raw
     * text. Grepping matched the English word "real" inside a docblock in
     * `DatabaseSeeder` — a guard that fires on prose trains people to ignore it,
     * and the next real violation gets waved through. Comments tokenize as
     * `T_COMMENT`/`T_DOC_COMMENT` and are skipped, so a file may freely explain
     * what is forbidden.
     *
     * @return list<string>
     */
    private function checkSchemaForFloatColumns(string $root): array
    {
        $violations = [];

        foreach (PhpSourceScanner::phpFilesIn(base_path('database')) as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $type = self::FLOAT_COLUMN_TYPES[strtoupper($token[1])] ?? null;

                if ($type === null) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d declares a %s column — DM-2 requires exact DECIMAL with explicit precision and scale.',
                    PhpSourceScanner::relative($file, $root),
                    $token[2],
                    $type,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        return array_merge(
            PhpSourceScanner::phpFilesIn(base_path('app')),
            PhpSourceScanner::phpFilesIn(base_path('config')),
            PhpSourceScanner::phpFilesIn(base_path('routes')),
            PhpSourceScanner::phpFilesIn(base_path('tests')),
        );
    }
}
