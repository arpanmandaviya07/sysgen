<?php


namespace Arpanmandaviya\SystemBuilder;


use Illuminate\Support\ServiceProvider;
use Arpan\SystemBuilder\Commands\BuildSystemCommand;


class GeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand::class,
            ]);


            $this->publishes([
                __DIR__ . '/../resources/stubs' => base_path('system-builder-stubs'),
            ], 'system-builder-stubs');
        }
    }


    public function register()
    {
// merge config later if needed
    }
}