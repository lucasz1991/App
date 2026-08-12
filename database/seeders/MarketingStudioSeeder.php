<?php

namespace Database\Seeders;

use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Seeder;
use RuntimeException;

final class MarketingStudioSeeder extends Seeder
{
    public function run(
        MarketingStudioService $studio,
        MarketingTemplateFactory $templates,
    ): void {
        $actor = $this->actor();

        foreach ($templates->starterDefinitions() as $definition) {
            $templateKey = data_get($definition, 'shared_content.template_key');
            if (! is_string($templateKey) || trim($templateKey) === '') {
                throw new RuntimeException('Das Marketing-Startmotiv besitzt keinen gültigen template_key.');
            }

            $exists = MarketingCreative::withTrashed()
                ->where('shared_content->template_key', $templateKey)
                ->exists();
            if ($exists) {
                continue;
            }

            $studio->createFromTemplate(
                $templates->typeForKey($templateKey),
                $actor,
                $templateKey,
            );
        }
    }

    private function actor(): User
    {
        $admins = User::query()->where('role', 'admin');
        $preferredEmail = trim((string) config('marketing.seed_admin_email', ''));
        if ($preferredEmail !== '') {
            $preferred = (clone $admins)->where('email', $preferredEmail)->first();
            if ($preferred) {
                return $preferred;
            }
        }

        $admin = $admins->orderBy('id')->first();
        if (! $admin) {
            throw new RuntimeException('Das Marketing-Studio kann nicht initialisiert werden: Es ist kein Administrator als Ersteller vorhanden.');
        }

        return $admin;
    }
}
