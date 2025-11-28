<?php

namespace Arpanmandaviya\SystemBuilder\Providers;

use Illuminate\Support\ServiceProvider;
use Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        try {
            // Register the single JSON-based build command
            $this->commands([
                BuildSystemCommand::class,
            ]);
        } catch (\Exception $e) {
            info('SystemBuilderServiceProvider: Command registration failed - ' . $e->getMessage());
        }
    }

    public function boot()
    {
        // Publish stubs for user modification
        $this->publishes([
            __DIR__.'/../../resources/stubs' => base_path('stubs/system-builder'),
        ], 'system-builder-stubs');
    }
}