<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Markdown;

/** Laravel's normal view/theme pipeline, with guarded system-mail inlining. */
final class SystemMailMarkdown extends Markdown
{
    public function render($view, array $data = [], $inliner = null)
    {
        return parent::render($view, $data, $inliner ?? new SystemMailCssInliner);
    }
}
