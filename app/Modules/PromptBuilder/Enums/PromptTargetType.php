<?php

namespace App\Modules\PromptBuilder\Enums;

enum PromptTargetType: string
{
    case Module = 'module';
    case Package = 'package';
    case Service = 'service';
}
