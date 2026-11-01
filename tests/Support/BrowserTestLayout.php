<?php

namespace Uteq\FeedbackHub\Tests\Support;

use Illuminate\Support\Facades\Blade;

final class BrowserTestLayout
{
    public static function render(string $body): string
    {
        return Blade::render(<<<BLADE
            <!DOCTYPE html>
            <html lang="nl">
            <head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Feedback test</title>@livewireStyles</head>
            <body style="margin: 0; padding: 16px">{$body} @livewireScripts</body>
            </html>
            BLADE);
    }
}
