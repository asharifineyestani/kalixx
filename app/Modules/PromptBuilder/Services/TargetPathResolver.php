<?php

namespace App\Modules\PromptBuilder\Services;

use App\Modules\PromptBuilder\Enums\PromptTargetType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TargetPathResolver
{
    public function resolve(
        PromptTargetType $type,
        string $target,
        ?string $customPath = null,
    ): string {
        if ($customPath !== null && trim($customPath) !== '') {
            return $this->projectPath($customPath);
        }

        return match ($type) {
            PromptTargetType::Module => $this->resolveModule($target),
            PromptTargetType::Package => $this->resolvePackage($target),
            PromptTargetType::Service => $this->resolveService($target),
        };
    }

    public function projectPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '') {
            throw new InvalidArgumentException('Path cannot be empty.');
        }

        if (
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized)
        ) {
            throw new InvalidArgumentException(
                'Only project-relative paths are allowed.'
            );
        }

        if (str_contains("/{$normalized}/", '/../')) {
            throw new InvalidArgumentException(
                'Parent directory traversal is not allowed.'
            );
        }

        return base_path(trim($normalized, '/'));
    }

    private function resolveModule(string $target): string
    {
        $name = Str::studly(
            pathinfo(
                basename(str_replace('\\', '/', $target)),
                PATHINFO_FILENAME
            )
        );

        return app_path("Modules/{$name}");
    }

    private function resolvePackage(string $target): string
    {
        $normalized = str_replace('\\', '/', trim($target, '/\\'));

        if (str_starts_with($normalized, 'packages/')) {
            return $this->projectPath($normalized);
        }

        if (str_contains($normalized, '/')) {
            return $this->projectPath("packages/{$normalized}");
        }

        return $this->projectPath(
            'packages/Medisa/' . Str::studly($normalized)
        );
    }

    private function resolveService(string $target): string
    {
        $normalized = str_replace('\\', '/', trim($target, '/\\'));

        if (str_starts_with($normalized, 'app/')) {
            return $this->projectPath($normalized);
        }

        if (str_starts_with($normalized, 'App/')) {
            $path = base_path(
                'app/' . Str::after($normalized, 'App/')
            );

            return $this->resolvePhpFileOrDirectory($path);
        }

        $serviceName = Str::studly(
            pathinfo(basename($normalized), PATHINFO_FILENAME)
        );

        return $this->resolvePhpFileOrDirectory(
            app_path("Services/{$serviceName}")
        );
    }

    private function resolvePhpFileOrDirectory(string $path): string
    {
        if (File::isDirectory($path) || File::isFile($path)) {
            return $path;
        }

        if (File::isFile("{$path}.php")) {
            return "{$path}.php";
        }

        return "{$path}.php";
    }
}
