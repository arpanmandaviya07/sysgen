<?php

namespace Arpanmandaviya\SystemBuilder\Providers;

use Illuminate\Support\ServiceProvider;
// Use the new interactive command
use Arpanmandaviya\SystemBuilder\Commands\InteractiveBuildCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        try {
            $this->commands([
                InteractiveBuildCommand::class,
            ]);
        } catch (\Exception $e) {
            // Use log instead of info if this is in a package
            info('SystemBuilderServiceProvider: Command registration failed - ' . $e->getMessage());
        }
    }

    public function boot()
    {
        // 1. Publish stubs for user modification
        $this->publishes([
            __DIR__.'/../../resources/stubs' => base_path('stubs/system-builder'),
        ], 'system-builder-stubs');
    }
}