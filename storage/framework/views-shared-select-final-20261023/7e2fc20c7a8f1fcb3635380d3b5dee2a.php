<?php
    $composerMode = $composerMode ?? 'assistant';
    $assistantComposer = in_array($composerMode, ['assistant', 'operations-chat'], true);
    $operationsChatComposer = $composerMode === 'operations-chat';
    $composerField = $assistantComposer ? 'prompt' : 'text';
    $composerAction = $operationsChatComposer ? 'submitChat' : ($assistantComposer ? 'submitPrompt' : 'submit');
    $composerId = 'intake-composer-'.$this->getId();
    $composerLimit = $maxCharacters ?? ($operationsChatComposer ? 500 : ($assistantComposer ? 2000 : 20000));
    $fileCount = count($uploads ?? []);
    $composerMaximum = max(1, min(3, (int) ($maxAttachmentCount ?? ($limits['max_attachment_count'] ?? 3))));
?>
<form class="rt-intake-composer grid min-w-0 gap-2"
    x-data="aiIntakeComposer($wire, <?php echo \Illuminate\Support\Js::from(['mode' => $composerMode, 'field' => $composerField, 'action' => $composerAction, 'componentId' => $this->getId(), 'maxFiles' => $composerMaximum])->toHtml() ?>)"
    x-effect="syncContext()" x-on:submit.prevent.stop="submit()"
    x-on:ai-intake-composer-submitted.window="submitted($event.detail.componentId)"
    x-on:ai-intake-submitted.window="submitted(<?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>)"
    x-on:ai-intake-composer-audio-removed.window="if ($event.detail.componentId === <?php echo \Illuminate\Support\Js::from($this->getId())->toHtml() ?>) clearAudioPreview()">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$assistantComposer): ?>
        <label class="sr-only" for="<?php echo e($composerId); ?>-contact">Antwortadresse</label>
        <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => ''.e($composerId).'-contact','wire:model' => 'contactEmail','type' => 'email','placeholder' => 'Antwortadresse (optional)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => ''.e($composerId).'-contact','wire:model' => 'contactEmail','type' => 'email','placeholder' => 'Antwortadresse (optional)']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
        <?php if (isset($component)) { $__componentOriginalad6e120586abfa0dc479588fa10434b3 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalad6e120586abfa0dc479588fa10434b3 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input-error','data' => ['for' => 'contactEmail']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'contactEmail']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalad6e120586abfa0dc479588fa10434b3)): ?>
<?php $attributes = $__attributesOriginalad6e120586abfa0dc479588fa10434b3; ?>
<?php unset($__attributesOriginalad6e120586abfa0dc479588fa10434b3); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalad6e120586abfa0dc479588fa10434b3)): ?>
<?php $component = $__componentOriginalad6e120586abfa0dc479588fa10434b3; ?>
<?php unset($__componentOriginalad6e120586abfa0dc479588fa10434b3); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($fileCount): ?>
        <div class="rt-intake-composer__files flex min-w-0 flex-wrap gap-1.5" aria-label="Ausgewählte Dateien">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $uploads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $upload): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($upload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile): ?>
                    <span class="flex min-w-0 max-w-full items-center gap-1 rounded-lg border border-rt-border px-2 py-1 text-xs" wire:key="<?php echo e($composerId); ?>-file-<?php echo e($upload->getFilename()); ?>">
                        <i class="far fa-paperclip" aria-hidden="true"></i><span class="truncate"><?php echo e($upload->getClientOriginalName()); ?></span>
                        <button type="button" class="shrink-0 p-1" x-on:click="removeFile(<?php echo e($index); ?>)" x-bind:disabled="busy || uploadActive" aria-label="Datei entfernen: <?php echo e($upload->getClientOriginalName()); ?>"><i class="far fa-times" aria-hidden="true"></i></button>
                    </span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <template x-for="(file, index) in filePreviews" :key="index"><div class="min-w-0"><img x-show="file.kind === 'image'" x-bind:src="file.kind === 'image' ? file.url : null" x-bind:alt="file.name" class="max-h-24 max-w-full rounded-lg object-contain" /><audio x-show="file.kind === 'audio'" x-bind:src="file.kind === 'audio' ? file.url : null" controls preload="metadata" class="h-9 w-full" x-bind:aria-label="'Audiodatei vorhören: ' + file.name"></audio></div></template>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($audioUpload): ?>
        <div class="rt-intake-composer__audio grid min-w-0 gap-1">
            <div class="flex items-center gap-2 text-xs"><i class="far fa-microphone" aria-hidden="true"></i><span class="min-w-0 flex-1 truncate"><?php echo e($audioUpload->getClientOriginalName()); ?></span><button type="button" class="p-1" x-on:click="removeAudio()" x-bind:disabled="busy" aria-label="Sprachaufnahme entfernen"><i class="far fa-times" aria-hidden="true"></i></button></div>
            <audio x-show="audioPreview" x-bind:src="audioPreview || null" controls preload="metadata" class="h-9 w-full" aria-label="Sprachaufnahme vorhören"></audio>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div x-cloak x-show="recording || requesting" class="flex flex-wrap items-center gap-2 text-xs" role="status" aria-live="polite">
        <span x-show="requesting">Mikrofonfreigabe ausstehend …</span><span x-show="recording">Aufnahme · <span x-text="seconds"></span> s</span>
        <button type="button" x-show="recording" x-on:click="stop()" class="rounded-lg border border-rt-border px-2 py-1">Aufnahme übernehmen</button>
        <button type="button" x-show="recording || requesting" x-on:click="cancelRecording()" class="rounded-lg border border-rt-border px-2 py-1">Abbrechen</button>
    </div>
    <?php if (isset($component)) { $__componentOriginal4eac748cdfe4352a577e5193b1c94fdd = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4eac748cdfe4352a577e5193b1c94fdd = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.floating.composer-row','data' => ['controlClass' => 'rt-intake-composer__shell border border-rt-border bg-rt-surface']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.floating.composer-row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['control-class' => 'rt-intake-composer__shell border border-rt-border bg-rt-surface']); ?>
         <?php $__env->slot('leading', null, []); ?> 
        <input id="<?php echo e($composerId); ?>-files" class="sr-only" type="file" multiple tabindex="-1" x-ref="files" wire:model="uploads"
            accept=".txt,.md,.csv,.json,.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.pptx,.wav,.mp3,.ogg,.webm,.m4a,.mp4,.aac,.flac"
            x-on:change="filesSelected($event)" x-on:livewire-upload-start="beginUpload()" x-on:livewire-upload-progress="progressUpload($event.detail)"
            x-on:livewire-upload-finish="finishUpload()" x-on:livewire-upload-error="failUpload()" x-on:livewire-upload-cancel="cancelUpload()" />
        <button type="button" class="rt-floating-composer-attachment rt-intake-composer__tool shrink-0 rounded-full p-2" x-on:click="chooseFiles()" x-bind:disabled="busy || uploadActive || recording || requesting || attachmentCount() >= <?php echo e($composerMaximum); ?>" title="Dateien anhängen" aria-label="Dateien anhängen"><i class="far fa-plus" aria-hidden="true"></i></button>
         <?php $__env->endSlot(); ?>
        <label class="sr-only" for="<?php echo e($composerId); ?>-prompt"><?php echo e($operationsChatComposer ? 'Nachricht an AI-Assist' : ($assistantComposer ? 'Frage oder Prüfhinweis' : 'Anfrage beschreiben')); ?></label>
        <textarea id="<?php echo e($composerId); ?>-prompt" class="rt-intake-composer__input min-h-10 min-w-0 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm focus:ring-0"
            rows="1" maxlength="<?php echo e($composerLimit); ?>" wire:model="<?php echo e($composerField); ?>" x-ref="prompt"
            x-on:input="updateInput(); <?php if($operationsChatComposer): ?> $dispatch('ai-assist-composer-input', { value: $el.value }) <?php endif; ?>"
            x-on:keydown.enter="enter($event)"
            x-bind:disabled="busy" placeholder="<?php echo e($operationsChatComposer ? 'Frage stellen oder / für Aktionen' : ($assistantComposer ? 'Frage oder Hinweis …' : 'Anfrage beschreiben …')); ?>"
            <?php if($operationsChatComposer): ?> aria-label="Nachricht an AI-Assist" <?php endif; ?>
            <?php if(!$assistantComposer): ?> aria-describedby="intake-assistant-help-<?php echo e($this->getId()); ?>" <?php endif; ?>></textarea>
        <button type="button" class="rt-intake-composer__tool shrink-0 rounded-full p-2" x-on:click="toggleRecording()" x-bind:disabled="busy || uploadActive || uploading || requesting || !!$wire.audioUpload || attachmentCount() >= <?php echo e($composerMaximum); ?>" x-bind:aria-pressed="recording.toString()" title="Sprache aufnehmen" aria-label="Sprache aufnehmen"><i class="far fa-microphone" aria-hidden="true"></i></button>
         <?php $__env->slot('actions', null, []); ?> 
        <button type="submit" x-cloak x-show="hasText || attachmentCount() > 0 || busy" class="rt-floating-composer-send rt-intake-composer__send shrink-0 rounded-full bg-rt-red p-2 text-white" x-bind:disabled="!canSubmit()" aria-label="<?php echo e($operationsChatComposer ? 'Nachricht an AI-Assist senden' : ($assistantComposer ? 'Frage oder Prüfhinweis senden' : 'Als AI-Eingang erfassen')); ?>"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
         <?php $__env->endSlot(); ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4eac748cdfe4352a577e5193b1c94fdd)): ?>
