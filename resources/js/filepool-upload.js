const INTERNAL_FILE_MIME = 'application/x-railtime-file';
const FILES_TRANSFER_TYPE = 'Files';
const DEFAULT_MAX_FILES = 20;
const DEFAULT_MAX_FILE_SIZE_BYTES = 100 * 1024 * 1024;

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
            maxFilesize: Number(config.maxFilesize || 100),
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

            this.dz = new DropzoneClass(element, {
                url: '#',
                autoProcessQueue: false,
                clickable: element,
                previewsContainer: element.querySelector('.dz-previews') || element,
                addRemoveLinks: true,
                maxFiles: this.single ? 1 : this.opts.maxFiles,
                maxFilesize: this.opts.maxFilesize,
                acceptedFiles: this.opts.acceptedFiles,
                dictRemoveFile: this.labels.removeFile || 'Datei entfernen',
                dictMaxFilesExceeded: this.labels.tooMany || 'Maximal 20 Dateien sind möglich.',
                dictFileTooBig: this.labels.tooLarge || 'Die Datei ist größer als 100 MB.',
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

            this.dz.on('removedfile', () => this.scheduleInputSync());
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
