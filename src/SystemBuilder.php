<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Concerns\InteractsWithIO;

// To use $this->info(), $this->error(), etc. in a builder

class SystemBuilder
{
    use InteractsWithIO;

    // Trait to mimic Command's output methods

    protected array      $definition;
    protected string     $basePath;
    protected Filesystem $files;
    protected bool       $force      = false;
    protected ?string    $moduleName = null; // New property for module folder

    protected bool  $overwriteAll  = false;
    protected bool  $skipAll       = false;
    protected array $createdTables = [];

    // The $output property is required by InteractsWithIO trait
    protected $output;

    public function __construct(array $definition, string $basePath, bool $force = false, ?string $moduleName = null)
    {
        $this->definition = $definition;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->files = new Filesystem();
        $this->force = $force;
        $this->moduleName = $moduleName ? Str::studly($moduleName) : null;
    }

    // Setter for $output, usually called from the Command that instantiates this class
    public function setOutput($output)
    {
        $this->output = $output;
    }

    public static function parseTableDefinition(string $line): array
    {
        $line = trim(str_replace('table:', '', $line));

        // Split by first space to get table name and columns
        $parts = explode(' ', $line, 2);
        $tableName = trim($parts[0]);
        $columns = [];

        if (isset($parts[1])) {
            $colDefs = explode(',', $parts[1]);
            foreach ($colDefs as $c) {
                $chunks = array_map('trim', explode('|', trim($c)));
                $colName = array_shift($chunks);
                $col = ['name' => $colName];

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

        return ['name' => $tableName, 'columns' => $columns, 'timestamps' => true];
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
                // Check for duplicates in the current run (less relevant in interactive mode, but good guard)
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

    /**
     * Helper method to get namespaced paths based on module.
     *
     * @param string $suffix
     * @return string
     */
    protected function getModulePath(string $suffix = ''): string
    {
        if ($this->moduleName) {
            // e.g., app/Modules/Invoices/Models
            return $this->basePath . '/app/Modules/' . $this->moduleName . $suffix;
        }
        // e.g., app/Models (standard Laravel)
        return $this->basePath . $suffix;
    }

    /**
     * Helper method to get the correct namespace.
     *
     * @param string $suffix
     * @return string
     */
    protected function getModuleNamespace(string $suffix = ''): string
    {
        if ($this->moduleName) {
            return "App\\Modules\\" . $this->moduleName . $suffix;
        }
        return "App" . $suffix;
    }

    // --- Path Definitions (Updated to use getModulePath) ---

    protected function migrationPath(): string
    {
        return $this->basePath . '/database/migrations'; // Migrations always go to base DB folder
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
        // Views go to resources/views/module_name/table_name
        $module = $this->moduleName ? Str::snake($this->moduleName) . '/' : '';
        return $this->basePath . '/resources/views/' . $module . $tableName;
    }

    // --- Generation Methods (Updated to use new paths/namespaces) ---

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

        if ($this->files->exists($fullPath)) {
            if (!$this->askPermission("Migration already exists: {$fileName}. Replace?")) {
                return;
            }
        }

        $this->files->put($fullPath, $content);
        $this->info("   - Migration created: {$fileName}");
    }

    protected function generateModel(array $table): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

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

        $modelName = ucfirst(Str::singular($table['name']));
        $controllerName = $modelName . 'Controller';
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
            // Parse folder and multiple files
            $line = trim($v);
            $files = [];
            preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches);
            if ($matches) {
                $baseFolder = trim($matches[1], '/');
                $files = array_map(fn($f) => trim($f) . '.blade.php', explode(',', $matches[2]));
            } else {
                // single file path, maybe like "admin/includes/head"
                $baseFolder = dirname($line);
                $files = [basename($line) . '.blade.php'];
            }

            foreach ($files as $file) {
                $fullPath = $this->basePath . '/resources/views/' . $baseFolder . '/' . $file;
                $dir = dirname($fullPath);
                if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);

                $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');
                $this->files->put($fullPath, str_replace('{{tableName}}', $table['name'], $stub));
                $this->info("   - View created: $fullPath");
            }
        }
    }


    // --- Helper Methods (No major logic change, but private/protected where appropriate) ---

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
        // ... (Your existing generateColumns logic, unchanged) ...
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

    protected function parseViewDefinition(string $line): array
    {
        // example: "view: admin/includes/[head,sidebar,footer]"
        $line = trim(str_replace('view:', '', $line));

        // Extract folder and file bracket part
        preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches);

        if (!$matches) {
            return [$line]; // normal single file case
        }

        $basePath = trim($matches[1], '/');
        $files = explode(',', $matches[2]);

        return array_map(fn($file) => "$basePath/$file.blade.php", $files);
    }

    protected function generateForeignKeys(array $columns): string
    {
        // ... (Your existing generateForeignKeys logic, unchanged) ...
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
        // Use $this->output->confirm() from InteractsWithIO trait for professional look
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
            if (!$this->askPermission("File already exists: " . basename($path) . ". Replace?")) {
                return;
            }
        }

        try {
            // Ensure directory exists before putting file
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