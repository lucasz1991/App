<?php

namespace App\Support;

use App\Models\User;

final class WelcomeIntroCatalog
{
    /**
     * Eine neue Version oeffnet die Einfuehrung auch fuer Konten, die den
     * bisherigen rein textlichen Rundgang bereits gesehen haben.
     */
    public const TRACKING_KEY = 'intro:welcome:video-v1';

    private const MEDIA_DIRECTORY = 'media/onboarding/v4';

    /**
     * Dateinamen, Laufzeiten und Icons sind bewusst ein fester Katalog.
     * Ein URL-Parameter darf niemals zu einem freien Dateipfad werden.
     *
     * @var array<string, array{file: string, duration: int, icon: string, size: int, sha256: string}>
     */
    private const MODULES = [
        'intro' => [
            'file' => '01-intro', 'duration' => 7, 'icon' => 'fa-route', 'size' => 822618,
            'sha256' => '621CF6656350C4AD6BE2F59AB57975E87F3F1103CC83C65B74329FB564122AE5',
        ],
        'devices' => [
            'file' => '02-device-management', 'duration' => 12, 'icon' => 'fa-laptop', 'size' => 2135674,
            'sha256' => 'B2F736403DDD24CA3C66EDEA40F9B1330355E2C2E0364FD07665A533026B227F',
        ],
        'orders' => [
            'file' => '03-order-processing', 'duration' => 8, 'icon' => 'fa-clipboard-list', 'size' => 1283221,
            'sha256' => '88DC4E098E9BD4A6FFD6756A7E026F7F233DCE91E2CF335CC46231CCC0DC0FB9',
        ],
        'shifts' => [
            'file' => '04-shift-planning', 'duration' => 9, 'icon' => 'fa-calendar-alt', 'size' => 1520055,
            'sha256' => '5BB63B5596596E3CD18E89738076B72D6904E97A3A7501AD3A8F2CB470A663C8',
        ],
        'communication' => [
            'file' => '05-communication', 'duration' => 10, 'icon' => 'fa-comments', 'size' => 1494910,
            'sha256' => '9CD4FBE64F3F6959A873793B9E69CBD0F9D056FA662D12752997A6706F5DD52E',
        ],
        'wagon-lists' => [
            'file' => '06-wagon-lists', 'duration' => 16, 'icon' => 'fa-train', 'size' => 3483017,
            'sha256' => 'EB1CB9D7A1C794BAE8D5A46F4ADE47592B5DCE81DDF464BA5E370F5563D8B268',
        ],
        'files' => [
            'file' => '07-file-management', 'duration' => 8, 'icon' => 'fa-folder-open', 'size' => 917879,
            'sha256' => '717D8CD4F032F373AEEEAA00A47545C57E3D214E335868F13760333CFFE23CB2',
        ],
        'support' => [
            'file' => '08-it-support', 'duration' => 10, 'icon' => 'fa-life-ring', 'size' => 1837529,
            'sha256' => '76B35166E7DB64FEF3CB9D36F8888EE03ABA60BF8B1E75FCE7E5F959DD99CEFA',
        ],
        'integrations' => [
            'file' => '09-integrations', 'duration' => 7, 'icon' => 'fa-plug', 'size' => 867587,
            'sha256' => 'A728F67FA82530C40D2F5AB0BBF6545A68188F1C1FA9C768CE15072274C80A26',
        ],
    ];

