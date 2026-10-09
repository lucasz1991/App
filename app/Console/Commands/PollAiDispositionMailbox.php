<?php

namespace App\Console\Commands;

use App\Jobs\ApplyApprovedAiIntakes;
use App\Jobs\DeliverAiIntakeMail;
use App\Jobs\PollAiIntakeMailbox;
use App\Jobs\ProcessAiIntake;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Services\Operations\AiIntakeMailService;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PollAiDispositionMailbox extends Command
{
    protected $signature = 'operations:ai-intake-poll';

    protected $description = 'Queue independent AI disposition mailbox polling when enabled.';

    public function handle(): int
    {
        $settings = AiDispositionSettings::all(true);
        if (AiIntakeSchema::ready()) {
            app(AiIntakeService::class)->expireStaleAnalysisRuns();
            app(AiIntakeMailService::class)->expireAwaitingReplies();
        }
        if ($settings['enabled'] && Cache::add('ai-disposition:poll-scheduled', true, (int) $settings['poll_interval_minutes'] * 60)) {
            PollAiIntakeMailbox::dispatch();
        }
        if ($settings['enabled'] && AiIntakeSchema::ready()) {
            app(AiIntakeMailService::class)->recoverConfirmationIntents();
            AiIntake::where('status', 'received')->orderBy('id')->limit(25)->pluck('id')->each(fn ($id) => ProcessAiIntake::dispatch((int) $id));
            AiIntakeDelivery::where('status', 'pending')->where('created_at', '<', now()->subMinutes(2))->orderBy('id')->limit(25)->pluck('id')->each(fn ($id) => DeliverAiIntakeMail::dispatch((int) $id));
            ApplyApprovedAiIntakes::dispatch();
        }

        return self::SUCCESS;
    }
}
