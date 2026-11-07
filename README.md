# Go! AOP Symfony shop demo

A Symfony 8.1 shop application built with [GoAopBundle](https://github.com/goaop/goaop-symfony-bundle) 4 and
[Go! AOP](https://github.com/goaop/framework) 4. Logging, caching, transactions, monitoring, auditing and security
live in six aspects, which are ordinary autowired services. The services they apply to stay plain business code.

The same application is a reproducible benchmark of the bundle in Symfony's environments: debug, production and
prebuilt cache, cold, warm and hot, with preloading and a primed OPcache file cache in a production-style Docker image.
The measured numbers are in [RESULTS.md](RESULTS.md). The framework-only version of the same shop, without Symfony, is
[goaop/shop-demo](https://github.com/goaop/shop-demo).

```bash
composer install
composer serve                    # http://127.0.0.1:8080/, the dev environment
php bin/phpunit
```

## How the bundle is wired in

Four steps, all of them visible in this repository:

1. **`composer require goaop/goaop-symfony-bundle`**, then make it the **first** bundle of
   [config/bundles.php](config/bundles.php). Classes loaded before the aspect kernel boots can not be woven, so the
   bundle refuses to start when another bundle is registered before it. Symfony Flex appends new bundles at the end,
   so this move is needed once.
2. **Configure the include paths** in [config/packages/go_aop.yaml](config/packages/go_aop.yaml): `src/` here, plus
   `PREBUILT_CACHE` for the `prebuilt` environment.
3. **Write aspects** as services that implement `Go\Aop\Aspect` ([src/Aspect/](src/Aspect)). Autoconfiguration
   registers them, autowiring injects their dependencies, and an aspect is created on the first call of one of its
   advices.
4. **Deploy with `cache:clear`**, which compiles the container and weaves the whole application. In non-debug
   environments it also writes the preload script of the AOP cache.

`bin/console debug:aspect` lists the aspects and their advisors, and
`bin/console debug:advisor --advisor='App\Aspect\CachingAspect->aroundCacheable'` lists the methods that advisor
matches.

## The application

| Part | Where | Woven |
|------|-------|:-----:|
| Domain | `src/Domain/`: products, customers, orders, order lines, money, enums | `Product` (property advice) |
| Repositories | `src/Repository/`: in-memory products, customers, orders | yes |
| Services | `src/Service/`: catalog, pricing, discounts, tax, inventory, payment, notifications, reports, the checkout scenario | yes |
| Events | `src/Event/OrderPlaced`, dispatched through Symfony's event dispatcher; `NotificationService` is an `#[AsEventListener]` | no |
| Controller | `src/Controller/ShopController`: `/`, `/checkout`, `/modules` | no |
| Infrastructure | `src/Infrastructure/`: in-memory audit log, metrics, transactions | no |
| Generated modules | `src/Module/` (`bin/generate.php [modules=40]`, run on `composer install`): per module a handler and a repository (woven), a validator (a service) and a DTO and an event (value objects) | partly |
| Library code | `lib/` (generated with the modules): stands in for `vendor/`, outside the include paths | no |

The module handlers implement `App\Contract\ModuleHandler`, which is tagged by `#[AutoconfigureTag]`.
`App\Service\ModuleRunner` collects them with `#[AutowireIterator]`.

The infrastructure keeps everything in memory, so the numbers show the cost of the framework, not of I/O. The caching
aspect uses a Symfony cache pool with the array adapter ([config/packages/cache.yaml](config/packages/cache.yaml)).

### Aspects

| Aspect | Advice | Pointcut | Depends on |
|--------|--------|----------|------------|
| `LoggingAspect` | `#[Before]` | `@execution(App\Attribute\Loggable)` | `AuditLog` |
| `CachingAspect` | `#[Around]` | `@execution(App\Attribute\Cacheable)` | the `aspect.cache` pool (`#[Target]`), `Metrics` |
| `TransactionAspect` | `#[Around]` | `@execution(App\Attribute\Transactional)` | `TransactionManager` |
| `MonitoringAspect` | `#[Around]` + `#[AfterThrowing]` | a named `#[Pointcut]` over the repositories, `execution(public App\Service\*->*(*))` | `Metrics`, `AuditLog` |
| `StockAuditAspect` | `#[Around]` on a property (native property hooks) | `access(public App\Domain\Product->stock)` | `AuditLog` |
| `SecurityAspect` | `#[Before]` | `execution(public App\Module\**\*Service->handle(*))` | `Metrics` |

One request to `/` places 50 orders of 5 lines, builds a sales report and calls every module once. That makes about
2,600 intercepted method calls and 950 intercepted property accesses. The JSON response carries:
- the business result;
- the counters of the aspects;
- timings: total, kernel boot including the aspect kernel, and handling;
- peak memory, included files and OPcache statistics.

[tests/Controller/ShopControllerTest.php](tests/Controller/ShopControllerTest.php) checks every counter.

### Environments

| `APP_ENV` | Kernel | GoAopBundle |
|-----------|--------|-------------|
| `dev` | debug | debug weaving: changed classes and aspects are re-woven, XDebug breakpoints work in the original files |
| `test` | debug | as `dev`, for PHPUnit |
| `prod` | no debug | production weaving: woven classes come from the cache through Composer's class map |
| `prebuilt` | no debug | production weaving with `PREBUILT_CACHE`: the cache built by `cache:clear` is trusted completely |
| `baseline` | no debug | not registered: the same application without AOP, the aspects do not run |

The environments are allowed in `App\Kernel::getAllowedEnvs()`, and `.env.<env>` sets `APP_DEBUG`.

```bash
APP_ENV=prebuilt bin/console cache:clear
APP_ENV=prebuilt php -d variables_order=EGPCS -S 127.0.0.1:8080 -t public public/index.php
```

`variables_order=EGPCS` lets PHP's built-in server hand `APP_ENV` to Symfony; PHP-FPM passes it through
`$_SERVER` anyway.

## Production-style Docker image

```bash
docker build -t shop-demo-symfony .
docker run --rm -p 8080:8080 --read-only --tmpfs /tmp shop-demo-symfony
curl http://127.0.0.1:8080/
```

The [Dockerfile](Dockerfile) builds everything at image build time:
1. an authoritative Composer class map;
2. `cache:clear` in the `prebuilt` environment: the container, the AOP cache and its `_preload.php`;
3. the OPcache file cache, with the bundle's `cache:warmup:opcache`.

The container then runs with `PREBUILT_CACHE`, `opcache.validate_timestamps=0` and preloading
([config/preload.php](config/preload.php): first the AOP preload script, then Symfony's container preload file) on a
read-only root file system. A new container serves its first request in 10–15 ms. The image uses PHP's built-in
server to stay self-contained; production setups use PHP-FPM with the same `php.ini`
([docker/opcache.ini](docker/opcache.ini)).

## Benchmarks

```bash
bash bench/run-all.sh    # ~10 minutes, writes results/
```

Requirements: PHP 8.4+ with OPcache. The profiler runs also need Xdebug installed but not enabled in `php.ini`; the
scripts load it with `-d zend_extension=xdebug` only where they need it. Every run uses `opcache.jit=disable` (the
tracing JIT of PHP 8.5.11/8.5.12 has a bug of its own) and `opcache.file_update_protection=0`, so that files written a
moment before are cached at once.

| Script | Measures |
|--------|----------|
| `bench/run-wallclock.php` | every environment × cold / warm / hot through the built-in web server, and `cache:clear` |
| `bench/run-startup.php` | the first request of a fresh server with a primed file cache and/or preloading |
| `bench/run-profiles.php` | Xdebug profiler runs (`xdebug.mode=profile`) of every environment and state |
| `bench/analyze-profile.php` | cachegrind analyzer without KCachegrind: time by subsystem (Symfony, GoAopBundle, framework, aspects, application), top functions, file-system calls |

The [CI workflow](.github/workflows/ci.yml) runs on each push:
- the tests, on PHP 8.4 and 8.5;
- one request in every environment;
- the Docker image on a read-only file system.

Run it manually (*Actions → CI → Run workflow*) to get the full benchmark as the job summary and the
`benchmark-results` artifact.

## License

MIT, see [LICENSE](LICENSE).
