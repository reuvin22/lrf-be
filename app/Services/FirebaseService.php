<?php

namespace App\Services;

namespace App\Services;

use Kreait\Firebase\Factory;

class FirebaseService
{
    public function storage()
    {
        $factory = (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->withDefaultStorageBucket(config('services.firebase.storage_bucket'));

        return $factory->createStorage();
    }
}
