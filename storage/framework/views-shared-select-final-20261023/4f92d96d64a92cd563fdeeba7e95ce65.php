<?php
    $panelId = 'rt-ai-assist-'.$this->getId();
    $allActions = collect($actions['page'])->concat($actions['global'])->values();
    // Antworttexte: erst escapen, dann **Hervorhebung** und Zeilenumbrüche erlauben.
    $format = fn (?string $text) => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e((string) $text));
    $tabs = array_filter(['chat' => 'Chat', 'intake' => $intakesReady ? 'Inbox' : null, 'actions' => 'Tasks', 'activity' => 'Logs']);
    $period = \Carbon\CarbonImmutable::parse($from)->format('d.m.').' – '.\Carbon\CarbonImmutable::parse($until)->format('d.m.');
    $chips = [['Seite', $pageLabel]];
    if (in_array($page, ['shifts', 'calendar', 'planning'], true)) {
        $chips[] = ['Zeitraum', $period];
    }
    if ($intakesReady) {
        $chips[] = ['AI-Eingänge', $reviewCount === 1 ? '1 prüfen' : $reviewCount.' prüfen'];
    }
    $canPlan = auth()->user()->can('operations.manage');
    $suggestions = array_values(array_filter([
        $canPlan ? 'Welche Schichten sind diese Woche noch offen?' : null,
        $canPlan ? 'Wer kann heute einen offenen Dienst übernehmen?' : null,
        auth()->user()->can('operations.inquiries.manage') ? 'Fasse die neuen Anfragen zusammen.' : null,
    ]));
