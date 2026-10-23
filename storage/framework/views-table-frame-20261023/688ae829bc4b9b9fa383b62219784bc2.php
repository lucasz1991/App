<section class="rt-staff-timeline-layout min-w-0" aria-label="Mitarbeiter-Zeitleiste"
    x-data="rtTimelinePlanning(<?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>)" x-on:operations-plan-changed.window="invalidate()"
    x-on:operations-timeline-filters-changed.window="if ($event.detail.timelineId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) invalidate()"
    x-on:resize.window="refreshFocusPreview()"
    x-on:operations-timeline-window-reset.window="if ($event.detail.timelineId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) invalidate()"
    x-on:operations-timeline-window-pending.window="if ($event.detail.timelineId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) invalidate()">
<?php
    $windowNavigation = (bool) ($periodNavigationOwner && $planningEnabled && !$absencesOnly);
    $focusStart = $focus['shift']?->starts_at->copy()->setTimezone($zone);
    $focusEnd = $focus['shift']?->ends_at->copy()->setTimezone($zone);
?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningEnabled && !$absencesOnly && $showSuggestions && !$suggestionsReady): ?>
    
    <span hidden wire:key="timeline-suggestions-load-<?php echo e($previewVersion); ?>-<?php echo e($from); ?>-<?php echo e($until); ?>" wire:init="loadSuggestions" data-timeline-suggestions-pending></span>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$absencesOnly): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($searchInHeader): ?>
        <template x-teleport="[data-topbar-page-search]" wire:key="timeline-topbar-search-<?php echo e($this->getId()); ?>">
            <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Mitarbeiter suchen','ariaLabel' => 'Mitarbeiter suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Mitarbeiter suchen','aria-label' => 'Mitarbeiter suchen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $attributes = $__attributesOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $component = $__componentOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__componentOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
        </template>
    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['wire:model.live.debounce.300ms' => 'search','placeholder' => 'Mitarbeiter suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model.live.debounce.300ms' => 'search','placeholder' => 'Mitarbeiter suchen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $attributes = $__attributesOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $component = $__componentOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__componentOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($windowNavigation): ?>
    <template x-teleport="[data-timeline-filter-owner='<?php echo e($periodNavigationOwner); ?>']" wire:key="timeline-filter-controls-<?php echo e($this->getId()); ?>">
        <?php echo $__env->make('livewire.operations.partials.timeline-filters', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </template>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<div class="rt-personnel-timeline" style="--timeline-days:<?php echo e($days->count()); ?>;--timeline-selected-days:<?php echo e($selectedDays->count()); ?>" x-data="rtStaffTimeline" x-effect="applyPersonnelMode()"
    x-on:operations-timeline-distribution-focus.window="requestDistributionFocus($event.detail)"
    data-no-sidebar-swipe data-rt-dropdown-scroll-root data-timeline-motion="<?php echo e($planningEnabled && !$absencesOnly ? 'true' : 'false'); ?>"
    data-timeline-window-navigation="<?php echo e($periodNavigationOwner && $planningEnabled && !$absencesOnly ? 'true' : 'false'); ?>"
    data-timeline-period-owner="<?php echo e($periodNavigationOwner); ?>" data-timeline-from="<?php echo e($from); ?>" data-timeline-until="<?php echo e($until); ?>"
    data-timeline-id="<?php echo e($this->getId()); ?>" data-timeline-focus-date="<?php echo e($focus['date']); ?>" data-timeline-focus-shift-id="<?php echo e($focus['shift']?->id); ?>"
    data-timeline-axis-from="<?php echo e($axisFrom->toDateString()); ?>" data-timeline-axis-until="<?php echo e($axisUntil->subDay()->toDateString()); ?>"
    data-timeline-selected-days="<?php echo e($selectedDays->count()); ?>"
    data-timeline-day-attention="<?php echo e(json_encode($dayAttention, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); ?>"
    data-timeline-buffer-before="<?php echo e((int) $axisFrom->diffInDays($selectedFrom)); ?>" data-timeline-buffer-after="<?php echo e((int) $selectedUntil->diffInDays($axisUntil)); ?>"
    x-bind:style="{ '--timeline-display-days': windowDisplayDayCount, '--timeline-selected-days': windowDisplaySelectedDayCount, '--timeline-content-offset': windowContentOffset }"
    x-bind:data-content-loading="windowContentLoading ? 'true' : 'false'"
    x-on:operations-timeline-period-request.window="requestWindowPeriod($event)"
    x-bind:aria-busy="windowContentLoading ? 'true' : 'false'">
    <div class="rt-timeline-window-feedback" x-show="windowError" x-cloak role="status" aria-live="polite">
        <span x-text="windowError"></span>
        <button type="button" x-on:click="retryWindow()" class="rt-timeline-window-retry">Erneut laden</button>
    </div>
    <div class="rt-timeline-loading-indicator" style="display: none" wire:loading.delay.flex wire:target="search,staffPresence,shiftRole,shiftStatus,assignmentStatusFilter,resetTimelineFilters,loadMore,refreshPlanning,loadSuggestions" role="status" aria-live="polite">
        <i class="far fa-spinner-third" aria-hidden="true"></i><span>Lädt …</span>
    </div>
    <div class="rt-personnel-timeline-header">
        <div class="rt-personnel-timeline-name rt-personnel-timeline-head rt-personnel-timeline-person-heading" data-timeline-person-column>
            <button type="button" class="rt-personnel-timeline-person-toggle" data-timeline-person-toggle x-on:click="togglePersonnelColumn()"
                x-bind:aria-expanded="personnelCompact ? 'false' : 'true'"
                x-bind:aria-label="personnelCompact ? 'Mitarbeiterspalte erweitern' : 'Mitarbeiterspalte kompakt anzeigen'"
                x-bind:title="personnelCompact ? 'Mitarbeiterspalte erweitern' : 'Mitarbeiterspalte kompakt anzeigen'"
                aria-label="Mitarbeiterspalte kompakt anzeigen" aria-expanded="true">
                <i class="far fa-users" aria-hidden="true"></i><span class="rt-personnel-timeline-person-label">Mitarbeiter</span>
                <span class="rt-personnel-timeline-person-cue" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" focusable="false"><path d="m6 4 4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
            </button>
        </div>
        <div class="rt-personnel-timeline-header-viewport">
        <div class="rt-personnel-timeline-header-scroll" x-ref="timelineHeader">
            <div class="rt-timeline-header-axis">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($windowNavigation): ?>
            
            <div class="rt-personnel-timeline-header-days" wire:ignore data-timeline-scaffold-header>
                <template x-for="day in windowScaffoldDays" :key="day.date">
                    <div class="rt-personnel-timeline-head" x-bind:class="{ 'rt-personnel-timeline-head--weekend': day.weekend }" x-bind:data-timeline-date="day.date" x-bind:data-focus="day.focus ? 'true' : 'false'" x-bind:data-pending="day.pending ? 'true' : 'false'" x-bind:role="day.pending ? 'status' : null" aria-live="polite">
                        <span class="rt-personnel-timeline-date-row">
                            <span class="rt-personnel-timeline-date"><span x-text="day.label"></span><i class="far fa-spinner-third fa-spin rt-timeline-day-spinner" x-show="day.pending && !windowError" aria-hidden="true"></i></span>
                            <span class="rt-timeline-day-attention" x-cloak x-show="!day.pending && day.attention?.shifts > 0" x-bind:title="day.attentionLabel" x-bind:aria-label="day.attentionLabel">
                                <span class="rt-timeline-day-attention__dot" aria-hidden="true"></span><span x-text="day.attention?.shifts" aria-hidden="true"></span><span class="rt-timeline-day-attention__caption" aria-hidden="true">offen</span>
                            </span>
                        </span>
                        <span class="sr-only" x-show="day.pending" x-text="'Daten für ' + day.label + (windowError ? ' konnten nicht geladen werden.' : ' werden geladen.')"></span>
                        <span class="rt-personnel-timeline-hours" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></span>
                    </div>
                </template>
            </div>
            <?php else: ?>
            <div class="rt-personnel-timeline-header-days">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $days; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-personnel-timeline-head', 'rt-personnel-timeline-head--weekend' => $day->isWeekend()]); ?>" data-timeline-date="<?php echo e($day->toDateString()); ?>" wire:key="staff-head-<?php echo e($day->toDateString()); ?>" data-focus="<?php echo e($focus['date'] === $day->toDateString() ? 'true' : 'false'); ?>">
                        <span class="rt-personnel-timeline-date-row">
                            <span class="rt-personnel-timeline-date"><?php echo e($day->locale('de')->translatedFormat('D, d.m.')); ?></span>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($dayAttention[$day->toDateString()]['shifts'] ?? 0) > 0): ?>
                                <?php
                                    $attention = $dayAttention[$day->toDateString()];
                                ?>
                                <span class="rt-timeline-day-attention" title="<?php echo e($attention['shifts']); ?> Schichten mit Bearbeitungsbedarf · <?php echo e($attention['open_places']); ?> offene Plätze · <?php echo e($attention['requested_places']); ?> ausstehende Zusagen" aria-label="<?php echo e($attention['shifts']); ?> Schichten mit Bearbeitungsbedarf · <?php echo e($attention['open_places']); ?> offene Plätze · <?php echo e($attention['requested_places']); ?> ausstehende Zusagen"><span class="rt-timeline-day-attention__dot" aria-hidden="true"></span><?php echo e($attention['shifts']); ?><span class="rt-timeline-day-attention__caption">offen</span></span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </span>
                        <span class="rt-personnel-timeline-hours" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></span>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--previous" x-cloak x-show="canScrollLeft || windowNavigationEnabled" x-on:click="scrollDay(-1)" aria-label="Weitere Tage links anzeigen" title="Weitere Tage links"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--next" x-cloak x-show="canScrollRight || windowNavigationEnabled" x-on:click="scrollDay(1)" aria-label="Weitere Tage rechts anzeigen" title="Weitere Tage rechts"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="rt-personnel-timeline-body snap-x snap-mandatory" x-ref="timelineBody" x-on:pointerdown="cancelDistributionFocus()" x-on:keydown="cancelDistributionFocus()" x-on:scroll.passive="syncHorizontal($event.target); refreshFocusPreview()" x-on:wheel="wheelPersonnel($event)" x-on:scrollend.passive="finishHorizontalIntent()" x-on:keydown.left="if ($event.target === $event.currentTarget) { $event.preventDefault(); scrollDay(-1); }" x-on:keydown.right="if ($event.target === $event.currentTarget) { $event.preventDefault(); scrollDay(1); }" tabindex="0" role="region" aria-label="Zeitfenster nach Mitarbeiter, vertikal scrollbar; weitere Tage über die Richtungspfeile, Pfeiltasten oder horizontales Scrollen">
    <div class="rt-personnel-timeline-grid" x-ref="timelineGrid"
        x-on:click="if (!windowContentLoading) openPlanner($event)" x-on:pointerover="if (!windowContentLoading) hoverCell($event)" x-on:pointerout="leaveCell($event)"
        x-on:focusin="if (!windowContentLoading) hoverCell($event)" x-on:focusout="leaveCell($event)">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($windowNavigation): ?>
        <div class="rt-timeline-scaffold-columns" wire:ignore aria-hidden="true" data-timeline-scaffold-body>
            <template x-for="day in windowScaffoldDays" :key="day.date">
                <div class="rt-timeline-scaffold-day" x-bind:class="{ 'rt-personnel-timeline-day--weekend': day.weekend }" x-bind:data-timeline-date="day.date" x-bind:data-pending="day.pending ? 'true' : 'false'"></div>
            </template>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php
        $personViewer = auth()->user();
        $personCanUseQuickActions = $planningEnabled && ! $absencesOnly && $personViewer->can('operations.manage');
        $personCanMessage = $personCanUseQuickActions && $personViewer->can('users.messages.create');
        $personCanViewProfile = $personCanUseQuickActions && $personViewer->canViewManagementDashboard() && $personViewer->can('employees.view');
        $personCanStartCall = $personCanUseQuickActions && ($personViewer->isAdmin() || $personViewer->hasRbacPermission('calls.start'));
    ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
        <?php
            $personCanCommunicate = $personCanUseQuickActions && $row['user']->isActive() && ! $row['user']->is($personViewer);
            $personCanCall = $personCanCommunicate && $personCanStartCall
                && ($row['user']->isAdmin() || $row['user']->hasRbacPermission('calls.join'));
        ?>
        <div class="rt-personnel-timeline-name min-w-0" data-timeline-person-column wire:key="staff-person-<?php echo e($row['user']->id); ?>" <?php if($focus['shift']): ?> data-focus-fit="<?php echo e($focus['candidates']->has($row['user']->id) ? 'eligible' : 'blocked'); ?>" <?php endif; ?>>
            <div class="rt-timeline-person-summary">
            <?php if (isset($component)) { $__componentOriginalc07b0998277cb6d4345f54b3047a566a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc07b0998277cb6d4345f54b3047a566a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.person-anchor-preview','data' => ['user' => $row['user'],'profileUrl' => $personCanViewProfile ? route($personViewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $row['user']->id) : null,'canMessage' => $personCanMessage && $personCanCommunicate,'canChat' => $personCanCommunicate,'canCall' => $personCanCall,'chatAction' => 'openDirectChat','callAction' => 'startDirectCall','showPresence' => false,'triggerClasses' => 'flex min-w-0 w-full']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.person-anchor-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['user']),'profile-url' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($personCanViewProfile ? route($personViewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $row['user']->id) : null),'can-message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($personCanMessage && $personCanCommunicate),'can-chat' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($personCanCommunicate),'can-call' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($personCanCall),'chat-action' => 'openDirectChat','call-action' => 'startDirectCall','show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'trigger-classes' => 'flex min-w-0 w-full']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <button type="button" class="min-h-11 min-w-0 w-full rounded-lg text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent" aria-label="<?php echo e(__('app.open_person_preview')); ?>: <?php echo e($row['user']->name); ?>" title="<?php echo e($row['user']->name); ?>">
                        <?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $row['user'],'size' => 6,'showEmail' => false,'showPresence' => false,'nameFormat' => 'initial-surname','class' => 'rt-timeline-person-identity']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['user']),'size' => 6,'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'name-format' => 'initial-surname','class' => 'rt-timeline-person-identity']); ?>
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
                 <?php $__env->endSlot(); ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $attributes = $__attributesOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $component = $__componentOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__componentOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($workloads->has($row['user']->id)): ?>
                <?php echo $__env->make('livewire.operations.partials.timeline-workload', ['workload' => $workloads->get($row['user']->id), 'person' => $row['user']], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$row['user']->status): ?><span class="ops-muted rt-timeline-person-status">Inaktiv</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="rt-personnel-timeline-track" data-timeline-lanes="<?php echo e($row['lane_count']); ?>" data-timeline-density="<?php echo e($row['lane_count'] > 1 ? 'compact' : 'normal'); ?>" style="--timeline-lanes:<?php echo e($row['lane_count']); ?>" wire:key="staff-track-<?php echo e($row['user']->id); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($windowNavigation): ?>
        <div class="rt-timeline-loaded-track">
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $row['days']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $cell): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $focusEligible = $focus['event'] && $focus['candidates']->has($row['user']->id)
                    && $cell['date']->lt($focusEnd) && $cell['date']->addDay()->gt($focusStart);
            ?>
            <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-personnel-timeline-day snap-start', 'rt-personnel-timeline-day--weekend' => $cell['date']->isWeekend(), 'rt-personnel-timeline-day--empty' => $cell['events']->isEmpty()]); ?>" data-timeline-date="<?php echo e($cell['date']->toDateString()); ?>" <?php if($focusEligible): ?> data-focus-fit="eligible" <?php endif; ?> wire:key="staff-day-<?php echo e($row['user']->id); ?>-<?php echo e($cell['date']->toDateString()); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($focusEligible): ?>
                    
                    <button type="button" class="rt-timeline-cell-action rt-timeline-focus-target" x-bind:disabled="windowContentLoading"
                        data-timeline-proposal data-focus="true" data-user="<?php echo e($row['user']->id); ?>" data-date="<?php echo e($cell['date']->toDateString()); ?>" data-shift="<?php echo e($focus['shift']->id); ?>" data-revision="<?php echo e($focus['shift']->revision); ?>"
                        aria-haspopup="dialog" aria-label="<?php echo e($row['user']->name); ?> für <?php echo e($focus['shift']->title); ?> einteilen · <?php echo e($focus['event']['timeline_label']); ?>" title="<?php echo e(collect($focus['candidates']->get($row['user']->id)['reasons'] ?? [])->implode(' · ')); ?>"></button>
                <?php elseif($planningEnabled && !$absencesOnly && $row['user']->status && $cell['date']->gte($selectedFrom) && $cell['date']->lt($selectedUntil)): ?>
                    <?php
                        $cellStatusId = 'timeline-cell-status-'.$this->getId().'-'.$row['user']->id.'-'.$cell['date']->toDateString();
                    ?>
                    <button type="button" class="rt-timeline-cell-action" data-user="<?php echo e($row['user']->id); ?>" data-date="<?php echo e($cell['date']->toDateString()); ?>" x-bind:disabled="windowContentLoading" aria-haspopup="dialog" aria-label="Offene Schicht auswählen: <?php echo e($row['user']->name); ?> · <?php echo e($cell['date']->format('d.m.Y')); ?>" aria-describedby="<?php echo e($cellStatusId); ?>" data-timeline-cell-action>
                        <span class="rt-timeline-cell-status" aria-hidden="true"><i class="far fa-circle" data-timeline-cell-status-icon></i></span>
                        <span id="<?php echo e($cellStatusId); ?>" class="sr-only" data-timeline-cell-status-text></span>
                    </button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <div class="rt-personnel-timeline-events">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $row['events']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $state = in_array($event['shift_status'] ?? null, ['in_progress', 'completed'], true) ? $event['shift_status'] : $event['status_value'];
                    $eventKey = $row['user']->id.'-'.$event['id'];
                    $detailFacts = \App\Support\Operations\TimelineEventDetail::forEvent($event, $row, $zone, $absencesOnly, $state);
                ?>
                <div class="rt-personnel-timeline-event" data-kind="<?php echo e($event['kind']); ?>" data-state="<?php echo e($state); ?>" data-continues-before="<?php echo e($event['continues_before'] ? 'true' : 'false'); ?>" data-continues-after="<?php echo e($event['continues_after'] ? 'true' : 'false'); ?>" data-time-start="<?php echo e($event['left_percent']); ?>" data-time-width="<?php echo e($event['width_percent']); ?>" data-time-lane="<?php echo e($event['lane']); ?>" style="--event-left:<?php echo e($event['left_percent']); ?>%;--event-width:<?php echo e($event['width_percent']); ?>%;--event-lane:<?php echo e($event['lane']); ?>" wire:key="staff-event-<?php echo e($eventKey); ?>">
                            <button type="button" class="rt-personnel-timeline-bar" aria-label="<?php echo e($event['local_label']); ?> · <?php echo e($event['title']); ?> · <?php echo e($event['status']); ?>" aria-expanded="false" aria-haspopup="dialog" aria-controls="rt-dropdown-timeline-event-<?php echo e($this->getId()); ?>-content"
                                data-timeline-detail="<?php echo e(json_encode($detailFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); ?>"
                                x-on:click="$dispatch('rt-timeline-event-preview', { anchor: $el })" data-table-row-ignore>
                                <span class="rt-personnel-timeline-time"><span class="rt-personnel-timeline-time-text"><?php echo e($event['timeline_label']); ?></span></span>
                                <span class="rt-personnel-timeline-duration-clip" aria-hidden="true">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $event['time_segments']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $segment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <span class="rt-personnel-timeline-mark" style="left:<?php echo e(($segment['left_percent'] - $event['left_percent']) / $event['width_percent'] * 100); ?>%;width:<?php echo e($segment['width_percent'] / $event['width_percent'] * 100); ?>%" aria-hidden="true"></span>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </span>
                            </button>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($focus['event'] && $focus['candidates']->has($row['user']->id)): ?>
            
            <div class="rt-timeline-focus" aria-hidden="true" data-time-start="<?php echo e($focus['event']['left_percent']); ?>" data-time-width="<?php echo e($focus['event']['width_percent']); ?>" wire:key="staff-focus-<?php echo e($row['user']->id); ?>-<?php echo e($focus['shift']->id); ?>" style="--time-label-offset:clamp(55px, <?php echo e($focus['event']['left_percent'] + $focus['event']['width_percent'] / 2); ?>%, calc(100% - 55px));--time-badge-width:max(110px, <?php echo e($focus['event']['width_percent']); ?>%)">
                <span class="rt-personnel-timeline-time rt-timeline-focus-preview">
                    <span class="rt-personnel-timeline-time-text"><?php echo e($focus['event']['timeline_label']); ?></span>
                </span>
                <span class="rt-personnel-timeline-duration-clip">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $focus['event']['time_segments']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $segment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <span class="rt-timeline-focus-duration" style="left:<?php echo e($segment['left_percent']); ?>%;width:<?php echo e($segment['width_percent']); ?>%"></span>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </span>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningEnabled && !$absencesOnly && $showSuggestions): ?>
            <div class="rt-timeline-proposals" aria-label="Unverbindliche Besetzungsvorschläge" wire:key="staff-proposals-<?php echo e($row['user']->id); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $proposalRows->get($row['user']->id,collect()); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $proposal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php if($focus['shift'] && $proposal['shift_id'] === $focus['shift']->id) continue; ?>
                    <div class="rt-timeline-proposal-shell" style="--proposal-left:<?php echo e($proposal['left_percent']); ?>%;--proposal-center:<?php echo e($proposal['left_percent'] + ($proposal['width_percent'] / 2)); ?>%;--proposal-width:<?php echo e($proposal['width_percent']); ?>%;--proposal-duration-width:calc(var(--timeline-days) * var(--timeline-day-width) * <?php echo e($proposal['width_percent'] / 100); ?>)" data-fit="<?php echo e($proposal['fit']); ?>" wire:key="<?php echo e($proposal['id']); ?>">
                        <button type="button" class="rt-timeline-proposal" data-user="<?php echo e($proposal['user_id']); ?>" data-shift="<?php echo e($proposal['shift_id']); ?>" data-revision="<?php echo e($proposal['revision']); ?>" x-bind:disabled="windowContentLoading" data-fit="<?php echo e($proposal['fit']); ?>" data-urgency="<?php echo e($proposal['urgency']); ?>" aria-haspopup="dialog" aria-label="Vorschlag prüfen: <?php echo e($row['user']->name); ?> · <?php echo e($proposal['title']); ?> · <?php echo e($proposal['local_label']); ?> · <?php echo e($proposal['fit_label']); ?> · <?php echo e($proposal['urgency_label']); ?>" title="<?php echo e($proposal['fit_label']); ?> · <?php echo e($proposal['urgency_label']); ?> · <?php echo e($proposal['title']); ?> · <?php echo e(implode(' · ',$proposal['reasons'])); ?>" data-timeline-proposal>
                            <i class="far <?php echo e($proposal['fit'] === 'review' ? 'fa-exclamation-circle' : ($proposal['fit'] === 'preferred' ? 'fa-check-circle' : 'fa-lightbulb')); ?>" aria-hidden="true"></i>
                            <span><?php echo e($proposal['timeline_label']); ?></span>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($proposal['urgency'] !== 'normal'): ?><i class="far fa-clock rt-timeline-proposal__urgency" aria-hidden="true"></i><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <span class="rt-timeline-proposal-duration" aria-hidden="true"></span>
                        </button>
                        <div class="rt-timeline-proposal-ping animate-ping motion-reduce:animate-none" aria-hidden="true"></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($windowNavigation): ?>
        </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><div class="rt-personnel-timeline-empty">Keine Mitarbeiter gefunden.</div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($users->hasMorePages()): ?>
        <div class="rt-personnel-timeline-load-more" style="grid-column: 1 / -1" wire:key="staff-timeline-load-more-<?php echo e($this->getPage('staffPage')); ?>" x-data x-intersect.once="$wire.loadMore()" aria-live="polite" role="status">
            <span wire:loading.remove wire:target="loadMore">Weitere Mitarbeitende laden</span>
            <span wire:loading wire:target="loadMore">Mitarbeitende werden geladen …</span>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    </div>
    <div class="rt-personnel-timeline-scrollbar-row" aria-hidden="true">
        <span class="rt-personnel-timeline-scrollbar-corner"></span>
        <div class="rt-personnel-timeline-scrollbar" x-ref="timelineScrollbar" x-on:scroll="syncHorizontal($event.target)"><div class="rt-personnel-timeline-scrollbar-width"></div></div>
    </div>
