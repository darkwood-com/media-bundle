<?php

declare(strict_types=1);

namespace App\Domain\Video\Enum;

enum AssetType: string
{
    case Video = 'video';
    case Voice = 'voice';
    case Final = 'final';
}
