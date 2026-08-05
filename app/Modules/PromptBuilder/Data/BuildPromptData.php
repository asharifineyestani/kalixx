<?php

namespace App\Modules\PromptBuilder\Data;

use App\Modules\PromptBuilder\Enums\PromptTargetType;

final readonly class BuildPromptData
{
    public function __construct(
        public PromptTargetType $type,
        public string $target,
        public string $targetPath,
        public array $extraPaths = [],
        public ?string $outputFilename = null,
        public bool $withMigrations = false,
        public int $maxSizeKb = 300,
    ) {
    }
}
