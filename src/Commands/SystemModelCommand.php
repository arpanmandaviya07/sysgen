<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class SystemModelCommand extends Command
{
    protected $signature = 'system:model
                            {name : Model name (e.g., User, Invoice)}
                            {--force : Overwrite existing model if exists}';

    protected $description = 'Generate a clean Laravel model with smart relationship helpers and optional table detection.';

    public function handle()
    {
        $input = $this->argument('name');
        $model = Str::studly($input);
        $table = Str::snake(Str::plural($model));

        $this->newLine();
        $this->info("📦 Creating Model: {$model}");
        $this->newLine();

        $modelPath = app_path("Models/{$model}.php");

        if (File::exists($modelPath) && !$this->option('force')) {
            if (!$this->confirm("⚠ Model already exists. Overwrite?", false)) {
                $this->warn("❌ Cancelled.");
                return Command::FAILURE;
            }
        }

        $tableExists = Schema::hasTable($table);

        if (!$tableExists) {
            if ($this->confirm("🛠 Table '{$table}' does not exist. Create migration?", true)) {
                $this->call('make:migration', [
                    'name' => "create_{$table}_table",
                ]);
            }
        } else {
            $this->info("✔ Using existing table: {$table}");
        }

        $relationships = $this->askForRelationships($model);

        $this->createModelFile($model, $table, $relationships);

        $this->newLine();
        $this->info("📁 Model created: app/Models/{$model}.php");
        $this->info("✅ Model generation completed successfully!");
        $this->newLine();

        return Command::SUCCESS;
    }

    protected function askForRelationships(string $model)
    {
        $relations = [];
        $existingModels = $this->getExistingModels();

        while ($this->confirm("➕ Add relationship?", false)) {
            $type = $this->choice("📌 Select relationship type", [
                'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
            ]);

            $target = $this->choice("🔗 Select related model", $existingModels);

            $relations[] = compact('type', 'target');
        }

        return $relations;
    }

    protected function getExistingModels()
    {
        $path = app_path('Models');

        return collect(File::files($path))
            ->map(fn($f) => pathinfo($f->getFilename(), PATHINFO_FILENAME))
            ->values()
            ->toArray();
    }

    protected function createModelFile($model, $table, $relationships)
    {
        $namespace = "App\\Models";

        $relationMethods = "";

        foreach ($relationships as $rel) {
            $method = match ($rel['type']) {
                'belongsTo', 'hasOne' => Str::camel(Str::singular($rel['target'])),
                'hasMany', 'belongsToMany' => Str::camel(Str::plural($rel['target'])),
                default => Str::camel($rel['target']),
            };

            $relationMethods .= "
    public function {$method}()
    {
        return \$this->{$rel['type']}({$rel['target']}::class);
    }\n";
        }

        $template = <<<PHP
<?php

namespace $namespace;

use Illuminate\Database\Eloquent\Model;

class $model extends Model
{
    protected \$table = '$table';

    protected \$guarded = [];

$relationMethods}
PHP;

        File::ensureDirectoryExists(app_path('Models'));
        file_put_contents(app_path("Models/{$model}.php"), $template);
    }
}
