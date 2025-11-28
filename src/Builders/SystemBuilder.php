<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Concerns\InteractsWithIO;

class SystemBuilder
{
    use InteractsWithIO;

    protected array $definition;
    protected string $basePath;
    protected Filesystem $files;
    protected bool $force = false;
    protected ?string $moduleName = null;

    protected bool $overwriteAll = false;
    protected bool $skipAll = false;
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

    public static function parseTableDefinition(string $line): array
    {
        $line = trim(str_replace('table:', '', $line));

        $parts = explode(' ', $line, 2);
        $tableName = trim($parts[0]);
        $columns = [];

        if (isset($parts[1])) {
            $colDefs = explode(',', $parts[1]);
            foreach ($colDefs as $c) {
                $chunks = array_map('trim', explode('|', trim($c)));
                $colName = array_shift($chunks);
                $col = ['name' => Str::snake($colName)];

                foreach ($chunks as $rule) {
                    if (str_starts_with($rule, 'len(')) {
                        $col['length'] = (int)preg_replace('/[^0-9]/', '', $rule);
                    } elseif ($rule === 'unique') {
                        $col['unique'] = true;
                    } elseif ($rule === 'nullable') {
                        $col['nullable'] = true;
                    } elseif (str_starts_with($rule, 'rel(')) {
                        preg_match('/rel\((.*?),(.*?),(.*?)\)/', $rule, $r);
                        $col['foreign'] = [
                            'on' => trim($r[1]),
                            'references' => trim($r[2]),
                            'onDelete' => trim($r[3]),
                        ];
                    } else {
                        $col['type'] = $rule;
                    }
                }
                $columns[] = $col;
            }
        }

        return ['name' => Str::snake($tableName), 'columns' => $columns, 'timestamps' => true];
    }


    public function build(): void
    {
        $this->info("Starting build for system: " . ($this->moduleName ?? 'Base System'));

        if (!isset($this->definition['tables'])) {
            $this->error("No tables defined in the schema.");
            return;
        }

        foreach ($this->definition['tables'] as $table) {
            $tableName = $table['name'] ?? ($table['table'] ?? null);

            if (!$tableName) {
                throw new \Exception("❌ Table name missing. Fix your definition input.");
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
                $this->error("Failed to generate files for table '{$tableName}'. Error: {$e->getMessage()}");
            }
        }

        $this->info('✅ System generation complete.');
    }

    protected function getModulePath(string $suffix = ''): string
    {
        if ($this->moduleName) {
            // e.g., base/app/Modules/Invoices/Models
            return $this->basePath . '/app/Modules/' . $this->moduleName . $suffix;
        }
        // Standard Laravel paths, e.g., base/app/Models
        return $this->basePath . '/app' . $suffix;
    }

    protected function getModuleNamespace(string $suffix = ''): string
    {
        if ($this->moduleName) {
            return "App\\Modules\\" . $this->moduleName . $suffix;
        }
        return "App" . $suffix;
    }

    // --- Path Definitions ---

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

    // --- Generation Methods ---

    protected function generateMigration(array $table): void
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
            isset($table['timestamps']) && $table['timestamps'] === false ? '' : "\n \$table->timestamps();",
        ], $stub);

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $fullPath = $migrationPath . '/' . $fileName;

        if (!$table['name']) {
            throw new \Exception("Table name missing. Check your JSON definition!");
        }

        $this->saveFileWithPrompt($fullPath, $content);
        $this->info("   - Migration created: {$fileName}");
    }

    protected function generateModel(array $table): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        // Crucial fix: Singularize the table name for the Model name
        $modelName = ucfirst(Str::singular($table['name']));
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

        // Crucial fix: Singularize the table name for Model/Controller name
        $modelName = ucfirst(Str::singular($table['name']));
        $controllerName = $modelName . 'Controller';
        $controllerNamespace = $this->getModuleNamespace('\\Http\\Controllers');
        $modelNamespace = $this->getModuleNamespace('\\Models');

        $isResource = $table['resource'] ?? false; // Check for a 'resource' flag if you added one to the $table array
        $stubName = $isResource ? 'controller.resource.stub' : 'controller.stub';

        // Fallback to non-resource stub if resource stub doesn't exist
        $stubFile = __DIR__ . "/../../resources/stubs/{$stubName}";
        if (!$this->files->exists($stubFile)) {
            $stubFile = __DIR__ . '/../../resources/stubs/controller.stub';
            $isResource = false;
        }

        $stub = $this->files->get($stubFile);

        $content = str_replace(
            ['{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{controllerNamespace}}', '{{modelNamespace}}'],
            [$controllerName, $modelName, Str::camel($modelName), $controllerNamespace, $modelNamespace],
            $stub
        );

        $this->saveFileWithPrompt("$controllerPath/{$controllerName}.php", $content);
        $this->info("   - Controller created: {$controllerName}.php" . ($isResource ? ' (Resource)' : ''));
    }

    protected function generateViews(array $table): void
    {
        $views = $table['views'] ?? [];

        foreach ($views as $v) {
            $line = trim($v);
            $filesToCreate = [];
            $baseFolder = $table['name']; // Default base folder is table name

            // Regex to find: folder/subfolder/[file1,file2,file3]
            if (preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches)) {
                $baseFolder = trim($matches[1], '/');
                $files = array_map(fn($f) => trim($f), explode(',', $matches[2]));
                $filesToCreate = array_map(fn($f) => $f . '.blade.php', $files);
            }
            // Simple folder case: view: users/index
            elseif (strpos($line, '/') !== false) {
                $baseFolder = trim($line, '/');
                $filesToCreate = ['index.blade.php'];
            }
            // Simple file case: view: index
            else {
                $filesToCreate = [trim($line) . '.blade.php'];
            }

            // If a view command is used, but a specific folder wasn't given, use the table name as base
            if (empty($baseFolder)) {
                $baseFolder = $table['name'];
            }

            foreach ($filesToCreate as $file) {
                $fullPath = $this->basePath . '/resources/views/' . $baseFolder . '/' . $file;
                $dir = dirname($fullPath);

                if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);

                $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');
                $content = str_replace('{{tableName}}', $table['name'] ?? '', $stub);

                $this->saveFileWithPrompt($fullPath, $content);
                $this->info("   - View created: /resources/views/{$baseFolder}/{$file}");
            }
        }
    }


    // --- Helper Methods ---

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

            if (in_array($col['name'], $existingCols)) {
                $this->warn("⛔ Skipping duplicate column: {$col['name']}");
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

    protected function askPermission(string $message): bool
    {
        if ($this->force || $this->overwriteAll) return true;
        if ($this->skipAll) return false;

        $answer = $this->ask("⚠️  $message (y/n/all/skip-all)", 'n');

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
            if (!$this->askPermission("File already exists: " . basename($path) . ". Replace?")) {
                return;
            }
        }

        try {
            $dir = dirname($path);
            if (!$this->files->isDirectory($dir)) {
                $this->files->makeDirectory($dir, 0755, true);
            }

            $this->files->put($path, $content);
        } catch (\Exception $e) {
            $this->error("Failed to write file {$path}: " . $e->getMessage());
        }
    }
}