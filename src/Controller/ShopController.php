<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\AuditLog;
use App\Infrastructure\Metrics;
use App\Infrastructure\TransactionManager;
use App\Kernel;
use App\Service\CheckoutScenario;
use App\Service\ModuleRunner;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The benchmark request: the checkout workload and the modules, answered with the business result, the counters of
 * the aspects and the cost of the request (timings, memory, included files, OPcache)
 */
final class ShopController extends AbstractController
{
    public function __construct(
        private readonly CheckoutScenario $checkout,
        private readonly ModuleRunner $modules,
        private readonly AuditLog $auditLog,
        private readonly Metrics $metrics,
        private readonly TransactionManager $transactions,
        private readonly NotificationService $notifications,
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/', name: 'shop')]
    public function shop(#[MapQueryParameter] int $orders = 50, #[MapQueryParameter] int $seed = 42): JsonResponse
    {
        return $this->report(['checkout' => $this->checkout->run($orders), 'modules' => $this->modules->run($seed)]);
    }

    #[Route('/checkout', name: 'checkout')]
    public function checkout(#[MapQueryParameter] int $orders = 50): JsonResponse
    {
        return $this->report(['checkout' => $this->checkout->run($orders)]);
    }

    #[Route('/modules', name: 'modules')]
    public function modules(#[MapQueryParameter] int $seed = 42): JsonResponse
    {
        return $this->report(['modules' => $this->modules->run($seed)]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function report(array $result): JsonResponse
    {
        $end     = hrtime(true);
        $start   = \defined('APP_START_NS') ? (int) \constant('APP_START_NS') : $end;
        $booted  = $this->kernel instanceof Kernel ? $this->kernel->bootedAt() : $end;
        $opcache = \function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return $this->json([
            'env'      => $this->kernel->getEnvironment(),
            'debug'    => $this->kernel->isDebug(),
            'response' => $result + [
                'stats' => [
                    'audit_records' => $this->auditLog->count(),
                    'cache_lookups' => $this->metrics->counter('cache.lookups'),
                    'cache_misses'  => $this->metrics->counter('cache.misses'),
                    'commits'       => $this->transactions->commits,
                    'repo_calls'    => $this->metrics->timedCalls(),
                    'security'      => $this->metrics->counter('security.checks'),
                    'notifications' => $this->notifications->sent(),
                ],
            ],
            'timing'   => [
                // from the first line of public/index.php: autoloader, runtime, kernel boot (aspect kernel included)
                'boot_ms'   => ($booted - $start) / 1e6,
                'handle_ms' => ($end - $booted) / 1e6,
                'total_ms'  => ($end - $start) / 1e6,
            ],
            'memory_peak_kb' => intdiv(memory_get_peak_usage(), 1024),
            'included_files' => \count(get_included_files()),
            'opcache'        => \is_array($opcache) ? [
                'enabled' => $opcache['opcache_enabled'],
                'scripts' => $opcache['opcache_statistics']['num_cached_scripts'] ?? null,
                'hits'    => $opcache['opcache_statistics']['hits'] ?? null,
                'misses'  => $opcache['opcache_statistics']['misses'] ?? null,
            ] : null,
        ]);
    }
}
