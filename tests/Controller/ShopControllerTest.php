<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Product;
use App\Module\Module01\Module01Service;
use App\Repository\ProductRepository;
use App\Service\OrderService;
use Go\Aop\Proxy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The request of the demo, in the test environment (GoAopBundle in debug mode): the aspects did their work
 */
final class ShopControllerTest extends WebTestCase
{
    public function testShopRequestRunsEveryAspect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['placed' => 47, 'declined' => 3, 'report' => ['paid' => 47, 'failed' => 3, 'revenue' => 4548713]], $data['response']['checkout']);
        self::assertSame(40, $data['response']['modules']['modules']);
        self::assertSame(
            [
                // LoggingAspect (350 calls of #[Loggable] methods) + StockAuditAspect (450 writes of Product::$stock)
                'audit_records' => 800,
                // CachingAspect: 301 calls of #[Cacheable] methods, 37 distinct keys
                'cache_lookups' => 301,
                'cache_misses'  => 37,
                // TransactionAspect: one commit per order
                'commits'       => 50,
                // MonitoringAspect: every repository call
                'repo_calls'    => 820,
                // SecurityAspect: once per module
                'security'      => 40,
                // NotificationService, an #[AsEventListener] of OrderPlaced
                'notifications' => 50,
            ],
            $data['response']['stats'],
        );
    }

    public function testServicesAndEntitiesAreWoven(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(Proxy::class, $container->get(OrderService::class));
        self::assertInstanceOf(Proxy::class, $container->get(ProductRepository::class));
        self::assertInstanceOf(Proxy::class, $container->get(Module01Service::class));
        self::assertTrue(is_subclass_of(Product::class, Proxy::class), 'Product is woven for its property advice');
    }
}
