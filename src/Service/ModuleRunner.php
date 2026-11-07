<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ModuleHandler;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Calls every module once: loads the classes of a larger application (services, repositories, validators, DTOs
 * and the library classes they use), like a real request does through its container
 */
class ModuleRunner
{
    /**
     * @param iterable<ModuleHandler> $modules
     */
    public function __construct(
        #[AutowireIterator('app.module')]
        private readonly iterable $modules,
    ) {}

    /**
     * @return array{modules: int, checksum: int}
     */
    public function run(int $seed): array
    {
        $count    = 0;
        $checksum = 0;
        foreach ($this->modules as $module) {
            $count++;
            $checksum += $module->handle($seed);
        }

        return ['modules' => $count, 'checksum' => $checksum];
    }
}
