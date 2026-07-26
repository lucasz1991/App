/**
 * Chat-Eingaenge erhalten eine eigene, helle Klangsignatur. Alle anderen
 * Posteingangsereignisse verwenden den etwas waermeren Nachrichtenton.
 *
 * Der sichtbare Seitenzustand ist bewusst kein Parameter: Eine neue Nachricht
 * soll auch dann hoerbar bleiben, wenn ihr Chat oder der Posteingang offen ist.
 */
export function incomingNotificationSound(category) {
    return category === 'chat' ? 'chat' : 'message';
}
