# Results

Reference run of `bash bench/run-all.sh`:
- Symfony 8.1.11, GoAopBundle 4.0.0, Go! AOP 4.0.0;
- PHP 8.5.12 NTS (Ubuntu packages) on 4 vCPUs (cloud sandbox);
- OPcache on, `opcache.jit=disable`, `opcache.file_update_protection=0`;
- Xdebug 3.5.3, for the profiles only.

Machines differ: compare the environments with each other, not the absolute numbers with your hardware.

One request (`/`): 50 orders × 5 lines, a sales report and one call into each of the 40 modules. That makes about
2,600 intercepted method calls and 950 intercepted property accesses.

## Web requests

The built-in web server (one worker, OPcache shared memory kept between requests), median time from the first line of
`public/index.php` to the response:

| `APP_ENV` | cold | warm | hot | hot, `validate_timestamps=0` | peak memory (hot) | files included |
|-----------|-----:|-----:|----:|-----------------------------:|------------------:|---------------:|
| `baseline` (no AOP, aspects do not run) | 330 ms | 103 ms | **2.8 ms** | 3.0 ms | 1.1 MB | 594 |
| `dev` | 567 ms | 176 ms | **20.9 ms** | 20.3 ms | 3.1 MB | 772 |
| `prod` | 500 ms | 134 ms | **9.0 ms** | 8.9 ms | 1.9 MB | 751 |
| `prebuilt` | built by `cache:clear` | 140 ms | **9.0 ms** | 8.5 ms | 1.9 MB | 751 |

- **cold**: no cache at all; the request compiles the container (and weaves, in `dev` and `prod`).
- **warm**: cache built by `cache:clear`, freshly started server (empty OPcache).
- **hot**: both caches filled, the steady state.

`cache:clear` from scratch: 376 ms (`baseline`), 743 ms (`prod`), 809 ms (`prebuilt`), 811 ms (`dev`). The AOP warm-up
of the 250 files of `src/` takes about 400 ms of that.

A cold request compiles the container. That process loads the service classes before GoAopBundle boots, so that one
request runs part of them unwoven: 450 instead of 800 audit records. The next request is fully woven. Production
deployments run `cache:clear`, which builds the container and the AOP cache together.

## Pod start

First request of a freshly started server on a cache built by `cache:clear` in the `prebuilt` environment:

| Variant | first request | hot | files included |
|---------|--------------:|----:|---------------:|
| `prebuilt` | 145 ms | 9.2 ms | 751 |
| + `opcache.file_cache` primed by `cache:warmup:opcache` | 34 ms | 9.4 ms | 751 |
| + `opcache.preload` ([config/preload.php](config/preload.php)) | 31 ms | 7.9 ms | 186 |
| + both | **12.9 ms** | 7.9 ms | 186 |
| `baseline`, no AOP | 98 ms | 3.2 ms | 594 |
| `baseline` + preload + primed file cache | 5.0 ms | 2.1 ms | 191 |

The Docker image of this repository combines both. A new container serves its first request in 10–15 ms.

## Where the time goes

Share of the profiled hot request in the `prebuilt` environment (Xdebug profiler; it slows call-heavy code down the
most, so interception is overstated):

| Bucket | share |
|--------|------:|
| runtime interception (joinpoints, interceptors) | 35 % |
| application code | 25 % |
| Symfony (kernel, container, HttpKernel), of which the `ArrayAdapter` cache pool of `CachingAspect` is ~15 % of the request | 18 % |
| advice bodies | 8 % |
| class loading + cache metadata (framework) | 6 % |
| Composer autoloader | 5 % |
| framework kernel + container | 0.7 % |
| GoAopBundle | 0.1 % |

The hot request of `prebuilt` costs 6 ms more than `baseline`. The interception itself takes roughly 1.5–2 ms of that:
about 0.3 µs per intercepted call, see the per-call numbers of [goaop/shop-demo](https://github.com/goaop/shop-demo).
The rest is the work the advices do: the caching aspect stores its results serialized in a Symfony cache pool (the
`ArrayAdapter` serializes by default), and the other aspects keep timers and records. In the framework-only demo
([goaop/shop-demo](https://github.com/goaop/shop-demo)), with an array as the cache, the same workload costs 3.3 ms
more than the plain code.

File-system calls of one hot request:

| Call | `prebuilt` | `prod` | `dev` | `baseline` |
|------|-----------:|-------:|------:|-----------:|
| `filemtime` | 2 | 2 | 468 | 2 |
| `filesize` | 0 | 0 | 233 | 0 |
| `file_get_contents` | 2 | 2 | 99 | 2 |
| `file_exists` (Composer PSR-4) | 479 | 479 | 603 | 523 |
