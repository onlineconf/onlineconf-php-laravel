<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Support\ServiceProvider;

final class OnlineconfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onlineconf.php', 'onlineconf');
    }
}
