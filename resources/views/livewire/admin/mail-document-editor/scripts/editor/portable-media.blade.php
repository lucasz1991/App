                    const bytesToBase64 = (bytes) => {
                        let binary = '';
                        const chunkSize = 0x8000;
                        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
                            binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
                        }

                        return window.btoa(binary);
                    };

                    const base64ToBytes = (encoded) => {
                        if (typeof encoded !== 'string'
                            || encoded.length === 0
                            || encoded.length % 4 !== 0
                            || !/^[A-Za-z0-9+/]*={0,2}$/.test(encoded)) {
                            throw new Error('Ein Medium enthält keine gültigen Base64-Daten.');
                        }

                        const binary = window.atob(encoded);
                        const bytes = new Uint8Array(binary.length);
                        for (let index = 0; index < binary.length; index += 1) {
                            bytes[index] = binary.charCodeAt(index);
                        }

                        return bytes;
                    };

                    const sha256 = async (bytes) => Array.from(
                        new Uint8Array(await window.crypto.subtle.digest('SHA-256', bytes)),
                    ).map((value) => value.toString(16).padStart(2, '0')).join('');

                    const portableMediaCatalog = () => {
                        const catalog = Array.isArray(config.portableMedia) ? config.portableMedia : [];
                        const seen = new Set();

                        return catalog.map((asset) => {
                            const source = String(asset?.source || '').trim();
                            const id = String(asset?.id || '').trim();
                            const mimeType = String(asset?.mime_type || '').toLowerCase();
                            const digest = String(asset?.sha256 || '').toLowerCase();
                            const bytes = Number(asset?.bytes || 0);
                            const resolved = new URL(source, window.location.href);
                            if (!id
                                || seen.has(id)
                                || resolved.origin !== window.location.origin
                                || !['image/gif', 'image/png', 'image/jpeg', 'image/webp'].includes(mimeType)
                                || !/^[a-f0-9]{64}$/.test(digest)
                                || !Number.isInteger(bytes)
                                || bytes < 1
                                || bytes > MAX_MEDIA_BYTES) {
                                throw new Error('Der serverseitige Medienbestand ist nicht portabel konfiguriert.');
                            }
                            seen.add(id);

                            return {
                                id,
                                name: String(asset?.name || id),
                                source: resolved.href,
                                mime_type: mimeType,
                                bytes,
                                sha256: digest,
                                required: Boolean(asset?.required),
                                included: asset?.included !== false,
                            };
                        });
                    };

                    const requiredPortableMediaIds = (source) => {
                        if (typeof runtimeBridge.resolvePortableMediaRequirementIds !== 'function') {
                            throw new Error('Die versionsabhängige Medienprüfung ist nicht verfügbar. Bitte Seite neu laden.');
                        }

                        return runtimeBridge.resolvePortableMediaRequirementIds(
                            config.portableMediaRequirements || {},
                            config.currentDocument,
                            source?.html || '',
                        );
                    };

                    const exportPortableMedia = async (source) => {
                        const exported = [];
                        let totalBytes = 0;
                        const requiredIds = new Set(requiredPortableMediaIds(source));

                        for (const asset of portableMediaCatalog().filter((entry) => (
                            requiredIds.has(entry.id)
                            || (entry.included && entry.id.startsWith('mail-imports/'))
                        ))) {
                            const response = await fetch(asset.source, {
                                credentials: 'same-origin',
                                cache: 'no-store',
                                redirect: 'error',
                            });
                            if (!response.ok || new URL(response.url).origin !== window.location.origin) {
                                throw new Error(`„${asset.name}“ konnte nicht sicher gelesen werden.`);
                            }

                            const bytes = new Uint8Array(await response.arrayBuffer());
                            const digest = await sha256(bytes);
                            if (bytes.byteLength !== asset.bytes || digest !== asset.sha256) {
                                throw new Error(`„${asset.name}“ wurde während des Exports verändert. Bitte Seite neu laden.`);
                            }
                            totalBytes += bytes.byteLength;
                            if (totalBytes > MAX_BUNDLE_BYTES) {
                                throw new Error('Die Medien des Bundles sind zusammen größer als 16 MiB.');
                            }

                            exported.push({ ...asset, data: bytesToBase64(bytes) });
                        }

                        return exported;
                    };

                    const portableBundle = async (source) => ({
                        format: MAIL_SOURCE_FORMAT,
                        version: MAIL_SOURCE_VERSION,
                        kind: config.currentDocument,
                        html: source.html,
                        css: source.css,
                        media: await exportPortableMedia(source),
                    });

                    const downloadPortableBundle = async (source) => {
                        const bundle = await portableBundle(source);
                        const blob = new Blob([`${JSON.stringify(bundle, null, 2)}\n`], {
                            type: 'application/json;charset=utf-8',
                        });
                        if (blob.size > MAX_BUNDLE_BYTES) {
                            throw new Error(`Das vollständige Bundle ist ${formatBytes(blob.size)} groß und überschreitet 16 MiB.`);
                        }
                        const objectUrl = URL.createObjectURL(blob);
                        const link = window.document.createElement('a');
                        const documentName = config.currentDocument === 'signature' ? 'signatur' : 'nachrichtenvorlage';
                        link.href = objectUrl;
                        link.download = `railtime-${documentName}-v${MAIL_SOURCE_VERSION}.json`;
                        link.hidden = true;
                        window.document.body.appendChild(link);

                        try {
                            link.click();
                        } finally {
                            link.remove();
                            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
                        }
                    };

                    const parsePortableBundle = async (text) => {
                        let bundle;
                        try {
                            bundle = JSON.parse(String(text || '').replace(/^\uFEFF/, ''));
                        } catch (_) {
                            throw new Error('Die JSON-Datei ist nicht gültig.');
                        }

                        if (!bundle || Array.isArray(bundle) || typeof bundle !== 'object'
                            || bundle.format !== MAIL_SOURCE_FORMAT
                            || ![1, MAIL_SOURCE_VERSION].includes(bundle.version)) {
                            throw new Error(`Erwartet wird ein RailTime-Mail-Bundle in Version 1 oder ${MAIL_SOURCE_VERSION}.`);
                        }
                        if (bundle.kind !== config.currentDocument) {
                            const expected = config.currentDocument === 'signature' ? 'eine Signatur' : 'eine Nachrichtenvorlage';
                            throw new Error(`Dieses Bundle gehört zu „${bundle.kind || 'unbekannt'}“. Geöffnet ist ${expected}.`);
                        }

                        const source = assertPortableSource({ html: bundle.html, css: bundle.css });
                        if (bundle.version === 1) {
                            return { source, media: [] };
                        }
                        if (!Array.isArray(bundle.media)) {
                            throw new Error('Dem Bundle fehlt der vollständige Medienbestand.');
                        }

                        const catalog = portableMediaCatalog();
                        const knownIds = new Set(catalog.map((asset) => asset.id));
                        const requiredIds = requiredPortableMediaIds(source);
                        const seenIds = new Set();
                        const seenSources = new Set();
                        let totalBytes = 0;
                        const media = [];
                        for (const entry of bundle.media) {
                            const id = String(entry?.id || '').trim();
                            const name = String(entry?.name || id).trim();
                            const sourceUrl = String(entry?.source || '').trim();
                            const mimeType = String(entry?.mime_type || '').toLowerCase();
                            const declaredBytes = Number(entry?.bytes || 0);
                            const declaredHash = String(entry?.sha256 || '').toLowerCase();
                            const importedId = id.match(/^mail-imports\/([a-f0-9]{64})\.(gif|png|jpg|webp)$/i);
                            const expectedExtension = {
                                'image/gif': 'gif',
                                'image/png': 'png',
                                'image/jpeg': 'jpg',
                                'image/webp': 'webp',
                            }[mimeType];
                            if (!id
                                || !name
                                || seenIds.has(id)
                                || (!knownIds.has(id) && !importedId)
                                || !sourceUrl
                                || seenSources.has(sourceUrl)
                                || !/^(?:https?:\/\/|\/)/i.test(sourceUrl)
                                || sourceUrl.includes('{{')
                                || !['image/gif', 'image/png', 'image/jpeg', 'image/webp'].includes(mimeType)
                                || !Number.isInteger(declaredBytes)
                                || declaredBytes < 1
                                || declaredBytes > MAX_MEDIA_BYTES
                                || !/^[a-f0-9]{64}$/.test(declaredHash)
                                || (importedId && (importedId[1].toLowerCase() !== declaredHash
                                    || importedId[2].toLowerCase() !== expectedExtension))) {
                                throw new Error('Das Bundle enthält einen ungültigen oder doppelten Medieneintrag.');
                            }

                            const bytes = base64ToBytes(entry.data);
                            const digest = await sha256(bytes);
                            if (bytes.byteLength !== declaredBytes || digest !== declaredHash) {
                                throw new Error(`Prüfsumme oder Größe von „${name}“ stimmt nicht.`);
                            }
                            totalBytes += bytes.byteLength;
                            if (totalBytes > MAX_BUNDLE_BYTES) {
                                throw new Error('Die Medien des Bundles sind zusammen größer als 16 MiB.');
                            }
                            seenIds.add(id);
                            seenSources.add(sourceUrl);
                            media.push({
                                id,
                                name,
                                source: sourceUrl,
                                mime_type: mimeType,
                                bytes: declaredBytes,
                                sha256: declaredHash,
                                data: entry.data,
                            });
                        }

                        if (requiredIds.some((id) => !seenIds.has(id))) {
                            throw new Error('Das Bundle enthält nicht den vollständigen Medienbestand dieses Dokuments.');
                        }

                        return { source, media };
                    };

