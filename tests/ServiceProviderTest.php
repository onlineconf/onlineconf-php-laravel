<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

final class ServiceProviderTest extends TestCase
{
    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame(
            ['dir' => null, 'module' => null, 'check_interval' => 5, 'log_channel' => null, 'config_override' => true, 'map' => []],
            $this->config()->get('onlineconf'),
        );
    }
}