?>
<div data-ai-assist-host>
<template x-teleport="<?php echo e('body'); ?>">
<div class="rt-ai-assist rt-ops" x-data="rtAiAssist" :data-open="open ? 'true' : 'false'" data-open="false" data-intake-open="<?php echo e($intakeRequested ? 'true' : 'false'); ?>"
    data-hints="<?php echo e(json_encode($hints, JSON_UNESCAPED_UNICODE)); ?>" data-actions="<?php echo e(json_encode($allActions, JSON_UNESCAPED_UNICODE)); ?>"
    x-on:keydown.window="shortcut($event)" x-on:keydown.escape="escape($event)" x-on:railtime-assistant-open.window="toggle(true)"
    x-on:railtime-assistant-close.window="if (!wide || window.innerWidth <= 760 || $event.detail?.mobile || !$event.detail?.corner || corner === $event.detail.corner) toggle(false)"
    x-on:rt-floating-surface-opened.window="if ($event.detail?.surface === 'communication' && (!wide || window.innerWidth <= 760 || $event.detail?.mobile || !$event.detail?.corner || corner === $event.detail.corner)) toggle(false)"
    x-on:ai-assist-composer-input.window="text = $event.detail.value; typed()"
    x-on:ai-intake-composer-submitted.window="if ($event.detail?.componentId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) { text = ''; typed() }"
    x-on:railtime-ai-intake-open.window="openIntake($event.detail)"
    x-on:ai-assist-intake-opened.window="if ($event.detail.componentId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) toggle(true)" data-ai-assist>
    <div x-data="railtimeAssistantCloud()" :data-state="orbState" x-bind:data-pet-open="open.toString()" data-state="idle">
        <?php if (isset($component)) { $__componentOriginalc713221267cfa0d53db99be55e71d894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc713221267cfa0d53db99be55e71d894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.floating.launcher-item','data' => ['class' => 'rt-ai-assist','xData' => 'railtimeAssistantCloud()',':dataState' => 'orbState',':dataOpen' => 'open ? \'true\' : \'false\'','xShow' => '!open','xCloak' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.floating.launcher-item'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-ai-assist','x-data' => 'railtimeAssistantCloud()',':data-state' => 'orbState',':data-open' => 'open ? \'true\' : \'false\'','x-show' => '!open','x-cloak' => true]); ?>
        <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['class' => 'rt-ai-assist__stage','align' => 'launcher','width' => 'auto','maxHeight' => 320,'offset' => 12,'openOnHover' => true,'hoverOpenDelay' => 140,'hoverCloseDelay' => 180,'dropdownId' => 'ai-assist-hint-'.e($this->getId()).'','layerGroup' => 'floating-assistant-hint','triggerClasses' => 'inline-flex pointer-events-auto','contentRole' => 'dialog','contentLabel' => 'AI-Eingang zur Prüfung','dropdownClasses' => 'rt-ai-assist__hint-dropdown !rounded-none !shadow-none','contentClasses' => '!overflow-visible !p-0 !shadow-none','xOn:railtimeAssistantOpen.window' => 'close()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-ai-assist__stage','align' => 'launcher','width' => 'auto','max-height' => 320,'offset' => 12,'open-on-hover' => true,'hover-open-delay' => 140,'hover-close-delay' => 180,'dropdown-id' => 'ai-assist-hint-'.e($this->getId()).'','layer-group' => 'floating-assistant-hint','trigger-classes' => 'inline-flex pointer-events-auto','content-role' => 'dialog','content-label' => 'AI-Eingang zur Prüfung','dropdown-classes' => 'rt-ai-assist__hint-dropdown !rounded-none !shadow-none','content-classes' => '!overflow-visible !p-0 !shadow-none','x-on:railtime-assistant-open.window' => 'close()']); ?>
             <?php $__env->slot('trigger', null, []); ?> 
            <?php if (isset($component)) { $__componentOriginalc21723261853e30cab8c244fa85e60e8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc21723261853e30cab8c244fa85e60e8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.floating.launcher','data' => ['class' => 'rt-ai-assist__launcher','xRef' => 'launcher','dataRtWindowLauncherFor' => ''.e($panelId).'','xOn:click.stop' => '$dispatch(\'railtime-assistant-open\')','xOn:focus' => 'if ($el.matches(\':focus-visible\') && prefs.proactive && hints.length && !$event.relatedTarget?.closest(\'[data-rt-dropdown-owner]\')) openDropdown(false)',':ariaExpanded' => 'open ? \'true\' : \'false\'','ariaExpanded' => 'false','ariaControls' => ''.e($panelId).'','ariaLabel' => 'AI-Assist öffnen'.e($reviewCount ? ' · '.$reviewCount.' AI-Eingänge zur Prüfung' : '').'','title' => 'AI-Assist (Strg J)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.floating.launcher'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-ai-assist__launcher','x-ref' => 'launcher','data-rt-window-launcher-for' => ''.e($panelId).'','x-on:click.stop' => '$dispatch(\'railtime-assistant-open\')','x-on:focus' => 'if ($el.matches(\':focus-visible\') && prefs.proactive && hints.length && !$event.relatedTarget?.closest(\'[data-rt-dropdown-owner]\')) openDropdown(false)',':aria-expanded' => 'open ? \'true\' : \'false\'','aria-expanded' => 'false','aria-controls' => ''.e($panelId).'','aria-label' => 'AI-Assist öffnen'.e($reviewCount ? ' · '.$reviewCount.' AI-Eingänge zur Prüfung' : '').'','title' => 'AI-Assist (Strg J)']); ?>
                <span class="rt-ai-assist__halo" aria-hidden="true"></span>
                <span class="rt-ai-assist__orb" data-assistant-cloud-slot="launcher" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($reviewCount > 0): ?>
                    <span class="rt-ai-assist__presence" aria-hidden="true"><?php echo e($reviewCount > 9 ? '9+' : $reviewCount); ?></span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc21723261853e30cab8c244fa85e60e8)): ?>
<?php $attributes = $__attributesOriginalc21723261853e30cab8c244fa85e60e8; ?>
<?php unset($__attributesOriginalc21723261853e30cab8c244fa85e60e8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc21723261853e30cab8c244fa85e60e8)): ?>
<?php $component = $__componentOriginalc21723261853e30cab8c244fa85e60e8; ?>
<?php unset($__componentOriginalc21723261853e30cab8c244fa85e60e8); ?>
<?php endif; ?>
             <?php $__env->endSlot(); ?>
             <?php $__env->slot('content', null, []); ?> 
                <div class="rt-ai-assist__orb-hint" x-show="prefs.proactive && hints.length > 0" style="display: none">
                    <span x-text="hints[0]?.text"></span>
                    <button type="button" x-on:click.stop="openHint(hints[0])" x-text="hints[0]?.label"></button>
                </div>
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

         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc713221267cfa0d53db99be55e71d894)): ?>
