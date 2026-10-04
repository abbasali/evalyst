<?php

namespace App\Services\GitHub;

/**
 * Decides which tree entries are worth downloading (pure). Runs before any file content is
 * fetched, so vendor/, node_modules/, lock files, binaries and big files are never requested.
 * See docs/04-github-ingestion.md.
 */
class PathFilter
{
    /**
     * @param  list<string>  $directories  Path segments, or segment sequences such as "bootstrap/cache".
     * @param  list<string>  $patterns  Globs matched against the basename and the full path.
     * @param  list<string>  $allowed  Basenames that are kept even if a pattern matches (e.g. .env.example).
     * @param  list<string>  $extensions  Binary/media extensions.
     */
    public function __construct(
        private array $directories,
        private array $patterns,
        private array $allowed,
        private array $extensions,
        private int $maxFileBytes,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('evalyst.github.ignored_paths');

        return new self(
            array_values((array) ($config['directories'] ?? [])),
            array_values((array) ($config['files'] ?? [])),
            array_values((array) ($config['files_allowed'] ?? [])),
            array_values((array) ($config['extensions'] ?? [])),
            (int) config('evalyst.github.max_file_bytes'),
        );
    }

    /**
     * @param  list<array{path: string, type: string, size: int}>  $entries  Tree entries (blobs and trees).
     * @param  list<string>  $extraGlobs  The assessment's extra ignored paths.
     * @return array{included: list<array{path: string, size: int}>, skipped: array<string, string>}
     */
    public function filter(array $entries, array $extraGlobs = []): array
    {
        $included = [];
        $skipped = [];

        foreach ($entries as $entry) {
            if ($entry['type'] !== 'blob') {
                continue;
            }

            $reason = $this->reason($entry['path'], $entry['size'], $extraGlobs);

            if ($reason === null) {
                $included[] = ['path' => $entry['path'], 'size' => $entry['size']];
            } elseif ($reason !== 'ignored_dir') {
                // Files under ignored directories (vendor/…) aren't even listed: there can be thousands.
                $skipped[$entry['path']] = $reason;
            }
        }

        return ['included' => $included, 'skipped' => $skipped];
    }

    /**
     * Why a path is skipped, or null when it should be downloaded.
     *
     * @param  list<string>  $extraGlobs
     */
    public function reason(string $path, int $size, array $extraGlobs = []): ?string
    {
        $path = ltrim($path, '/');
        $basename = basename($path);
        $directory = '/'.dirname($path).'/';

        foreach ($this->directories as $ignored) {
            if (str_contains(strtolower($directory), '/'.strtolower(trim($ignored, '/')).'/')) {
                return 'ignored_dir';
            }
        }

        if (! in_array($basename, $this->allowed, true)) {
            foreach ($this->patterns as $pattern) {
                if (fnmatch($pattern, $basename, FNM_CASEFOLD) || fnmatch($pattern, $path, FNM_CASEFOLD)) {
                    return 'ignored_pattern';
                }
            }
        }

        foreach ($extraGlobs as $glob) {
            if (self::matches($glob, $path)) {
                return 'ignored_pattern';
            }
        }

        if (in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), $this->extensions, true)) {
            return 'binary';
        }

        return $size > $this->maxFileBytes ? 'too_large' : null;
    }

    /**
     * Glob match where `**` spans directories and `*` stays within one segment.
     * "vendor/**" also matches "vendor" itself; a bare path matches only that path.
     */
    public static function matches(string $glob, string $path): bool
    {
        $directory = str_ends_with(trim($glob), '/');
        $glob = trim(trim($glob), '/');

        if ($glob === '') {
            return false;
        }

        // "docs/" means the folder; a plain name also covers a folder of that name.
        if ($directory) {
            $glob .= '/**';
        } elseif (strpbrk($glob, '*?') === false && str_starts_with($path, $glob.'/')) {
            return true;
        }

        if (str_ends_with($glob, '/**') && self::matches(substr($glob, 0, -3), $path)) {
            return true;
        }

        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length;) {
            if (substr($glob, $i, 3) === '**/') {
                $regex .= '(?:.*/)?';
                $i += 3;
            } elseif (substr($glob, $i, 2) === '**') {
                $regex .= '.*';
                $i += 2;
            } else {
                $regex .= match ($glob[$i]) {
                    '*' => '[^/]*',
                    '?' => '[^/]',
                    default => preg_quote($glob[$i], '#'),
                };
                $i++;
            }
        }

        return preg_match('#^'.$regex.'$#', $path) === 1;
    }
}
