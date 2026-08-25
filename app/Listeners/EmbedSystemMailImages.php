<?php

namespace App\Listeners;

use App\Support\Mail\SystemMailInlineImageEmbedder;
use Illuminate\Mail\Events\MessageSending;

final class EmbedSystemMailImages
{
    public function __construct(
        private readonly SystemMailInlineImageEmbedder $embedder,
    ) {}

    public function handle(MessageSending $event): void
    {
        $this->embedder->embed($event->message);
    }
}