<?php $attributes = $__attributesOriginalc713221267cfa0d53db99be55e71d894; ?>
<?php unset($__attributesOriginalc713221267cfa0d53db99be55e71d894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc713221267cfa0d53db99be55e71d894)): ?>
<?php $component = $__componentOriginalc713221267cfa0d53db99be55e71d894; ?>
<?php unset($__componentOriginalc713221267cfa0d53db99be55e71d894); ?>
<?php endif; ?>
        <?php if (isset($component)) { $__componentOriginaldd549be980c60c890cd21eb6d78ce68d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginaldd549be980c60c890cd21eb6d78ce68d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.floating.window','data' => ['class' => 'rt-ai-assist__panel','id' => ''.e($panelId).'','xRef' => 'panel','role' => 'dialog',':ariaModal' => 'mode === \'fullscreen\' ? \'true\' : \'false\'','xBind:dataWindowMode' => 'mode','xBind:dataCorner' => 'corner','ariaLabel' => 'AI-Assist','xTrap.inert.noscroll' => 'open && mode === \'fullscreen\'','layoutAction' => 'syncLayout();','closeAction' => 'toggle(false, { restoreFocus: false })','sessionKey' => 'assist','sessionRestore' => 'restoreWindowSession(rtWindowSession)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.floating.window'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-ai-assist__panel','id' => ''.e($panelId).'','x-ref' => 'panel','role' => 'dialog',':aria-modal' => 'mode === \'fullscreen\' ? \'true\' : \'false\'','x-bind:data-window-mode' => 'mode','x-bind:data-corner' => 'corner','aria-label' => 'AI-Assist','x-trap.inert.noscroll' => 'open && mode === \'fullscreen\'','layout-action' => 'syncLayout();','close-action' => 'toggle(false, { restoreFocus: false })','session-key' => 'assist','session-restore' => 'restoreWindowSession(rtWindowSession)']); ?>
             <?php $__env->slot('header', null, ['class' => 'rt-ai-assist__head rt-assistant-header','data-assistant-header' => true]); ?> 
                <?php if (isset($component)) { $__componentOriginalcf91ec3598697788c3919e34573be424 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcf91ec3598697788c3919e34573be424 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.assistant-navigation','data' => ['class' => 'rt-ai-assist__tabs','appearance' => 'window','tabs' => $tabs,'selected' => $tab,'action' => 'setTab','idPrefix' => $panelId,'controls' => $panelId.'-body','reviewCount' => $reviewCount,'label' => 'AI-Assist']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.assistant-navigation'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-ai-assist__tabs','appearance' => 'window','tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($tabs),'selected' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($tab),'action' => 'setTab','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId),'controls' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-body'),'review-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($reviewCount),'label' => 'AI-Assist']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcf91ec3598697788c3919e34573be424)): ?>
