<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\User;
use App\Support\Operations\OperationsAccess;

/** Advisory identity candidates; this service never assigns or creates customers. */
class AiIntakeCustomerSuggestions
{
    private const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'live.de', 'msn.com',
        'yahoo.com', 'yahoo.de', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com', 'icloud.com', 'me.com', 'mac.com',
        'gmx.de', 'gmx.net', 'gmx.com', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de', 'freenet.de', 'arcor.de', 'vodafone.de',
        'mail.de', 'email.de', 'mail.com', 'email.com', 'mailbox.org', 'posteo.de', 'posteo.net', 'proton.me', 'protonmail.com', 'pm.me',
        'aol.com', 'aol.de', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'rambler.ru', 'qq.com', '163.com', '126.com',
    ];

    public function suggest(AiIntake $intake, User $actor): array
    {
        $freshActor = User::findOrFail($actor->id);
        OperationsAccess::authorize($freshActor, 'operations.inquiries.manage');
        $fresh = AiIntake::findOrFail($intake->id);
        if ($fresh->customer_id) {
            return [];
        }
        $draft = (array) ($fresh->analysis['customer_draft'] ?? []);
        $company = is_string($draft['company_name'] ?? null) ? $this->normalizedName($draft['company_name']) : '';
        $reasons = [];
        if ($company !== '') {
            // Select only company identity fields, never private contact/name or mail contents.
            foreach (Customer::active()->orderBy('id')->get(['id', 'company_name']) as $customer) {
                if ($this->normalizedName($customer->company_name) === $company) {
                    $reasons[(int) $customer->id][] = 'company_name_exact';
                }
            }
        }
        $message = $fresh->messages()->where('direction', 'inbound')->whereKey($fresh->latest_inbound_message_id)->first(['id', 'intake_id', 'sender_email']);
        $sender = strtolower(trim((string) $message?->sender_email));
        $domain = filter_var($sender, FILTER_VALIDATE_EMAIL) ? substr(strrchr($sender, '@'), 1) : '';
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/D', $domain) && ! in_array($domain, self::PUBLIC_DOMAINS, true)) {
            // The address is checked in SQL; only matching customer IDs are returned from storage.
            $ids = Customer::active()->where(function ($query) use ($domain): void {
                $query->whereRaw('LOWER(TRIM(email)) LIKE ?', ['%@'.$domain])->orWhereExists(function ($contacts) use ($domain): void {
                    $contacts->selectRaw('1')->from('customer_contacts')->whereColumn('customer_contacts.customer_id', 'customers.id')
                        ->where('customer_contacts.is_active', true)->whereRaw('LOWER(TRIM(customer_contacts.email)) LIKE ?', ['%@'.$domain]);
                });
            })->orderBy('id')->limit(10)->pluck('id');
            foreach ($ids as $id) {
                $reasons[(int) $id][] = 'sender_domain';
            }
        }
        uksort($reasons, fn ($a, $b) => (int) in_array('company_name_exact', $reasons[$b], true) <=> (int) in_array('company_name_exact', $reasons[$a], true) ?: (int) $a <=> (int) $b);
        $result = [];
        foreach (array_slice($reasons, 0, 10, true) as $id => $why) {
            $result[] = ['customer_id' => (int) $id, 'reasons' => array_values(array_unique($why))];
        }

        return $result;
    }

    private function normalizedName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }
}
