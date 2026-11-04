<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

/** Finite execution facts only; no Office messages, identifiers or mail content. */
final class OutlookUniversalOperationDetails
{
    public const KEYS = ['originalCode', 'step', 'bodyLengthOrigin', 'composeType', 'automaticReason', 'context'];

    public const REASONS = ['compose_type_unknown', 'automatic_disabled', 'automatic_policy_unconfirmed',
        'prior_write_uncertain', 'universal_source_unavailable', 'template_only_policy'];

    private const STEPS = ['binding', 'legacy', 'session.read', 'session.claim', 'session.finalize', 'session.verify',
        'snapshot.type', 'snapshot.format', 'snapshot.body', 'snapshot.region', 'source.state', 'header.write',
        'body.write', 'context.compare', 'readback.material', 'readback.compose_type', 'readback.quote', 'readback.author'];

    public static function valid(array $details, string $code): bool
    {
        $values = [
            'originalCode' => [...OutlookUniversalDiagnostics::READBACK_CODES, 'UNAVAILABLE', 'OFFICE_WRITE_FAILED',
                'MAILBOX_BINDING_INVALID', 'SENDER_API_UNAVAILABLE', 'SENDER_READ_FAILED', 'MAILBOX_MISMATCH', 'SENDER_MISMATCH'],
            'step' => self::STEPS,
            'bodyLengthOrigin' => ['original', 'actual'],
            'composeType' => ['newMail', 'reply', 'forward', 'unknown'],
            'automaticReason' => self::REASONS,
            'context' => ['event', 'taskpane'],
        ];
        foreach ($details as $key => $value) {
            if (! isset($values[$key]) || ! in_array($value, $values[$key], true)) {
                return false;
            }
        }

        return $code === 'U2E_AUTOMATIC_SKIPPED'
            ? isset($details['automaticReason'])
            : ! array_key_exists('automaticReason', $details);
    }
}