    /**
     * Admin und Verwaltung erhalten den Unternehmensueberblick. In den
     * Erklaerungen steht ausdruecklich, welche Arbeitsbereiche nur die globale
     * Admin-Rolle bedienen darf. Mitarbeiter und Gaeste sehen nur Themen, die
     * zu ihren vorhandenen Oberflaechen passen.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCE_MODULES = [
        'admin' => [
            'intro',
            'devices',
            'orders',
            'shifts',
            'communication',
            'wagon-lists',
            'files',
            'support',
            'integrations',
        ],
        'management' => [
            'intro',
            'devices',
            'communication',
            'wagon-lists',
            'files',
            'support',
        ],
        'employee' => [
            'intro',
            'communication',
            'wagon-lists',
            'files',
            'support',
        ],
        'guest' => [
            'intro',
            'communication',
            'files',
            'support',
        ],
    ];

    /**
     * @return array{audience: string, label: string, slides: list<array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $audience = $this->normalizeAudience($user->dashboardAudience());
        $audienceContent = trans('app.welcome_intro_content.'.$audience);
        $moduleContent = trans('app.welcome_intro_modules');

        $audienceContent = is_array($audienceContent)
            ? $audienceContent
            : ['label' => config('app.name')];
        $moduleContent = is_array($moduleContent) ? $moduleContent : [];
        $locale = app()->getLocale() === 'en' ? 'en' : 'de';

        $slides = [];

        foreach ($this->moduleIdsFor($user) as $moduleId) {
            $module = self::MODULES[$moduleId];
            $copy = $moduleContent[$moduleId] ?? null;

            if (! is_array($copy)) {
                continue;
            }

            $video = $this->media($moduleId, 'video');
            $poster = $this->media($moduleId, 'poster');
            $videoAvailable = $video
                && is_file($video['path'])
                && filesize($video['path']) === $module['size'];

            $slides[] = array_merge($copy, [
                'id' => $moduleId,
                'icon' => $module['icon'],
                'duration' => $module['duration'],
                'durationLabel' => sprintf('00:%02d', $module['duration']),
                'moduleLabel' => __('app.welcome_intro_module', [
                    'current' => count($slides) + 1,
                    'total' => count($this->moduleIdsFor($user)),
                ]),
                'videoLabel' => __('app.welcome_intro_play_video', [
                    'title' => (string) ($copy['title'] ?? ''),
                ]),
                'videoAvailable' => $videoAvailable,
                'video' => $videoAvailable
                    ? route('welcome-intro.media', ['module' => $moduleId, 'asset' => 'video'])
                    : null,
                'poster' => $poster && is_file($poster['path'])
                    ? route('welcome-intro.media', ['module' => $moduleId, 'asset' => 'poster'])
                    : null,
                'tracks' => $videoAvailable ? [
                    [
                        'src' => route('welcome-intro.media', [
                            'module' => $moduleId,
                            'asset' => 'captions-de',
                        ]),
                        'srclang' => 'de',
                        'label' => __('app.welcome_intro_captions_german'),
                        'default' => $locale === 'de',
                    ],
                    [
                        'src' => route('welcome-intro.media', [
                            'module' => $moduleId,
                            'asset' => 'captions-en',
                        ]),
                        'srclang' => 'en',
                        'label' => __('app.welcome_intro_captions_english'),
                        'default' => $locale === 'en',
                    ],
                ] : [],
            ]);
        }

        return [
            'audience' => $audience,
            'label' => (string) ($audienceContent['label'] ?? config('app.name')),
            'slides' => $slides,
        ];
    }

    public function canView(User $user, string $moduleId): bool
    {
        return in_array($moduleId, $this->moduleIdsFor($user), true);
    }

    /**
     * @return array{path: string, mime: string, filename: string, etag: string|null, expectedSize: int|null}|null
     */
    public function media(string $moduleId, string $asset): ?array
    {
        $module = self::MODULES[$moduleId] ?? null;

        if (! $module) {
            return null;
        }

        $variant = match ($asset) {
            'video' => [$module['file'].'.mp4', 'video/mp4'],
            'poster' => [$module['file'].'.jpg', 'image/jpeg'],
            'captions-de' => [$module['file'].'.de.vtt', 'text/vtt; charset=UTF-8'],
            'captions-en' => [$module['file'].'.en.vtt', 'text/vtt; charset=UTF-8'],
            default => null,
        };

        if (! $variant) {
            return null;
        }

        [$filename, $mime] = $variant;

        return [
            'path' => resource_path(self::MEDIA_DIRECTORY.'/'.$filename),
            'mime' => $mime,
            'filename' => $filename,
            'etag' => $asset === 'video' ? $module['sha256'] : null,
            'expectedSize' => $asset === 'video' ? $module['size'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function moduleIdsFor(User $user): array
    {
        $audience = $this->normalizeAudience($user->dashboardAudience());
        $modules = self::AUDIENCE_MODULES[$audience];

        if (! $user->isAdmin() && ! $user->can('devices.view')) {
            $modules = array_values(array_filter(
                $modules,
                static fn (string $module): bool => $module !== 'devices',
            ));
        }

        return $modules;
    }

    private function normalizeAudience(string $audience): string
    {
        return match ($audience) {
            'admin' => 'admin',
            'administration', 'management' => 'management',
            'employee' => 'employee',
            default => 'guest',
        };
    }
}