<?php $attributes = $__attributesOriginalcf91ec3598697788c3919e34573be424; ?>
<?php unset($__attributesOriginalcf91ec3598697788c3919e34573be424); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcf91ec3598697788c3919e34573be424)): ?>
<?php $component = $__componentOriginalcf91ec3598697788c3919e34573be424; ?>
<?php unset($__componentOriginalcf91ec3598697788c3919e34573be424); ?>
<?php endif; ?>
             <?php $__env->endSlot(); ?>
             <?php $__env->slot('actions', null, ['class' => 'rt-ai-assist__head-actions']); ?> 
                <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'right','width' => '96','offset' => 6,'maxHeight' => 420,'dropdownId' => $panelId.'-context','layerGroup' => $panelId.'-header','contentRole' => 'group','contentLabel' => 'Aktueller Seitenkontext','class' => 'rt-ai-assist__header-dropdown','xShow' => 'prefs.context','xCloak' => true,'xEffect' => 'if (!Alpine.$data($el.closest(\'.rt-ai-assist\')).open) close()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'right','width' => '96','offset' => 6,'max-height' => 420,'dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-context'),'layer-group' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-header'),'content-role' => 'group','content-label' => 'Aktueller Seitenkontext','class' => 'rt-ai-assist__header-dropdown','x-show' => 'prefs.context','x-cloak' => true,'x-effect' => 'if (!Alpine.$data($el.closest(\'.rt-ai-assist\')).open) close()']); ?>
                     <?php $__env->slot('trigger', null, []); ?> 
                        <button type="button" class="rt-floating-window__action rt-ai-assist__icon-btn" data-no-drag aria-label="Seitenkontext anzeigen" title="Seitenkontext"><i class="far fa-info-circle" aria-hidden="true"></i></button>
                     <?php $__env->endSlot(); ?>
                     <?php $__env->slot('content', null, []); ?> 
                        <div class="rt-ai-assist rt-ai-assist__context-menu">
                            <div class="rt-ai-assist__chips">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $chips; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$label, $value]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <span class="rt-ai-assist__chip"><small><?php echo e($label); ?></small><strong><?php echo e($value); ?></strong></span>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($actions['page'] !== []): ?>
                                <div class="rt-ai-assist__quickbar">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = array_slice($actions['page'], 0, 2); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <button type="button" class="rt-ai-assist__quick" x-on:click="run(<?php echo \Illuminate\Support\Js::from($action['key'])->toHtml() ?>)" :disabled="busy"><span><?php echo e($action['title']); ?></span><i class="far fa-arrow-right" aria-hidden="true"></i></button>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
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
                    <button class="rt-ai-assist__icon-btn" type="button" x-cloak x-show="wide" x-on:click="resizeWindow()"
                        :aria-label="mode === 'fullscreen' ? 'AI-Assist verkleinern' : 'AI-Assist im Vollbild öffnen'"
                        :title="mode === 'fullscreen' ? 'Verkleinern' : 'Vollbild öffnen'">
                        <i class="far" :class="mode === 'fullscreen' ? 'fa-compress-alt' : 'fa-expand-alt'" aria-hidden="true"></i>
                    </button>

                <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'right','width' => '96','offset' => 6,'maxHeight' => 620,'dropdownId' => $panelId.'-settings','layerGroup' => $panelId.'-header','contentRole' => 'dialog','contentLabel' => 'Assistenz-Einstellungen','class' => 'rt-ai-assist__header-dropdown','xInit' => '$watch(\'open\', value => settingsOpen = value)','xEffect' => 'if (!Alpine.$data($el.closest(\'.rt-ai-assist\')).open) close()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'right','width' => '96','offset' => 6,'max-height' => 620,'dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-settings'),'layer-group' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-header'),'content-role' => 'dialog','content-label' => 'Assistenz-Einstellungen','class' => 'rt-ai-assist__header-dropdown','x-init' => '$watch(\'open\', value => settingsOpen = value)','x-effect' => 'if (!Alpine.$data($el.closest(\'.rt-ai-assist\')).open) close()']); ?>
                     <?php $__env->slot('trigger', null, []); ?> 
                        <button type="button" class="rt-floating-window__action rt-ai-assist__icon-btn" aria-label="Assistenz-Einstellungen" title="Einstellungen" <?php if($canConfigure): ?> x-on:click="if (!open) $wire.openSettings()" <?php endif; ?>><i class="far fa-cog" aria-hidden="true"></i></button>
                     <?php $__env->endSlot(); ?>
                     <?php $__env->slot('content', null, []); ?> 
                        <div class="rt-ai-assist rt-ai-assist__settings" data-rt-dropdown-keep-open>
                            <div class="rt-ai-assist__settings-head">
                                <div><span class="rt-ai-assist__kicker">AI-Assist</span><strong>Assistenz-Einstellungen</strong></div>
                                <button class="rt-ai-assist__icon-btn" type="button" aria-label="Einstellungen schließen" x-on:click="close(true)"><i class="far fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="rt-ai-assist__status">
                                <span class="rt-ai-assist__status-dot" data-on="<?php echo e($enabled ? 'true' : 'false'); ?>"></span>
                                <div>
                                    <strong><?php echo e($enabled ? 'AI-Eingang aktiv' : 'AI-Eingang pausiert'); ?></strong>
                                    <small>
                                        Datenmodell <?php echo e($status['configured'] ? 'eingerichtet' : 'fehlt'); ?> · Bildverständnis <?php echo e($status['image_configured'] ? 'eingerichtet' : 'fehlt'); ?>

                                        · Postfach <?php echo e($status['mailbox_configured'] ? 'verbunden' : 'nicht verbunden'); ?> · Versand <?php echo e($status['smtp_configured'] ? 'eingerichtet' : 'fehlt'); ?>

                                    </small>
                                </div>
                            </div>

                            <p class="rt-ai-assist__group">Persönlich auf diesem Gerät</p>
                            <div class="rt-ai-assist__list">
                                <label class="rt-ai-assist__setting">
                                    <span class="rt-ai-assist__setting-copy"><strong>Seitenkontext zeigen</strong><small>Seite, Zeitraum und Schnellaktionen im Info-Menü der Kopfzeile</small></span>
                                    <input type="checkbox" :checked="prefs.context" x-on:change="setPref('context', $event.target.checked)"><span class="rt-ai-assist__switch" aria-hidden="true"></span>
                                </label>
                                <label class="rt-ai-assist__setting">
                                    <span class="rt-ai-assist__setting-copy"><strong>Hinweise an der Orb</strong><small>Bei Hover offene Dienste und Freigaben anzeigen</small></span>
                                    <input type="checkbox" :checked="prefs.proactive" x-on:change="setPref('proactive', $event.target.checked)"><span class="rt-ai-assist__switch" aria-hidden="true"></span>
                                </label>
                            </div>

                            <p class="rt-ai-assist__group">AI-Eingang</p>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canConfigure): ?>
                                <div class="rt-ai-assist__list">
                                    <div class="rt-ai-assist__setting"><label for="<?php echo e($panelId); ?>-automation-mode" class="rt-ai-assist__setting-copy"><strong>Automatisierung</strong><small>Assistiert: jede Änderung erst nach Freigabe</small></label>
                                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => ''.e($panelId).'-automation-mode','wire:model' => 'config.automation_mode','ariaLabel' => 'Automatisierung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => ''.e($panelId).'-automation-mode','wire:model' => 'config.automation_mode','aria-label' => 'Automatisierung']); ?><option value="assisted">Assistiert</option><option value="automatic">Automatisch</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?></div>
                                    <div class="rt-ai-assist__setting"><label for="<?php echo e($panelId); ?>-max-rounds" class="rt-ai-assist__setting-copy"><strong>Rückfragen an Kunden</strong><small>Höchstens so viele Runden je Eingang</small></label>
                                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => ''.e($panelId).'-max-rounds','wire:model' => 'config.max_rounds','ariaLabel' => 'Rückfragen an Kunden']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => ''.e($panelId).'-max-rounds','wire:model' => 'config.max_rounds','aria-label' => 'Rückfragen an Kunden']); ?><option value="1">1 Runde</option><option value="2">2 Runden</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?></div>
                                    <div class="rt-ai-assist__setting"><label for="<?php echo e($panelId); ?>-reply-timeout" class="rt-ai-assist__setting-copy"><strong>Antwortfrist</strong><small>Danach als Kundenantwort ausstehend markieren</small></label>
                                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => ''.e($panelId).'-reply-timeout','wire:model' => 'config.reply_timeout_hours','ariaLabel' => 'Antwortfrist']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => ''.e($panelId).'-reply-timeout','wire:model' => 'config.reply_timeout_hours','aria-label' => 'Antwortfrist']); ?><option value="24">24 Stunden</option><option value="48">48 Stunden</option><option value="72">72 Stunden</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?></div>
                                    <div class="rt-ai-assist__setting"><label for="<?php echo e($panelId); ?>-max-ai-calls" class="rt-ai-assist__setting-copy"><strong>AI-Aufrufe je Stunde</strong><small>Obergrenze für automatische Auswertungen</small></label>
                                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => ''.e($panelId).'-max-ai-calls','wire:model' => 'config.max_ai_calls_per_hour','ariaLabel' => 'AI-Aufrufe je Stunde']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => ''.e($panelId).'-max-ai-calls','wire:model' => 'config.max_ai_calls_per_hour','aria-label' => 'AI-Aufrufe je Stunde']); ?><option value="50">50</option><option value="100">100</option><option value="250">250</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?></div>
                                </div>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['config'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="rt-ai-assist__error" role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <div class="rt-ai-assist__settings-foot">
                                    <a href="<?php echo e(route('admin.settings', ['tab' => 'ai-disposition', 'section' => 'configuration'])); ?>">Postfach &amp; Verbindung einrichten</a>
                                    <button type="button" class="rt-ai-assist__btn" data-primary="true" wire:click="saveSettings" wire:loading.attr="disabled" wire:target="saveSettings">Speichern</button>
                                </div>
                            <?php else: ?>
                                <div class="rt-ai-assist__list">
                                    <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Automatisierung</strong><small>Ändern nur durch die Systemverwaltung</small></span><b><?php echo e($mode === 'assisted' ? 'Assistiert' : 'Automatisch'); ?></b></div>
                                    <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Rückfragen an Kunden</strong><small>Höchstens je Eingang</small></span><b><?php echo e($values['max_rounds']); ?> <?php echo e((int) $values['max_rounds'] === 1 ? 'Runde' : 'Runden'); ?></b></div>
                                    <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Antwortfrist</strong><small>Kundenantwort</small></span><b><?php echo e($values['reply_timeout_hours']); ?> Stunden</b></div>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <p class="rt-ai-assist__privacy"><i class="far fa-info-circle" aria-hidden="true"></i><span>Mailinhalte und ergänzte Dateien werden über die eingerichtete AI-Verbindung geprüft. Kundenzuordnung, Erfassung und Versand bleiben persönlich freizugeben.<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($supervisor): ?> Verantwortlich: <?php echo e($supervisor); ?>.<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span></p>
                        </div>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tab === 'chat'): ?><button class="rt-ai-assist__icon-btn" type="button" aria-label="Neues Gespräch" title="Neues Gespräch" wire:click="resetConversation" <?php if($messages === []): echo 'disabled'; endif; ?>><i class="far fa-edit" aria-hidden="true"></i></button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <button class="rt-ai-assist__icon-btn" type="button" aria-label="AI-Assist schließen" title="Schließen (Esc)" x-on:click="toggle(false)"><i class="far fa-times" aria-hidden="true"></i></button>
             <?php $__env->endSlot(); ?>

            <p class="rt-ai-assist__error" x-show="callError" style="display:none" role="alert" x-text="callError"></p>

            <div class="rt-ai-assist__body" id="<?php echo e($panelId); ?>-body" role="tabpanel" aria-labelledby="<?php echo e($panelId); ?>-tab-<?php echo e($tab); ?>" <?php if(in_array($tab, ['intake', 'activity'], true)): ?> wire:poll.20s.visible <?php endif; ?>>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($intakeMounted && $intakesReady): ?>
                    <div class="rt-ai-assist__intake-workspace" x-show="$wire.tab === 'intake'" <?php if($tab !== 'intake'): ?> style="display:none" <?php endif; ?>>
                        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.ai-intake-inbox', ['embedded' => true,'customerId' => $intakeCustomerId,'initialIntakeId' => $intakeId,'initialCapture' => $intakeCapture,'parentComponentId' => $this->getId()]);

