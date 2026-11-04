import { U2E_REPORT_CODES } from './u2e-session-report.js';
import { u2eCheckpointLabel } from './u2e-status-labels.js';

// Display evidence only. This function cannot authorize an Office write.
export function universalTaskpaneStatus(report, errorCode = null) {
    const code = U2E_REPORT_CODES.includes(errorCode) ? errorCode
        : errorCode === null && U2E_REPORT_CODES.includes(report?.code) ? report.code : null;
    if (!code || report?.state === 'complete' && errorCode === null) return null;
    // A report from an earlier attempt must not explain a different failure.
    const evidence = report?.code === code ? report : null;
    const written = evidence?.bodyWriteSucceeded === true;
    const notStarted = evidence?.bodyWriteStarted === false && evidence?.bodyWriteSucceeded === false;
    const uncertain = code === 'U2E_INSERT_UNCERTAIN' || evidence?.state === 'uncertain'
        || evidence?.bodyWriteStarted === true || written;
    const blocked = uncertain || code === 'U2E_NEW_DRAFT_REQUIRED';
    const warning = written || uncertain && !notStarted;
    const title = written ? 'Eingefügt, Darstellung nicht bestätigt'
        : warning ? 'Einfügung nicht bestätigt' : 'Aktion nicht abgeschlossen';
    const fact = written ? 'Outlook hat das Einfügen bestätigt; die anschließende Prüfung ist fehlgeschlagen.'
        : warning ? 'Ob Outlook die Vorlage eingefügt hat, ist nicht bestätigt.'
            : notStarted ? 'Die Aktion ist vor dem Einfügen in die Nachricht fehlgeschlagen.'
                : 'Die Vorlage konnte nicht sicher vorbereitet werden.';
    const label = u2eCheckpointLabel(evidence?.checkpoint, evidence?.step);
    let at = '';
    if (typeof evidence?.at === 'string' && evidence.at.length === 24
        && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(evidence.at) && Number.isFinite(Date.parse(evidence.at))) {
        try {
            at = ` Zeitpunkt: ${new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'medium', timeZone: 'Europe/Berlin' }).format(new Date(evidence.at))} (Berlin).`;
        } catch { at = ` Zeitpunkt: ${evidence.at} (UTC).`; }
    }
    const action = blocked ? ' Nicht erneut einfügen. Öffnen Sie in Outlook eine neue Nachricht.' : '';
    const sending = warning ? ' Das Add-in sperrt den Versand nicht; die Darstellung ist nicht bestätigt.' : '';
    return Object.freeze({ code, tone: warning ? 'warning' : 'error', title,
        detail: `${fact} Prüfschritt: ${label}.${at}${action}${sending}`,
        blocked, newMessageRequired: blocked });
}
