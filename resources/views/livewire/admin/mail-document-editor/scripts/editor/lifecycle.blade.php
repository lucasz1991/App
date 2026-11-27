                    boot().catch((error) => {
                        if (destroyed) return;

                        try {
                            instance?.destroy?.();
                        } catch (_) {
                            // Die Recovery-Werkzeuge bleiben auch dann aktiv,
                            // wenn eine halbfertige Builderinstanz sich nicht
                            // mehr vollstaendig abbauen laesst.
                        }
                        instance = null;
                        editorBootState = 'failed';
                        root.innerHTML = '';
                        const notice = window.document.createElement('div');
                        notice.className = 'rt-mail-editor-error';
                        notice.setAttribute('role', 'alert');
                        notice.textContent = `Editor konnte nicht geladen werden: ${error.message} Ein JSON-, HTML- oder CSS-Import bleibt über „Werkzeuge“ verfügbar.`;
                        root.appendChild(notice);
                        toast('error', error.message, 'E-Mail-Editor nicht verfügbar');
                    });

                    const teardown = () => {
                        destroyed = true;
                        finishResizeGesture();
                        deliveryPreviewGeneration += 1;
                        deliveryPreviewRequest?.abort();
                        deliveryPreviewRequest = null;
                        if (previewResizeFrame !== null) window.cancelAnimationFrame(previewResizeFrame);
                        previewResizeFrame = null;
                        codeDialogOpener = null;
                        if (codeDialog?.open) codeDialog.close('teardown');
                        if (importFile) importFile.value = '';
                        controlListeners.abort();
                        unregisterNavigation?.();
                        unregisterNavigation = null;
                        detachBuilderToolbarContext?.();
                        detachBuilderToolbarContext = null;
                        instance?.destroy?.();
                        instance = null;
                        window.RailTimeMailDocumentEditor = null;
                        window.document.removeEventListener('livewire:navigating', teardown);
                    };

                    window.document.addEventListener('livewire:navigating', teardown);
                    window.RailTimeMailDocumentEditor = {
                        destroy: teardown,
                        hasUnsavedChanges: () => Boolean(instance?.hasUnsavedChanges?.()),
                    };
