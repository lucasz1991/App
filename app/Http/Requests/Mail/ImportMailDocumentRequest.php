<?php

namespace App\Http\Requests\Mail;

use App\Enums\MailDocumentKind;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\SignatureArtifactVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Vollstaendiges, portables Erstimport-Bundle fuer ein Maildokument. */
final class ImportMailDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', 'in:railtime-mail-document'],
            'version' => ['required', 'integer', 'in:2'],
            'kind' => ['required', 'string', Rule::enum(MailDocumentKind::class)],
            'html' => ['required', 'string', 'max:600000'],
            'css' => ['present', 'string', 'max:250000'],
            'media' => ['required', 'array', 'max:256'],
            'media.*' => ['required', 'array'],
            'media.*.id' => ['required', 'string', 'max:160'],
            'media.*.name' => ['required', 'string', 'max:200'],
            'media.*.source' => ['required', 'string', 'max:2048'],
            'media.*.mime_type' => ['required', 'string', 'in:image/gif,image/png,image/jpeg,image/webp'],
            'media.*.bytes' => ['required', 'integer', 'min:1', 'max:2097152'],
            'media.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'media.*.data' => ['required', 'string', 'max:2796204'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('css') === null) {
            $this->merge(['css' => '']);
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $media = $this->input('media', []);
                if (! is_array($media)) {
                    return;
                }

                $encodedBytes = array_sum(array_map(
                    static fn ($entry): int => is_array($entry)
                        ? strlen((string) ($entry['data'] ?? ''))
                        : 0,
                    $media,
                ));
                if ($encodedBytes > 16 * 1024 * 1024) {
                    $validator->errors()->add('media', 'Das Medienpaket ist groesser als 16 MiB.');
                }

                $ids = array_map(
                    static fn ($entry): string => is_array($entry)
                        ? trim((string) ($entry['id'] ?? ''))
                        : '',
                    $media,
                );
                $counts = array_count_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
                if (array_filter($counts, static fn (int $count): bool => $count !== 1) !== []) {
                    $validator->errors()->add('media', 'Jede Medienkennung darf nur einmal vorkommen.');
                }

                $kind = MailDocumentKind::tryFrom((string) $this->input('kind'));
                if ($kind === null) {
                    return;
                }

                $missing = array_values(array_diff(
                    PortableMediaCatalog::requiredSystemAssetIds(
                        $kind,
                        SignatureArtifactVersion::detect($kind, (string) $this->input('html')),
                    ),
                    array_keys($counts),
                ));
                if ($missing !== []) {
                    $validator->errors()->add(
                        'media',
                        'Im Bundle fehlen erforderliche Medien: '.implode(', ', $missing).'.',
                    );
                }
            },
        ];
    }
}
