<?php

declare(strict_types=1);

namespace App\Shared\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A minimal PHP source scanner used by the CI guards.
 *
 * The guards are intentionally TOKEN-based rather than regex-only, so a
 * prohibited construct cannot hide inside a comment or a string literal and
 * cause a false pass. `php -l` is not used for the same reason: it proves the
 * file parses, not that it is free of `float`.
 */
final class PhpSourceScanner
{
    /**
     * @return list<string> absolute paths of PHP files under $directory
     */
    public static function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strip comments and string literals, keeping the offsets of real code.
     *
     * `token_get_all` gives us this exactly, and it is the reason a guard can be
     * trusted: a `float` mentioned in a docblock is not a `float` in the
     * program, and a guard that cannot tell the difference is a guard that gets
     * disabled.
     *
     * `type` is a token id (an `int`) for anything `token_get_all` recognised, and
     * the raw character for the single-character tokens it returns as a plain
     * string — `(`, `;`, `,`, `{`. A consumer that cares only about language
     * constructs checks `is_int($token['type'])`, which is what the money guard
     * does.
     *
     * @return list<array{type: int|string, text: string, line: int}>
     */
    public static function codeTokens(string $file): array
    {
        $source = (string) file_get_contents($file);
        $tokens = token_get_all($source);
        $result = [];
        $line = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $line = $token[2];
                $id = $token[0];

                // Skip comments and docblocks entirely.
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }

                // Keep string literals as markers but do not let their CONTENT be
                // scanned for code. HTML and whitespace are noise.
                if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_INLINE_HTML) {
                    $result[] = ['type' => $id, 'text' => '', 'line' => $line];

                    continue;
                }

                $result[] = ['type' => $id, 'text' => trim($token[1]), 'line' => $line];

                continue;
            }

            $result[] = ['type' => $token, 'text' => $token, 'line' => $line];
        }

        return $result;
    }

    public static function relative(string $path, string $root): string
    {
        $normalisedRoot = rtrim(str_replace('\\', '/', $root), '/');

        return str_replace('\\', '/', str_replace($normalisedRoot, '', $path));
    }
}
