<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;

class BuildSystemCommand extends Command
{
    protected $signature = 'system:build {--json= : Path to JSON definition} {--force : Overwrite existing files} {--module= : Optional module/directory prefix}';
    protected $description = 'Generate migrations, models, controllers, and views from a minimal JSON schema.';

    public function handle()
    {
        $jsonPath = $this->option('json') ?: storage_path('app/system.json');

        if (!file_exists($jsonPath)) {
            $this->error("JSON file not found: {$jsonPath}");
            return 1;
        }

        $definition = json_decode(file_get_contents($jsonPath), true);

        if (!$definition || !is_array($definition)) {
            $this->error('Invalid JSON schema. The file must contain a main array of build commands.');
            return 1;
        }

        try {
            // Instantiate the builder with the raw definition
            $builder = new SystemBuilder($definition, base_path(), $this->option('force'), $this->option('module'));
            $builder->setOutput($this->output);
            $builder->build();

            $this->info('✅ System generation complete.');
            $this->comment('✨ Don\'t forget to run "php artisan migrate" and setup your routes.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}