<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['seats', 'summary', 'expanded' => false, 'timezone' => 'Europe/Berlin', 'detailIdPrefix' => 'service-seat-detail']));

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

foreach (array_filter((['seats', 'summary', 'expanded' => false, 'timezone' => 'Europe/Berlin', 'detailIdPrefix' => 'service-seat-detail']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $open = max(0, (int) $summary['open']);
    $staffingLabel = $summary['reserved'].' von '.$summary['required'].' Einsatzplätzen eingeplant, '.$summary['confirmed'].' bestätigt, '.$open.' offen';
    $viewer = auth()->user();
    $canViewProfiles = $viewer?->canViewManagementDashboard() && $viewer->can('employees.view');
?>
<div class="rt-service-staffing__content" <?php if($expanded): ?> data-service-staffing-preview-content x-data="{ activeSeat: null }" x-effect="if (!open) activeSeat = null" <?php endif; ?>>
    <div class="rt-service-staffing__caption">
        <span class="tabular-nums" aria-label="<?php echo e($staffingLabel); ?>"><?php echo e($summary['reserved']); ?>/<?php echo e($summary['required']); ?></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($open > 0): ?>
            <span class="rt-service-staffing__open"><?php echo e($open); ?> <?php echo e($open === 1 ? 'Platz offen' : 'Plätze offen'); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($seats->isNotEmpty() || $open > 0): ?>
        <div class="rt-service-avatars" role="group" aria-label="<?php echo e($staffingLabel); ?>" tabindex="0">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($seats->isNotEmpty()): ?>
                <div class="rt-service-avatar-group" <?php if($expanded): ?> data-service-preview-assigned-group <?php else: ?> data-service-assigned-group <?php endif; ?> role="group" aria-label="<?php echo e($seats->count()); ?> belegte Einsatzplätze">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $seats; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $seat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $user = $seat['user'];
                            $name = $user?->name ?: 'Eingeplante Person nicht verfügbar';
                            $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') ?: '?';
                            $seatLabel = $name.' · '.$seat['shift'];
                        ?>
                        <span class="rt-service-avatar" aria-label="<?php echo e($seatLabel); ?>" <?php if($expanded): ?> data-service-preview-person="<?php echo e($user?->id); ?>" data-service-preview-seat="<?php echo e($seat['id']); ?>" role="button" tabindex="0" aria-controls="<?php echo e($detailIdPrefix); ?>-<?php echo e($seat['id']); ?>" :aria-expanded="(activeSeat === <?php echo e($seat['id']); ?>).toString()" data-rt-dropdown-keep-open @pointerenter="if ($event.pointerType === 'mouse') activeSeat = <?php echo e($seat['id']); ?>" @focus="activeSeat = <?php echo e($seat['id']); ?>" @click.stop="activeSeat = <?php echo e($seat['id']); ?>" @keydown.enter.prevent.stop="activeSeat = <?php echo e($seat['id']); ?>" @keydown.space.prevent.stop="activeSeat = <?php echo e($seat['id']); ?>" <?php else: ?> title="<?php echo e($seatLabel); ?>" data-service-person="<?php echo e($user?->id); ?>" data-service-seat="<?php echo e($seat['id']); ?>" <?php endif; ?>>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($user?->profile_photo_path): ?>
                                <img src="<?php echo e($user->profile_photo_url); ?>" alt="" loading="lazy" onerror="this.hidden=true" />
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <span aria-hidden="true"><?php echo e($user ? $initials : '?'); ?></span>
                        </span>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($open > 0): ?>
                <div class="rt-service-avatar-group rt-service-avatar-group--open" <?php if($expanded): ?> data-service-preview-open-group <?php else: ?> data-service-open-group <?php endif; ?> role="group" aria-label="<?php echo e($open); ?> offene Einsatzplätze">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($slot = 0; $slot < $open; $slot++): ?>
                        <span class="rt-service-avatar rt-service-avatar--open" title="Offener Einsatzplatz <?php echo e($slot + 1); ?> von <?php echo e($open); ?>" aria-label="Offener Einsatzplatz <?php echo e($slot + 1); ?> von <?php echo e($open); ?>" <?php if($expanded): ?> data-service-preview-open-slot <?php else: ?> data-service-open-slot <?php endif; ?>><span aria-hidden="true">?</span><span class="rt-service-avatar__dot" aria-hidden="true"></span></span>
                    <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($expanded && $seats->isNotEmpty()): ?>
        <div class="rt-service-person-details" aria-live="polite" aria-atomic="true" x-show="activeSeat !== null" x-cloak>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $seats; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $seat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $user = $seat['user'];
                    $name = $user?->name ?: 'Eingeplante Person nicht verfügbar';
                    $from = ($seat['starts_at'] ?? null)?->copy()->setTimezone($timezone);
                    $until = ($seat['ends_at'] ?? null)?->copy()->setTimezone($timezone);
                ?>
                <div id="<?php echo e($detailIdPrefix); ?>-<?php echo e($seat['id']); ?>" class="rt-service-person-detail" data-service-person-detail="<?php echo e($seat['id']); ?>" x-show="activeSeat === <?php echo e($seat['id']); ?>" x-transition:enter="rt-service-person-enter" x-transition:enter-start="rt-service-person-enter-from" x-transition:enter-end="rt-service-person-enter-to" x-cloak>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($user): ?>
                        <div class="rt-service-person-detail__body">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canViewProfiles): ?>
                                <a href="<?php echo e(route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $user->id)); ?>" wire:navigate class="rt-service-person-detail__link" aria-label="Profil öffnen: <?php echo e($name); ?>" data-service-person-profile="<?php echo e($user->id); ?>" data-table-row-ignore>
                                    <?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $user,'size' => 10,'showEmail' => false,'showPresence' => false,'localInitials' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user),'size' => 10,'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'local-initials' => true]); ?>
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
                                </a>
                            <?php else: ?>
                                <?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $user,'size' => 10,'showEmail' => false,'showPresence' => false,'localInitials' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user),'size' => 10,'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'local-initials' => true]); ?>
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
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <div class="rt-service-person-detail__shift"><span><i class="far fa-calendar-day" aria-hidden="true"></i> <?php echo e($seat['shift'] ?: 'Schicht'); ?></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from && $until): ?><time datetime="<?php echo e($from->toIso8601String()); ?>"><?php echo e($from->format('d.m.Y H:i')); ?> – <?php echo e($until->format('d.m.Y H:i')); ?></time><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
                        </div>
                    <?php else: ?>
                    <span class="rt-service-person-detail__avatar" aria-hidden="true">
                        <span>?</span>
                    </span>
                    <div><strong><?php echo e($name); ?></strong><span><i class="far fa-calendar-day" aria-hidden="true"></i> <?php echo e($seat['shift'] ?: 'Schicht'); ?></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from && $until): ?><time datetime="<?php echo e($from->toIso8601String()); ?>"><?php echo e($from->format('d.m.Y H:i')); ?> – <?php echo e($until->format('d.m.Y H:i')); ?></time><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-staffing-content.blade.php ENDPATH**/ ?>