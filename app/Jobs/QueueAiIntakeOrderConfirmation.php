<?php

namespace App\Jobs;

use App\Services\Operations\AiIntakeMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueAiIntakeOrderConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $inquiryId, public int $approverId)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function handle(AiIntakeMailService $mail): void
    {
        $mail->enqueueForConvertedInquiry($this->inquiryId, $this->approverId);
    }
}
