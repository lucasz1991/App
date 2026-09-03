<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingMotiveService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CreativeFiles extends Component
{
    #[Locked]
    public int $creativeId;

    #[Locked]
    public int $filePoolId;

    public string $title = '';

    public string $type = MarketingCreativeType::Info->value;

    public function mount(MarketingCreative $creative, MarketingMotiveService $motives): void
    {
        $actor = $this->admin();
        $pool = $motives->filePoolFor($creative, $actor);
        $creative = $creative->fresh();

        $this->creativeId = (int) $creative->getKey();
        $this->filePoolId = (int) $pool->getKey();
        $this->title = (string) $creative->title;
        $this->type = $creative->type->value;
    }

    public function saveMetadata(MarketingMotiveService $motives): void
    {
        $actor = $this->admin();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(MarketingCreativeType::class)],
        ]);

        $creative = $motives->update(
            $this->creative(),
            (string) $validated['title'],
            MarketingCreativeType::from((string) $validated['type']),
            $actor,
        );

        $this->title = (string) $creative->title;
        $this->type = $creative->type->value;
        $this->filePoolId = (int) $creative->filePool->getKey();

        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: 'Motivdetails wurden gespeichert.',
        );
    }

    public function deleteMotive(MarketingMotiveService $motives): void
    {
        $actor = $this->admin();

        $motives->delete($this->creative(), $actor);

        $this->redirectRoute('admin.marketing.creatives.index', navigate: true);
    }

    public function render()
    {
        $this->admin();
        $creative = $this->creative();

        return view('livewire.admin.marketing.creative-files', [
            'creativeRecord' => $creative,
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    private function creative(): MarketingCreative
    {
        return MarketingCreative::query()->findOrFail($this->creativeId);
    }

    private function admin(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);

        return $actor;
    }
}
