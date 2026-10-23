<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'user',
    'profileUrl' => null,
    'canChat' => false,
    'canCall' => false,
    'canMessage' => false,
    'chatAction' => null,
    'callAction' => null,
    'messageAction' => null,
    // 'row' = kompakte Symbolleiste (Dropdown), 'grid' = Kacheln (Modal)
    'variant' => 'row',
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
    'user',
    'profileUrl' => null,
    'canChat' => false,
    'canCall' => false,
    'canMessage' => false,
    'chatAction' => null,
    'callAction' => null,
    'messageAction' => null,
    // 'row' = kompakte Symbolleiste (Dropdown), 'grid' = Kacheln (Modal)
    'variant' => 'row',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Nur einfache Methodennamen zulassen: der Wert landet in $wire.call().
    $safeAction = static fn (mixed $value): ?string => is_string($value)
        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)
            ? $value
            : null;

    $chat = $safeAction($chatAction);
    $call = $safeAction($callAction);
    $message = $safeAction($messageAction);

    $isSelf = $user->is(auth()->user());

    $actions = [];

    // Fertiger JS-Ausdruck fuer x-on:click — Methodenname und Argumente sind
    // beide bereits geprueft bzw. werden als JSON eingebettet.
    $wireCall = static fn (string $method, array $arguments): string => '$wire.call('
        .implode(', ', array_map(
            fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR),
            [$method, ...$arguments],
        ))
        .')';

    if ($canChat && $chat && ! $isSelf) {
        $actions[] = [
            'icon' => 'far fa-comment-dots',
            'label' => __('app.chat'),
            'click' => $wireCall($chat, [$user->id]),
        ];
    }

    if ($canCall && $call && ! $isSelf) {
        $actions[] = [
            'icon' => 'far fa-phone-alt',
            'label' => __('app.voice_call'),
            'click' => $wireCall($call, [$user->id, 'voice']),
        ];
        $actions[] = [
            'icon' => 'far fa-video',
            'label' => __('app.video_call'),
            'click' => $wireCall($call, [$user->id, 'video']),
        ];
    }

    if ($canMessage && $message && ! $isSelf) {
        $actions[] = [
            'icon' => 'far fa-paper-plane',
            'label' => __('app.send_message'),
            'click' => $wireCall($message, [$user->id]),
        ];
    }

    if ($profileUrl) {
        $actions[] = [
            'icon' => 'far fa-id-card',
            'label' => __('app.view_profile'),
            'href' => $profileUrl,
        ];
    }

    $rowButton = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm text-rt-muted outline-none transition duration-200 ease-rt-spring hover:bg-rt-red/10 hover:text-rt-red active:scale-[0.96] focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent';
    $gridButton = 'group flex min-w-0 flex-col items-center gap-2 rounded-xl px-1.5 py-3 text-center text-rt-muted transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent';
    $gridIcon = 'flex h-10 w-10 items-center justify-center rounded-xl bg-rt-surface-muted text-base shadow-rt-xs ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface';
?>

<?php
    // Statische Klassen: dynamisch zusammengesetzte Namen wuerden vom
    // Tailwind-Build nicht gefunden und fehlten im fertigen Stylesheet.
    $gridColumns = match (min(count($actions), 5)) {
        1 => 'grid-cols-1',
        2 => 'grid-cols-2',
        3 => 'grid-cols-3',
        5 => 'grid-cols-5',
        default => 'grid-cols-4',
    };
    $wrapperClass = $variant === 'grid'
        ? 'grid gap-2 '.$gridColumns
        : 'flex items-center gap-0.5';
    $buttonClass = $variant === 'grid' ? $gridButton : $rowButton;
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($actions !== []): ?>
    <div <?php echo e($attributes->class($wrapperClass)); ?> aria-label="<?php echo e(__('app.quick_actions')); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $actions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <<?php echo e(isset($action['href']) ? 'a' : 'button'); ?>

                <?php if(isset($action['href'])): ?>
                    href="<?php echo e($action['href']); ?>" wire:navigate
                <?php else: ?>
                    type="button" x-on:click.prevent="<?php echo e($action['click']); ?>"
                <?php endif; ?>
                class="<?php echo e($buttonClass); ?>"
                aria-label="<?php echo e($action['label']); ?>"
                title="<?php echo e($action['label']); ?>"
                data-table-row-ignore
            >
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($variant === 'grid'): ?>
                    <span class="<?php echo e($gridIcon); ?>"><i class="<?php echo e($action['icon']); ?>" aria-hidden="true"></i></span>
                    <span class="w-full truncate text-[10px] font-bold"><?php echo e($action['label']); ?></span>
                <?php else: ?>
                    <i class="<?php echo e($action['icon']); ?>" aria-hidden="true"></i>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </<?php echo e(isset($action['href']) ? 'a' : 'button'); ?>>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/user/quick-actions.blade.php ENDPATH**/ ?>