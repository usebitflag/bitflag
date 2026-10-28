<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * The light theme's colors, as hex values, for email. Mail clients do not understand CSS variables, so the layout cannot
 * use the app's theme file; a test keeps these equal to `design/bitflag/tokens.json`.
 */
final class EmailTheme
{
    public const BACKGROUND = '#f4f4f5';

    public const SURFACE = '#ffffff';

    public const TEXT = '#0a0a0f';

    public const MUTED = '#71717a';

    public const DIVIDER = '#e7e7ea';

    public const PRIMARY = '#6d4fe0';

    public const PRIMARY_FOREGROUND = '#ffffff';

    public const PRIMARY_SOFT = '#f0edfc';

    public const WARNING_TEXT = '#92400e';

    public const WARNING_SUBTLE = '#f6eae1';

    public const WARNING_BORDER = '#e5c3a9';

    public const FONT = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";

    public const MONO = "'Azeret Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
}
