            <script>
                (() => {
                    const root = $wire.$el.querySelector('[data-mail-document-bootstrap]');
                    const button = root?.querySelector('[data-mail-document-bootstrap-button]');
                    const input = root?.querySelector('[data-mail-document-bootstrap-file]');
                    const status = root?.querySelector('[data-mail-document-bootstrap-status]');
                    const errorBox = root?.querySelector('[data-mail-document-bootstrap-error]');
                    if (!root || !button || !input || !status || !errorBox) return;

                    const listeners = new AbortController();
                    const setError = (message = '') => {
                        errorBox.textContent = message;
                        errorBox.hidden = message === '';
                        errorBox.classList.toggle('hidden', message === '');
                    };
                    const requestError = async (response) => {
                        let payload = null;
                        try { payload = await response.json(); } catch (_) {}
                        const messages = Object.values(payload?.errors || {}).flat().filter(Boolean);
                        return new Error(messages[0] || payload?.message || `Import fehlgeschlagen (HTTP ${response.status}).`);
                    };

                    button.addEventListener('click', () => input.click(), { signal: listeners.signal });
                    input.addEventListener('change', async () => {
                        const file = input.files?.[0] || null;
                        input.value = '';
                        if (!file) return;

                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        setError();
                        status.textContent = 'Bundle wird lokal gelesen und serverseitig geprüft …';

                        try {
                            if (file.size < 1 || file.size > 16 * 1024 * 1024) {
                                throw new Error('Das JSON-Bundle muss zwischen 1 Byte und 16 MiB groß sein.');
                            }
                            const bundle = JSON.parse((await file.text()).replace(/^\uFEFF/, ''));
                            if (!bundle || Array.isArray(bundle)
                                || bundle.format !== 'railtime-mail-document'
                                || bundle.version !== 2
                                || bundle.kind !== root.dataset.kind
                                || typeof bundle.html !== 'string'
                                || typeof bundle.css !== 'string'
                                || !Array.isArray(bundle.media)) {
                                throw new Error('Die Datei ist kein passendes RailTime-Mail-Bundle in Version 2.');
                            }

                            const response = await fetch(root.dataset.endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.content || '',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify(bundle),
                            });
                            if (!response.ok) throw await requestError(response);
                            const payload = await response.json();
                            status.textContent = 'Entwurf angelegt. Editor wird geöffnet …';
                            window.location.assign(payload.redirect);
                        } catch (error) {
                            setError(error instanceof Error ? error.message : 'Das Bundle konnte nicht importiert werden.');
                            status.textContent = 'Nicht importiert.';
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        }
                    }, { signal: listeners.signal });

                    window.document.addEventListener('livewire:navigating', () => listeners.abort(), {
                        once: true,
                        signal: listeners.signal,
                    });
                })();
            </script>
