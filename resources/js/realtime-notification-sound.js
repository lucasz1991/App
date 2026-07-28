/**
 * Chat-Eingaenge erhalten eine eigene, helle Klangsignatur. Alle anderen
 * Posteingangsereignisse verwenden den etwas waermeren Nachrichtenton.
 *
 * Ob ein Hinweis ueberhaupt praesentiert wird, entscheidet der gemeinsame
 * Visibility-/Chat-Kontext in app.js. Dieses Modul waehlt danach nur noch die
 * passende Klangsignatur.
 */
export function incomingNotificationSound(category) {
    return category === 'chat' ? 'chat' : 'message';
}
