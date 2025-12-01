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

        // --- 1. Process tables first ---
        if (isset($this->definition['tables'])) {
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
                    $this->generateController($table); // Now handles the name conflict logic

                    // Generate views *if* defined inside the table definition
                    if (!empty($table['views'])) {
                        $this->generateTableViews($table);
                    }


                    $this->info("Table '{$tableName}' generated successfully.");
                } catch (\Exception $e) {
                    $this->error("Failed to generate files for table '{$tableName}'. Error: {$e->getMessage()}");
                }
            }
        }

        // --- 2. Process general components (models, controllers, views) ---
        $this->generateGeneralComponents();


        $this->info('✅ System generation complete.');
    }

    // --- New Method for Non-Table Components ---
    protected function generateGeneralComponents(): void
    {
        $this->comment("\nProcessing general components...");

        // Models
        if (isset($this->definition['models'])) {
            foreach ($this->definition['models'] as $modelName) {
                $this->createModelFile($modelName);
            }
        }

        // Controllers
        if (isset($this->definition['controllers'])) {
            foreach ($this->definition['controllers'] as $controllerName) {
                $this->createControllerFile($controllerName);
            }
        }

        // Views (General utility views)
        if (isset($this->definition['views'])) {
            foreach ($this->definition['views'] as $viewDefinition) {
                $this->createViewFile($viewDefinition);
            }
        }
    }


    protected function getModulePath(string $suffix = ''): string
    {
        if ($this->moduleName) {
            return $this->basePath . '/app/Modules/' . $this->moduleName . $suffix;
        }
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
        // Crucial fix: Singularize the table name for the Model name
        $modelName = ucfirst(Str::singular($table['name']));

        // Check if this model name is in the general 'models' array (to avoid re-prompting)
        $generalModels = $this->definition['models'] ?? [];
        if (in_array($modelName, $generalModels)) {
            $this->warn("   - Model name '{$modelName}' is defined in both 'tables' and 'models'. Skipping re-generation.");
            return;
        }

        $this->createModelFile($modelName, $table['name']);
    }

    protected function createModelFile(string $modelDef, ?string $tableName = null): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $namespace = $this->getModuleNamespace('\\Models');

        // Determine model name and table
        $modelName = $modelDef['name'] ?? Str::studly($modelDef);
        $tableName = $tableName ?? ($modelDef['table'] ?? Str::snake(Str::plural($modelName)));

        $fillable = [];
        if ($tableName) {
            $fillable = $this->getFillableColumns($this->getColumnsForTable($tableName));
        }
        $fillableCode = $fillable ? implode(",\n        ", $fillable) : "/* fillable columns here */";

        // Generate relationships
        $relationsCode = $this->generateRelationships($modelDef['relations'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/model.stub');

        $content = str_replace(
            ['{{modelName}}', '{{tableName}}', '{{fillable}}', '{{namespace}}', '{{relationships}}'],
            [$modelName, $tableName, $fillableCode, $namespace, $relationsCode],
            $stub
        );

        $this->saveFileWithPrompt("$modelPath/{$modelName}.php", $content);
        $this->info("   - Model created: {$modelName}.php");
    }

    protected function generateRelationships(array $relations): string
    {
        $code = '';

        foreach ($relations as $rel) {
            // Format: "relationName:type"
            [$name, $type] = explode(':', $rel);

            $methodName = Str::camel($name);

            switch (strtolower($type)) {
                case 'belongsto':
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->belongsTo("
                        . ucfirst(Str::studly($name)) . "::class);\n    }\n\n";
                    break;
                case 'hasmany':
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->hasMany("
                        . ucfirst(Str::studly(Str::singular($name))) . "::class);\n    }\n\n";
                    break;
                case 'hasone':
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->hasOne("
                        . ucfirst(Str::studly($name)) . "::class);\n    }\n\n";
                    break;
                case 'belongstomany':
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->belongsToMany("
                        . ucfirst(Str::studly(Str::singular($name))) . "::class);\n    }\n\n";
                    break;
            }
        }

        return $code;
    }



    protected function generateController(array $table): void
    {
        $tableBasedName = ucfirst(Str::singular($table['name'])) . 'Controller';
        $userDefinedControllers = $this->definition['controllers'] ?? [];

        $controllerToGenerate = $tableBasedName;
        $createBoth = false;

        // Check for potential name conflict/overlap
        if (in_array($tableBasedName, $userDefinedControllers)) {
            $this->warn("⚠️  Controller '{$tableBasedName}' is implied by table '{$table['name']}' AND explicitly listed in 'controllers'. Skipping table-based creation.");
            return; // It will be created by generateGeneralComponents later
        }

        // Check if table's implied model name matches a user-defined controller name (e.g. table name "users" vs "UserController")
        $singularTableName = ucfirst(Str::singular($table['name']));
        foreach ($userDefinedControllers as $userController) {
            if ($userController === $singularTableName . 'Controller') {
                $choice = $this->ask("Table '{$table['name']}' implies controller '{$tableBasedName}'. You defined '{$userController}' in JSON. Use JSON name, Table name, or Both? (json/table/both)", 'table');

                if (strtolower($choice) === 'json') {
                    $controllerToGenerate = $userController;
                } elseif (strtolower($choice) === 'both') {
                    $createBoth = true;
                }
                // else: controllerToGenerate remains tableBasedName
                break;
            }
        }

        $this->createControllerFile($controllerToGenerate, $singularTableName);

        if ($createBoth && $controllerToGenerate !== $tableBasedName) {
            $this->createControllerFile($tableBasedName, $singularTableName);
        }
    }

    protected function createControllerFile(string|array $controllerDef, ?string $modelNameFallback = null): void
    {
        $controllerPath = $this->controllerPath();
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        // Support JSON object with table/model
        $controllerName = is_array($controllerDef) ? ($controllerDef['name'] ?? 'UnknownController') : $controllerDef;
        $modelName = is_array($controllerDef) ? ($controllerDef['model'] ?? $modelNameFallback) : $modelNameFallback;

        $modelName = ucfirst($modelName); // Ensure studly case
        $modelVariable = Str::camel($modelName);
        $controllerNamespace = $this->getModuleNamespace('\\Http\\Controllers');
        $modelNamespace = $this->getModuleNamespace('\\Models');

        // Load controller stub
        $stubFile = __DIR__ . '/../../resources/stubs/controller.stub';
        if (!$this->files->exists($stubFile)) {
            throw new \Exception("Controller stub not found at $stubFile");
        }

        $stub = $this->files->get($stubFile);

        // Replace placeholders
        $content = str_replace(
            ['{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{controllerNamespace}}', '{{modelNamespace}}'],
            [$controllerName, $modelName, $modelVariable, $controllerNamespace, $modelNamespace],
            $stub
        );

        $this->saveFileWithPrompt("$controllerPath/{$controllerName}.php", $content);
        $this->info("   - Controller created: {$controllerName}.php");
    }


    // Generates views listed directly inside the 'tables' array
    protected function generateTableViews(array $table): void
    {
        $views = $table['views'] ?? [];
        foreach ($views as $v) {
            $this->createViewFile($v, $table['name']);
        }
    }

    // Generates general views listed in the top-level 'views' array
    protected function createViewFile(string $viewDefinition, ?string $tableName = null): void
    {
        $line = trim($viewDefinition);
        $filesToCreate = [];

        // Determine base path for the view: resources/views/{folder}/
        $folder = null;
        $fileList = $line;

        // Try to parse format: folder/[file1,file2]
        if (preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches)) {
            $folder = trim($matches[1], '/');
            $files = array_map('trim', explode(',', $matches[2]));
            $filesToCreate = array_map(fn($f) => $f . '.blade.php', $files);
        }
        // Try to parse format: folder/filename (treat filename as index if no brackets)
        elseif (strpos($line, '/') !== false) {
            $parts = explode('/', trim($line, '/'));
            $folder = $parts[0];
            $filesToCreate = [end($parts) . '.blade.php'];
        }
        // Simple file case: index or file.blade.php
        else {
            $filesToCreate = [Str::endsWith($line, '.blade.php') ? $line : $line . '.blade.php'];
            $folder = $tableName ?? 'default';
        }

        // Final view path construction
        $basePath = $this->basePath . '/resources/views/' . $folder;

        foreach ($filesToCreate as $file) {
            $fullPath = $basePath . '/' . $file;
            $dir = dirname($fullPath);

            if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);

            $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');
            $content = str_replace('{{tableName}}', $tableName ?? 'N/A', $stub);

            $this->saveFileWithPrompt($fullPath, $content);
            $this->info("   - View created: /resources/views/{$folder}/{$file}");
        }
    }


    // --- Helper Methods ---

    private function getColumnsForTable(string $tableName): array
    {
        foreach ($this->definition['tables'] as $table) {
            if ($table['name'] === $tableName) {
                return $table['columns'] ?? [];
            }
        }
        return [];
    }

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