$__key = 'ai-assist-intake-workspace-'.($intakeCustomerId ?? 'all');

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3162936962-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tab === 'intake'): ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($intakesReady)): ?><p class="rt-ai-assist__note">Die AI-Annahme ist derzeit nicht verfügbar.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php elseif($tab === 'chat'): ?>
                    <div class="rt-ai-assist__messages" x-ref="messages" x-on:scroll.passive="handleMessagesScroll()" aria-live="polite">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($messages === []): ?>
                            <div class="rt-ai-assist__empty" x-show="!busy">
                                <span class="rt-ai-assist__orb rt-ai-assist__orb--empty" data-assistant-cloud-slot="empty" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                                <strong>Was steht als Nächstes an?</strong>
                                <span>AI-Annahme, Anfragen und Schichtplanung an einem Ort. Ich bereite Vorschläge vor, du gibst Änderungen frei.</span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($overview && $overview['available']): ?>
                                    <div class="rt-assistant-operations__overview">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['review' => 'Zur Prüfung', 'busy' => 'In Analyse', 'waiting' => 'Warten auf Antwort']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <button type="button" wire:click="setTab('intake')"><strong><?php echo e($overview['counts'][$key]); ?></strong><span><?php echo e($label); ?></span></button>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <div class="rt-ai-assist__suggest">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $suggestions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $question): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <button type="button" x-on:click="ask(<?php echo \Illuminate\Support\Js::from($question)->toHtml() ?>)"><i class="far fa-comment-alt" aria-hidden="true"></i><?php echo e($question); ?></button>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($overview['recent'])): ?>
                                    <div class="rt-assistant-operations__recent">
                                        <strong>Letzte Aktivitäten</strong>
                                        <ol class="rt-ai-assist__activity">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = array_slice($overview['recent'], 0, 3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <li><button type="button" class="rt-ai-assist__activity-item" wire:click="setTab('activity')"><span class="rt-ai-assist__activity-icon" data-tone="<?php echo e($item['tone']); ?>"><i class="far <?php echo e($item['icon']); ?>" aria-hidden="true"></i></span><span class="rt-ai-assist__activity-text"><strong><?php echo e($item['title']); ?></strong><small><?php echo e($item['detail']); ?></small></span><span class="rt-ai-assist__badge" data-tone="<?php echo e($item['tone']); ?>"><?php echo e($item['status']); ?></span></button></li>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </ol>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $messages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($message['role'] ?? '') === 'user'): ?>
                                <div class="rt-ai-assist__row rt-ai-assist__row--user" wire:key="ai-assist-message-<?php echo e($message['id']); ?>">
                                    <div class="rt-ai-assist__msg">
                                        <span><?php echo e($message['text']); ?></span>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $message['attachments'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $attachment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <span class="rt-ai-assist__attachment"><i class="far <?php echo e(!empty($attachment['audio']) ? 'fa-microphone' : 'fa-paperclip'); ?>" aria-hidden="true"></i> <?php echo e($attachment['name']); ?></span>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($message['time'])): ?><span class="rt-ai-assist__message-time"><?php echo e($message['time']); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                    $card = $message['card'] ?? null;
                                    $state = $card['state'] ?? null;
                                ?>
                                <div class="rt-ai-assist__row rt-ai-assist__row--answer" wire:key="ai-assist-message-<?php echo e($message['id']); ?>">
                                    <div class="rt-ai-assist__sender">
                                        <span class="rt-ai-assist__orb rt-ai-assist__orb--msg" data-assistant-cloud-slot="message" data-state="idle" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                                        <strong>AI-Assist</strong><span><?php echo e($message['time']); ?></span>
                                    </div>
                                    <div class="rt-ai-assist__stack">
                                        <div class="rt-ai-assist__msg"><?php echo $format($message['lead'] ?? ''); ?></div>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($card): ?>
                                            <?php if (isset($component)) { $__componentOriginal23227a01526384b75f162d724134c17c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal23227a01526384b75f162d724134c17c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.assist-response-card','data' => ['card' => $card,'cardId' => $panelId.'-response-'.$message['id']]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.assist-response-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['card' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($card),'card-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-response-'.$message['id'])]); ?>
                                                 <?php $__env->slot('actions', null, []); ?> 
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state === 'done'): ?>
                                                    <div class="rt-ai-assist__card-buttons rt-ai-assist__card-buttons--done">
                                                        <span><i class="far fa-check-circle" aria-hidden="true"></i>Übernommen · <?php echo e($card['done_at'] ?? ''); ?></span>
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($card['undo'])): ?>
                                                            <button type="button" class="rt-ai-assist__btn" x-on:click="call('act', <?php echo e($message['id']); ?>, 'undo')" :disabled="busy"><i class="far fa-undo" aria-hidden="true"></i>Rückgängig</button>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </div>
                                                <?php elseif(! $state && ! empty($card['buttons'])): ?>
                                                    <div class="rt-ai-assist__card-buttons">
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $card['buttons']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $button): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($button['href'])): ?>
                                                                <a class="rt-ai-assist__btn" href="<?php echo e($button['href']); ?>" data-primary="<?php echo e(! empty($button['primary']) ? 'true' : 'false'); ?>"><?php echo e($button['label']); ?></a>
                                                            <?php else: ?>
                                                                <button type="button" class="rt-ai-assist__btn" data-primary="<?php echo e(! empty($button['primary']) ? 'true' : 'false'); ?>" x-on:click="call('act', <?php echo e($message['id']); ?>, <?php echo \Illuminate\Support\Js::from($button['act'])->toHtml() ?>)" :disabled="busy"><?php echo e($button['label']); ?></button>
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </div>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                 <?php $__env->endSlot(); ?>
                                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal23227a01526384b75f162d724134c17c)): ?>
