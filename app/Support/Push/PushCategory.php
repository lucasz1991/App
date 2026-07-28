<?php

namespace App\Support\Push;

enum PushCategory: string
{
    case Messages = 'messages';
    case Chat = 'chat';
    case Calls = 'calls';

    public function label(): string
    {
        return match ($this) {
            self::Messages => __('app.push_category_messages'),
            self::Chat => __('app.push_category_chat'),
            self::Calls => __('app.push_category_calls'),
        };
    }
}
