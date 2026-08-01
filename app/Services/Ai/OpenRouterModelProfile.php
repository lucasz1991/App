<?php

namespace App\Services\Ai;

enum OpenRouterModelProfile: string
{
    case Text = 'text';
    case Data = 'data';
    case ImageUnderstanding = 'image_understanding';
}
