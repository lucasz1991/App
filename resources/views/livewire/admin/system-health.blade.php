<section
    class="rt-health"
    x-data="systemHealth({ initialRows: @js($rows) })"
    x-effect="setOverviewActive(typeof openTab !== 'undefined' && openTab === 'overview')"
    aria-labelledby="system-health-title"
    data-system-health
>
    <div class="rt-health-shell">
        <div class="rt-health-core">
            <header class="rt-health-header">
                <div class="rt-health-heading">
                    <span class="rt-health-eyebrow">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v6c0 4.6 7.5 9 7.5 9s7.5-4.4 7.5-9V6L12 3Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
                        Systemdiagnose · Superadmin
                    </span>
                    <h2 id="system-health-title">Ein klarer Blick<br class="hidden sm:block"> auf deine Systeme.</h2>
                    <p>Technik, Verbindungen und Hintergrunddienste. Ein gemeinsames Betriebsbild – mit nachvollziehbaren Nachweisen.</p>
                </div>
                <div class="rt-health-control-shell">
                    <div class="rt-health-controls">
                        <span class="rt-health-control-label">Aktueller Prüfstand</span>
                        <div class="rt-health-overall" :data-status="summary.status" role="status" aria-live="polite" aria-atomic="true">
                            <span class="rt-health-dot" aria-hidden="true"></span>
                            <span x-text="summary.label">Betriebsbild noch unvollständig</span>
                        </div>
                        <span class="rt-health-timestamp" x-text="latestCheck ? 'Letztes Ergebnis: ' + timeLabel(latestCheck) : 'Noch keine Ergebnisse vorhanden'">Noch keine Ergebnisse vorhanden</span>
                        <button type="button" class="rt-health-primary" @click="run(true)" :disabled="busy" :aria-busy="busy">
                            <span x-text="busy ? 'Prüfung läuft …' : 'Alle erneut prüfen'">Alle erneut prüfen</span>
                            <span class="rt-health-button-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.7-1L20 9M4 15l2.2 3A7 7 0 0 0 17.9 17"/></svg>
                            </span>
                        </button>
                        <p>15 Minuten gespeichert. Neue Prüfungen erst beim nächsten Öffnen oder auf deinen Klick.</p>
                    </div>
                </div>
            </header>

            <div class="rt-health-metrics" aria-label="Ergebnisübersicht">
                <div><strong x-text="rows.filter(row => row.status === 'ok' && row.fresh).length">0</strong><span>Ohne Befund <small>frisch geprüft</small></span></div>
                <div><strong x-text="(counts.warning || 0) + (counts.error || 0)">0</strong><span>Hinweise <small>Warnungen &amp; Fehler</small></span></div>
                <div><strong x-text="(counts.not_checked || 0) + (counts.not_configured || 0) + (counts.running || 0)">0</strong><span>Offene Nachweise <small>inkl. Einrichtung</small></span></div>
            </div>

            <div class="rt-health-progress" x-show="busy" x-cloak role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Fortschritt der Systemprüfung">
                <span :style="{ transform: 'scaleX(' + progress / 100 + ')' }"></span>
            </div>

            <div class="rt-health-notice rt-health-notice--error" x-show="error" x-cloak role="alert"><span x-text="error"></span></div>
            <div class="rt-health-notice" x-show="observationEnded" x-cloak role="status">Die Beobachtungszeit ist beendet. Eine noch offene Ausführung ist kein Nachweis eines ausgefallenen Workers. Du kannst die betreffende Prüfung später erneut aufrufen.</div>

            <div class="rt-health-list-heading">
                <div><h3>Systeme &amp; Integrationen</h3><p x-text="busy ? completed + ' von ' + planned + ' Prüfungen bearbeitet' : rows.length + ' Prüfungen · einzeln nachvollziehbar'">Prüfungen · einzeln nachvollziehbar</p></div>
                <span class="rt-health-cache-label" x-text="counts.stale ? counts.stale + ' ohne frischen Nachweis' : 'Ergebnisse bis zu 15 Minuten gültig'"></span>
            </div>

            <div class="rt-health-groups">
                <template x-for="group in groups" :key="group.label">
                    <section class="rt-health-group" :aria-label="group.label">
                        <h4 x-text="group.label"></h4>
                        <div class="rt-health-checks">
                            <template x-for="row in group.rows" :key="row.id">
                                <details class="rt-health-check" :data-check-id="row.id" :data-status="currentId === row.id ? 'running' : row.status" :data-fresh="row.fresh ? 'true' : 'false'">
                                    <summary>
                                        <span class="rt-health-check-symbol" aria-hidden="true">
                                            <svg viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="8.5"/>
                                                <path x-show="row.status === 'ok' && currentId !== row.id" d="m8 12 2.7 2.7 5.3-5.4"/>
                                                <path x-show="['error', 'warning'].includes(row.status) && currentId !== row.id" d="M12 7.5v5m0 3v.1"/>
                                                <path x-show="row.status === 'running' || currentId === row.id" d="M12 7.5V12l3 2"/>
                                                <path x-show="!['ok', 'error', 'warning', 'running'].includes(row.status) && currentId !== row.id" d="M8.5 12h7"/>
                                            </svg>
                                        </span>
                                        <span class="rt-health-check-description"><strong x-text="row.label"></strong><span x-text="row.message"></span></span>
                                        <span class="rt-health-check-meta">
                                            <span class="rt-health-status" x-text="currentId === row.id ? 'Läuft' : statusLabel(row.status)"></span>
                                            <span class="rt-health-evidence" x-text="evidenceLabel(row.evidence)"></span>
                                            <span class="rt-health-stale" x-show="!row.fresh && row.checked_at">Veralteter Nachweis</span>
                                        </span>
                                        <svg class="rt-health-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
                                    </summary>
                                    <div class="rt-health-check-body">
                                        <div class="rt-health-detail-meta"><span x-text="timeLabel(row.checked_at)"></span><span x-text="durationLabel(row.duration_ms)"></span><span x-text="!row.fresh ? 'Kein frischer Nachweis' : row.source === 'cache' ? 'Gespeichertes Ergebnis' : 'In diesem Aufruf geprüft'"></span></div>
                                        <ul x-show="row.details && row.details.length"><template x-for="(detail, detailIndex) in row.details" :key="detailIndex"><li x-text="detail"></li></template></ul>
                                        <div class="rt-health-detail-actions">
                                            <button type="button" class="rt-health-secondary" @click="run(true, row.id)" :disabled="busy" :aria-label="row.label + ' erneut prüfen'">Erneut prüfen</button>
                                            <button type="button" class="rt-health-link" x-show="row.settings_tab" @click="selectTab(row.settings_tab, true); $dispatch('rt-settings-open-section', { tab: row.settings_tab, section: row.settings_section })">
                                                Einstellung öffnen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </details>
                            </template>
                        </div>
                    </section>
                </template>
            </div>

            <footer class="rt-health-footer">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V7a5 5 0 0 1 10 0v3"/><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M12 14v3"/></svg>
                <p>Nur sichere Diagnosen. Kein Mailversand, keine Gerätebefehle, keine automatische Reparatur. Konfiguration und Verbindung sind noch kein vollständiger Funktionstest.</p>
            </footer>
        </div>
    </div>
</section>
