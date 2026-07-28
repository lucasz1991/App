<?php

namespace App\Services\Calls;

use RuntimeException;

/**
 * Der Media-Server konnte den Raum nicht anlegen.
 *
 * Ohne diese Ausnahme wuerde der Anrufer in ein Anruf-Fenster geleitet, dessen
 * Raum an der SFU nie existiert hat: Er kaeme nie zustande, und der DB-Raum
 * bliebe mangels room_finished-Webhook fuer immer auf 'pending' – was
 * Chat::activeRoom() dauerhaft belegt und den Anruf-Button des Chats
 * unbrauchbar macht.
 */
class CallInfrastructureUnavailable extends RuntimeException
{
}
