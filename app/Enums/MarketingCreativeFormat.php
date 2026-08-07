<?php

namespace App\Enums;

enum MarketingCreativeFormat: string
{
    case Story = 'story';
    case Post = 'post';
    case Web = 'web';

    /** @return array{width: int, height: int} */
    public function dimensions(): array
    {
        return match ($this) {
            self::Story => ['width' => 1080, 'height' => 1920],
            self::Post => ['width' => 1080, 'height' => 1080],
            self::Web => ['width' => 1200, 'height' => 630],
        };
    }
}
