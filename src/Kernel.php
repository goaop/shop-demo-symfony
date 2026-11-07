<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?int $bootedAt = null;

    public function boot(): void
    {
        parent::boot();
        $this->bootedAt ??= hrtime(true);
    }

    /**
     * hrtime() when the kernel (and with it the aspect kernel of GoAopBundle) finished booting
     */
    public function bootedAt(): int
    {
        return $this->bootedAt ?? hrtime(true);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        // dev: debug weaving, prod: production weaving, prebuilt: production + PREBUILT_CACHE,
        // baseline: production without GoAopBundle (the aspects do not run), test: functional tests
        return ['prod', 'dev', 'prebuilt', 'baseline', 'test'];
    }
}
