<?php

namespace App\Support\Privacy;

use App\Models\User;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Carbon;

class EmployeeDatabaseSessionHandler extends DatabaseSessionHandler
{
    private ?int $preservedActivity = null;

    public function write($sessionId, $data): bool
    {
        $this->preservedActivity = null;
        if ($this->container && ! SessionActivity::isInteraction($this->container->make('request'))) {
            $activity = $this->getQuery()->where('id', $sessionId)->value('last_activity');
            $this->preservedActivity = $activity === null ? null : (int) $activity;
        }

        try {
            return parent::write($sessionId, $data);
        } finally {
            $this->preservedActivity = null;
        }
    }

    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);
        if ($this->preservedActivity !== null) {
            $payload['last_activity'] = $this->preservedActivity;
        }

        return $payload;
    }

    protected function expired($session)
    {
        if (! isset($session->last_activity)) {
            return false;
        }

        $user = isset($session->user_id) ? User::find($session->user_id) : null;
        $minutes = SessionLifetimePolicy::minutes($user);

        return $session->last_activity <= Carbon::now()->subMinutes($minutes)->getTimestamp();
    }

    public function gc($lifetime): int
    {
        // Reads enforce the current account's timeout. Collection must retain
        // all still-valid employee and administrator logins.
        return parent::gc(max((int) $lifetime, (int) config('session.employee_lifetime', 4320) * 60,
            (int) config('session.administrator_lifetime', 4320) * 60));
    }
}
