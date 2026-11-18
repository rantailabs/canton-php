<?php

return [

    // JSON Ledger API address of your participant node
    'url' => env('CANTON_URL', 'http://localhost:3975'),

    // seconds to wait for the node before giving up
    'timeout' => env('CANTON_TIMEOUT', 30),

    // "shared-secret" for cn-quickstart LocalNet, "oauth2" for DevNet, TestNet and MainNet
    'auth' => env('CANTON_AUTH', 'shared-secret'),

    'audience' => env('CANTON_AUDIENCE', 'https://canton.network.global'),

    // shared-secret
    'user' => env('CANTON_USER', 'ledger-api-user'),
    'secret' => env('CANTON_SECRET', 'unsafe'),

    // oauth2 (client credentials grant)
    'token_url' => env('CANTON_TOKEN_URL'),
    'client_id' => env('CANTON_CLIENT_ID'),
    'client_secret' => env('CANTON_CLIENT_SECRET'),
    'scope' => env('CANTON_SCOPE'),

];
