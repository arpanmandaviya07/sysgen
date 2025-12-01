<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Support\Facades\File;

// Retained from the input, though Filesystem is primary
use Carbon\Carbon;

// Use Carbon for more robust time manipulation

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

    // --- Property to manage migration ordering
    protected int $migrationCounter = 0;

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
                    } else if ($rule === 'unique') {
                        $col['unique'] = true;
                    } else if ($rule === 'nullable') {
                        $col['nullable'] = true;
                    } else if (str_starts_with($rule, 'rel(')) {
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
            $generatedControllers = [];

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

                    // Increment counter before generation to ensure unique timestamp
                    $this->migrationCounter++;
                    $this->generateMigration($table, $this->migrationCounter);

                    $this->generateModel($table);
                    $controllerName = $this->generateController($table);

                    // Add the generated controller to the list for route generation
                    if ($controllerName) {
                        // $controllerName is the base name, e.g., 'Post'
                        if (!in_array($controllerName, $generatedControllers)) {
                            $generatedControllers[] = $controllerName;
                        }
                    }

                    // Generate views *if* defined inside the table definition
                    if (!empty($table['views'])) {
                        $this->generateTableViews($table);
                    }

                    $this->info("Table '{$tableName}' generated successfully.");
                } catch (\Exception $e) {
                    $this->error("Failed to generate files for table '{$tableName}'. Error: {$e->getMessage()}");
                }
            }

            // Update routes once after processing all tables
            if (!empty($generatedControllers)) {
                $this->updateWebRoutes($generatedControllers);
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
            foreach ($this->definition['models'] as $model) {
                $modelName = is_array($model) ? ($model['name'] ?? null) : $model;
                $tableName = is_array($model) ? ($model['table'] ?? null) : null;

                if ($modelName) {
                    $this->createModelFile($model, $tableName);
                }
            }
        }

        // Controllers
        if (isset($this->definition['controllers'])) {
            foreach ($this->definition['controllers'] as $controllerDef) {
                $this->createControllerFile($controllerDef);
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

    /**
     * @param array $table
     * @param int   $index Used to ensure unique timestamps for file ordering
     * @throws \Exception
     */
    protected function generateMigration(array $table, int $index): void
    {
        $migrationPath = $this->migrationPath();
        if (!$this->files->isDirectory($migrationPath)) {
            $this->files->makeDirectory($migrationPath, 0755, true);
        }

        $columnsCode = $this->generateColumns($table['columns'] ?? []);
        $fkCode = $this->generateForeignKeys($table['columns'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/migration.stub');

        // Logic for timestamps replacement
        $timestampsCode = '';
        if (!isset($table['timestamps']) || $table['timestamps'] !== false) {
            $timestampsCode = "\n \$table->timestamps();";
        }

        $content = str_replace(['{{tableName}}', '{{columns}}', '{{foreign_keys}}', '{{timestamps}}'], [
            $table['name'],
            $columnsCode,
            $fkCode,
            $timestampsCode,
        ], $stub);

        // --- Custom logic to ensure unique, sequential timestamp (Step 1 of fix)
        $timestamp = Carbon::now()->addSeconds($index)->format('Y_m_d_His');
        // --- End of Custom logic

        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $fullPath = $migrationPath . '/' . $fileName;

        if (!$table['name']) {
            throw new \Exception("Table name missing. Check your JSON definition!");
        }

        $this->saveFileWithPrompt($fullPath, $content);
        $this->info("   - Migration created: {$fileName}");
    }

    protected function generateModel(array $table): void
    {
        $modelName = ucfirst(Str::singular($table['name']));
        $generalModels = array_map(fn($m) => is_array($m) ? ($m['name'] ?? '') : $m, $this->definition['models'] ?? []);

        if (in_array($modelName, $generalModels)) {
            $this->warn("   - Model name '{$modelName}' is explicitly defined in 'models'. Skipping table-based generation.");
            return;
        }

        $this->createModelFile($modelName, $table['name']);
    }

    protected function createModelFile(string|array $modelDef, ?string $tableName = null): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $namespace = $this->getModuleNamespace('\\Models');

        $modelName = is_array($modelDef) ? ($modelDef['name'] ?? Str::studly($modelDef)) : Str::studly($modelDef);
        $tableName = $tableName ?? (is_array($modelDef) ? ($modelDef['table'] ?? Str::snake(Str::plural($modelName))) : Str::snake(Str::plural($modelName)));

        $fillable = [];
        if ($tableName) {
            $fillable = $this->getFillableColumns($this->getColumnsForTable($tableName));
        }
        $fillableCode = $fillable ? "'" . implode("', '", $fillable) . "'" : "/* fillable columns here */";

        $relations = is_array($modelDef) ? ($modelDef['relations'] ?? []) : [];
        $relationsCode = $this->generateRelationships($relations);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/model.stub');

        $content = str_replace(
            ['{{modelName}}', '{{tableName}}', '{{fillable}}', '{{namespace}}', '{{relationships}}'],
            [$modelName, $tableName, $fillableCode, $namespace, $relationsCode],
            $stub
        );

        $this->saveFileWithPrompt("$modelPath/{$modelName}.php", $content);
        $this->info("   - Model created: {$modelName}.php");
    }

    /**
     * Implements the logic for updating web.php with resource routes.
     * * @param array $routes List of base controller names (e.g., ['Post', 'User'])
     *
     * @return void
     */
    protected function updateWebRoutes(array $routes): void
    {
        $webFile = $this->basePath . '/routes/web.php';

        if (!$this->files->exists($webFile)) {
            $this->error("web.php not found. Skipping...");
            return;
        }

        $existing = $this->files->get($webFile);

        $routeBlock = "\n\n// -----------------------------------------------\n";
        $routeBlock .= "// AUTO-GENERATED ROUTES BY SystemBuilder PACKAGE\n";
        $routeBlock .= "// You can modify them as needed.\n";
        $routeBlock .= "// -----------------------------------------------\n\n";

        foreach ($routes as $route) {
            // $route is the base name (e.g., 'Post')
            $controllerClass = $this->getModuleNamespace('\\Http\\Controllers\\') . ucfirst($route) . 'Controller';

            // Route URL is the snake_case plural of the resource name (e.g., 'posts')
            $routeBlock .= "Route::resource('" . Str::snake(Str::plural($route)) . "', \\{$controllerClass}::class);\n";
        }

        // Handle existing block conflict resolution
        if (strpos($existing, 'AUTO-GENERATED ROUTES') !== false && !$this->force) {
            $choice = $this->choice(
                "⚠ web.php already has auto-generated routes. What do you want to do?",
                ['Replace', 'Merge', 'Skip'],
                1 // Merge is default
            );

            if ($choice === 'Skip') {
                $this->info("⏩ Skipped updating web.php.");
                return;
            }

            if ($choice === 'Replace') {
                // Regex to remove the entire existing auto-generated block
                $existing = preg_replace('/\/\/ -----------------------------------------------(.|\s)*?(\/\/ -----------------------------------------------\s*)*\n(Route::.*|)/m', '', $existing);
            }
            // If 'Merge' is chosen, we just append the new block, allowing duplicates if the user hasn't cleaned up.
        }

        $finalContent = $existing . $routeBlock;

        $this->files->put($webFile, $finalContent);
        $this->info("✅ Routes updated in web.php");
    }


    protected function generateRelationships(array $relations): string
    {
        $code = '';
        $modelNamespace = $this->getModuleNamespace('\\Models\\');

        foreach ($relations as $rel) {
            if (!str_contains($rel, ':')) continue;
            [$name, $type] = explode(':', $rel);

            $methodName = Str::camel($name);
            $modelClass = '';

            switch (strtolower($type)) {
                case 'belongsto':
                    $modelClass = ucfirst(Str::studly(Str::singular($name)));
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->belongsTo(\\{$modelNamespace}{$modelClass}::class);\n    }\n\n";
                    break;
                case 'hasmany':
                    $modelClass = ucfirst(Str::studly(Str::singular($name)));
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->hasMany(\\{$modelNamespace}{$modelClass}::class);\n    }\n\n";
                    break;
                case 'hasone':
                    $modelClass = ucfirst(Str::studly(Str::singular($name)));
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->hasOne(\\{$modelNamespace}{$modelClass}::class);\n    }\n\n";
                    break;
                case 'belongstomany':
                    $modelClass = ucfirst(Str::studly(Str::singular($name)));
                    $code .= "    public function {$methodName}()\n    {\n        return \$this->belongsToMany(\\{$modelNamespace}{$modelClass}::class);\n    }\n\n";
                    break;
            }
        }

        return $code;
    }


    /**
     * Generates a controller based on a table definition and returns its base name.
     *
     * @param array $table
     * @return string|null The base name of the controller (e.g., 'Post') generated or null if skipped.
     * @throws \Exception
     */
    protected function generateController(array $table): ?string
    {
        $tableBasedName = ucfirst(Str::singular($table['name'])) . 'Controller';
        $singularTableName = ucfirst(Str::singular($table['name']));
        $userDefinedControllers = $this->definition['controllers'] ?? [];

        $controllerToGenerate = $tableBasedName;
        $createBoth = false;

        $userDefinedNames = array_map(fn($c) => is_array($c) ? ($c['name'] ?? '') : $c, $userDefinedControllers);

        if (in_array($tableBasedName, $userDefinedNames)) {
            $this->warn("⚠️  Controller '{$tableBasedName}' is implied by table '{$table['name']}' AND explicitly listed in 'controllers'. Skipping table-based creation.");
            return null;
        }

        foreach ($userDefinedNames as $userControllerName) {
            if ($userControllerName === $tableBasedName) {
                $choice = $this->ask("Table '{$table['name']}' implies controller '{$tableBasedName}'. You defined '{$userControllerName}' in JSON. Use JSON name, Table name, or Both? (json/table/both)", 'table');

                if (strtolower($choice) === 'json') {
                    $controllerToGenerate = $userControllerName;
                } else if (strtolower($choice) === 'both') {
                    $createBoth = true;
                }
                break;
            }
        }

        $this->createControllerFile($controllerToGenerate, $singularTableName);

        if ($createBoth && $controllerToGenerate !== $tableBasedName) {
            $this->createControllerFile($tableBasedName, $singularTableName);
            return str_replace('Controller', '', $tableBasedName);
        }

        return str_replace('Controller', '', $controllerToGenerate); // Return base name for route generation
    }

    protected function createControllerFile(string|array $controllerDef, ?string $modelNameFallback = null): void
    {
        $controllerPath = $this->controllerPath();
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        $controllerName = is_array($controllerDef) ? ($controllerDef['name'] ?? 'UnknownController') : $controllerDef;
        $inferredModelName = Str::studly(str_replace('Controller', '', $controllerName));

        $modelName = is_array($controllerDef) ? ($controllerDef['model'] ?? $modelNameFallback ?? $inferredModelName) : ($modelNameFallback ?? $inferredModelName);

        $modelName = ucfirst($modelName);
        $modelVariable = Str::camel($modelName);
        $controllerNamespace = $this->getModuleNamespace('\\Http\\Controllers');
        $modelNamespace = $this->getModuleNamespace('\\Models');

        $stubFile = __DIR__ . '/../../resources/stubs/controller.stub';
        if (!$this->files->exists($stubFile)) {
            throw new \Exception("Controller stub not found at $stubFile");
        }

        $stub = $this->files->get($stubFile);

        $content = str_replace(
            ['{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{controllerNamespace}}', '{{modelNamespace}}'],
            [$controllerName, $modelName, $modelVariable, $controllerNamespace, $modelNamespace],
            $stub
        );

        $this->saveFileWithPrompt("$controllerPath/{$controllerName}.php", $content);
        $this->info("   - Controller created: {$controllerName}.php");
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

        $folder = null;

        if (preg_match('/(.*?)\/\[(.*?)\]/', $line, $matches)) {
            $folder = trim($matches[1], '/');
            $files = array_map('trim', explode(',', $matches[2]));
            $filesToCreate = array_map(fn($f) => $f . '.blade.php', $files);
        } else if (strpos($line, '/') !== false) {
            $parts = explode('/', trim($line, '/'));
            $folder = $parts[0];
            $filesToCreate = [end($parts) . '.blade.php'];
        } else {
            $filesToCreate = [Str::endsWith($line, '.blade.php') ? $line : $line . '.blade.php'];
            $folder = $tableName ?? 'default';
        }

        $basePath = $this->basePath . '/resources/views/' . $folder;

        foreach ($filesToCreate as $file) {
            $fullPath = $basePath . '/' . $file;
            $dir = dirname($fullPath);

            if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);

            $stub = $this->files->get(__DIR__ . '/../../resources/stubs/view.stub');
            $content = str_replace('{{tableName}}', $tableName ?? 'N/A', $stub);

            $this->saveFileWithPrompt($fullPath, $content);
            $this->info("   - View created: /resources/views/{$folder}/{$file}");
        }
    }


    // --- Helper Methods ---

    private function getColumnsForTable(string $tableName): array
    {
        foreach ($this->definition['tables'] as $table) {
            if (($table['name'] ?? $table['table'] ?? null) === $tableName) {
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
                $fillable[] = $col['name'];
            }
        }
        return $fillable;
    }

    protected function generateColumns(array $columns): string
    {
        $lines = '';
        $existingCols = [];

        foreach ($columns as $col) {
            if (!isset($col['name'])) continue;

            if (in_array($col['name'], $existingCols)) {
                $this->warn("⛔ Skipping duplicate column: {$col['name']}");
                continue;
            }

            $existingCols[] = $col['name'];

            // Skip default timestamps
            if (in_array($col['name'], ['created_at', 'updated_at'])) {
                $this->warn("⛔ Skipping explicit timestamp column '{$col['name']}'. Use `timestamps: false` to disable default timestamps.");
                continue;
            }

            if (isset($col['type']) && in_array($col['type'], ['id', 'bigIncrements', 'increments'])) {
                if ($col['type'] === 'id') {
                    $lines .= " \$table->id();\n";
                }
                continue;
            }

            $type = $col['type'] ?? 'string';
            $name = $col['name'];

            $base = "\$table->{$type}('{$name}'";

            if (isset($col['length']) && in_array($type, ['string', 'char', 'varchar', 'decimal', 'float'])) {
                if (in_array($type, ['decimal', 'float']) && isset($col['scale'])) {
                    $base .= ", {$col['length']}, {$col['scale']}";
                } else {
                    $base .= ", {$col['length']}";
                }
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

        $answer = $this->ask("⚠️  $message (y/n/all/skip-all)", 'n');

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