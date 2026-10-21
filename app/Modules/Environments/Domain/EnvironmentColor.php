<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * The accent an environment carries in the portal, chosen from the theme palette.
 */
enum EnvironmentColor: string
{
    case Slate = 'slate';
    case Lilac = 'lilac';
    case Violet = 'violet';
    case Indigo = 'indigo';
    case Ink = 'ink';
}
