<?php

// opcache.preload=config/preload.php (prod, prebuilt and baseline; APP_ENV selects the cache).
// The preload script of the AOP cache comes first: it hands the woven class map to Composer, so the container
// preload file below preloads the woven classes of the services, not the original ones.
$cacheDir = dirname(__DIR__).'/var/cache/'.($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'prebuilt');

if (is_file($cacheDir.'/aop/_preload.php')) {
    require $cacheDir.'/aop/_preload.php';
}
foreach (glob($cacheDir.'/*.preload.php') ?: [] as $file) {
    require $file;
}
