{{--
    Gemeinsames Geruest der Identitaetskarte.

    variant="own"      Eigenes Profil (livewire.profile.profile-identity-card):
                       <section>, Layout .profile-identity__layout, Fotofeld
                       .profile-identity__photo. Die Inhaltsspalte bringt ihren
                       eigenen Alpine-Scope mit und kommt deshalb samt Huelle
                       aus dem Standard-Slot.
    variant="employee" Mitarbeiterprofil (livewire.employees.profile.partials.identity-card):
                       <header>, Layout .employee-profile__identity-layout,
                       Portraet und Textspalte gemeinsam in .employee-profile__person.

    Slots:  before  – vor dem Layout (z. B. Autosave-Status)
            photo   – Inhalt des Fotofelds
            (Standard) – Name, Rolle, Meta-Zeilen
            actions – Aktionen; die Bedingung steht im Slot, nicht um ihn herum
            meta    – nachgeordnete Eckdaten innerhalb des Layouts
            after   – nach dem Layout (Hinweise, Fehlermeldungen)

    Die Verzweigungen stehen bewusst als einfaches PHP und nicht als Blade-@if:
    Livewire setzt um jedes Blade-@if Morph-Marker, das gerenderte HTML soll
    aber dem Stand vor dem Zusammenlegen entsprechen.

    Attribute werden NICHT durchgereicht: der Baustein kennt nur "variant" und
    gibt $attributes nirgends aus. class, wire:key oder data-* am Aufruf
    verschwinden still. Ein $attributes->merge() an der Wurzel wuerde die
    Attributreihenfolge und damit das gerenderte HTML aendern – wer Attribute
    braucht, muss das bewusst nachziehen.
--}}
@props(['variant' => 'employee'])
@php
    $own = $variant === 'own';
    $tag = $own ? 'section' : 'header';
@endphp
<?php if ($own): ?>
<section
    class="employee-profile__identity relative overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/80 dark:bg-rt-dark-surface"
    data-anim="fade-up"
    data-profile-identity
    data-autosave-scope
>
<?php else: ?>
<header class="employee-profile__identity" data-profile-identity data-anim="fade-up">
<?php endif; ?>
    {{ $before ?? '' }}

    <div class="{{ $own ? 'profile-identity__layout bg-rt-surface dark:bg-rt-dark-surface' : 'employee-profile__identity-layout' }}">
        <?php if ($own): ?>
        <div class="profile-identity__photo">
            {{ $photo ?? '' }}
        </div>

        {{ $slot }}
        <?php else: ?>
        <div class="employee-profile__person">
            <span class="employee-profile__portrait relative shrink-0">
                {{ $photo ?? '' }}
            </span>

            <div class="employee-profile__person-copy">
                {{ $slot }}
            </div>
        </div>
        <?php endif; ?>

        {{ $actions ?? '' }}

        {{ $meta ?? '' }}
    </div>

    {{ $after ?? '' }}
</{{ $tag }}>
