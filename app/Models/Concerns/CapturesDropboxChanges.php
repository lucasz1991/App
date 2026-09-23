<?php

namespace App\Models\Concerns;

use App\Services\Dropbox\ChangeCapture;
use App\Services\Dropbox\SyncContext;

trait CapturesDropboxChanges
{
    // The model write and durable outbox share the SAME transaction, including
    // legacy forms which call save/updateOrCreate without a service transaction.
    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            $result = parent::save($options);
            if ($result && ! SyncContext::importing() && ($this->wasRecentlyCreated || $this->wasChanged())) {
                app(ChangeCapture::class)->record($this);
            }

            return $result;
        });
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $result = parent::delete();
            if ($result && ! SyncContext::importing()) {
                app(ChangeCapture::class)->record($this);
            }

            return $result;
        });
    }
}
