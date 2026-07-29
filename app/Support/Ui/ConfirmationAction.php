<?php

namespace App\Support\Ui;

use Illuminate\Support\Js;
use InvalidArgumentException;

final class ConfirmationAction
{
    /**
     * Build the Alpine expression used by confirmation-enabled UI components.
     *
     * Blade directives inside an attribute on an anonymous component are not
     * compiled a second time. Keeping the serialization here ensures that the
     * final HTML attribute contains executable JavaScript instead of an
     * uncompiled Blade JavaScript-serialization directive.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function alpine(
        string $method,
        array $arguments = [],
        ?string $title = null,
        ?string $message = null,
        string $variant = 'destructive',
        ?string $confirmLabel = null,
    ): string {
        $method = trim($method);

        if (! preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $method)) {
            throw new InvalidArgumentException('The confirmation action requires a valid Livewire method name.');
        }

        $serializedArguments = implode(', ', array_map(
            static fn (mixed $argument): string => Js::from($argument)->toHtml(),
            array_values($arguments),
        ));

        return '$dispatch("rt-confirm", {'
            .'title: '.Js::from($title ?? __('app.continue'))->toHtml().','
            .'message: '.Js::from($message ?? '')->toHtml().','
            .'variant: '.Js::from($variant)->toHtml().','
            .'confirmLabel: '.Js::from($confirmLabel ?? __('app.continue'))->toHtml().','
            .'action: () => $wire.'.$method.'('.$serializedArguments.')'
            .'})';
    }
}
