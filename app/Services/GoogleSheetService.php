<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;

class GoogleSheetService
{
    protected $googleSheet;

    public function __construct()
    {
        $client = new Client();

        $client->setAuthConfig(
            storage_path('app/google/service-account.json')
        );

        $client->addScope(Sheets::SPREADSHEETS);
        $client->addScope(Sheets::DRIVE);

        $this->googleSheet = new Sheets($client);
    }
}