</div>
    <?php echo $__env->make('livewire.operations.partials.timeline-shared-event-detail', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningEnabled && !$absencesOnly): ?>
    <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '96','externalTrigger' => true,'dropdownId' => 'timeline-planner-'.e($this->getId()).'','layerGroup' => 'staff-timeline-events','contentRole' => 'dialog','contentLabel' => 'Schicht einteilen','triggerClasses' => 'hidden','class' => 'rt-timeline-planner-host','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','xOn:dropdownClosed.window' => 'if ($event.detail?.id === layerId) closePlanner()','dataTimelinePlanner' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '96','external-trigger' => true,'dropdown-id' => 'timeline-planner-'.e($this->getId()).'','layer-group' => 'staff-timeline-events','content-role' => 'dialog','content-label' => 'Schicht einteilen','trigger-classes' => 'hidden','class' => 'rt-timeline-planner-host','content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','x-on:dropdown-closed.window' => 'if ($event.detail?.id === layerId) closePlanner()','data-timeline-planner' => true]); ?>
         <?php $__env->slot('trigger', null, []); ?> <button type="button" tabindex="-1" aria-hidden="true">Schichtauswahl</button> <?php $__env->endSlot(); ?>
         <?php $__env->slot('content', null, []); ?> 
        <div class="rt-timeline-planner" data-rt-dropdown-keep-open x-bind:aria-busy="plannerLoading">
        <header class="rt-timeline-planner__header">
            <strong tabindex="-1" data-dropdown-initial-focus>Schicht einteilen</strong>
            <button type="button" class="rt-timeline-planner__close" x-on:click="close(true)" aria-label="Schichtauswahl schließen" title="Schließen"><i class="far fa-times" aria-hidden="true"></i></button>
        </header>
        <div x-cloak x-show.important="plannerLoading" role="status" aria-live="polite" class="rt-timeline-planner__loading">
            <i class="far fa-spinner-third" aria-hidden="true"></i>
            <strong>Schichten werden geladen</strong>
            <span>Verfügbarkeit wird geprüft …</span>
        </div>
        <p x-cloak x-show.important="plannerError" x-text="plannerError" role="alert" class="rt-timeline-planner__message"></p>
        <div x-cloak x-show.important="plannerReady && !plannerLoading && !plannerError" class="rt-timeline-planner__results" data-timeline-planner-results>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($workflowError = $this->getErrorBag()->first('workflow')): ?><p role="alert" class="rt-timeline-planner__error"><?php echo e($workflowError); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($assignmentOpen): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningUser): ?>
            <div class="rt-timeline-planner__person">
                <?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $planningUser,'showEmail' => false,'showPresence' => false]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($planningUser),'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false)]); ?>
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
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningShift): ?>
            <?php
                $planningTimeFormat = $planningShift->starts_at->setTimezone($zone)->offset !== $planningShift->ends_at->setTimezone($zone)->offset ? 'd.m. H:i P' : 'd.m. H:i';
                $planningStatusHintId = 'timeline-assignment-hint-'.$this->getId();
            ?>
            <section class="rt-timeline-planner__shift" aria-label="Gewählte Schicht">
                <h3><?php echo e($planningShift->title); ?></h3>
                <dl class="rt-timeline-planner__facts">
                    <div class="rt-timeline-planner__period"><dt class="sr-only">Zeitraum</dt><dd><i class="far fa-clock" aria-hidden="true"></i><span><?php echo e($planningShift->starts_at->setTimezone($zone)->format($planningTimeFormat)); ?> – <?php echo e($planningShift->ends_at->setTimezone($zone)->format($planningTimeFormat)); ?></span></dd></div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($planningShift->order?->customer?->company_name)): ?><div><dt>Kunde</dt><dd><?php echo e($planningShift->order->customer->company_name); ?></dd></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </dl>
            </section>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningReasons): ?>
                <section class="rt-timeline-planner__reasons" aria-label="Prüfungshinweise">
                    <span class="rt-timeline-planner__eyebrow">Prüfungshinweise</span>
                    <ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $planningReasons; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reason): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($reason); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
                </section>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <form wire:submit="confirmAssignment" class="rt-timeline-planner__form">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Routing\TomTomSettings::all()['enabled'] && $planningUser): ?>
                    <?php ($timelineTravelInitial = $planningTravel[$planningShift->id] ?? []); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$timelineTravelInitial): ?><div class="sr-only" x-data x-init="$nextTick(() => $wire.loadPlanningTravel(<?php echo e($planningShift->id); ?>))" wire:key="timeline-travel-load-<?php echo e($planningShift->id); ?>-<?php echo e($planningUser->id); ?>-<?php echo e($planningShift->revision); ?>" role="status">Fahrzeiten werden nachgeladen …</div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.shift-travel-panel', ['shiftId' => $planningShift->id,'employeeIds' => [$planningUser->id],'initialAssessments' => $timelineTravelInitial ?: null,'parentManaged' => true,'sameDayTransfersOnly' => true]);

