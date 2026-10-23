<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'mode' => 'basic',
    'size' => 'md',
    'can' => true,
    'confirmMethod' => null,
    'confirmArguments' => [],
    'confirmTitle' => null,
    'confirmMessage' => null,
    'confirmVariant' => 'destructive',
    'confirmLabel' => null,
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
    'mode' => 'basic',
    'size' => 'md',
    'can' => true,
    'confirmMethod' => null,
    'confirmArguments' => [],
    'confirmTitle' => null,
    'confirmMessage' => null,
    'confirmVariant' => 'destructive',
    'confirmLabel' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php

$modeClasses = match ($mode) {
    'primary', 'blue' => ' text-white dark:text-white bg-rt-red hover:bg-rt-red-dark focus:ring-white/80 border-rt-red',
    'basic', 'secondary', 'light', 'white' => ' text-rt-text dark:text-white bg-rt-surface dark:bg-rt-dark-surface hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted focus:ring-rt-accent/40 border-rt-border dark:border-rt-dark-border',
    'danger' => ' text-white dark:text-white bg-red-600 hover:bg-red-700 focus:ring-white/80 border-red-600',
    'success' => ' text-white dark:text-white bg-emerald-600 hover:bg-emerald-700 focus:ring-white/80 border-emerald-600',
    'warning' => ' text-slate-950 bg-amber-400 hover:bg-amber-500 focus:ring-slate-950/70 border-amber-400',
    'info' => ' text-white dark:text-white bg-sky-600 hover:bg-sky-700 focus:ring-white/80 border-sky-600',
    'dark' => ' text-white dark:text-white bg-slate-800 hover:bg-slate-900 focus:ring-white/80 border-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600 dark:border-slate-600',
    'link' => ' text-rt-red dark:text-rt-dark-accent bg-transparent hover:bg-rt-red/10 dark:hover:bg-rt-dark-accent-soft focus:ring-rt-red/30 border-transparent',
};

$themeVariant = match ($mode) {
    'primary', 'blue' => 'primary',
    'basic', 'secondary', 'light', 'white' => 'secondary',
    default => $mode,
};

$sizeClasses = match ($size) {
    'sm' => 'px-2 py-1 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-5 py-3 text-lg',
    'xl' => 'px-6 py-4 text-xl',
    '2xl' => 'px-7 py-5 text-2xl',
};

if (is_string($can) && $can !== '') {
    $isAllowed = auth()->check() && auth()->user()->can($can);
} else {
    $isAllowed = (bool) $can;
}

$isDeniedByCan = ! $isAllowed;
$isDisabled = isset($attributes['disabled']) || $isDeniedByCan;

$classes = 'rt-ui-button rt-ui-button-' . $themeVariant . ' ' . $modeClasses . ' ' . $sizeClasses;
$classes .= ' transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 text-center font-semibold leading-none border rounded-lg shadow-rt-xs active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-inset focus:ring-offset-0 disabled:opacity-50 [&>i]:shrink-0 [&>i]:leading-none [&>svg]:h-[1em] [&>svg]:w-[1em] [&>svg]:shrink-0';

if ($mode === 'primary' || $mode === 'blue') {
    $classes .= ' hover:shadow-rt-glow hover:-translate-y-px';
}

if ($isDisabled) {
    $classes .= ' opacity-80 cursor-not-allowed';
}

$title = $isDeniedByCan
    ? __('app.no_permission')
    : $attributes->get('title');

$attributesWithoutTitle = $attributes->except('title');

if (is_string($confirmMethod) && trim($confirmMethod) !== '') {
    $attributesWithoutTitle = $attributesWithoutTitle
        ->filter(function ($value, $key) {
            foreach (['wire:click', '@click', 'x-on:click', 'onclick'] as $prefix) {
                if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
                    return false;
                }
            }

            return true;
        })
        ->merge([
            'x-on:click.prevent' => \App\Support\Ui\ConfirmationAction::alpine(
                method: $confirmMethod,
                arguments: is_array($confirmArguments) ? $confirmArguments : [$confirmArguments],
                title: $confirmTitle,
                message: $confirmMessage,
                variant: $confirmVariant,
                confirmLabel: $confirmLabel,
            ),
        ]);
}

$interactiveAttributes = $attributesWithoutTitle->filter(function ($value, $key) use ($isDisabled) {
    if (! $isDisabled) {
        return true;
    }

    if ($key === 'href') {
        return false;
    }

    foreach (['wire:click', '@click', 'x-on:click', 'onclick'] as $prefix) {
        if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
            return false;
        }
    }

    return true;
});

// Interne Links navigieren SPA-artig via wire:navigate (Livewire);
// mit data-no-navigate kann ein Aufrufer das gezielt deaktivieren.
$href = (string) $attributes->get('href', '');
$shouldNavigate = $href !== ''
    && ! $isDisabled
    && ! $attributes->has('wire:navigate')
    && ! $attributes->has('target')
    && ! $attributes->has('download')
    && ! $attributes->has('data-no-navigate')
    && ! preg_match('~^(mailto:|tel:|#|javascript:|data:)~i', $href)
    && (str_starts_with($href, '/') || str_starts_with($href, url('/')));

?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($attributes['href'])): ?>
    <a <?php echo $interactiveAttributes->merge(['class' => $classes]); ?>

        <?php if($shouldNavigate): ?> wire:navigate <?php endif; ?>
        <?php if($title): ?> title="<?php echo e($title); ?>" <?php endif; ?>
        <?php if($isDisabled): ?> aria-disabled="true" tabindex="-1" x-on:click.prevent.stop <?php endif; ?>
        >
        <?php echo e($slot); ?>

    </a>
<?php else: ?>
    <button <?php echo $interactiveAttributes->merge(['class' => $classes]); ?>

        <?php if($title): ?> title="<?php echo e($title); ?>" <?php endif; ?>
        <?php if($isDisabled): ?> disabled <?php endif; ?>>
        <?php echo e($slot); ?>

    </button>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/buttons/button-basic.blade.php ENDPATH**/ ?>