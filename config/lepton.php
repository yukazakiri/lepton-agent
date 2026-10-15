<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    | circle: shell out to `circle` + `arc-canteen` binaries via Symfony Process
    | developer / circle-rest: pure HTTP REST API via Circle Developer-Controlled Wallets
    | fake: in-memory ledger for tests / offline demo (no chain touch)
    */
    'default' => env('LEPTON_DRIVER', env('APP_ENV') === 'testing' ? 'fake' : 'circle'),

    'developer' => [
        'api_key' => env('LEPTON_CIRCLE_API_KEY', env('CIRCLE_API_KEY')),
        'entity_secret' => env('LEPTON_ENTITY_SECRET', env('CIRCLE_ENTITY_SECRET')),
        'wallet_set_id' => env('LEPTON_WALLET_SET_ID', env('CIRCLE_WALLET_SET_ID')),
        'base_url' => env('LEPTON_CIRCLE_API_URL', env('CIRCLE_API_URL', 'https://api.circle.com/v1/w3s')),
    ],

    'circle' => [
        'bin' => env('LEPTON_CIRCLE_BIN', 'circle'),
        'timeout' => (int) env('LEPTON_CLI_TIMEOUT', 60),
    ],

    'arc' => [
        'bin' => env('LEPTON_ARC_BIN', 'arc-canteen'),
        'chain' => env('LEPTON_CHAIN', 'ARC-TESTNET'),
        // 5042002 = ARC-TESTNET, 5042 = ARC mainnet
        'chain_id' => (int) env('LEPTON_CHAIN_ID', 5042002),
        'treasury' => env('LEPTON_TREASURY_ADDRESS'),
        // Optional explicit RPC override, else resolved via `arc-canteen rpc-url`
        'rpc_url' => env('LEPTON_RPC_URL'),
        'timeout' => (int) env('LEPTON_CLI_TIMEOUT', 60),
    ],

    // USDC uses 6 decimals on Circle side, 18 decimals as Arc native gas.
    // Package always works in integer base units internally, never floats.
    'usdc_decimals' => 6,

    // When true, transfers are validated but never broadcast (adds --estimate).
    'dry_run' => (bool) env('LEPTON_DRY_RUN', false),

    // When false, raw circle commands via lepton:circle or runCircle() are blocked.
    'allow_raw_commands' => (bool) env('LEPTON_ALLOW_RAW_CIRCLE', false),

    'explorer' => [
        'testnet' => env('LEPTON_EXPLORER_TESTNET', 'https://testnet.arcscan.app/tx/'),
        'mainnet' => env('LEPTON_EXPLORER_MAINNET', 'https://arcscan.app/tx/'),
    ],
];
