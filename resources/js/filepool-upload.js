const INTERNAL_FILE_MIME = 'application/x-railtime-file';
const FILES_TRANSFER_TYPE = 'Files';
const DEFAULT_MAX_FILES = 20;
const DEFAULT_MAX_FILE_SIZE_MB = 50;
const DEFAULT_MAX_FILE_SIZE_BYTES = DEFAULT_MAX_FILE_SIZE_MB * 1024 * 1024;

const FILE_KIND_RULES = [
    { kind: 'image', icon: 'fa-file-image', extensions: ['avif', 'bmp', 'gif', 'heic', 'jpeg', 'jpg', 'png', 'svg', 'webp'] },
    { kind: 'video', icon: 'fa-file-video', extensions: ['avi', 'm4v', 'mkv', 'mov', 'mp4', 'webm', 'wmv'] },
    { kind: 'audio', icon: 'fa-file-audio', extensions: ['aac', 'flac', 'm4a', 'mp3', 'ogg', 'wav', 'wma'] },
    { kind: 'pdf', icon: 'fa-file-pdf', extensions: ['pdf'] },
    { kind: 'spreadsheet', icon: 'fa-file-excel', extensions: ['csv', 'ods', 'xls', 'xlsm', 'xlsx'] },
    { kind: 'document', icon: 'fa-file-word', extensions: ['doc', 'docx', 'odt', 'rtf'] },
    { kind: 'presentation', icon: 'fa-file-powerpoint', extensions: ['odp', 'ppt', 'pptx'] },
    { kind: 'archive', icon: 'fa-file-archive', extensions: ['7z', 'gz', 'rar', 'tar', 'zip'] },
    { kind: 'code', icon: 'fa-file-code', extensions: ['css', 'html', 'js', 'json', 'md', 'php', 'sql', 'ts', 'txt', 'xml', 'yaml', 'yml'] },
];

export function uploadFilePresentation(file) {
    const filename = String(file?.name || '');
    const extension = filename.includes('.') ? filename.split('.').pop().toLowerCase() : '';
    const mimeGroup = String(file?.type || '').split('/')[0].toLowerCase();
    const matched = FILE_KIND_RULES.find(({ kind, extensions }) => (
        extensions.includes(extension) || ['image', 'video', 'audio'].includes(kind) && mimeGroup === kind
    ));

    return {
        extension: extension ? extension.toUpperCase() : 'DATEI',
        kind: matched?.kind || 'generic',
        icon: matched?.icon || 'fa-file-alt',
    };
}

