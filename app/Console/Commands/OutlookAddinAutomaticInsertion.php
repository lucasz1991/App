<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OutlookAddinAutomaticInsertion extends Command
{
    protected $signature = 'outlook-addin:automatic-insertion
        {mailbox : Exakter bestehender Microsoft-Anmeldename}
        {--disable : Automatische Einfügung für dieses Konto deaktivieren}
        {--enable : Automatische Einfügung für dieses Konto aktivieren}
        {--apply : Geprüfte Änderung speichern; ohne diese Option nur Vorschau}
        {--actor= : Interne ID des verantwortlichen aktiven Administrators}';

    protected $description = 'Zeigt oder ändert die automatische Outlook-Einfügung für genau ein bestehendes Mitarbeiterkonto';

    public function handle(OutlookAddinAutomaticInsertionPolicy $policy): int
    {
        $disable = (bool) $this->option('disable');
        $enable = (bool) $this->option('enable');
        $apply = (bool) $this->option('apply');
        if (($disable && $enable) || ($apply && ! $disable && ! $enable)) {
            $this->error('Genau --disable oder --enable wählen. --apply benötigt eine dieser Änderungen.');

            return self::INVALID;
        }

        try {
            $mailbox = (string) $this->argument('mailbox');
            $current = $policy->forMailbox($mailbox);
            if (! $disable && ! $enable) {
                $this->line($this->status($current['userId'], $current['enabled']));

                return self::SUCCESS;
            }
            $wanted = $enable;
            if (! $apply) {
                $this->line($this->status($current['userId'], $current['enabled']));
                $this->info('Vorschau: Automatische Einfügung würde '.($wanted ? 'aktiviert' : 'deaktiviert').'. Zum Speichern --apply und --actor=<ID> ergänzen.');

                return self::SUCCESS;
            }
            $actorId = (string) $this->option('actor');
            if (! ctype_digit($actorId) || (int) $actorId < 1) {
                throw new AuthorizationException('Für Änderungen ist --actor mit der ID eines aktiven Administrators erforderlich.');
            }
            $actor = User::query()->find((int) $actorId);
            if (! $actor instanceof User) {
                throw new AuthorizationException('Der verantwortliche Administrator wurde nicht gefunden.');
            }
            $result = $policy->setForMailbox($mailbox, $wanted, $actor);
            $this->info($this->status($result['userId'], $result['enabled']).($result['changed'] ? ' Gespeichert.' : ' Bereits unverändert.'));

            return self::SUCCESS;
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));
        } catch (Throwable) {
            $this->error('Die Outlook-Einstellung konnte nicht sicher gelesen oder geändert werden.');
        }

        return self::FAILURE;
    }

    private function status(int $userId, bool $enabled): string
    {
        return 'Mitarbeiter '.$userId.': automatische Outlook-Einfügung '.($enabled ? 'aktiviert' : 'deaktiviert').'.';
    }
}
