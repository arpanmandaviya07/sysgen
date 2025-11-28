<?php

namespace Arpanmandaviya\SystemBuilder;

use Illuminate\Support\ServiceProvider;
use Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand;

class GeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        try {
            $this->commands([
                BuildSystemCommand::class,
            ]);
        } catch (\Exception $e) {
            info('SystemBuilder: Command registration failed - ' . $e->getMessage());
        }
    }

    public function boot()
    {
        try {
            // boot logic if needed
        } catch (\Exception $e) {
            info('SystemBuilder: Boot failed - ' . $e->getMessage());
        }
    }
}
