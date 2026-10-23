<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'user',
    'profileUrl' => null,
    'canMessage' => false,
    'canChat' => false,
    'canCall' => false,
    'chatAction' => null,
    'callAction' => null,
    'messageAction' => 'openMessage',
    'selected' => false,
    'selectionIndicator' => false,
    'showPresence' => true,
    'showEmail' => true,
    'size' => 10,
    'triggerClasses' => 'inline-flex',
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
    'canMessage' => false,
    'canChat' => false,
    'canCall' => false,
    'chatAction' => null,
    'callAction' => null,
    'messageAction' => 'openMessage',
    'selected' => false,
    'selectionIndicator' => false,
    'showPresence' => true,
    'showEmail' => true,
    'size' => 10,
    'triggerClasses' => 'inline-flex',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $position = $user->profile?->position;
    $team = $user->currentTeam?->name;

    // Kopfzeile fasst Funktion und Team zusammen; leere Werte entfallen,
    // statt eine Zeile mit Gedankenstrich zu hinterlassen. Telefonnummern
    // bleiben bewusst aussen vor — die Schnellansicht steht allen Kolleginnen
    // und Kollegen offen, Kontaktdaten gehoeren ins Profil.
    $subline = implode(' · ', array_filter([$position, $team]));
?>

<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '72','offset' => 10,'contentRole' => 'dialog','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','triggerClasses' => $triggerClasses,'dataPersonAnchorPreview' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '72','offset' => 10,'content-role' => 'dialog','content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','trigger-classes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($triggerClasses),'data-person-anchor-preview' => true]); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($trigger)): ?>
            <?php echo e($trigger); ?>

        <?php else: ?>
            <button
                type="button"
                class="group min-w-0 rounded-lg px-1 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                aria-label="<?php echo e(__('app.open_person_preview')); ?>: <?php echo e($user->name); ?>"
                title="<?php echo e(__('app.open_person_preview')); ?>"
                data-table-row-ignore
            >
                <?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $user,'size' => $size,'showEmail' => $showEmail,'showPresence' => $showPresence,'selected' => $selected,'selectionIndicator' => $selectionIndicator]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showEmail),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showPresence),'selected' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selected),'selection-indicator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectionIndicator)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $attributes = $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $component = $__componentOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?>
            </button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
     <?php $__env->endSlot(); ?>

     <?php $__env->slot('content', null, []); ?> 
        <article class="w-72 max-w-[calc(100vw-1.5rem)] overflow-hidden" aria-label="<?php echo e(__('app.person_preview')); ?>: <?php echo e($user->name); ?>">
            
            <div class="flex min-w-0 items-center gap-3 px-4 pb-3 pt-4">
                <img
                    src="<?php echo e($user->profile_photo_url); ?>"
                    alt=""
                    class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                >
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-5 text-rt-text dark:text-rt-dark-text"><?php echo e($user->name); ?></p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($subline !== ''): ?>
                        <p class="truncate text-xs leading-4 text-rt-muted dark:text-rt-dark-muted"><?php echo e($subline); ?></p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            
            <div class="flex min-w-0 items-center gap-2 px-4 pb-3 text-xs text-rt-muted dark:text-rt-dark-muted">
                <i class="far fa-envelope w-3.5 shrink-0 text-center text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                <span class="truncate"><?php echo e($user->email); ?></span>
            </div>

            
            <?php if (isset($component)) { $__componentOriginalc3c080b60e3353450a486f9b416d4d1b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc3c080b60e3353450a486f9b416d4d1b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.quick-actions','data' => ['user' => $user,'profileUrl' => $profileUrl,'canChat' => $canChat,'canCall' => $canCall,'canMessage' => $canMessage,'chatAction' => $chatAction,'callAction' => $callAction,'messageAction' => $messageAction,'variant' => 'row','class' => 'border-t border-rt-border/70 px-2.5 py-1.5 dark:border-rt-dark-border/70']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.quick-actions'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user),'profile-url' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($profileUrl),'can-chat' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($canChat),'can-call' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($canCall),'can-message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($canMessage),'chat-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($chatAction),'call-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($callAction),'message-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($messageAction),'variant' => 'row','class' => 'border-t border-rt-border/70 px-2.5 py-1.5 dark:border-rt-dark-border/70']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc3c080b60e3353450a486f9b416d4d1b)): ?>
<?php $attributes = $__attributesOriginalc3c080b60e3353450a486f9b416d4d1b; ?>
<?php unset($__attributesOriginalc3c080b60e3353450a486f9b416d4d1b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc3c080b60e3353450a486f9b416d4d1b)): ?>
<?php $component = $__componentOriginalc3c080b60e3353450a486f9b416d4d1b; ?>
<?php unset($__componentOriginalc3c080b60e3353450a486f9b416d4d1b); ?>
<?php endif; ?>
        </article>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $attributes = $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $component = $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/user/person-anchor-preview.blade.php ENDPATH**/ ?>