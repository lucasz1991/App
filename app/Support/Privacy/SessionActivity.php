<?php

namespace App\Support\Privacy;

use Illuminate\Http\Request;

class SessionActivity
{
    private const AUTOMATIC_METHODS = [
        '', '$refresh', '__dispatch', 'refreshStatus', 'loadInbox', 'pollTick', 'refreshMicrosoftSync',
        'refreshMessages', 'advance', 'reportRinging', 'updateWagonAssistantContext',
    ];

    public static function isInteraction(Request $request): bool
    {
        if ($request->is('livewire/update')) {
            foreach ((array) $request->input('components', []) as $component) {
                if (! empty($component['updates'])) {
                    return true;
                }
                foreach ((array) ($component['calls'] ?? []) as $call) {
                    if (! in_array($call['method'] ?? '', self::AUTOMATIC_METHODS, true)) {
                        return true;
                    }
                }
            }

            return false;
        }
        if ($request->expectsJson() || $request->ajax() || $request->isMethod('HEAD')
            || $request->is('pwa/runtime-status', 'pwa/session-status', 'employee-location/open', '.well-known/*', 'broadcasting/*', 'api/*')) {
            return false;
        }

        return $request->header('Sec-Fetch-Mode') === 'navigate'
            || str_contains(strtolower((string) $request->header('Accept')), 'text/html');
    }
}
