<?php

namespace App\Support\Environment;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class EnvironmentFile
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<string, string>  $values
     */
    public function update(string $path, array $values): void
    {
        if (! $this->files->isFile($path)) {
            throw new RuntimeException("Environment file not found: {$path}");
        }

        $contents = $this->files->get($path);
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            $this->assertValidKey($key);

            $line = $key.'='.$this->encode($value);
            $pattern = '/^[\t ]*'.preg_quote($key, '/').'[\t ]*=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $line, $contents) ?? $contents;

                continue;
            }

            $contents = rtrim($contents, "\r\n").$newline.$line.$newline;
        }

        $stat = @stat($path);
        $this->files->replace($path, $contents);

        if (is_array($stat)) {
            @chmod($path, $stat['mode'] & 0777);

            if (function_exists('chown')) {
                @chown($path, $stat['uid']);
            }

            if (function_exists('chgrp')) {
                @chgrp($path, $stat['gid']);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function read(string $path): array
    {
        if (! $this->files->isFile($path)) {
            throw new RuntimeException("Environment file not found: {$path}");
        }

        $values = [];

        foreach (preg_split('/\R/', $this->files->get($path)) ?: [] as $line) {
            if (! preg_match('/^[\t ]*([A-Z][A-Z0-9_]*)[\t ]*=(.*)$/', $line, $matches)) {
                continue;
            }

            $values[$matches[1]] = $this->decode(trim($matches[2]));
        }

        return $values;
    }

    private function assertValidKey(string $key): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException("Invalid environment key: {$key}");
        }
    }

    private function encode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@-]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, "\\\"\n\r").'"';
    }

    private function decode(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
