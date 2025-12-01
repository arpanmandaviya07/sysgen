<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class SystemModelCommand extends Command
{
    protected $signature = 'system:model
                            {name : Model name (e.g., User, Invoice)}
                            {--force : Overwrite existing model if exists}
                            {--help : View detailed usage instructions}';

    protected $description = 'Generate a model with optional table mapping, fields, and relationships.';

    public function handle()
    {
        $model = Str::studly($this->argument('name'));
        $table = Str::snake(Str::plural($model));

        $this->info("📦 Creating Model: $model");

        // Check if model already exists
        $modelPath = app_path("Models/$model.php");
        if (File::exists($modelPath) && !$this->option('force')) {
            if (!$this->confirm("Model already exists. Overwrite?")) {
                $this->warn("❌ Operation cancelled.");
                return 0;
            }
        }

        // Detect if table exists
        $tableExists = Schema::hasTable($table);

        if ($tableExists) {
            $choice = $this->choice(
                "Table '$table' already exists. Which action?",
                ['Use Existing Table', 'Create New Table'],
                0
            );

            if ($choice === 'Create New Table') {
                $table = $this->ask("Enter new table name", $table);
            } else {
                $this->info("✔ Using existing table: $table");
            }
        } else {
            if ($this->confirm("Table '$table' does not exist. Create migration?", true)) {
                $this->call('make:migration', [
                    'name' => "create_{$table}_table",
                ]);
                $this->info("🛠 Migration created: create_{$table}_table");
            }
        }

        // Ask for relationship setup
        $relationships = $this->askForRelationships($model);

        // Generate Model File
        $this->createModelFile($model, $table, $relationships);

        $this->info("✅ Model generation complete!");
        return Command::SUCCESS;
    }

    protected function displayHelp()
    {
        $this->info("📘 System Model Generator — Help Guide");
        $this->line("");
        $this->comment("🔹 Command Usage:");
        $this->line("   php artisan system:model ModelName");
        $this->line("");
        $this->comment("🔹 Arguments:");
        $this->table(
            ['Argument', 'Required', 'Description'],
            [
                ['name', 'YES', 'Model name in singular StudlyCase (Example: User, Category)'],
            ]
        );

        $this->comment("🔹 Options:");
        $this->table(
            ['Option', 'Default', 'Description'],
            [
                ['--force', 'false', 'Overwrite model file if already exists'],
                ['--help', 'false', 'Displays this help information'],
            ]
        );

        $this->comment("🔹 Relationship Types:");
        $this->table(
            ['Type', 'Meaning', 'Usage Example'],
            [
                ['hasOne', 'Model has exactly one related record', 'User → hasOne → Profile'],
                ['hasMany', 'Model owns many related records', 'User → hasMany → Post'],
                ['belongsTo', 'Model references another record', 'Post → belongsTo → User'],
                ['belongsToMany', 'Many-to-many via pivot table', 'User → belongsToMany → Role'],
            ]
        );

        $this->comment("🔹 Behavior Notes:");
        $this->table(
            ['Logic', 'Description'],
            [
                ['Table Detection', 'If a matching table exists, you may reuse it or create a new one'],
                ['Pivot Auto-Generation', 'If belongsToMany is selected, tool asks to create pivot migration'],
                ['Model Output', 'Generated inside app/Models with relationships included'],
            ]
        );

        $this->comment("🔹 Examples:");

        $this->line("✔ Create a Standard Model:");
        $this->line("   php artisan system:model Product");

        $this->line("\n✔ Force-create (overwrite):");
        $this->line("   php artisan system:model Invoice --force");

        $this->line("\n📌 Tip: Run interactively to add relationships and optional migration.");

        return Command::SUCCESS;
    }


    protected function askForRelationships(string $model)
    {
        $relations = [];
        $existingModels = $this->getExistingModels();

        while ($this->confirm("Add relationship?", false)) {
            $type = $this->choice("Select relationship type", [
                'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
            ]);

            $target = $this->choice("Select related model", $existingModels);

            $relations[] = compact('type', 'target');

            // Auto create pivot for many-to-many
            if ($type === 'belongsToMany' && $this->confirm("Create pivot table migration?", true)) {
                $pivot = Str::snake(Str::singular($model)) . '_' . Str::snake(Str::singular($target));
                $this->call('make:migration', [
                    'name' => "create_{$pivot}_table",
                ]);
                $this->info("🔗 Pivot migration created: $pivot");
            }
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
            $relationMethods .= "\n    public function " . Str::camel(Str::plural($rel['target'])) . "()
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

    $relationMethods
}
PHP;

        File::ensureDirectoryExists(app_path('Models'));
        file_put_contents(app_path("Models/$model.php"), $template);

        $this->info("📁 Model created: app/Models/$model.php");
    }
}