$__key = 'timeline-travel-'.$planningShift->id.'-'.$planningUser->id.'-'.$planningShift->revision.'-'.md5(json_encode($timelineTravelInitial));

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-825479332-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($planningAssessment['issues'])): ?><div role="alert"><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $planningAssessment['issues']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $issue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($issue['message']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul><button type="button" class="underline min-h-11" x-on:click="$dispatch('operations-shift-detail-request', { id: <?php echo e($planningShift->id); ?> })">In Schichtdetails prüfen</button></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <fieldset class="rt-timeline-planner__status" aria-describedby="<?php echo e($planningStatusHintId); ?>" wire:loading.attr="disabled" wire:target="confirmAssignment,backToCellChoices">
                    <legend>Zuweisung</legend>
                    <div class="rt-timeline-planner__status-options">
                        <label class="rt-timeline-planner__status-option">
                            <input type="radio" name="timeline-assignment-status-<?php echo e($this->getId()); ?>" value="requested" wire:model="assignmentStatus" <?php if($assignmentStatus === 'requested'): echo 'checked'; endif; ?> required>
                            <span><strong>Angefragt</strong><small>Zusage steht noch aus</small></span>
                        </label>
                        <label class="rt-timeline-planner__status-option">
                            <input type="radio" name="timeline-assignment-status-<?php echo e($this->getId()); ?>" value="confirmed" wire:model="assignmentStatus" <?php if($assignmentStatus === 'confirmed'): echo 'checked'; endif; ?> required>
                            <span><strong>Bestätigt</strong><small>Bestätigt einplanen</small></span>
                        </label>
                    </div>
                    <p id="<?php echo e($planningStatusHintId); ?>">Erst mit „Einteilen“ wird die Schicht zugewiesen.</p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($assignmentError = $this->getErrorBag()->first('assignmentStatus')): ?><p role="alert" class="rt-timeline-planner__error"><?php echo e($assignmentError); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </fieldset>
                <footer class="rt-timeline-planner__footer">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningDate): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'secondary','class' => 'rt-timeline-planner__back','wire:click' => 'backToCellChoices','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'secondary','class' => 'rt-timeline-planner__back','wire:click' => 'backToCellChoices','wire:loading.attr' => 'disabled']); ?><i class="far fa-arrow-left" aria-hidden="true"></i> Andere Schicht <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'submit','mode' => 'primary','class' => 'rt-timeline-planner__confirm','wire:loading.attr' => 'disabled','disabled' => !empty($planningAssessment['issues']) ? true : null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit','mode' => 'primary','class' => 'rt-timeline-planner__confirm','wire:loading.attr' => 'disabled','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(!empty($planningAssessment['issues']) ? true : null)]); ?>
                        <span wire:loading.remove wire:target="confirmAssignment">Einteilen</span>
                        <span wire:loading.inline-flex wire:target="confirmAssignment"><i class="far fa-spinner-third" aria-hidden="true"></i> Wird eingeteilt</span>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                </footer>
            </form>
        <?php else: ?>
            <div class="rt-timeline-planner__choice-heading"><strong>Offene Schichten</strong><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planningDate): ?><span><?php echo e(\Carbon\CarbonImmutable::parse($planningDate)->format('d.m.Y')); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
            <div class="rt-timeline-choices" aria-label="Offene Schichten">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $choices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $choice): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <article class="rt-timeline-choice" data-fit="<?php echo e($choice->eligible ? 'suitable' : 'blocked'); ?>" wire:key="timeline-choice-<?php echo e($choice->id); ?>">
                    <div class="rt-timeline-choice__main"><strong><?php echo e($choice->title); ?></strong><span class="rt-timeline-choice__period"><?php echo e($choice->period); ?></span><small class="rt-timeline-choice__places"><?php echo e($choice->open); ?> <?php echo e($choice->open === 1 ? 'Platz frei' : 'Plätze frei'); ?></small><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($choice->showTravel): ?><span data-routing-state="<?php echo e($choice->travel['route_state']); ?>"><?php echo e($choice->travel['route_label']); ?></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $choice->travel['warnings'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $warning): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><small><?php echo e($warning['message']); ?></small><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($choice->eligible): ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','mode' => 'secondary','class' => 'rt-timeline-choice__select','wire:click' => 'selectCellShift('.e($choice->id).','.e($choice->revision).')','wire:loading.attr' => 'disabled','ariaLabel' => 'Auswählen: '.e($choice->title).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','mode' => 'secondary','class' => 'rt-timeline-choice__select','wire:click' => 'selectCellShift('.e($choice->id).','.e($choice->revision).')','wire:loading.attr' => 'disabled','aria-label' => 'Auswählen: '.e($choice->title).'']); ?>Auswählen <i class="far fa-arrow-right" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    <?php else: ?>
                        <p class="rt-timeline-choice__blocked"><i class="far fa-ban" aria-hidden="true"></i> Nicht passend</p>
                        <ul class="rt-timeline-choice__issues"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $choice->issues; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $issue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($issue['message']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </article>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="rt-timeline-planner__message">Keine offenen Schichten an diesem Tag.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($choices->count() === 30): ?><p class="rt-timeline-planner__limit">Erste 30 Einsätze · weitere über „Noch zu verteilen“</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div></div>
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
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($personCanMessage): ?>
    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('admin.users.messages.message-form', []);

$__key = 'timeline-message-form-'.$this->getId();

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-825479332-1', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/staff-timeline.blade.php ENDPATH**/ ?>