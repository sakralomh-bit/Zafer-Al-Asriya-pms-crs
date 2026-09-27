<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Architecture\PhpSourceScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * AC-T-001: every PHP file this project owns declares strict types.
 *
 * This is not stylistic. `declare(strict_types=1)` is what turns a `float`
 * argument into a `TypeError` at a money boundary instead of a silent coercion
 * to a string that then parses back as an inexact decimal. ADR-0006 relies on
 * that behaviour: the `Money` constructor accepts only `string|int`, and this
 * guard is what makes that parameter type a control rather than a suggestion.
 */
final class GuardStrictTypes extends Command
{
    protected $signature = 'zafer:guard-strict-types';

    protected $description = 'Fail if any project PHP file omits declare(strict_types=1).';

    public function handle(): int
    {
        $root = base_path();
        $violations = [];

        foreach ($this->scannedDirectories() as $directory) {
            foreach (PhpSourceScanner::phpFilesIn($directory) as $file) {
                $source = (string) file_get_contents($file);

                if (! Str::contains($source, 'declare(strict_types=1)')) {
                    $violations[] = PhpSourceScanner::relative($file, $root);
                }
            }
        }

        if ($violations !== []) {
            $this->error(sprintf(
                '%d file(s) are missing declare(strict_types=1):',
                count($violations),
            ));
            foreach ($violations as $violation) {
                $this->line('  - ' . $violation);
            }
            $this->error('Strict typing is a money-safety control (ADR-0006), not a style preference.');

            return self::FAILURE;
        }

        $this->info('strict_types guard: all project PHP files declare strict types.');

        return self::SUCCESS;
    }

    /**
     * `vendor/` is excluded. The guard covers code this project owns.
     *
     * @return list<string>
     */
    private function scannedDirectories(): array
    {
        return [
            base_path('app'),
            base_path('config'),
            base_path('database'),
            base_path('routes'),
            base_path('tests'),
        ];
    }
}
