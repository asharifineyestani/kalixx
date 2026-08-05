<?php

namespace App\Modules\PromptBuilder\Console\Commands;

use App\Modules\PromptBuilder\Data\BuildPromptData;
use App\Modules\PromptBuilder\Enums\PromptTargetType;
use App\Modules\PromptBuilder\Services\PromptContextBuilder;
use App\Modules\PromptBuilder\Services\TargetPathResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class BuildPromptCommand extends Command
{
    protected $signature = 'prompt:build
                            {type : Target type: module, package, or service}
                            {target : Target name or project-relative path}
                            {--path= : Custom project-relative target path}
                            {--output= : Custom output filename}
                            {--with-migrations : Include related module migrations}
                            {--extra=* : Extra project-relative file or folder paths}
                            {--max-size= : Maximum file size in KB}';

    protected $description = 'Build an AI prompt context from a module, package, or service.';

    public function handle(
        TargetPathResolver $pathResolver,
        PromptContextBuilder $builder,
    ): int {
        $type = PromptTargetType::tryFrom(
            Str::lower((string) $this->argument('type'))
        );

        if ($type === null) {
            $this->error(
                'Invalid type. Allowed values: module, package, service.'
            );

            return self::FAILURE;
        }

        $target = trim((string) $this->argument('target'));

        try {
            $targetPath = $pathResolver->resolve(
                $type,
                $target,
                $this->option('path')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! File::exists($targetPath)) {
            $this->error("Target path not found: {$targetPath}");

            return self::FAILURE;
        }

        $extraPaths = [];

        foreach ((array) $this->option('extra') as $extraPath) {
            try {
                $absolutePath = $pathResolver->projectPath(
                    $extraPath
                );
            } catch (Throwable) {
                $this->warn(
                    "Invalid extra path ignored: {$extraPath}"
                );

                continue;
            }

            if (! File::exists($absolutePath)) {
                $this->warn(
                    "Extra path not found: {$extraPath}"
                );

                continue;
            }

            $extraPaths[] = $absolutePath;
        }

        $maxSizeKb = $this->option('max-size') !== null
            ? (int) $this->option('max-size')
            : (int) config(
                'prompt-builder.max_size_kb',
                300
            );

        if ($maxSizeKb < 0) {
            $this->error(
                'The max-size option cannot be negative.'
            );

            return self::FAILURE;
        }

        $withMigrations = (bool) $this->option(
            'with-migrations'
        );

        if (
            $withMigrations
            && $type !== PromptTargetType::Module
        ) {
            $this->warn(
                'The with-migrations option only applies to modules.'
            );

            $withMigrations = false;
        }

        try {
            $result = $builder->build(
                new BuildPromptData(
                    type: $type,
                    target: $target,
                    targetPath: $targetPath,
                    extraPaths: $extraPaths,
                    outputFilename: $this->option('output'),
                    withMigrations: $withMigrations,
                    maxSizeKb: $maxSizeKb,
                )
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            'Prompt context generated successfully.'
        );

        $this->line("Type: {$type->value}");
        $this->line("Target: {$target}");
        $this->line("Files included: {$result['count']}");
        $this->line("Output: {$result['path']}");

        return self::SUCCESS;
    }
}
