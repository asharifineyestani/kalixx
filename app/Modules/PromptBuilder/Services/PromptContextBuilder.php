<?php

namespace App\Modules\PromptBuilder\Services;

use App\Modules\PromptBuilder\Data\BuildPromptData;
use App\Modules\PromptBuilder\Enums\PromptTargetType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class PromptContextBuilder
{
    /**
     * @return array{path: string, count: int}
     */
    public function build(BuildPromptData $data): array
    {
        $files = $this->collectFilesFromPath(
            $data->targetPath,
            $data->maxSizeKb
        );

        foreach ($data->extraPaths as $extraPath) {
            $files = array_merge(
                $files,
                $this->collectFilesFromPath(
                    $extraPath,
                    $data->maxSizeKb
                )
            );
        }

        if (
            $data->type === PromptTargetType::Module
            && $data->withMigrations
        ) {
            $files = array_merge(
                $files,
                $this->collectRelatedMigrations(
                    $data->targetPath,
                    $data->maxSizeKb
                )
            );
        }

        $files = collect($files)
            ->filter(
                fn (SplFileInfo $file): bool => $file->getRealPath() !== false
            )
            ->unique(
                fn (SplFileInfo $file): string => (string) $file->getRealPath()
            )
            ->sortBy(
                fn (SplFileInfo $file): string => $this->relativePath(
                    (string) $file->getRealPath()
                )
            )
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException('No files were found.');
        }

        $outputDirectory = base_path(
            trim(
                (string) config(
                    'prompt-builder.output_directory',
                    'prompt'
                ),
                '/\\'
            )
        );

        File::ensureDirectoryExists($outputDirectory);

        $outputPath = $outputDirectory
            . DIRECTORY_SEPARATOR
            . $this->resolveOutputFilename($data);

        $stream = fopen($outputPath, 'wb');

        if ($stream === false) {
            throw new RuntimeException(
                "Unable to create output file: {$outputPath}"
            );
        }

        try {
            fwrite(
                $stream,
                $this->buildHeader($data, $files->count())
            );

            foreach ($files as $file) {
                fwrite(
                    $stream,
                    $this->formatFileContent($file)
                );
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Failed to build prompt context.',
                previous: $exception
            );
        } finally {
            fclose($stream);
        }

        return [
            'path' => $outputPath,
            'count' => $files->count(),
        ];
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function collectFilesFromPath(
        string $path,
        int $maxSizeKb,
    ): array {
        if (File::isFile($path)) {
            $file = new SplFileInfo($path);

            return $this->shouldIncludeFile($file, $maxSizeKb)
                ? [$file]
                : [];
        }

        if (! File::isDirectory($path)) {
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator(
            $path,
            RecursiveDirectoryIterator::SKIP_DOTS
        );

        $filterIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $item): bool {
                if (! $item->isDir()) {
                    return true;
                }

                return ! $this->isIgnoredDirectory($item);
            }
        );

        $iterator = new RecursiveIteratorIterator($filterIterator);

        $files = [];

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if (! $this->shouldIncludeFile($file, $maxSizeKb)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function collectRelatedMigrations(
        string $modulePath,
        int $maxSizeKb,
    ): array {
        $migrationPath = database_path('migrations');

        if (! File::isDirectory($migrationPath)) {
            return [];
        }

        $moduleName = pathinfo(
            basename($modulePath),
            PATHINFO_FILENAME
        );

        $snakeName = Str::snake($moduleName);
        $kebabName = Str::kebab($moduleName);

        $keywords = array_unique([
            $snakeName,
            Str::plural($snakeName),
            $kebabName,
            Str::plural($kebabName),
        ]);

        return array_values(
            array_filter(
                $this->collectFilesFromPath(
                    $migrationPath,
                    $maxSizeKb
                ),
                function (SplFileInfo $file) use ($keywords): bool {
                    $filename = Str::lower($file->getFilename());

                    foreach ($keywords as $keyword) {
                        if (
                            $keyword !== ''
                            && str_contains(
                                $filename,
                                Str::lower($keyword)
                            )
                        ) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );
    }

    private function shouldIncludeFile(
        SplFileInfo $file,
        int $maxSizeKb,
    ): bool {
        if (! $file->isFile()) {
            return false;
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return false;
        }

        $relativePath = $this->relativePath($realPath);

        if ($this->containsIgnoredDirectory($relativePath)) {
            return false;
        }

        if ($this->matchesIgnoredFilePattern($file, $relativePath)) {
            return false;
        }

        $extension = Str::lower($file->getExtension());

        $ignoredExtensions = config(
            'prompt-builder.ignored_extensions',
            []
        );

        if (in_array($extension, $ignoredExtensions, true)) {
            return false;
        }

        if (
            $maxSizeKb > 0
            && $file->getSize() > ($maxSizeKb * 1024)
        ) {
            return false;
        }

        return $this->isTextFile($realPath);
    }

    private function isIgnoredDirectory(SplFileInfo $directory): bool
    {
        $realPath = $directory->getRealPath();

        if ($realPath === false) {
            return true;
        }

        return $this->containsIgnoredDirectory(
            $this->relativePath($realPath)
        );
    }

    private function containsIgnoredDirectory(
        string $relativePath,
    ): bool {
        $path = '/' . trim(
            str_replace('\\', '/', $relativePath),
            '/'
        ) . '/';

        foreach (
            config('prompt-builder.ignored_directories', [])
            as $ignoredDirectory
        ) {
            $ignored = '/' . trim(
                str_replace('\\', '/', $ignoredDirectory),
                '/'
            ) . '/';

            if (str_contains($path, $ignored)) {
                return true;
            }
        }

        return false;
    }

    private function matchesIgnoredFilePattern(
        SplFileInfo $file,
        string $relativePath,
    ): bool {
        foreach (
            config('prompt-builder.ignored_file_patterns', [])
            as $pattern
        ) {
            if (
                Str::is($pattern, $file->getFilename())
                || Str::is($pattern, $relativePath)
            ) {
                return true;
            }
        }

        return false;
    }

    private function isTextFile(string $path): bool
    {
        $contents = @file_get_contents(
            $path,
            false,
            null,
            0,
            8192
        );

        if ($contents === false) {
            return false;
        }

        return ! str_contains($contents, "\0");
    }

    private function buildHeader(
        BuildPromptData $data,
        int $fileCount,
    ): string {
        return implode(PHP_EOL, [
            'Laravel Prompt Context',
            'Generated at: ' . now()->toDateTimeString(),
            'Project: ' . basename(base_path()),
            'Type: ' . $data->type->value,
            'Target: ' . $data->target,
            'Path: ' . $this->relativePath($data->targetPath),
            'Files: ' . $fileCount,
            sprintf(
                'Command: php artisan prompt:build %s %s',
                $data->type->value,
                $data->target
            ),
            '========================================',
            '',
            '',
        ]);
    }

    private function formatFileContent(
        SplFileInfo $file,
    ): string {
        $path = $file->getRealPath();

        if ($path === false) {
            return '';
        }

        return implode(PHP_EOL, [
            '',
            '===== FILE: ' . $this->relativePath($path) . ' =====',
            File::get($path),
            '',
            '',
        ]);
    }

    private function resolveOutputFilename(
        BuildPromptData $data,
    ): string {
        if ($data->outputFilename !== null) {
            $filename = trim($data->outputFilename);

            if (
                $filename === ''
                || basename($filename) !== $filename
            ) {
                throw new RuntimeException(
                    'The output option must only contain a filename.'
                );
            }

            return $filename;
        }

        $relativePath = $this->relativePath(
            $data->targetPath
        );

        $relativePath = match ($data->type) {
            PromptTargetType::Module => Str::after(
                $relativePath,
                'app/Modules/'
            ),
            PromptTargetType::Package => Str::after(
                $relativePath,
                'packages/'
            ),
            PromptTargetType::Service => Str::after(
                $relativePath,
                'app/Services/'
            ),
        };

        $relativePath = Str::beforeLast(
            $relativePath,
            '.php'
        );

        $label = Str::kebab(
            str_replace(['/', '\\'], '-', $relativePath)
        );

        return "{$data->type->value}-{$label}-context.txt";
    }

    private function relativePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        $normalizedBasePath = rtrim(
            str_replace('\\', '/', base_path()),
            '/'
        ) . '/';

        if (str_starts_with($normalizedPath, $normalizedBasePath)) {
            return substr(
                $normalizedPath,
                strlen($normalizedBasePath)
            );
        }

        return $normalizedPath;
    }
}
