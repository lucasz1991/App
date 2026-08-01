<?php

namespace App\Services\Ai\Attachments;

enum AssistantAttachmentKind: string
{
    case Text = 'text';
    case Office = 'office';
    case Pdf = 'pdf';
    case Image = 'image';
}
