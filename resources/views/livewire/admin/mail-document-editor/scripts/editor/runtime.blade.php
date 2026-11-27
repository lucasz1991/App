                    const loadOnce = (tag, attributes) => new Promise((resolve, reject) => {
                        const selector = tag === 'link'
                            ? `link[href="${attributes.href}"]`
                            : `script[src="${attributes.src}"]`;

                        if (window.document.querySelector(selector)) {
                            resolve();
                            return;
                        }

                        const node = window.document.createElement(tag);
                        Object.assign(node, attributes);
                        node.addEventListener('load', resolve, { once: true });
                        node.addEventListener('error', () => reject(new Error('Der LMZ Page Builder konnte nicht geladen werden.')), { once: true });
                        window.document.head.appendChild(node);
                    });

                    const ensureRuntime = async () => {
                        const expectedVersion = String(config.vendor.builderVersion || '');
                        const runtimeIsCurrent = () => window.LMZBuilder?.create
                            && (!expectedVersion || String(window.LMZBuilder.assetVersion || '') === expectedVersion);

                        if (runtimeIsCurrent()) {
                            return window.LMZBuilder;
                        }

                        await Promise.all([
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.coreCss }),
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.builderCss }),
                        ]);
                        await loadOnce('script', { src: config.vendor.builderJs, defer: true });

                        if (!runtimeIsCurrent()) {
                            throw new Error('LMZ Page Builder 2.4.5 wurde nicht initialisiert.');
                        }

                        return window.LMZBuilder;
                    };

                    const waitForFullscreenActivation = async () => {
                        if (workspace.dataset.pageBuilderEditorActive !== 'false') return true;

                        const shellId = studioRoot.dataset.pageBuilderShellId || '';
                        return await new Promise((resolve) => {
                            const finish = (active) => {
                                window.removeEventListener('page-builder-shell:opened', opened);
                                document.removeEventListener('livewire:navigating', cancelled);
                                controlListeners.signal.removeEventListener('abort', cancelled);
                                resolve(active);
                            };
                            const opened = (event) => {
                                if (shellId && event.detail?.id && event.detail.id !== shellId) return;
                                finish(true);
                            };
                            const cancelled = () => finish(false);

                            window.addEventListener('page-builder-shell:opened', opened);
                            document.addEventListener('livewire:navigating', cancelled, { once: true });
                            controlListeners.signal.addEventListener('abort', cancelled, { once: true });
                        });
                    };

                    const request = async (url, method, body = null, options = {}) => {
                        const response = await fetch(url, {
                            method,
                            credentials: 'same-origin',
                            signal: options.signal || undefined,
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                ...(body ? { 'Content-Type': 'application/json' } : {}),
                            },
                            body: body ? JSON.stringify(body) : null,
                        });

                        let payload = {};
                        try {
                            payload = await response.json();
                        } catch (_) {
                            payload = {};
                        }

                        if (!response.ok) {
                            const validation = payload.errors
                                ? Object.values(payload.errors).flat().filter(Boolean)
                                : [];
                            const error = new Error(validation[0] || payload.message || `Anfrage fehlgeschlagen (${response.status}).`);
                            error.messages = validation;
                            throw error;
                        }

                        return payload;
                    };

