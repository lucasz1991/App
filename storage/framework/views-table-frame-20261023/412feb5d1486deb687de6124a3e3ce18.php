<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'model',
    'mode' => 'multi',
    'label' => null,
    'acceptedFiles' => null,
    'maxFiles' => null,
    'maxFilesize' => null,
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
    'model',
    'mode' => 'multi',
    'label' => null,
    'acceptedFiles' => null,
    'maxFiles' => null,
    'maxFilesize' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $label = $label ?? __('app.drop_files_here');
    $isSingle = in_array($mode, ['single', true, 1, '1'], true);
    $dropzoneMaxFiles = $isSingle ? 1 : ($maxFiles ?? 20);
    $dropzoneMaxFilesize = $maxFilesize ?? 50;
    $dropzoneAcceptedFiles = $acceptedFiles;
    $isGerman = app()->getLocale() === 'de';
    $labels = [
        'removeFile' => $isGerman ? 'Datei entfernen' : 'Remove file',
        'tooMany' => $isGerman
            ? "Maximal {$dropzoneMaxFiles} Dateien sind möglich."
            : "A maximum of {$dropzoneMaxFiles} files is allowed.",
        'tooLarge' => $isGerman
            ? "Die Datei ist größer als {$dropzoneMaxFilesize} MB."
            : "The file is larger than {$dropzoneMaxFilesize} MB.",
        'invalidType' => $isGerman ? 'Dieser Dateityp ist nicht erlaubt.' : 'This file type is not allowed.',
        'uploadFailed' => $isGerman ? 'Upload fehlgeschlagen.' : 'Upload failed.',
        'cancelUpload' => $isGerman ? 'Upload abbrechen' : 'Cancel upload',
        'uploadCanceled' => $isGerman ? 'Upload abgebrochen.' : 'Upload canceled.',
        'preparing' => $isGerman ? 'Wird vorbereitet' : 'Preparing',
        'uploading' => $isGerman ? 'Wird übertragen' : 'Uploading',
        'ready' => $isGerman ? 'Bereit zum Speichern' : 'Ready to save',
    ];
?>

<div
    x-data="filePoolDropzone({
        model: <?php echo \Illuminate\Support\Js::from($model)->toHtml() ?>,
        single: <?php echo \Illuminate\Support\Js::from($isSingle)->toHtml() ?>,
        maxFiles: <?php echo \Illuminate\Support\Js::from($dropzoneMaxFiles)->toHtml() ?>,
        maxFilesize: <?php echo \Illuminate\Support\Js::from($dropzoneMaxFilesize)->toHtml() ?>,
        acceptedFiles: <?php echo \Illuminate\Support\Js::from($dropzoneAcceptedFiles)->toHtml() ?>,
        labels: <?php echo \Illuminate\Support\Js::from($labels)->toHtml() ?>,
    })"
    class="rt-filepool-dropzone-shell"
    data-filepool-dropzone
    data-filepool-model="<?php echo e($model); ?>"
    data-filepool-mode="<?php echo e($isSingle ? 'single' : 'multi'); ?>"
>
    <div
        x-ref="dzForm"
        class="rt-filepool-dropzone rt-ui-surface-muted dropzone pointer-events-auto rounded-[1.35rem] border-2 border-dashed border-rt-border bg-rt-surface-muted transition-[border-color,background-color,box-shadow,transform] duration-300 ease-rt-spring hover:border-rt-red/60 hover:bg-rt-red/[0.03] focus-within:border-rt-red/70 focus-within:ring-4 focus-within:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:hover:border-rt-red/60 dark:hover:bg-rt-red/[0.06]"
        wire:ignore
    >
        <button type="button" class="dz-message needsclick" aria-label="<?php echo e($label); ?>">
            <span class="rt-filepool-dropzone__icon" aria-hidden="true">
                <i class="fad fa-cloud-upload-alt"></i>
            </span>
            <span class="rt-filepool-dropzone__title"><?php echo e($label); ?></span>
            <span class="rt-filepool-dropzone__hint">
                <?php echo e($isGerman ? 'Aus Explorer oder Finder hineinziehen oder zum Auswählen klicken' : 'Drag files here from Explorer or Finder, or click to browse'); ?>

            </span>
            <span class="rt-filepool-dropzone__limits" aria-label="<?php echo e($dropzoneMaxFiles); ?> <?php echo e(__('app.files')); ?>, <?php echo e($dropzoneMaxFilesize); ?> MB <?php echo e(__('app.file')); ?>">
                <span><i class="far fa-layer-group" aria-hidden="true"></i> <?php echo e($dropzoneMaxFiles); ?> <?php echo e(__('app.files')); ?></span>
                <span><i class="far fa-weight-hanging" aria-hidden="true"></i> <?php echo e($dropzoneMaxFilesize); ?> MB / <?php echo e(__('app.file')); ?></span>
            </span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isSingle): ?>
                <span class="rt-filepool-dropzone__single"><?php echo e(__('app.max_one_file')); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>

        <div class="dz-previews" aria-live="polite" aria-relevant="additions removals"></div>
    </div>

    <input
        x-ref="fileInput"
        type="file"
        <?php if (! ($isSingle)): ?> multiple <?php endif; ?>
        class="sr-only"
        <?php if($acceptedFiles): ?> accept="<?php echo e($acceptedFiles); ?>" <?php endif; ?>
        wire:model="<?php echo e($model); ?>"
        tabindex="-1"
        aria-hidden="true"
    >

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = [$model];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
        <p class="mt-2 flex items-start gap-2 text-sm font-medium text-red-600 dark:text-red-400" role="alert">
            <i class="far fa-exclamation-circle mt-1 shrink-0" aria-hidden="true"></i>
            <span><?php echo e($message); ?></span>
        </p>
    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/filepool/drop-zone.blade.php ENDPATH**/ ?>