<?php

namespace Arhx\TelegramLogChannel\Tests;

use Arhx\TelegramLogChannel\TelegramLogChannelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            TelegramLogChannelServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default configuration
    }
}
