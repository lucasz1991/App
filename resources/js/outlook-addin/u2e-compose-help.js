// Advice only: never authorizes another write or weakens a quote boundary.
export function universalComposeBoundaryHelp(code, report) {
    if (code !== 'U2E_NEW_DRAFT_REQUIRED' || report?.code !== code || report?.phase !== 'preflight'
        || report?.step !== 'snapshot.region' || !['reply', 'forward'].includes(report?.composeType)
        || report?.bodyWriteStarted === true || report?.bodyWriteSucceeded === true) return null;
    return Object.freeze({
        mobile: 'RailTime: Zitatgrenze unklar (RT-COMPOSE). Signatur aus neuer Mail manuell oberhalb des Zitats kopieren.',
        taskpane: 'RailTime kann den eigenen Nachrichtenteil nicht sicher vom Zitat trennen. Es wurde nichts eingefügt. '
            + 'Für diese Antwort oder Weiterleitung können Sie die Signatur aus einer neuen Mail manuell oberhalb des Zitats kopieren. '
            + 'Text und Verlauf bleiben erhalten; bitte vor dem Senden prüfen.',
    });
}
