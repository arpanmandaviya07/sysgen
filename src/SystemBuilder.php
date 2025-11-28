<?php

namespace Arpanmandaviya\SystemBuilder;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class SystemBuilder
{
    protected $signature = 'system:build 
        {--json= : Path to the JSON definition file} 
        {--force : Overwrite existing files without asking}';

    protected $description = 'Generate migrations, models, controllers, and views from JSON schema.';

    protected $definition;
    protected $basePath;
    protected $files;
    protected $force = false;

    // NEW: To avoid repeated confirmation
    protected $overwriteAll = false;
    protected $skipAll = false;
    protected $createdTables = [];

    public function __construct(array $definition, string $basePath, bool $force = false)
    {
        $this->definition = $definition;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->files = new Filesystem();
        $this->force = $force;
    }

    public function build()
    {
        foreach ($this->definition['tables'] as $table) {

            if (in_array($table['name'], $this->createdTables)) {
                if (!$this->askPermission("Table '{$table['name']}' already processed. Generate AGAIN?")) {
                    continue;
                }
            }

            $this->createdTables[] = $table['name'];

            $this->generateMigration($table);
            $this->generateModel($table);
            $this->generateController($table);
            $this->generateViews($table);
        }
    }

    protected function askPermission(string $message): bool
    {
        if ($this->overwriteAll) return true;
        if ($this->skipAll) return false;

        echo "\n⚠️  $message (y/n/all/skip-all): ";
        $answer = trim(fgets(STDIN));

        if ($answer === 'all') {
            $this->overwriteAll = true;
            return true;
        }
        if ($answer === 'skip-all') {
            $this->skipAll = true;
            return false;
        }

        return strtolower($answer) === 'y';
    }

    protected function migrationPath(): string
    {
        return $this->basePath . '/database/migrations';
    }

