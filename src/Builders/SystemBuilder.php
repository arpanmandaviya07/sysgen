<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Concerns\InteractsWithIO;

class SystemBuilder
{
    use InteractsWithIO;

    protected array      $definition;
    protected string     $basePath;
    protected Filesystem $files;
    protected bool       $force      = false;
    protected ?string    $moduleName = null;

    protected bool  $overwriteAll  = false;
    protected bool  $skipAll       = false;
    protected array $createdTables = [];

    protected $output;

    public function __construct(array $definition, string $basePath, bool $force = false, ?string $moduleName = null)
    {
        $this->definition = $definition;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->files = new Filesystem();
        $this->force = $force;
        $this->moduleName = $moduleName ? Str::studly($moduleName) : null;
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    public function build(): void
    {
        $this->info("Starting build for system: " . ($this->moduleName ?? 'Base System'));

        if (!isset($this->definition['tables'])) {
            $this->error("No tables defined in the schema.");
            return;
        }

        foreach ($this->definition['tables'] as $table) {
            $tableName = $table['name'] ?? null;

            if (!$tableName) {
                throw new \Exception("❌ Table name missing in definition!");
            }

            $this->comment("\nProcessing table: {$tableName}...");

            try {
                if (in_array($tableName, $this->createdTables)) {
                    if (!$this->askPermission("Table '{$tableName}' already processed. Generate AGAIN?")) {
                        continue;
                    }
                }
                $this->createdTables[] = $tableName;

                $this->generateMigration($table);
                $this->generateModel($table);
                $this->generateController($table);
                $this->generateViews($table);

                $this->info("Table '{$tableName}' generated successfully.");
            } catch (\Exception $e) {
                $this->error("Failed for table '{$tableName}': {$e->getMessage()}");
            }
        }

        $this->info('✅ System generation complete.');
    }

    // -------------------- Paths & Namespaces --------------------

    protected function getModulePath(string $suffix = ''): string
    {
        if ($this->moduleName) {
            return $this->basePath . '/app/Modules/' . $this->moduleName . $suffix;
        }
        return $this->basePath . $suffix;
    }

    protected function getModuleNamespace(string $suffix = ''): string
    {
        if ($this->moduleName) {
            return "App\\Modules\\" . $this->moduleName . $suffix;
        }
        return "App" . $suffix;
    }

    protected function migrationPath(): string
    {
        return $this->basePath . '/database/migrations';
    }

    protected function modelPath(): string
    {
        return $this->getModulePath('/Models');
    }

    protected function controllerPath(): string
    {
        return $this->getModulePath('/Http/Controllers');
    }

    protected function viewPath(string $tableName): string
    {
        $module = $this->moduleName ? Str::snake($this->moduleName) . '/' : '';
        return $this->basePath . '/resources/views/' . $module . $tableName;
    }

    // -------------------- Generate Methods --------------------

    protected function generateMigration(array $table): void
    {
        $migrationPath = $this->migrationPath();
        if (!$this->files->isDirectory($migrationPath)) {
            $this->files->makeDirectory($migrationPath, 0755, true);
        }

        $columnsCode = $this->generateColumns($table['columns'] ?? []);
        $fkCode = $this->generateForeignKeys($table['columns'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/migration.stub');

        $content = str_replace(
            ['{{tableName}}', '{{columns}}', '{{foreign_keys}}', '{{timestamps}}'],
            [
                $table['name'],
                $columnsCode,
                $fkCode,
                isset($table['timestamps']) && $table['timestamps'] === false ? '' : "\n        \$table->timestamps();"
            ],
            $stub
        );

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $fullPath = $migrationPath . '/' . $fileName;

        $this->saveFileWithPrompt($fullPath, $content);
        $this->info("   - Migration created: {$fileName}");
    }

    protected function generateModel(array $table): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $modelName = $table['model'] ?? ucfirst(Str::singular($table['name']));
        $namespace = $this->getModuleNamespace('\\Models');
        $fillable = $this->getFillableColumns($table['columns'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/model.stub');

        $content = str_replace(
            ['{{modelName}}', '{{tableName}}', '{{fillable}}', '{{namespace}}'],
            [$modelName, $table['name'], implode(', ', $fillable), $namespace],
            $stub
        );

        $this->saveFileWithPrompt("$modelPath/{$modelName}.php", $content);
        $this->info("   - Model created: {$modelName}.php");
    }

    protected function generateController(array $table): void
    {
        $controllerPath = $this->controllerPath();
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        $modelName = $table['model'] ?? ucfirst(Str::singular($table['name']));
        $controllerName = $table['controller'] ?? $modelName . 'Controller';
        $modelNamespace = $this->getModuleNamespace('\\Models');

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/controller.stub');

        $content = str_replace(
            ['{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{modelNamespace}}'],
            [$controllerName, $modelName, Str::camel($modelName), $modelNamespace],
            $stub
        );

        $this->saveFileWithPrompt("$controllerPath/{$controllerName}.php", $content);
        $this->info("   - Controller created: {$controllerName}.php");
    }

    protected function generateViews(array $table): void
    {
        $views = $table['views'] ?? [];

        foreach ($views as $v) {
            $line = trim($v);
            $files = [];
            preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches);
            if ($matches) {
                $baseFolder = trim($matches[1], '/');
                $files = array_map(fn($f) => trim($f) . '.blade.php', explode(',', $matches[2]));
            } else {
                $baseFolder = trim($line, '/');
                $files = ['index.blade.php'];
            }

            foreach ($files as $file) {
                $fullPath = $this->basePath . '/resources/views/' . $baseFolder . '/' . $file;
                $dir = dirname($fullPath);
                if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);
                $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');
                $this->files->put($fullPath, str_replace('{{tableName}}', $table['name'] ?? '', $stub));
                $this->info("   - View created: $fullPath");
            }
        }
    }

    // -------------------- Helper Methods --------------------

    private function getFillableColumns(array $columns): array
    {
        $fillable = [];
        foreach ($columns as $col) {
            if (!in_array($col['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                $fillable[] = "'" . $col['name'] . "'";
            }
        }
        return $fillable;
    }

    protected function generateColumns(array $columns): string
    {
        $lines = '';
        $existingCols = [];

        foreach ($columns as $col) {
            if (in_array($col['name'], $existingCols)) continue;
            $existingCols[] = $col['name'];

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
            $lines .= "        $base;\n";
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
                    $lines .= "        \$table->foreign('{$columnName}')->references('{$ref}')->on('{$on}')";
                    if (!empty($fk['onDelete'])) $lines .= "->onDelete('{$fk['onDelete']}')";
                    if (!empty($fk['onUpdate'])) $lines .= "->onUpdate('{$fk['onUpdate']}')";
                    $lines .= ";\n";
                }
            }
        }
        return $lines;
    }

    protected function askPermission(string $message): bool
    {
        if ($this->force || $this->overwriteAll) return true;
        if ($this->skipAll) return false;

        $answer = $this->output->ask("⚠️  $message (y/n/all/skip-all)", 'n');

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

    protected function saveFileWithPrompt(string $path, string $content): void
    {
        if ($this->files->exists($path)) {
            if (!$this->askPermission("File exists: " . basename($path) . ". Replace?")) {
                return;
            }
        }

        try {
            $dir = dirname($path);
            if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);
            $this->files->put($path, $content);
        } catch (\Exception $e) {
            $this->error("Failed to write file {$path}: " . $e->getMessage());
        }
    }
}
