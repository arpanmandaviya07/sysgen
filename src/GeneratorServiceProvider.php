<?php

namespace Arpanmandaviya\SystemBuilder;

use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            \Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand::class,
        ]);
    }

    public function boot()
    {
        // boot logic here
    }
}
