<?php

namespace App\Jobs;

use App\Enums\MarketingRenderStatus;
use App\Models\MarketingRender;
use App\Services\Marketing\MarketingRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RenderMarketingCreative implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 85;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public function __construct(public MarketingRender $render)
    {
        $this->onQueue((string) config('marketing.renders.queue', 'default'));
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('marketing-render-'.$this->render->fingerprint))
                ->releaseAfter(5)
                ->expireAfter(90),
        ];
    }

    public function uniqueId(): string
    {
        return $this->render->fingerprint;
    }

    public function handle(MarketingRenderService $renderer): void
    {
        $renderer->render($this->render);
    }

    public function failed(?Throwable $exception): void
    {
        $render = MarketingRender::query()->find($this->render->id);
        if (! $render || $render->status === MarketingRenderStatus::Completed) {
            return;
        }

        $render->forceFill([
            'status' => MarketingRenderStatus::Failed,
            'error' => mb_substr(trim($exception?->getMessage() ?: 'Der PNG-Export ist fehlgeschlagen.'), 0, 1000),
        ])->save();
    }
}
