<?php

use App\Kernel;

// Start of the request, for the timings of the benchmark responses (ShopController).
// Symfony Runtime includes this file twice: the first include wins
defined('APP_START_NS') || define('APP_START_NS', hrtime(true));

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
