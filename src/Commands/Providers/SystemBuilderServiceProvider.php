<?php

namespace Arpanmandaviya\SystemBuilder\Providers;

use Illuminate\Support\ServiceProvider;
use Arpan\SystemBuilder\Console\SystemBuildCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            SystemBuildCommand::class,
        ]);
    }
}
