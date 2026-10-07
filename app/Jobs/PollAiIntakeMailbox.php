<?php

namespace App\Jobs;

use App\Services\Operations\AiIntakeMailboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollAiIntakeMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 660;

    public function __construct(public array $historicalUids = [], public ?int $historicalValidity = null, public ?string $expectedMailbox = null, public ?int $expectedSettingsRevision = null)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function uniqueId(): string
    {
        return 'poll:'.hash('sha256', json_encode([$this->historicalUids, $this->historicalValidity, $this->expectedMailbox]));
    }

    public function handle(AiIntakeMailboxService $service): void
    {
        $service->poll($this->historicalUids, $this->historicalValidity, $this->expectedMailbox, $this->expectedSettingsRevision);
    }
}
