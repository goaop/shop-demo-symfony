<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Build the test container and the AOP cache in a separate process. The process that compiles the container loads
// the service classes before GoAopBundle boots, so in that process they would run unwoven: without this, the first
// test run after a change of the configuration (or on a clean checkout) would see no aspects.
passthru(sprintf('%s %s cache:warmup --env=test --quiet', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__).'/bin/console')), $exitCode);
if ($exitCode !== 0) {
    exit($exitCode);
}
