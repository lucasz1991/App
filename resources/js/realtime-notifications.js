import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import { incomingNotificationSound } from './realtime-notification-sound';

// Eingehende Benachrichtigungen (Reverb-User-Channel, Service-Worker-Push,
// Posteingangs-Polling) laufen hier zusammen. app.js erzeugt Praesenzkontext
// und Gesehen-Speicher einmal und ruft die Installationsschritte an den
// bisherigen Stellen auf, damit die Reihenfolge der Listener gleich bleibt.
export function createRealtimeNotifications({ rtSeenNotifications, rtNotificationContext }) {
    let rtForegroundPushHandler = null;

    function installServiceWorkerBridge() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data?.type === 'railtime:push-context-request') {
                    if (document.visibilityState !== 'visible') {
                        return;
                    }

                    const contextPort = event.ports?.[0];
                    const snapshot = rtNotificationContext.publish();

                    contextPort?.postMessage({
                        type: 'railtime:push-context-ack',
                        notification_id: event.data.notification_id,
                        focused: snapshot.focused,
                        active_chat_id: snapshot.activeChatId,
                    });
                    contextPort?.close();

                    return;
                }

                if (event.data?.type !== 'railtime:push-received') {
                    return;
                }

                if (
                    !rtForegroundPushHandler
                    || document.visibilityState !== 'visible'
                ) {
                    return;
                }

                try {
                    rtForegroundPushHandler(event.data);

                    const acknowledgementPort = event.ports?.[0];
                    acknowledgementPort?.postMessage({
                        type: 'railtime:push-received-ack',
                        notification_id: event.data.notification_id,
                    });
                    acknowledgementPort?.close();
                } catch (_) {
                    // Ohne erfolgreiche Verarbeitung und ACK zeigt der Service Worker den OS-Push.
                }
            });
        }
    }

    function rtChatIdFromNotification(payload) {
        const explicit = Number(payload.chatId || payload.chat_id || 0);

        if (explicit > 0) {
            return explicit;
        }

        try {
            return Number(new URL(payload.url, window.location.href).searchParams.get('chat') || 0);
        } catch (_) {
            return 0;
        }
    }

    function rtHandleIncomingNotification(payload, source = 'echo') {
        const category = payload.category === 'chat' ? 'chat' : 'messages';
        const notificationId = String(payload.notification_id || '').trim();
        const chatId = category === 'chat' ? rtChatIdFromNotification(payload) : 0;
        const normalizedPayload = { ...payload, category, chatId };
        const chatIsAlreadyVisible = chatId > 0 && rtNotificationContext.isChatVisible(chatId);
        const mayToast = !chatIsAlreadyVisible && rtNotificationContext.shouldPresent(
            normalizedPayload,
            { forceLocal: source === 'service-worker' },
        );

        if (mayToast && rtSeenNotifications.take(notificationId)) {
            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: {
                    type: 'info',
                    title: payload.title,
                    text: payload.body || '',
                    sound: incomingNotificationSound(category),
                },
            }));
        } else if (chatIsAlreadyVisible) {
            // Der sichtbare Verlauf ist bereits die Benachrichtigung. Die stabile
            // ID wird tabuebergreifend verbraucht, damit ein spaeter eintreffender
            // Web Push nicht doch noch einen doppelten Hinweis erzeugt.
            rtSeenNotifications.take(notificationId);
        }

        if (category === 'chat' && rtNotificationContext.isLocalChatVisible(chatId)) {
            Livewire.dispatch('chat:refresh', { chatId });
        }

        Livewire.dispatch('inbox:refresh');
    }

    function enableForegroundPushHandling() {
        rtForegroundPushHandler = (payload) => {
            // Anruf-Pushes duerfen NICHT als generischer "messages"-Toast enden:
            // Das Klingeln uebernimmt das Reverb-Overlay. Der Fehler hier laesst das
            // ACK aus, damit der Service Worker die echte OS-Benachrichtigung zeigt —
            // der Weck-Fallback, falls Reverb gerade nicht verbunden ist.
            if (String(payload?.category || '') === 'calls') {
                throw new Error('calls-push wird als OS-Benachrichtigung angezeigt');
            }

            rtHandleIncomingNotification(payload, 'service-worker');
        };
    }

    // ---------------------------------------------------------------
    // Live-Benachrichtigungen: privaten User-Channel abonnieren.
    // Bei neuer Nachricht: Toast anzeigen + Posteingang aktualisieren.
    // Das Abo ueberlebt wire:navigate (Modul laeuft nur einmal).
    // ---------------------------------------------------------------
    function subscribeUserChannel() {
        const userId = document.querySelector('meta[name="rt-user-id"]')?.content;
        if (!window.Echo || !userId) {
            return;
        }

        const lang = window.rtLang || {};

        window.Echo.private(`App.Models.User.${userId}`)
            .listen('.message.received', (event) => {
                const title = lang.newMessage || 'Neue Nachricht';
                const from = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';
                const text = [from, event.subject].filter(Boolean).join(' — ');

                rtHandleIncomingNotification({
                    notification_id: event.notification_id || `message:${event.id}`,
                    category: 'messages',
                    title,
                    body: text,
                });
            })
            .listen('.chat.message.received', (event) => {
                const title = lang.newChatMessage || 'Neue Chatnachricht';
                const text = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';

                rtHandleIncomingNotification({
                    notification_id: event.notification_id || `chat-message:${event.messageId}`,
                    category: 'chat',
                    chatId: Number(event.chatId),
                    title,
                    body: text,
                });
            })
            // Videoanrufe: Das Klingel-Overlay (Livewire IncomingCallOverlay)
            // uebernimmt Anzeige, Ton und Cross-Tab-Dedup – hier wird das Event
            // nur als Window-Event weitergereicht.
            .listen('.call.invited', (event) => {
                window.dispatchEvent(new CustomEvent('rt:call-invited', { detail: event }));
            })
            .listen('.call.missed', (event) => {
                window.dispatchEvent(new CustomEvent('rt:call-missed', { detail: event }));

                window.dispatchEvent(new CustomEvent('swal:toast', {
                    detail: { text: lang.missedCall || 'Verpasster Anruf', type: 'info', sound: false },
                }));
            });
    }

    // ---------------------------------------------------------------
    // Weitere Sound-Ausloeser (Modul laeuft nur einmal, Listener ueberleben
    // wire:navigate):
    // - 'saved': Jetstream-Profilformulare melden Erfolg ohne Toast.
    // - 'rt:inbox-increased': HeaderInbox meldet neue Nachrichten ueber das
    //   60s-Polling. Nur relevant, wenn kein Echo/Reverb laeuft — mit Echtzeit-
    //   Verbindung klingelt bereits der Toast des User-Channels.
    // ---------------------------------------------------------------
    function installNotificationSoundTriggers() {
        window.addEventListener('saved', () => {
            window.RTSound?.play('success');
        });

        window.addEventListener('rt:inbox-increased', (event) => {
            // Nur der tatsaechlich verbundene Echtzeit-Kanal ersetzt den Polling-Ton —
            // ein konfigurierter, aber nicht erreichbarer Reverb-Server darf die
            // Benachrichtigung nicht verschlucken.
            if (window.Echo?.connector?.pusher?.connection?.state === 'connected') {
                return;
            }

            const notifications = Array.isArray(event.detail?.notifications)
                ? event.detail.notifications
                : [];

            if (notifications.length > 0) {
                notifications.forEach((notification) => {
                    rtHandleIncomingNotification(notification, 'polling');
                });

                return;
            }

            const source = event.detail?.source || 'both';

            if (!rtNotificationContext.shouldPresent({
                category: source === 'chat' ? 'chat' : 'messages',
            })) {
                return;
            }

            window.RTSound?.play(incomingNotificationSound(source));
        });
    }

    return {
        installServiceWorkerBridge,
        handleIncomingNotification: rtHandleIncomingNotification,
        enableForegroundPushHandling,
        subscribeUserChannel,
        installNotificationSoundTriggers,
    };
}
