<?php

declare(strict_types=1);

namespace App\Domain\Video\Enum;

enum SceneStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
