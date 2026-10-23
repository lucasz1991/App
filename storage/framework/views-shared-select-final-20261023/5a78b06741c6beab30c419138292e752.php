<section data-employee-location-status x-data x-on:worktime-synced.window.debounce.400ms="$wire.refreshStatus()" x-on:railtime-app-location-updated.window.debounce.400ms="$wire.refreshStatus()" <?php if($locationStatus['enabled']): ?> wire:poll.60s="refreshStatus" <?php endif; ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($locationStatus['enabled']): ?>
        <?php
            $installation = $locationStatus['installation'];
            $consent = $locationStatus['consent'];
            $labels = ['unpaired' => 'Geschäftshandy verbinden', 'consent_missing' => 'Freigabe für diese Anmeldesitzung fehlt', 'not_working' => 'Keine laufende Arbeitszeit', 'paused' => 'Erfassung pausiert', 'start_required' => 'Standortdienst starten', 'offline' => 'Erfassung unterbrochen / Gerät offline', 'inaccurate' => 'Messung ungenau', 'unavailable' => 'Standortmessung derzeit nicht verfügbar', 'active' => 'Standortdienst aktiv'];
            $showExplanation = $explanationOpen || $locationStatus['needs_decision'];
        ?>
        <div class="ops-panel ops-stack" aria-labelledby="employee-location-heading">
            <header class="ops-toolbar"><div><h3 id="employee-location-heading">App-Standort</h3><p class="ops-muted" role="status"><?php echo e(!$browserLocationStatus['consented'] ? 'Freigabe für diese Anmeldesitzung fehlt' : ($browserLocationStatus['last_point_at'] ? 'Letzter Punkt aus der App-Nutzung verfügbar' : 'Noch kein präziser App-Standort empfangen')); ?></p></div><span class="ops-badge">Letzter Punkt · höchstens 15 Minuten aktuell</span></header>
            <p class="ops-muted">Mit deiner Freigabe wird bei der aktiven Nutzung der sichtbaren RailTime-App der letzte präzise Standort ersetzt, höchstens einmal pro Minute. Das funktioniert unabhängig von deiner Zeiterfassung und ohne zusätzlichen Standortdienst. Schichtplanung und Anreisehinweise dürfen nur einen höchstens 15 Minuten alten Punkt verwenden.</p>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($browserLocationStatus['last_point_at']): ?>
                <p><strong>Zuletzt gemeldet:</strong> <?php echo e(\Carbon\CarbonImmutable::parse($browserLocationStatus['last_point_at'])->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s')); ?> · Genauigkeit <?php echo e(number_format($browserLocationStatus['accuracy_m'], 0, ',', '.')); ?> m</p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <p class="ops-muted">Bei geschlossenem oder gesperrtem Handy pausiert die Web-App. Beim nächsten Benutzen kann sie wieder einen aktuellen Punkt melden. Es gibt keinen App-Standortverlauf; abgelaufene Punkte sind sofort ausgeschlossen und werden spätestens im stündlichen Löschlauf entfernt.</p>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showExplanation): ?>
                <div class="ops-stack" role="group" aria-labelledby="employee-location-consent-heading">
                    <h4 id="employee-location-consent-heading">Deine Entscheidung für diese Anmeldesitzung</h4>
                    <p style="white-space:pre-line"><?php echo e($locationStatus['settings']['explanation_text']); ?></p>
                    <p class="ops-muted">Die Freigabe gilt, solange deine Anmeldesitzung aktiv bleibt. Die Nutzung der App verlängert sie; eine neue tägliche Zustimmung ist nicht nötig. Abmeldung, Sitzungsablauf oder Widerruf beenden die Freigabe. Eine Ablehnung hat keine Nachteile für deine Zeiterfassung. Bitte entscheide nur, wenn du sicher stehen kannst.</p>
                    <div class="ops-actions">
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'accept','wire:loading.attr' => 'disabled','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'accept','wire:loading.attr' => 'disabled','class' => 'min-h-11']); ?>Für diese Sitzung erlauben <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'decline','wire:loading.attr' => 'disabled','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'decline','wire:loading.attr' => 'disabled','class' => 'min-h-11']); ?>Ohne Standort fortfahren <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    </div>
                    <p class="ops-muted">Die Standortberechtigung deines Browsers wird zusätzlich benötigt. Du kannst sie unter „Mein Profil → Einstellungen → Push und Standort“ einrichten.</p>
                </div>
            <?php else: ?>
                <div class="ops-actions"><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'openExplanation','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'openExplanation','class' => 'min-h-11']); ?>Erklärung und Freigabe öffnen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><a class="ops-link min-h-11 inline-flex items-center" href="<?php echo e(route('profile.show', ['tab' => 'app'])); ?>">Push und Standort im eigenen Profil</a></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['location'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p role="alert" class="ops-warning"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($browserLocationStatus['consented'] || ($consent && $consent->decision === 'accepted' && !$consent->withdrawn_at)): ?>
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'withdraw','wire:loading.attr' => 'disabled','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'withdraw','wire:loading.attr' => 'disabled','class' => 'min-h-11']); ?>Freigabe widerrufen und zugehörige Punkte löschen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeConfigured): ?>
            <details>
                <summary class="min-h-11 cursor-pointer">Optionaler Arbeitsstandortdienst</summary>
                <div class="ops-stack mt-3">
                    <p class="ops-muted">Zusätzlich eingerichtete native Arbeitsstandorte: alle 15 Minuten während laufender Arbeitszeit, sieben Tage Aufbewahrung. Für den App-Standort oben ist diese Einrichtung nicht erforderlich.</p>
                    <p class="ops-muted" role="status"><?php echo e($labels[$locationStatus['state']] ?? 'Erfassung unterbrochen'); ?></p>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($installation): ?>
                <p><strong>Geschäftshandy:</strong> <?php echo e($installation->assignment?->device?->display_name ?: $installation->assignment?->device?->asset_tag); ?> · <?php echo e($installation->platform === 'ios' ? 'iPhone' : 'Android'); ?></p>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($locationStatus['last_point']): ?>
                    <p class="ops-muted">Letzter gespeicherter Punkt: <?php echo e($locationStatus['last_point']->measured_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s')); ?> · Genauigkeit <?php echo e(number_format($locationStatus['last_point']->accuracy_m, 0, ',', '.')); ?> m</p>
                <?php else: ?><p class="ops-muted">Noch kein Punkt gespeichert.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($locationStatus['state'] === 'active' && $installation->next_sample_at): ?><p class="ops-muted">Nächste Meldung ungefähr <?php echo e($installation->next_sample_at->setTimezone('Europe/Berlin')->format('H:i')); ?> Uhr. Ausfälle werden nicht nachträglich aufgefüllt.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($locationStatus['state'], ['start_required', 'offline', 'inaccurate', 'unavailable', 'active']) && $locationStatus['running']): ?>
                    <div class="ops-actions">
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'prepareHandoff','wire:loading.attr' => 'disabled','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'prepareHandoff','wire:loading.attr' => 'disabled','class' => 'min-h-11']); ?>Standortdienst starten / fortsetzen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($handoffCode && $handoffExpiresAt && now()->lt($handoffExpiresAt)): ?>
                        <?php
                            $handoffExpiry = \Carbon\CarbonImmutable::parse($handoffExpiresAt);
                            $handoffRemainingMs = max(0, (int) now()->diffInMilliseconds($handoffExpiry, false));
                        ?>
                        <div class="ops-stack" wire:key="location-handoff-<?php echo e(hash('sha256', $handoffCode)); ?>" x-data="{ usable: true }" x-init="setTimeout(() => { usable = false; $wire.refreshStatus() }, <?php echo e($handoffRemainingMs); ?>)">
                            <div class="ops-stack" x-show="usable">
                                <a class="ops-link min-h-11 inline-flex items-center" href="<?php echo e($handoffUrl); ?>" rel="noreferrer">Jetzt im Standortdienst öffnen</a>
                                <label for="employee-location-start-code">Einmaliger Startcode · gültig bis <?php echo e($handoffExpiry->setTimezone('Europe/Berlin')->format('H:i:s')); ?> Uhr</label>
                                <input id="employee-location-start-code" type="text" readonly value="<?php echo e($handoffCode); ?>" autocomplete="off" spellcheck="false" class="min-h-11 w-full" />
                                <p class="ops-muted">Falls die App-Verknüpfung noch nicht funktioniert: Öffne den Standortdienst selbst und kopiere diesen Code in „Startcode“. Er gilt höchstens 60 Sekunden und nur einmal für dein verbundenes Geschäftshandy.</p>
                            </div>
                            <p class="ops-muted" x-show="!usable" x-cloak>Startcode abgelaufen. Bereite den Start erneut vor.</p>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <details>
                <summary class="min-h-11 cursor-pointer">Geschäftshandy einrichten oder wechseln</summary>
                <div class="ops-stack mt-3">
                    <p>Installiere den betrieblich bereitgestellten Android- oder iPhone-Standortdienst. Gib dort die RailTime-HTTPS-Adresse und den einmaligen Verbindungscode ein. Ein neues Gerät beendet die bisherige Standortinstallation.</p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['android' => 'Android-Standortdienst', 'ios' => 'iPhone-Standortdienst']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $platform => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($locationStatus['settings'][$platform.'_install_url']): ?><a class="ops-link min-h-11 inline-flex items-center" href="<?php echo e($locationStatus['settings'][$platform.'_install_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($label); ?> installieren</a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <label for="employee-location-device">Zugeordnetes Geschäftshandy</label>
                    <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'employee-location-device','wire:model' => 'assignmentId','ariaLabel' => 'Zugeordnetes Geschäftshandy','class' => 'min-h-11 w-full']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'employee-location-device','wire:model' => 'assignmentId','aria-label' => 'Zugeordnetes Geschäftshandy','class' => 'min-h-11 w-full']); ?><option value="">Auswählen</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $locationStatus['assignments']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $assignment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($assignment->id); ?>"><?php echo e($assignment->device->display_name ?: $assignment->device->asset_tag); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assignmentId'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'createRegistration','wire:loading.attr' => 'disabled','class' => 'min-h-11']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'createRegistration','wire:loading.attr' => 'disabled','class' => 'min-h-11']); ?>Einmaligen Verbindungscode erstellen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($registrationCode && $registrationExpiresAt && now()->lt($registrationExpiresAt)): ?><label for="employee-location-code">Verbindungscode · 15 Minuten gültig</label><input id="employee-location-code" type="text" readonly value="<?php echo e($registrationCode); ?>" autocomplete="off" spellcheck="false" class="min-h-11 w-full" /><p class="ops-muted">Der Code ist nur für die Standortinstallation bestimmt. Gib ihn nicht an andere weiter.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </details>
                </div>
            </details>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($locationStatus['last_point'] || $historyOpen): ?>
            <?php echo $__env->make('livewire.operations.partials.employee-location-history', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php elseif($locationStatus['last_point']): ?>
        <div class="ops-panel ops-stack" aria-labelledby="employee-location-history-heading">
            <h3 id="employee-location-history-heading">Mein Standortverlauf</h3>
            <p class="ops-muted">Die Standorterfassung ist ausgeschaltet. Deine noch gespeicherten Punkte kannst du weiterhin ansehen und löschen; sie verfallen spätestens nach sieben Tagen.</p>
            <?php echo $__env->make('livewire.operations.partials.employee-location-history', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/employee-location-status.blade.php ENDPATH**/ ?>