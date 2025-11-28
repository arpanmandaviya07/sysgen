<?php

namespace Arpanmandaviya\SystemBuilder\Providers;

use Illuminate\Support\ServiceProvider;
use Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        try {
            $this->commands([
                BuildSystemCommand::class,
            ]);
        } catch (\Exception $e) {
            info('SystemBuilderServiceProvider: Command registration failed - ' . $e->getMessage());
        }
    }
}
