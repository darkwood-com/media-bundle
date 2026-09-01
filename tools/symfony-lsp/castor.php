<?php

declare(strict_types=1);

namespace App\Tools;

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\run;

#[AsTask(description: 'Run Symfony-aware LSP diagnostics', aliases: ['symfony-lsp'])]
function symfonyLsp(): int
{
    return run(
        [__DIR__ . '/check.sh'],
        context()->withAllowFailure(),
    )->getExitCode();
}
