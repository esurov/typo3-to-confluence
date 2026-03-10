#!/usr/bin/env php
<?php

define('LARAVEL_START', microtime(true));

$pharPath = 'phar://'.__FILE__;

require $pharPath.'/vendor/autoload.php';

$app = require $pharPath.'/bootstrap/app.php';

// If no artisan command is specified, default to our export command
$args = $_SERVER['argv'];
if (! isset($args[1]) || str_starts_with($args[1], '-')) {
    array_splice($args, 1, 0, ['app:export-typo3-to-confluence']);
}

$status = $app->handleCommand(new Symfony\Component\Console\Input\ArgvInput($args));

exit($status);

__HALT_COMPILER();
