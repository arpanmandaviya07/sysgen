<?php

namespace Arpanmandaviya\SystemBuilder;

use App\Console\Commands\SystemControllerCommand;
use App\Console\Commands\SystemModelCommand;
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