<?php $attributes = $__attributesOriginal4eac748cdfe4352a577e5193b1c94fdd; ?>
<?php unset($__attributesOriginal4eac748cdfe4352a577e5193b1c94fdd); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4eac748cdfe4352a577e5193b1c94fdd)): ?>
<?php $component = $__componentOriginal4eac748cdfe4352a577e5193b1c94fdd; ?>
<?php unset($__componentOriginal4eac748cdfe4352a577e5193b1c94fdd); ?>
<?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$assistantComposer): ?><p id="intake-assistant-help-<?php echo e($this->getId()); ?>" class="text-xs ops-muted" title="Der Eingang wird zur persönlichen Prüfung erfasst; Umschalt+Enter fügt einen Absatz hinzu.">Unverbindlich zur Prüfung erfassen.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [$composerField, 'uploads', 'uploads.*', 'audioUpload']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $errorField): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = [$errorField];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="rt-ai-assist__error text-sm text-rt-red" role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($operationsChatComposer): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assistant'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="rt-ai-assist__error text-sm text-rt-red" role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <p x-cloak x-show="uploadError || error" x-text="uploadError || error" class="text-sm text-rt-red" role="alert"></p>
    <p x-cloak x-show="busy" class="text-xs ops-muted" role="status" aria-live="polite"><?php echo e($operationsChatComposer ? 'AI-Assist wird aktualisiert …' : ($assistantComposer ? 'Die AI prüft diesen Eingang …' : 'Eingang wird gespeichert …')); ?></p>
</form>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/partials/ai-intake-composer.blade.php ENDPATH**/ ?>