export function formatUploadFileSize(bytes, locale = 'de-DE') {
    const size = Math.max(0, Number(bytes) || 0);
    if (size < 1024) {
        return `${size} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = size / 1024;
    let unit = units[0];

    for (let index = 1; index < units.length && value >= 1024; index += 1) {
        value /= 1024;
        unit = units[index];
    }

    return `${new Intl.NumberFormat(locale || 'de-DE', { maximumFractionDigits: value >= 10 ? 1 : 2 }).format(value)} ${unit}`;
}

export function uploadPreviewTemplate() {
    return `
        <article class="dz-preview rt-filepool-upload-file" data-file-kind="generic">
            <div class="rt-filepool-upload-file__visual" aria-hidden="true">
                <img class="rt-filepool-upload-file__thumbnail" data-dz-thumbnail alt="">
                <span class="rt-filepool-upload-file__type-icon">
                    <i class="fad fa-file-alt" data-upload-type-icon></i>
                </span>
                <span class="rt-filepool-upload-file__extension" data-upload-extension>DATEI</span>
            </div>
            <div class="rt-filepool-upload-file__body">
                <div class="rt-filepool-upload-file__heading">
                    <div class="rt-filepool-upload-file__identity">
                        <span class="rt-filepool-upload-file__name" data-dz-name></span>
                        <span class="rt-filepool-upload-file__meta">
                            <span data-upload-file-size></span>
                            <span aria-hidden="true">&bull;</span>
                            <span data-upload-file-type>DATEI</span>
                        </span>
                    </div>
                    <button type="button" class="dz-remove rt-filepool-upload-file__remove" data-dz-remove>
                        <i class="far fa-trash-alt" aria-hidden="true"></i>
                        <span class="sr-only" data-upload-remove-label>Datei entfernen</span>
                    </button>
                </div>
                <div class="rt-filepool-upload-file__state">
                    <span class="rt-filepool-upload-file__status">
                        <i class="far fa-clock" data-upload-status-icon aria-hidden="true"></i>
                        <span data-upload-status>Wird vorbereitet</span>
                    </span>
                    <span class="rt-filepool-upload-file__percent" data-upload-progress-value>0 %</span>
                </div>
                <div class="dz-progress rt-filepool-upload-file__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span class="dz-upload" data-dz-uploadprogress></span>
                </div>
                <div class="dz-error-message rt-filepool-upload-file__error" role="alert">
                    <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                    <span data-dz-errormessage></span>
                </div>
            </div>
        </article>
    `.trim();
}

function transferTypes(dataTransfer) {
    if (!dataTransfer?.types) {
        return [];
    }

    return Array.from(dataTransfer.types);
}

export function isExternalFileTransfer(dataTransfer) {
    const types = transferTypes(dataTransfer);

    return types.includes(FILES_TRANSFER_TYPE) && !types.includes(INTERNAL_FILE_MIME);
}

export function fileSignature(file) {
    return [file?.name || '', file?.size || 0, file?.lastModified || 0].join(':');
}

/**
 * Browser liefern Verzeichnisse im DataTransfer ebenfalls als "file"-Item.
 * Sie werden hier bewusst nicht traversiert: Der FilePool besitzt seine eigene
 * Ordner- und Rechte-Struktur und darf nie unbemerkt einen lokalen Baum anlegen.
 */
export function filesFromTransfer(dataTransfer) {
    const files = [];
    const directories = [];
    const items = Array.from(dataTransfer?.items || []);

    if (items.length > 0) {
        items.forEach((item) => {
            if (item?.kind !== 'file') {
                return;
            }

            const entry = typeof item.webkitGetAsEntry === 'function'
                ? item.webkitGetAsEntry()
                : null;

            if (entry?.isDirectory) {
                directories.push(entry.name || 'Ordner');
                return;
            }

            const file = typeof item.getAsFile === 'function' ? item.getAsFile() : null;
            if (file) {
                files.push(file);
            }
        });
    } else {
        files.push(...Array.from(dataTransfer?.files || []));
    }

    const uniqueFiles = [];
    const signatures = new Set();
    files.forEach((file) => {
        const signature = fileSignature(file);
        if (!signatures.has(signature)) {
            signatures.add(signature);
            uniqueFiles.push(file);
        }
    });

    return { files: uniqueFiles, directories };
}

export function validateUploadFiles(
    files,
    {
        maxFiles = DEFAULT_MAX_FILES,
        maxFileSizeBytes = DEFAULT_MAX_FILE_SIZE_BYTES,
        existingFiles = [],
    } = {},
) {
    const accepted = [];
    const rejected = [];
    const signatures = new Set(existingFiles.map(fileSignature));
    let availableSlots = Math.max(0, maxFiles - existingFiles.length);

    Array.from(files || []).forEach((file) => {
        const signature = fileSignature(file);

        if (signatures.has(signature)) {
            rejected.push({ file, reason: 'duplicate' });
            return;
        }

        signatures.add(signature);

        if (Number(file?.size || 0) > maxFileSizeBytes) {
            rejected.push({ file, reason: 'too-large' });
            return;
        }

        if (availableSlots <= 0) {
            rejected.push({ file, reason: 'too-many' });
            return;
        }

        availableSlots -= 1;
        accepted.push(file);
    });

    return { accepted, rejected };
}

function targetIsInsideUploadModal(target) {
    return typeof Element !== 'undefined'
        && target instanceof Element
        && target.closest('[data-filepool-upload-modal]') !== null;
}

function emitToast(type, text) {
    if (!text) {
        return;
    }

    window.dispatchEvent(new CustomEvent('swal:toast', {
        detail: { type, text },
    }));
}

/**
 * Viewportweiter OS-Drop-Controller. Interne FilePool-Verschiebungen besitzen
 * ihren eigenen MIME-Type und gelangen dadurch niemals in diesen Pfad.
 */
export function filePoolExternalDrop(config = {}) {
    return {
        writable: config.writable === true,
        model: config.model || '',
        targetPath: config.targetPath || '',
        openFileForm: config.openFileForm ?? false,
        dragDepth: 0,
        externalDragActive: false,
        openingUpload: false,
        listeners: null,

        init() {
            this.listeners = new AbortController();
            const options = { signal: this.listeners.signal };

            this.onWindowDragEnter = this.handleWindowDragEnter.bind(this);
            this.onWindowDragOver = this.handleWindowDragOver.bind(this);
            this.onWindowDragLeave = this.handleWindowDragLeave.bind(this);
            this.onWindowDrop = this.handleWindowDrop.bind(this);
            this.onWindowDragEnd = this.resetExternalDrag.bind(this);

            window.addEventListener('dragenter', this.onWindowDragEnter, options);
            window.addEventListener('dragover', this.onWindowDragOver, options);
            window.addEventListener('dragleave', this.onWindowDragLeave, options);
            window.addEventListener('drop', this.onWindowDrop, options);
            window.addEventListener('dragend', this.onWindowDragEnd, options);
            window.addEventListener('blur', this.onWindowDragEnd, options);
            document.addEventListener('livewire:navigating', this.onWindowDragEnd, options);
        },

        destroy() {
            this.listeners?.abort();
            this.listeners = null;
            this.resetExternalDrag();
        },

        isActiveExplorer() {
            return this.writable
                && Boolean(this.model)
                && this.$root?.isConnected !== false
                && document.visibilityState !== 'hidden'
                && !this.openFileForm
                && !this.openingUpload;
        },

        currentTargetPath() {
            return this.$root?.dataset?.targetPath || this.targetPath;
        },

        shouldHandle(event) {
            return this.isActiveExplorer()
                && isExternalFileTransfer(event?.dataTransfer)
                && !targetIsInsideUploadModal(event?.target);
        },

        handleWindowDragEnter(event) {
            if (!this.shouldHandle(event)) {
                return;
            }

            event.preventDefault();
            this.dragDepth += 1;
            this.externalDragActive = true;
        },

        handleWindowDragOver(event) {
            if (!this.shouldHandle(event)) {
                return;
            }

            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
            this.externalDragActive = true;
        },

        handleWindowDragLeave(event) {
            if (!isExternalFileTransfer(event?.dataTransfer)) {
                return;
            }

            this.dragDepth = Math.max(0, this.dragDepth - 1);
            if (this.dragDepth === 0) {
                this.externalDragActive = false;
            }
        },

        resetExternalDrag() {
            this.dragDepth = 0;
            this.externalDragActive = false;
        },

        async handleWindowDrop(event) {
            if (!this.shouldHandle(event)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.resetExternalDrag();

            const { files, directories } = filesFromTransfer(event.dataTransfer);
            if (directories.length > 0) {
                emitToast('warning', config.directoryMessage || 'Ordner können nicht hochgeladen werden.');
            }

            if (files.length === 0) {
                return;
            }

            this.openingUpload = true;

            try {
                await this.$wire.openUploadForm();
                this.openFileForm = true;

                await new Promise((resolve) => this.$nextTick(resolve));
                await new Promise((resolve) => window.requestAnimationFrame(resolve));

                window.dispatchEvent(new CustomEvent('filepool:add-files', {
                    detail: {
                        model: this.model,
                        files,
                    },
                }));
            } catch (error) {
                emitToast('error', config.openErrorMessage || 'Der Datei-Upload konnte nicht geöffnet werden.');
            } finally {
                this.openingUpload = false;
            }
        },

        async cancelUploadForm() {
            try {
                await Promise.resolve(this.$wire.cancelUpload(this.model));
            } catch (error) {
                // Ein noch nicht gestarteter oder bereits beendeter Temp-Upload
                // hat nichts mehr abzubrechen. Das Formular wird trotzdem
                // verlaesslich serverseitig zurueckgesetzt.
            }

            await this.$wire.cancelUploadForm();
        },
    };
}

function emptyFileList() {
    return new DataTransfer().files;
}

/**
 * Adapter zwischen Dropzone (Vorschau) und Livewires echtem File-Input.
 * Nur von Dropzone akzeptierte Dateien werden ins Input gespiegelt.
 */
export function filePoolDropzone(config = {}) {
    return {
        model: config.model || '',
        single: config.single === true,
        opts: {
            maxFiles: Number(config.maxFiles || DEFAULT_MAX_FILES),
            maxFilesize: Number(config.maxFilesize || DEFAULT_MAX_FILE_SIZE_MB),
            acceptedFiles: config.acceptedFiles || '',
        },
        labels: config.labels || {},
        dz: null,
        active: false,
        suppressInputSync: false,
        inputSyncScheduled: false,
        listeners: null,

        init() {
            this.listeners = new AbortController();
            const options = { signal: this.listeners.signal };

            window.addEventListener('filepool:saved', (event) => this.handleResetEvent(event), options);
            window.addEventListener('filepool:cancelled', (event) => this.handleResetEvent(event), options);
            window.addEventListener('filepool:reset', (event) => this.handleResetEvent(event), options);
            window.addEventListener('filepool:add-files', (event) => this.handleExternalFiles(event), options);

            document.addEventListener('livewire-upload-start', (event) => this.markAllAsProcessing(event), options);
            document.addEventListener('livewire-upload-progress', (event) => this.updateAllProgress(event), options);
            document.addEventListener('livewire-upload-error', (event) => this.finishAll(event, false), options);
            document.addEventListener('livewire-upload-finish', (event) => this.finishAll(event, true), options);

            this.$nextTick(() => this.mountDropzone());
        },

        destroy() {
            this.listeners?.abort();
            this.listeners = null;
            this.suppressInputSync = true;
            this.dz?.destroy?.();
            this.dz = null;
            this.suppressInputSync = false;
        },

        fileInput() {
            return this.$refs.fileInput || null;
        },

        mountDropzone() {
            if (this.dz) {
                return;
            }

            const DropzoneClass = window.Dropzone;
            const element = this.$refs.dzForm;
            if (!DropzoneClass || !element || !this.fileInput()) {
                return;
            }

            DropzoneClass.autoDiscover = false;

            const picker = element.querySelector('.dz-message') || element;

            this.dz = new DropzoneClass(element, {
                url: '#',
                autoProcessQueue: false,
                clickable: picker,
                previewsContainer: element.querySelector('.dz-previews') || element,
                previewTemplate: uploadPreviewTemplate(),
                addRemoveLinks: false,
                maxFiles: this.single ? 1 : this.opts.maxFiles,
                maxFilesize: this.opts.maxFilesize,
                acceptedFiles: this.opts.acceptedFiles,
                dictRemoveFile: this.labels.removeFile || 'Datei entfernen',
                dictMaxFilesExceeded: this.labels.tooMany || 'Maximal 20 Dateien sind möglich.',
                dictFileTooBig: this.labels.tooLarge || `Die Datei ist größer als ${this.opts.maxFilesize} MB.`,
                dictInvalidFileType: this.labels.invalidType || 'Dieser Dateityp ist nicht erlaubt.',
                dictResponseError: this.labels.uploadFailed || 'Upload fehlgeschlagen.',
                dictCancelUpload: this.labels.cancelUpload || 'Upload abbrechen',
                dictUploadCanceled: this.labels.uploadCanceled || 'Upload abgebrochen.',
            });

            this.dz.on('maxfilesexceeded', (file) => {
                if (!this.single) {
                    return;
                }

                this.suppressInputSync = true;
                this.dz.removeAllFiles(true);
                this.suppressInputSync = false;
                this.dz.addFile(file);
            });

            this.dz.on('addedfile', (file) => {
                this.decorateFilePreview(file);

                queueMicrotask(() => {
                    if (file.accepted !== true) {
                        return;
                    }

                    this.active = true;
                    this.dz.emit('processing', file);
                    this.dz.emit('uploadprogress', file, 0, 0);
                    this.scheduleInputSync();
                });
            });

            this.dz.on('thumbnail', (file) => {
                file.previewElement?.setAttribute('data-has-thumbnail', 'true');
            });
            this.dz.on('processing', (file) => {
                this.updateFileStatus(file, 'uploading', this.labels.uploading || 'Wird übertragen');
            });
            this.dz.on('uploadprogress', (file, progress) => {
                this.updateFileProgress(file, progress);
            });
            this.dz.on('success', (file) => {
                this.updateFileProgress(file, 100);
                this.updateFileStatus(file, 'ready', this.labels.ready || 'Bereit zum Speichern');
            });
            this.dz.on('error', (file) => {
                this.updateFileStatus(file, 'error', this.labels.uploadFailed || 'Upload fehlgeschlagen.');
            });
            this.dz.on('removedfile', () => this.scheduleInputSync());
        },

        decorateFilePreview(file) {
            const preview = file?.previewElement;
            if (!preview) {
                return;
            }

            const presentation = uploadFilePresentation(file);
            preview.dataset.fileKind = presentation.kind;
            preview.setAttribute('aria-label', file?.name || presentation.extension);

            const icon = preview.querySelector('[data-upload-type-icon]');
            if (icon) {
                icon.className = `fad ${presentation.icon}`;
            }

            const extension = preview.querySelector('[data-upload-extension]');
            if (extension) {
                extension.textContent = presentation.extension;
            }

            const type = preview.querySelector('[data-upload-file-type]');
            if (type) {
                type.textContent = presentation.extension;
            }

            const size = preview.querySelector('[data-upload-file-size]');
            if (size) {
                size.textContent = formatUploadFileSize(file?.size, document.documentElement.lang || 'de-DE');
            }

            const removeLabel = preview.querySelector('[data-upload-remove-label]');
            if (removeLabel) {
                removeLabel.textContent = `${this.labels.removeFile || 'Datei entfernen'}: ${file?.name || presentation.extension}`;
            }

            this.updateFileStatus(file, 'preparing', this.labels.preparing || 'Wird vorbereitet');
            this.updateFileProgress(file, 0);
        },

        updateFileStatus(file, state, label) {
            const preview = file?.previewElement;
            if (!preview) {
                return;
            }

            const icons = {
                preparing: 'fa-clock',
                uploading: 'fa-cloud-upload-alt',
                ready: 'fa-check-circle',
                error: 'fa-exclamation-circle',
            };
            preview.dataset.uploadState = state;
            preview.setAttribute('aria-busy', ['preparing', 'uploading'].includes(state) ? 'true' : 'false');

            const status = preview.querySelector('[data-upload-status]');
            if (status) {
                status.textContent = label;
            }

            const icon = preview.querySelector('[data-upload-status-icon]');
            if (icon) {
                icon.className = `far ${icons[state] || icons.preparing}`;
            }
        },

        updateFileProgress(file, progress) {
            const preview = file?.previewElement;
            if (!preview) {
                return;
            }

            const percent = Math.max(0, Math.min(100, Math.round(Number(progress) || 0)));
            const label = preview.querySelector('[data-upload-progress-value]');
            if (label) {
                label.textContent = `${percent} %`;
            }

            preview.querySelector('[role="progressbar"]')?.setAttribute('aria-valuenow', String(percent));
        },

        handleExternalFiles(event) {
            if (event?.detail?.model !== this.model || !this.dz) {
                return;
            }

            const existing = new Set(this.dz.files.map(fileSignature));
            Array.from(event.detail.files || []).forEach((file) => {
                const signature = fileSignature(file);
                if (existing.has(signature)) {
                    return;
                }

                existing.add(signature);
                this.dz.addFile(file);
            });
        },

        scheduleInputSync() {
            if (this.suppressInputSync || this.inputSyncScheduled) {
                return;
            }

            this.inputSyncScheduled = true;
            queueMicrotask(() => {
                this.inputSyncScheduled = false;
                this.syncInput();
            });
        },

        syncInput() {
            if (this.suppressInputSync || !this.dz) {
                return;
            }

            const input = this.fileInput();
            if (!input) {
                return;
            }

            const transfer = new DataTransfer();
            const acceptedFiles = this.dz.getAcceptedFiles();
            const selectedFiles = this.single ? acceptedFiles.slice(-1) : acceptedFiles;
            selectedFiles.forEach((file) => transfer.items.add(file));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        isOwnUploadEvent(event) {
            const input = this.fileInput();
            return this.active && (!event?.target || event.target === document || event.target === input);
        },

        markAllAsProcessing(event) {
            if (!this.isOwnUploadEvent(event) || !this.dz) {
                return;
            }

            const DropzoneClass = window.Dropzone;
            this.dz.files.forEach((file) => {
                if (file.status !== DropzoneClass.SUCCESS && file.status !== DropzoneClass.ERROR) {
                    this.dz.emit('processing', file);
                    this.dz.emit('uploadprogress', file, 0, 0);
                }
            });
        },

        updateAllProgress(event) {
            if (!this.isOwnUploadEvent(event) || !this.dz) {
                return;
            }

            const percent = Number(event?.detail?.progress || 0);
            const DropzoneClass = window.Dropzone;
            this.dz.files.forEach((file) => {
                if ([DropzoneClass.UPLOADING, DropzoneClass.PROCESSING, DropzoneClass.QUEUED].includes(file.status)) {
                    const bytesSent = Math.round((percent / 100) * Number(file.size || 0));
                    this.dz.emit('uploadprogress', file, percent, bytesSent);
                }
            });
        },

        finishAll(event, ok) {
            if (!this.isOwnUploadEvent(event) || !this.dz) {
                return;
            }

            this.dz.files.forEach((file) => {
                this.dz.emit(ok ? 'success' : 'error', file, ok ? {} : (this.labels.uploadFailed || 'Upload fehlgeschlagen.'));
                this.dz.emit('complete', file);
                file.previewElement?.classList.toggle('dz-success', ok);
                file.previewElement?.classList.toggle('dz-error', !ok);
            });
            this.active = false;
        },

        handleResetEvent(event) {
            if (event?.detail?.model !== this.model) {
                return;
            }

            if (event.type === 'filepool:cancelled') {
                try {
                    this.$wire.cancelUpload(this.model);
                } catch (error) {
                    // Best effort: Das Serverformular ist bereits geschlossen.
                }
            }

            this.reset();
        },

        reset() {
            this.suppressInputSync = true;
            this.dz?.removeAllFiles(true);
            const input = this.fileInput();
            if (input) {
                input.files = emptyFileList();
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            this.suppressInputSync = false;
            this.active = false;
            this.inputSyncScheduled = false;
        },

        openPicker() {
            this.dz?.hiddenFileInput?.click();
        },
    };
}

export const FILEPOOL_UPLOAD_LIMITS = Object.freeze({
    maxFiles: DEFAULT_MAX_FILES,
    maxFileSizeBytes: DEFAULT_MAX_FILE_SIZE_BYTES,
});
