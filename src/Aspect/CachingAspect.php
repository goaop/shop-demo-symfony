<?php

declare(strict_types=1);

namespace App\Aspect;

use App\Infrastructure\Metrics;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Attribute\Around;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Caches the result of #[Cacheable] methods in a Symfony cache pool (config/packages/cache.yaml)
 */
final class CachingAspect implements Aspect
{
    public function __construct(
        #[Target('aspect.cache')]
        private readonly CacheInterface $cache,
        private readonly Metrics $metrics,
    ) {}

    #[Around('@execution(App\Attribute\Cacheable)')]
    public function aroundCacheable(MethodInvocation $invocation): mixed
    {
        $method = $invocation->getMethod();
        $key    = $method->class . '::' . $method->name;
        foreach ($invocation->getArguments() as $argument) {
            $key .= ':' . (\is_scalar($argument) ? $argument : get_debug_type($argument));
        }
        $this->metrics->increment('cache.lookups');

        // Cache keys may not contain "\" or ":": hash the readable key
        return $this->cache->get(hash('xxh128', $key), function () use ($invocation): mixed {
            $this->metrics->increment('cache.misses');

            return $invocation->proceed();
        });
    }
}
