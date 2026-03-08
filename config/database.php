<?php

return [

    'default' => 'typo3',

    'connections' => [

        'typo3' => [
            'driver' => 'mysql',
            'host' => env('TYPO3_DB_HOST', '127.0.0.1'),
            'port' => env('TYPO3_DB_PORT', '3306'),
            'database' => env('TYPO3_DB_DATABASE', 'typo3'),
            'username' => env('TYPO3_DB_USERNAME', 'root'),
            'password' => env('TYPO3_DB_PASSWORD', ''),
            'charset' => env('TYPO3_DB_CHARSET', 'utf8mb4'),
            'collation' => env('TYPO3_DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('TYPO3_MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

    ],

];
