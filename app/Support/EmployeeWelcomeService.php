<?php

namespace App\Support;

use App\Models\Mail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmployeeWelcomeService
{
    public function send(User $user): ?Mail
    {
        try {
            return Mail::query()->create([
                'type' => 'both',
                'status' => false,
                'content' => $this->contentFor($user),
                'recipients' => [[
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => false,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::error('Willkommensnachricht konnte nicht vorbereitet werden.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{subject: string, header: string, body: string, lines: array<int, string>, link: string, system_key: string, team: string}
     */
    public function contentFor(User $user): array
    {
        $user->loadMissing('currentTeam');

        $teamName = trim((string) ($user->currentTeam?->name ?? ''));
        $displayTeamName = $teamName !== '' ? $teamName : __('app.welcome_team_fallback_name');
        $teamText = __($this->teamTranslationKey($teamName));
        $lines = [
            __('app.welcome_message_intro', ['app' => config('app.name')]),
            __('app.welcome_message_team_intro', ['team' => $displayTeamName]),
            $teamText,
            __('app.welcome_message_next_steps'),
        ];

        return [
            'subject' => __('app.welcome_message_subject', ['app' => config('app.name')]),
            'header' => __('app.welcome_message_header', ['name' => $user->name]),
            'body' => implode("\n\n", $lines),
            'lines' => $lines,
            'link' => route($user->isAdmin() ? 'admin.login' : 'login'),
            'system_key' => 'employee_welcome',
            'team' => $displayTeamName,
        ];
    }

    private function teamTranslationKey(string $teamName): string
    {
        $normalizedName = Str::lower(Str::ascii(trim($teamName)));

        return match ($normalizedName) {
            'administratoren', 'administrator', 'administration' => 'app.welcome_message_team_administrators',
            'verwaltung', 'management' => 'app.welcome_message_team_management',
            'mitarbeiter', 'mitarbeitende', 'staff' => 'app.welcome_message_team_employees',
            'gaste', 'gaeste', 'gast', 'guest' => 'app.welcome_message_team_guests',
            default => 'app.welcome_message_team_fallback',
        };
    }
}