    protected function generateMigration(array $table)
    {
        $migrationPath = $this->migrationPath();

        if (!$this->files->isDirectory($migrationPath)) {
            $this->files->makeDirectory($migrationPath, 0755, true);
        }

        $columnsCode = $this->generateColumns($table['columns'] ?? []);
        $fkCode = $this->generateForeignKeys($table['columns'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/migration.stub');

        $content = str_replace(['{{tableName}}', '{{columns}}', '{{foreign_keys}}', '{{timestamps}}'], [
            $table['name'],
            $columnsCode,
            $fkCode,
            isset($table['timestamps']) && $table['timestamps'] === false
                ? ''
                : "\n \$table->timestamps();",
        ], $stub);

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $full = $migrationPath . '/' . $fileName;

        if ($this->files->exists($full)) {
            if (!$this->askPermission("Migration already exists: {$fileName}. Replace?")) {
                return;
            }
        }

        $this->files->put($full, $content);
    }

    protected function generateColumns(array $columns): string
    {
        $lines = '';
        $existingCols = [];

        foreach ($columns as $col) {

            if (in_array($col['name'], $existingCols)) {
                echo "⛔ Skipping duplicate column: {$col['name']}\n";
                continue;
            }

            $existingCols[] = $col['name'];

            if (isset($col['type']) && in_array($col['type'], ['id', 'bigIncrements', 'increments'])) {
                if ($col['type'] === 'id') {
                    $lines .= " \$table->id();\n";
                }
                continue;
            }

            $type = $col['type'] ?? 'string';
            $name = $col['name'];

            $base = "\$table->{$type}('{$name}'";

            if (!empty($col['length']) && in_array($type, ['string', 'char', 'varchar'])) {
                $base .= ", {$col['length']}";
            }

            if ($type === 'enum' && !empty($col['values']) && is_array($col['values'])) {
                $vals = array_map(fn($v) => "'" . addslashes($v) . "'", $col['values']);
                $base = "\$table->enum('{$name}', [" . implode(', ', $vals) . "])";
            } else {
                $base .= ")";
            }

            if (!empty($col['nullable'])) $base .= "->nullable()";
            if (!empty($col['unique'])) $base .= "->unique()";
            if (!empty($col['index'])) $base .= "->index()";

            if (isset($col['default'])) {
                $default = is_numeric($col['default']) ? $col['default'] : "'" . addslashes($col['default']) . "'";
                $base .= "->default({$default})";
            }

            if (!empty($col['comment'])) $base .= "->comment('" . addslashes($col['comment']) . "')";

            $lines .= " $base;\n";
        }

        return $lines;
    }

    protected function generateForeignKeys(array $columns): string
    {
        $lines = '';

        foreach ($columns as $col) {
            if (!empty($col['foreign'])) {
                $fk = $col['foreign'];
                $columnName = $col['name'];
                $on = $fk['on'] ?? null;
                $ref = $fk['references'] ?? 'id';

                if ($on) {
                    $lines .= " \$table->foreign('{$columnName}')->references('{$ref}')->on('{$on}')";
                    if (!empty($fk['onDelete'])) $lines .= "->onDelete('{$fk['onDelete']}')";
                    if (!empty($fk['onUpdate'])) $lines .= "->onUpdate('{$fk['onUpdate']}')";
                    $lines .= ";\n";
                }
            }
        }
        return $lines;
    }

    protected function saveFileWithPrompt(string $path, string $content)
    {
        if ($this->files->exists($path)) {
            if (!$this->askPermission("File already exists: $path. Replace?")) {
                return;
            }
        }

        $this->files->put($path, $content);
    }

    protected function generateModel(array $table)
    {
        $modelPath = $this->basePath . '/app/Models';
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $modelName = ucfirst(Str::singular($table['name']));
        $fillable = [];

        foreach ($table['columns'] as $col) {
            if (!in_array($col['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                $fillable[] = "'{$col['name']}'";
            }
        }

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/model.stub');

        $content = str_replace(['{{modelName}}', '{{tableName}}', '{{fillable}}'], [
            $modelName,
            $table['name'],
            implode(', ', $fillable),
        ], $stub);

        $this->saveFileWithPrompt("$modelPath/{$modelName}.php", $content);
    }

    protected function generateController(array $table)
    {
        $controllerPath = $this->basePath . '/app/Http/Controllers';
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        $modelName = ucfirst(Str::singular($table['name']));
        $controllerName = $modelName . 'Controller';

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/controller.stub');

        $content = str_replace(['{{controllerName}}', '{{modelName}}', '{{modelVariable}}'], [
            $controllerName,
            $modelName,
            Str::camel($modelName),
        ], $stub);

        $this->saveFileWithPrompt("$controllerPath/{$controllerName}.php", $content);
    }

    protected function generateViews(array $table)
    {
        $viewPath = $this->basePath . '/resources/views/' . $table['name'];
        if (!$this->files->isDirectory($viewPath)) $this->files->makeDirectory($viewPath, 0755, true);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');

        $content = str_replace('{{tableName}}', $table['name'], $stub);

        $this->saveFileWithPrompt("$viewPath/index.blade.php", $content);
    }

    public function handle()
    {
        if ($this->option('help')) {
            return $this->displayHelp();
        }

        $this->info("🚀 Running System Builder...");
        // Your build logic here
    }

    protected function displayHelp()
    {
        $this->line("");
        $this->info("📘 Laravel System Builder Help");
        $this->line("------------------------------------");

        $this->comment("Usage:");
        $this->line("  php artisan system:build --json=path/to/file.json");

        $this->line("");
        $this->comment("Options:");
        $this->line("  --json      Path to schema file");
        $this->line("  --force     Overwrite existing files");
        $this->line("  --help      Show this help message");

        $this->line("");
        $this->comment("Example:");
        $this->line("  php artisan system:build --json=storage/app/system.json");
        $this->line("");

        return 0;
    }

}