<?php $attributes = $__attributesOriginal23227a01526384b75f162d724134c17c; ?>
<?php unset($__attributesOriginal23227a01526384b75f162d724134c17c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal23227a01526384b75f162d724134c17c)): ?>
<?php $component = $__componentOriginal23227a01526384b75f162d724134c17c; ?>
<?php unset($__componentOriginal23227a01526384b75f162d724134c17c); ?>
<?php endif; ?>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <div class="rt-ai-assist__row" x-show="busy" style="display: none" role="status">
                            <span class="rt-ai-assist__orb rt-ai-assist__orb--msg" data-assistant-cloud-slot="message" data-state="thinking" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                            <div class="rt-ai-assist__msg rt-ai-assist__msg--typing"><span x-text="loaded ? 'Aktion wird bearbeitet' : 'Übersicht wird geladen'">Übersicht wird geladen</span><i></i><i></i><i></i></div>
                        </div>
                    </div>
                    <button type="button" class="rt-ai-assist__new-output" x-show="unseenOutput" style="display:none" x-on:click="jumpToLatest()">Neue Antworten <i class="far fa-arrow-down" aria-hidden="true"></i></button>
                <?php elseif($tab === 'actions'): ?>
                    <div class="rt-ai-assist__scroll">
                        <?php echo $__env->make('livewire.tools.partials.operations-automation-status', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['page' => 'Für '.$pageLabel, 'global' => 'Überall']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group => $heading): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($actions[$group] !== []): ?>
                                <p class="rt-ai-assist__kicker rt-ai-assist__kicker--block"><?php echo e($heading); ?></p>
                                <div class="rt-ai-assist__actions">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $actions[$group]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <button type="button" class="rt-ai-assist__action" x-on:click="run(<?php echo \Illuminate\Support\Js::from($action['key'])->toHtml() ?>)" :disabled="busy">
                                            <i class="far <?php echo e($action['icon']); ?>" aria-hidden="true"></i><span><strong><?php echo e($action['title']); ?></strong><small><?php echo e($action['detail']); ?></small></span><i class="far fa-arrow-right" aria-hidden="true"></i>
                                        </button>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if (isset($component)) { $__componentOriginalfb465a1dcc26784b1fc83101a59110ee = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalfb465a1dcc26784b1fc83101a59110ee = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.activity-toolbar','data' => ['value' => $activityFilter,'model' => 'activityFilter','dropdownId' => $panelId.'-activity-filter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.activity-toolbar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($activityFilter),'model' => 'activityFilter','dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelId.'-activity-filter')]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalfb465a1dcc26784b1fc83101a59110ee)): ?>
<?php $attributes = $__attributesOriginalfb465a1dcc26784b1fc83101a59110ee; ?>
<?php unset($__attributesOriginalfb465a1dcc26784b1fc83101a59110ee); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalfb465a1dcc26784b1fc83101a59110ee)): ?>
<?php $component = $__componentOriginalfb465a1dcc26784b1fc83101a59110ee; ?>
<?php unset($__componentOriginalfb465a1dcc26784b1fc83101a59110ee); ?>
<?php endif; ?>
                    <div class="rt-ai-assist__scroll">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activity->isEmpty()): ?>
                            <p class="rt-ai-assist__note">Noch keine Aktivitäten. Übernommene Vorschläge und AI-Eingänge erscheinen hier.</p>
                        <?php else: ?>
                            <?php
                                $zone = config('operations.display_timezone', 'Europe/Berlin');
                                $today = \Carbon\CarbonImmutable::now($zone)->toDateString();
                                $yesterday = \Carbon\CarbonImmutable::now($zone)->subDay()->toDateString();
                                $group = null;
                            ?>
                            <ol class="rt-ai-assist__activity">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $activity; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php
                                        $local = $item['at']->setTimezone($zone);
                                        $day = $local->toDateString() === $today ? 'Heute' : ($local->toDateString() === $yesterday ? 'Gestern' : $local->format('d.m.Y'));
                                    ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($day !== $group): ?>
                                        <?php
                                            $group = $day;
                                        ?>
                                        <li class="rt-ai-assist__activity-group"><?php echo e($day); ?></li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <li>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item['href']): ?><a class="rt-ai-assist__activity-item" href="<?php echo e($item['href']); ?>"><?php else: ?><div class="rt-ai-assist__activity-item"><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <span class="rt-ai-assist__activity-icon" data-tone="<?php echo e($item['tone']); ?>"><i class="far <?php echo e($item['icon']); ?>" aria-hidden="true"></i></span>
                                            <span class="rt-ai-assist__activity-text"><strong><?php echo e($item['title']); ?></strong><small><?php echo e($item['detail']); ?></small><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item['intake_id'] ?? null): ?><small>AI-Eingang #<?php echo e($item['intake_id']); ?></small><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                                            <span class="rt-ai-assist__activity-side"><span><?php echo e($local->format('H:i')); ?></span><span class="rt-ai-assist__badge" data-tone="<?php echo e($item['tone']); ?>"><?php echo e($item['status']); ?></span></span>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item['href']): ?></a><?php else: ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </ol>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="rt-ai-assist__composer" x-show="$wire.tab === 'chat'" x-cloak>
                <div class="rt-ai-assist__slash" role="listbox" aria-label="Aktionen" x-show="slash" style="display: none">
                    <template x-for="(action, index) in slashList" :key="action.key">
                        <button type="button" role="option" class="rt-ai-assist__slash-item" :aria-selected="index === slashIndex ? 'true' : 'false'" x-on:click="run(action.key)">
                            <i class="far" :class="action.icon" aria-hidden="true"></i><span x-text="action.title"></span>
                        </button>
                    </template>
                    <p class="rt-ai-assist__slash-empty" x-show="slashList.length === 0">Keine passende Aktion.</p>
                </div>
                <?php echo $__env->make('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'operations-chat'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginaldd549be980c60c890cd21eb6d78ce68d)): ?>
<?php $attributes = $__attributesOriginaldd549be980c60c890cd21eb6d78ce68d; ?>
<?php unset($__attributesOriginaldd549be980c60c890cd21eb6d78ce68d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginaldd549be980c60c890cd21eb6d78ce68d)): ?>
<?php $component = $__componentOriginaldd549be980c60c890cd21eb6d78ce68d; ?>
<?php unset($__componentOriginaldd549be980c60c890cd21eb6d78ce68d); ?>
<?php endif; ?>
    </div>
</div>
</template>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/ai-assist.blade.php ENDPATH**/ ?>