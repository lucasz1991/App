@extends('layouts.master', ['area' => 'user'])

@section('title', 'Tabs QA')

@section('content')
    <div class="mx-auto max-w-5xl px-3 py-8 sm:px-6">
        <x-ui.accordion.tabs
            :tabs="[
                'general' => ['label' => 'Allgemein', 'icon' => 'fad fa-sliders-h'],
                'company' => ['label' => 'Firmendaten', 'icon' => 'fad fa-building'],
                'users' => ['label' => 'Benutzer', 'icon' => 'fad fa-users'],
                'system' => ['label' => 'System', 'icon' => 'fad fa-server'],
            ]"
            default="general"
            :force-default="true"
            persist-key="codex-tabs-preview"
        >
            @foreach (['general' => 'Allgemein', 'company' => 'Firmendaten', 'users' => 'Benutzer', 'system' => 'System'] as $key => $label)
                <x-ui.accordion.tab-panel :for="$key">
                    <div class="min-h-64 rounded-2xl border border-rt-border bg-rt-surface p-6 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
                        <h2 class="text-xl font-bold text-rt-text dark:text-white">{{ $label }}</h2>
                        <p class="mt-2 text-rt-muted dark:text-rt-dark-muted">Animierter Testinhalt für {{ $label }}.</p>
                    </div>
                </x-ui.accordion.tab-panel>
            @endforeach
        </x-ui.accordion.tabs>
    </div>
@endsection
