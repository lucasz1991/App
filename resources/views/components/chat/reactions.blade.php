{{--
    Reaktionsleiste unter einer Chat-Nachricht – gemeinsam fuer den Chat
    (livewire.chat-box) und den Anruf-Chat (livewire.calls.call-chat).

    Der Aufrufer entscheidet, OB die Leiste erscheint (z. B. nicht bei
    geloeschten Nachrichten); der Baustein rendert sie dann immer.

    interactive   false = reine Anzeige-Chips (eigene Nachricht oder Nur-Lesen),
                  true  = Chip oeffnet x-chat.reaction-dropdown.
    centered      Anzeige-Chip mit justify-center (Chat) oder ohne (Anruf-Chat).
    showNames     Anzeige-Chip traegt die Namen der Reagierenden als title
                  (Anruf-Chat); der Dropdown-Chip zeigt sie ohnehin.
    idPrefix      Praefix der Dropdown-Kennung, damit Chat und Anruf-Chat
                  getrennte Kennungen behalten.
--}}
@props([
    'messageId',
    'reactionGroups',
    'allowedReactions' => [],
    'own' => false,
    'interactive' => false,
    'myReaction' => null,
    'centered' => true,
    'showNames' => false,
    'reactMethod' => 'setReaction',
    'removeMethod' => 'removeReaction',
    'idPrefix' => 'chat-reaction',
])

<div class="rt-chat-reactions rt-chat-reactions--overlay {{ $own ? 'rt-chat-reactions--own' : 'rt-chat-reactions--other' }} flex max-w-full flex-nowrap items-center gap-1 px-1" data-chat-action-ignore data-no-chat-swipe aria-label="{{ __('app.chat_message_reactions') }}">
    @foreach ($allowedReactions as $emoji)
        @if ($reactionGroups->has($emoji))
            @php
                $reactions = $reactionGroups->get($emoji);
                $count = $reactions->count();
                $names = $reactions->pluck('user.name')->filter()->join(', ');
            @endphp
            @if (! $interactive)
                <span
                    class="rt-chat-reaction-chip inline-flex items-center {{ $centered ? 'justify-center ' : '' }}gap-1 px-0.5 text-sm"
                    @if ($showNames) title="{{ $names }}" @endif
                    aria-label="{{ trans_choice('app.chat_reaction_count', $count, ['emoji' => $emoji, 'count' => $count]) }}"
                >
                    <span aria-hidden="true">{{ $emoji }}</span>
                    @if ($count > 1)<span class="text-[10px] font-extrabold tabular-nums">{{ $count }}</span>@endif
                </span>
            @else
                <x-chat.reaction-dropdown
                    :message-id="$messageId"
                    :emoji="$emoji"
                    :count="$count"
                    :names="$names"
                    :my-reaction="$myReaction"
                    :react-method="$reactMethod"
                    :remove-method="$removeMethod"
                    dropdown-id="{{ $idPrefix }}-{{ $messageId }}-{{ md5($emoji) }}"
                />
            @endif
        @endif
    @endforeach
</div>
