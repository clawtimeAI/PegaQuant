<?php
return  [
    'default' => 'pgsql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            // 'host'        => '10.0.0.7',
            'host'        => '127.0.0.1',
            'port'        => '3306',
            // 'database'    => 'track',
            'database'    => 'quantitas',
            'username'    => 'root',
            'password'    => 'qweqwe',
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
            'prefix'      => '',
            'strict'      => true,
            'engine'      => null,
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
        'pgsql' => [
            'driver'      => 'pgsql',
            'host'        => '127.0.0.1',
            'port'        => '5432',
            'database'    => 'btc_quant',
            'username'    => 'postgres',
            'password'    => 'qweqwe123',
            'charset'     => 'utf8',
            'prefix'      => '',
            'schema'      => 'public',
            'sslmode'     => 'prefer',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
