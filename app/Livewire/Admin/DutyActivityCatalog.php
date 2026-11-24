<?php

namespace App\Livewire\Admin;

use App\Models\DutyActivity;
use App\Models\QualificationBundle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DutyActivityCatalog extends Component
{
    #[Locked]
    public ?int $editing = null;

    #[Locked]
    public int $revision = 0;

    public bool $open = false;

    public string $search = '';

    public array $form = [];

    public function edit(?int $id = null): void
    {
        Gate::authorize('settings.manage');
        $activity = $id ? DutyActivity::findOrFail($id) : new DutyActivity;
        $this->resetValidation();
        $this->editing = $id;
        $this->revision = $activity->revision ?? 0;
        $this->form = $activity->only(['code', 'title', 'description', 'active', 'requirements_confirmed', 'qualification_bundle_id', 'proof_type', 'wagon_list', 'individual_proof', 'customer_acceptance']);
        if (! $id) {
            $this->form = array_merge($this->form, ['active' => true, 'requirements_confirmed' => false, 'wagon_list' => false, 'customer_acceptance' => false, 'individual_proof' => false, 'proof_type' => 'Einsatzbericht']);
        }
        $this->open = true;
    }

    public function save(): void
    {
        Gate::authorize('settings.manage');
        $this->form['code'] = preg_replace('~\s*/\s*~u', '/', trim($this->form['code'] ?? ''));
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:60', Rule::unique('duty_activities', 'code')->ignore($this->editing)],
            'form.title' => 'nullable|string|max:160|required_if:form.requirements_confirmed,true',
            'form.description' => 'nullable|string|max:500',
            'form.qualification_bundle_id' => ['nullable', 'integer', Rule::exists('qualification_bundles', 'id')->where('status', 'approved')],
            'form.active' => 'required|boolean', 'form.requirements_confirmed' => 'required|boolean',
            'form.proof_type' => 'required|string|max:80', 'form.wagon_list' => 'required|boolean', 'form.individual_proof' => 'required|boolean', 'form.customer_acceptance' => 'required|boolean',
        ])['form'];
        DB::transaction(function () use ($data) {
            $row = $this->editing ? DutyActivity::lockForUpdate()->findOrFail($this->editing) : new DutyActivity;
            if (($row->revision ?? 0) !== $this->revision) {
                throw ValidationException::withMessages(['form.code' => 'Der Eintrag wurde geändert. Bitte erneut öffnen.']);
            }
            $key = mb_strtolower($data['code']);
            $previous = DB::table('duty_activity_codes')->where('lookup', $key)->lockForUpdate()->first();
            if ($previous && (int) $previous->duty_activity_id !== $row->id) {
                throw ValidationException::withMessages(['form.code' => 'Dieses Kürzel gehört bereits zu einer anderen Tätigkeit.']);
            }
            $row->fill($data);
            $row->qualification_bundle_id = ($data['qualification_bundle_id'] ?? null) ?: null;
            $row->revision = $this->revision + 1;
            $row->save();
            if (! $previous) {
                DB::table('duty_activity_codes')->insert(['lookup' => $key, 'duty_activity_id' => $row->id]);
            }
        });
        $this->open = false;
    }

    public function render()
    {
        Gate::authorize('settings.manage');
        $ready = Schema::hasTable('duty_activities');
        $activities = $ready ? DutyActivity::where('code', 'like', '%'.$this->search.'%')->orWhere('title', 'like', '%'.$this->search.'%')->orderBy('code')->get() : collect();
        $bundles = $ready && $this->open ? QualificationBundle::where('status', 'approved')->orderBy('name')->get() : collect();

        return view('livewire.admin.duty-activity-catalog', compact('activities', 'bundles', 'ready'));
    }
}
