<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $legacyFields = [
        'phone', 'mobile', 'street', 'postal_code', 'city', 'country',
        'birth_date', 'personnel_nr', 'position',
    ];

    /** @var array<int, string> */
    private array $newFields = [
        'first_name', 'last_name', 'birth_place', 'birth_name', 'nationality', 'education',
        'entry_date', 'multiple_employment', 'employment_type', 'weekly_working_hours',
        'additional_information', 'tax_identification_number', 'social_security_number',
        'iban', 'health_insurance', 'tax_class', 'children_count', 'religion',
        'compensation_type', 'compensation_amount',
    ];

    public function up(): void
    {
        foreach ($this->legacyFields as $field) {
            if (Schema::hasColumn('user_profiles', $field) && ! Schema::hasColumn('user_profiles', $field.'_secure')) {
                Schema::table('user_profiles', function (Blueprint $table) use ($field) {
                    $table->longText($field.'_secure')->nullable();
                });
            }
        }

        foreach ($this->newFields as $field) {
            if (! Schema::hasColumn('user_profiles', $field)) {
                Schema::table('user_profiles', function (Blueprint $table) use ($field) {
                $table->longText($field)->nullable();
                });
            }
        }

        $fieldsToEncrypt = array_values(array_filter(
            $this->legacyFields,
            fn (string $field): bool => Schema::hasColumn('user_profiles', $field)
                && Schema::hasColumn('user_profiles', $field.'_secure')
        ));

        DB::table('user_profiles')->orderBy('id')->chunkById(100, function ($profiles) use ($fieldsToEncrypt): void {
            foreach ($profiles as $profile) {
                $updates = [];
                foreach ($fieldsToEncrypt as $field) {
                    $value = $profile->{$field};
                    $updates[$field.'_secure'] = $value === null || $value === ''
                        ? null
                        : $this->encryptUnlessEncrypted((string) $value);
                }
                if ($updates !== []) {
                    DB::table('user_profiles')->where('id', $profile->id)->update($updates);
                }
            }
        });

        foreach ($this->legacyFields as $field) {
            if (Schema::hasColumn('user_profiles', $field)) {
                Schema::table('user_profiles', function (Blueprint $table) use ($field) {
                    $table->dropColumn($field);
                });
            }
        }

        foreach ($this->legacyFields as $field) {
            if (Schema::hasColumn('user_profiles', $field.'_secure') && ! Schema::hasColumn('user_profiles', $field)) {
                $this->renameSecureColumn($field);
            }
        }
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            foreach ($this->legacyFields as $field) {
                if ($field === 'birth_date') {
                    $table->date($field.'_legacy')->nullable();
                } else {
                    $table->string($field.'_legacy', in_array($field, ['street', 'city', 'country'], true) ? 255 : 100)->nullable();
                }
            }
        });

        DB::table('user_profiles')->orderBy('id')->chunkById(100, function ($profiles): void {
            foreach ($profiles as $profile) {
                $updates = [];
                foreach ($this->legacyFields as $field) {
                    $value = $profile->{$field};
                    $updates[$field.'_legacy'] = $value === null || $value === '' ? null : Crypt::decryptString($value);
                }
                DB::table('user_profiles')->where('id', $profile->id)->update($updates);
            }
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(array_merge($this->legacyFields, $this->newFields));
        });

        foreach ($this->legacyFields as $field) {
            Schema::table('user_profiles', function (Blueprint $table) use ($field) {
                $table->renameColumn($field.'_legacy', $field);
            });
        }
    }

    private function encryptUnlessEncrypted(string $value): string
    {
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private function renameSecureColumn(string $field): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('user_profiles', function (Blueprint $table) use ($field) {
                $table->renameColumn($field.'_secure', $field);
            });

            return;
        }

        // XAMPP deployments can use a MariaDB release that does not yet
        // understand MySQL's RENAME COLUMN syntax.
        DB::statement(sprintf(
            'ALTER TABLE `user_profiles` CHANGE `%s_secure` `%s` LONGTEXT NULL',
            $field,
            $field
        ));
    }
};
