<?php


namespace Arpanmandaviya\SystemBuilder\Commands;


use Illuminate\Console\Command;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;


class BuildSystemCommand extends Command
{
    protected $signature = 'system:build {--json= : Path to JSON definition} {--force : Overwrite existing files}';
    protected $description = 'Generate migrations, models, controllers and views from JSON schema';


    public function handle()
    {
        $jsonPath = $this->option('json') ?: storage_path('app/system.json');


        if (!file_exists($jsonPath)) {
            $this->error("JSON file not found: {$jsonPath}");
            return 1;
        }


        $definition = json_decode(file_get_contents($jsonPath), true);
        if (!$definition || !isset($definition['tables'])) {
            $this->error('Invalid JSON schema. Make sure it has a "tables" array.');
            return 1;
        }


        $builder = new SystemBuilder($definition, base_path(), $this->option('force'));
        $builder->build();


        $this->info('✅ System generation complete.');
        return 0;
    }
}