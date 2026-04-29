<?php

declare(strict_types=1);

namespace App\Domain\Video\Enum;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
