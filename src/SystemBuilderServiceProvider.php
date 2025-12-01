<?php

namespace Arpanmandaviya\SystemBuilder;

use Arpanmandaviya\SystemBuilder\Commands\SystemControllerCommand;
use Arpanmandaviya\SystemBuilder\Commands\SystemModelCommand;
use Illuminate\Support\ServiceProvider;
use Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand;
use Arpanmandaviya\SystemBuilder\Commands\InteractiveBuildCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildSystemCommand::class,
                InteractiveBuildCommand::class,
                SystemControllerCommand::class,
                SystemModelCommand::class,
            ]);
        }
    }
}
