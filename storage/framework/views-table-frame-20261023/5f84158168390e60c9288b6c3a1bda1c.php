<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'person' => null,
    'user' => null,
    'size' => 8,
    'showEmail' => false,
    'showPresence' => true,
    'showContext' => false,
    'selected' => false,
    'selectionIndicator' => false,
    'nameFormat' => 'full',
    'localInitials' => false,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'person' => null,
    'user' => null,
    'size' => 8,
    'showEmail' => false,
    'showPresence' => true,
    'showContext' => false,
    'selected' => false,
    'selectionIndicator' => false,
    'nameFormat' => 'full',
    'localInitials' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $resolvedPerson = $person ?? $user?->person ?? null;
    $resolvedUser = $user ?? $resolvedPerson?->user ?? null;
    $hasUser = (bool) $resolvedUser;

    $meaningfulName = static function (mixed $value): string {
        $name = trim((string) $value);

        return preg_match('/[\p{L}\p{N}]/u', $name) === 1 ? $name : '';
    };

    $personFirst = $meaningfulName($resolvedPerson?->vorname);
    $personLast = $meaningfulName($resolvedPerson?->nachname);
    $personName = match (true) {
        $personFirst !== '' && $personLast !== '' => $personLast . ', ' . $personFirst,
        $personLast !== '' => $personLast,
        default => $personFirst,
    };

    $profileFirst = $meaningfulName($resolvedUser?->profile?->first_name);
    $profileLast = $meaningfulName($resolvedUser?->profile?->last_name);
    $profileName = trim($profileFirst . ' ' . $profileLast);
    $accountName = $meaningfulName($resolvedUser?->name);

    $displayName = $personName
        ?: $accountName
        ?: $profileName
        ?: ($resolvedUser->email ?? $resolvedPerson?->email ?? '')
        ?: 'Unbekannt';
    $email = trim((string) ($resolvedUser->email ?? $resolvedPerson->email ?? ''));
    $visibleName = $displayName;
    if ($nameFormat === 'initial-surname') {
        [$first, $last] = $personName !== '' ? [$personFirst, $personLast] : [$profileFirst, $profileLast];
        if ($first === '' && $last === '' && $accountName !== '') {
            if (str_contains($accountName, ',')) {
                [$last, $first] = array_map('trim', explode(',', $accountName, 2));
            } else {
                [$first, $last] = array_pad(preg_split('/\s+/u', $accountName, 2), 2, '');
            }
        }
        $visibleName = $last !== ''
            ? ($first !== '' ? mb_substr($first, 0, 1).'. ' : '').$last
            : $displayName;
    }
    $isOnline = $hasUser && $showPresence && $resolvedUser->isOnline();
    $profilePosition = trim((string) ($resolvedUser?->profile?->position ?? ''));
    $teamName = trim((string) ($resolvedUser?->currentTeam?->name ?? ''));
    $contextLabel = $profilePosition !== '' ? $profilePosition : $teamName;

    $avatarUrl = 'https://ui-avatars.com/api/?name='
        . urlencode($displayName)
        . '&color=7F9CF5&background=EBF4FF&bold=true&size=96';

    $sizeClass = match ((int) $size) {
        6 => 'h-6 w-6 rounded-full',
        9 => 'h-9 w-9 rounded-full',
        10 => 'h-10 w-10 rounded-full',
        12 => 'h-12 w-12 rounded-full',
        default => 'h-8 w-8 rounded-full',
    };
    $initials = collect(preg_split('/[\s,]+/u', trim($displayName)) ?: [])
        ->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') ?: '?';
?>

<div <?php echo e($attributes->class(['flex min-w-0 items-center gap-2.5', 'opacity-90' => ! $hasUser])); ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectionIndicator): ?>
        
        <span
            class="rt-person-selection-slot"
            data-checked="<?php echo e($selected ? 'true' : 'false'); ?>"
            aria-hidden="true"
        >
            <span
                class="rt-person-selection-indicator"
                data-checked="<?php echo e($selected ? 'true' : 'false'); ?>"
            >
                <svg
                    class="rt-person-selection-indicator__icon"
                    viewBox="0 0 18 18"
                    focusable="false"
                    aria-hidden="true"
                >
                    <path d="M4.75 9.15 7.45 12l5.8-6.1"></path>
                </svg>
            </span>
        </span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <span class="relative shrink-0">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($localInitials && ! filled($resolvedUser?->profile_photo_path)): ?>
            <span class="<?php echo e($sizeClass); ?> inline-flex items-center justify-center bg-rt-accent-soft text-sm font-semibold text-rt-accent ring-1 ring-rt-border/70 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-border/70" aria-hidden="true"><?php echo e($initials); ?></span>
        <?php else: ?>
        <img
            src="<?php echo e($hasUser && ! empty($resolvedUser->profile_photo_url) ? $resolvedUser->profile_photo_url : $avatarUrl); ?>"
            alt="<?php echo e($displayName); ?>"
            class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                $sizeClass,
                'object-cover shadow-rt-xs ring-1 transition duration-200',
                'ring-rt-red/70 dark:ring-rt-dark-accent/80' => $selected,
                'ring-rt-border/70 dark:ring-rt-dark-border/70' => ! $selected,
                'grayscale' => ! $hasUser,
            ]); ?>"
        >
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasUser && $showPresence): ?>
            <span
                class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                    'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-rt-surface dark:ring-rt-dark-surface',
                    'bg-emerald-500' => $isOnline,
                    'bg-slate-300 dark:bg-slate-600' => ! $isOnline,
                ]); ?>"
                aria-hidden="true"
            ></span>
            <span class="sr-only"><?php echo e($isOnline ? __('app.online') : __('app.offline')); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </span>

    <span class="min-w-0 flex-1">
        <span class="flex min-w-0 items-baseline gap-1.5">
            <span class="min-w-0 truncate text-sm font-semibold leading-5 text-rt-text dark:text-rt-dark-text" title="<?php echo e($displayName); ?>">
                <?php echo e($visibleName); ?>

            </span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showContext && $contextLabel !== ''): ?>
                <span class="rt-user-public-info__context shrink-0 truncate text-[9px] font-bold leading-4 text-rt-accent dark:text-rt-dark-accent">
                    <?php echo e($contextLabel); ?>

                </span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showEmail && $email !== ''): ?>
            <span class="block truncate text-xs leading-4 text-rt-muted dark:text-rt-dark-muted">
                <?php echo e($email); ?>

            </span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </span>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/user/public-info.blade.php ENDPATH**/ ?>