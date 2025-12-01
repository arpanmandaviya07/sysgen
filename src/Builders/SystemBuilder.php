<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Concerns\InteractsWithIO;
use Carbon\Carbon;

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

    // For guaranteeing unique migration timestamps per run
    protected int    $migrationCounter = 0;
    protected Carbon $migrationBaseTime;

    protected $output;

    public function __construct(array $definition, string $basePath, bool $force = false, ?string $moduleName = null)
    {
        $this->definition = $definition;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->files = new Filesystem();
        $this->force = $force;
        $this->moduleName = $moduleName ? Str::studly($moduleName) : null;

        // set a base time (now) for migration timestamps and freeze here for the run
        $this->migrationBaseTime = Carbon::now();
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    /**
     * Build the system (tables => migrations, models, controllers, views; then general components)
     */
    public function build(): void
    {
        $this->info("Starting build for system: " . ($this->moduleName ?? 'Base System'));

        $generatedControllers = [];

        // --- 1. Process tables first (so migrations are created in a predictable order) ---
        if (!empty($this->definition['tables'])) {
            foreach ($this->definition['tables'] as $table) {
                $tableName = $table['name'] ?? ($table['table'] ?? null);
                if (!$tableName) {
                    $this->error("❌ Table name missing in one of your table definitions. Skipping...");
                    continue;
                }

                $this->comment("\nProcessing table: {$tableName}...");

                try {
                    if (in_array($tableName, $this->createdTables)) {
                        if (!$this->askPermission("Table '{$tableName}' already processed. Generate AGAIN?")) {
                            continue;
                        }
                    }

                    $this->createdTables[] = $tableName;

                    // increment migration counter to ensure unique timestamp for this file
                    $this->migrationCounter++;
                    $this->generateMigration($table, $this->migrationCounter);

                    // create model and controller for this table
                    $this->generateModel($table);
                    $controllerBase = $this->generateController($table);
                    if ($controllerBase) {
                        $generatedControllers[] = $controllerBase;
                    }

                    // table-scoped views
                    if (!empty($table['views'])) {
                        $this->generateTableViews($table);
                    }

                    $this->info("Table '{$tableName}' generated successfully.");
                } catch (\Throwable $e) {
                    $this->error("Failed to generate for table '{$tableName}': " . $e->getMessage());
                }
            }

            // update routes once after all tables processed
            if (!empty($generatedControllers)) {
                $this->updateWebRoutes($generatedControllers);
            }
        }

        // --- 2. Process general components (models/controllers/views listed at top-level) ---
        $this->generateGeneralComponents();

        $this->info("✅ System generation complete.");
    }

    /**
     * Generate non-table-specific components declared in top-level 'models', 'controllers', 'views'
     */
    protected function generateGeneralComponents(): void
    {
        $this->comment("\nProcessing general components...");

        // Models (top-level)
        if (!empty($this->definition['models'])) {
            foreach ($this->definition['models'] as $modelDef) {
                $modelName = is_array($modelDef) ? ($modelDef['name'] ?? null) : $modelDef;
                $tableName = is_array($modelDef) ? ($modelDef['table'] ?? null) : null;
                if ($modelName) {
                    $this->createModelFile($modelDef, $tableName);
                }
            }
        }

        // Controllers (top-level)
        if (!empty($this->definition['controllers'])) {
            foreach ($this->definition['controllers'] as $controllerDef) {
                $this->createControllerFile($controllerDef);
            }
        }

        // Views (top-level)
        if (!empty($this->definition['views'])) {
            foreach ($this->definition['views'] as $v) {
                $this->createViewFile($v, null);
            }
        }
    }

    // --- Path / Namespace helpers ---

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

    // --- Migration generation ---

    /**
     * Generate migration file content and save it.
     *
     * @param array $table
     * @param int   $index index to offset timestamp seconds so migrations in same run have distinct timestamps
     */
    protected function generateMigration(array $table, int $index): void
    {
        $migrationPath = $this->migrationPath();
        if (!$this->files->isDirectory($migrationPath)) {
            $this->files->makeDirectory($migrationPath, 0755, true);
        }

        $columns = $table['columns'] ?? [];

        // detect if user explicitly included created_at/updated_at columns
        $hasExplicitCreatedAt = $this->columnExists($columns, 'created_at');
        $hasExplicitUpdatedAt = $this->columnExists($columns, 'updated_at');

        // Generate column lines — this method will produce column declarations and possibly inline foreign key column declarations
        $columnsCode = $this->generateColumnsCode($columns);

        // Generate foreign keys as separate ->foreign(...) statements only for columns that require separate FK statements
        $fkCode = $this->generateForeignKeysCode($columns);

        // timestamps insertion: if both explicit present -> don't add $table->timestamps()
        $timestampsCode = '';
        if (!($hasExplicitCreatedAt || $hasExplicitUpdatedAt)) {
            $timestampsCode = "\n            \$table->timestamps();";
        }

        // load stub
        $stubPath = __DIR__ . '/../../resources/stubs/migration.stub';
        if (!$this->files->exists($stubPath)) {
            // fallback: small inline default if stub missing
            $stub = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('{{tableName}}', function (Blueprint \$table) {
{{columns}}
{{foreign_keys}}{{timestamps}}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{tableName}}');
    }
};
PHP;
        } else {
            $stub = $this->files->get($stubPath);
        }

        $content = str_replace(
            ['{{tableName}}', '{{columns}}', '{{foreign_keys}}', '{{timestamps}}'],
            [$table['name'], $columnsCode, $fkCode, $timestampsCode],
            $stub
        );

        // timestamp filename logic: migrationBaseTime + index seconds ensures sequential timestamps and uniqueness within run
        $timestamp = $this->migrationBaseTime->copy()->addSeconds($index - 1)->format('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $fullPath = $migrationPath . '/' . $fileName;

        if (!$table['name']) {
            throw new \Exception("Table name missing in migration generation.");
        }

        $this->saveFileWithPrompt($fullPath, $content);
        $this->info("   - Migration created: {$fileName}");
    }

    /**
     * Return true if a column with given name exists in columns array
     */
    protected function columnExists(array $columns, string $colName): bool
    {
        foreach ($columns as $c) {
            if (($c['name'] ?? null) === $colName) return true;
        }
        return false;
    }

    /**
     * Build the column definitions lines for migration
     * - If column has 'foreign' and its type is 'integer' or not specified, generate $table->foreignId(...)
     * - Skip explicit created_at / updated_at because timestamps() handles them
     */
    protected function generateColumnsCode(array $columns): string
    {
        $lines = '';
        $existing = [];

        foreach ($columns as $col) {
            if (empty($col['name'])) continue;
            $name = $col['name'];
            if (in_array($name, $existing)) continue;
            $existing[] = $name;

            // Skip explicit timestamps (we'll handle via timestamps())
            if (in_array($name, ['created_at', 'updated_at'])) {
                // do not add explicit timestamp column here
                continue;
            }

            $type = $col['type'] ?? 'string';

            // handle id shorthand
            if (in_array($type, ['id', 'bigIncrements', 'increments'])) {
                $lines .= "            \$table->id();\n";
                continue;
            }

            // if foreign present and integer-like, prefer foreignId / unsignedBigInteger
            $hasForeign = !empty($col['foreign']);
            if ($hasForeign && in_array($type, ['integer', 'bigInteger', 'unsignedBigInteger', ''])) {
                // choose foreignId (unsignedBigInteger) by default for modern Laravel
                $lines .= "            \$table->foreignId('{$name}')";
                if (!empty($col['nullable'])) $lines .= "->nullable()";
                // don't chain constrained here; kept in separate fk code OR we can attempt to add ->constrained('table')->onDelete('...') if 'on' available
                $lines .= ";\n";
                continue;
            }

            // build base type
            // handle decimal: length may be "10,2" or array -> if numeric string contains comma, split
            $base = "            \$table->{$type}('{$name}'";
            if (!empty($col['length'])) {
                // decimal/spaces: allow "10,2" or numeric
                $len = $col['length'];
                if (is_string($len) && Str::contains($len, ',')) {
                    // For decimal you must pass precision, scale separate
                    $parts = explode(',', str_replace(' ', '', $len));
                    if (count($parts) === 2) {
                        $base .= ", {$parts[0]}, {$parts[1]}";
                    } else {
                        $base .= ", {$len}";
                    }
                } else {
                    $base .= ", {$len}";
                }
            }
            $base .= ")";

            if (!empty($col['nullable'])) $base .= "->nullable()";
            if (!empty($col['unique'])) $base .= "->unique()";
            if (!empty($col['index'])) $base .= "->index()";
            if (isset($col['default'])) {
                $default = is_numeric($col['default']) ? $col['default'] : "'" . addslashes($col['default']) . "'";
                $base .= "->default({$default})";
            }
            if (!empty($col['comment'])) $base .= "->comment('" . addslashes($col['comment']) . "')";

            $lines .= $base . ";\n";
        }

        return $lines;
    }

    /**
     * Build separate foreign key statements (where we prefer explicit ->foreign(...) calls).
     * For columns that were created with foreignId we will try to convert to ->constrained if possible
     */
    protected function generateForeignKeysCode(array $columns): string
    {
        $lines = '';

        foreach ($columns as $col) {
            if (empty($col['foreign'])) continue;
            $fk = $col['foreign'];
            $colName = $col['name'];
            $on = $fk['on'] ?? null;
            $ref = $fk['references'] ?? 'id';
            $onDelete = $fk['onDelete'] ?? null;
            $onUpdate = $fk['onUpdate'] ?? null;

            if (!$on) continue;

            // If we created a foreignId column above, we can use ->constrained('table')->onDelete(...) by emitting a separate modify statement,
            // but simpler & robust: add explicit foreign constraint
            $lines .= "            \$table->foreign('{$colName}')->references('{$ref}')->on('{$on}')";
            if ($onDelete) $lines .= "->onDelete('{$onDelete}')";
            if ($onUpdate) $lines .= "->onUpdate('{$onUpdate}')";
            $lines .= ";\n";
        }

        return $lines;
    }

    // --- Model generation ---

    protected function generateModel(array $table): void
    {
        $modelName = ucfirst(Str::singular($table['name']));

        // If model explicitly listed in top-level models, skip auto-generation (we'll create it later via generateGeneralComponents)
        $explicitModels = array_map(fn($m) => is_array($m) ? ($m['name'] ?? '') : $m, $this->definition['models'] ?? []);
        if (in_array($modelName, $explicitModels)) {
            $this->warn("   - Model '{$modelName}' is explicitly defined in 'models' array; skipping table-based model creation.");
            return;
        }

        $this->createModelFile($modelName, $table['name']);
    }

    /**
     * Create model file. $modelDef can be string (name) or array with name/table/relations.
     */
    protected function createModelFile(string|array $modelDef, ?string $tableName = null): void
    {
        $modelPath = $this->modelPath();
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $namespace = $this->getModuleNamespace('\\Models');

        // determine model name and table
        if (is_array($modelDef)) {
            $modelName = $modelDef['name'] ?? Str::studly($modelDef);
            $tableName = $tableName ?? ($modelDef['table'] ?? Str::snake(Str::plural($modelName)));
            $relations = $modelDef['relations'] ?? [];
        } else {
            $modelName = Str::studly($modelDef);
            $tableName = $tableName ?? Str::snake(Str::plural($modelName));
            $relations = [];
        }

        // get fillable columns from tables definition
        $columns = $this->getColumnsForTable($tableName);
        $fillable = $this->getFillableColumns($columns);
        $fillableCode = !empty($fillable) ? "'" . implode("', '", $fillable) . "'" : "/* fillable columns here */";

        // relationships
        $relationsCode = $this->generateRelationships($relations);

        // load stub
        $stubPath = __DIR__ . '/../../resources/stubs/model.stub';
        if ($this->files->exists($stubPath)) {
            $stub = $this->files->get($stubPath);
        } else {
            $stub = <<<PHP
<?php

namespace {{namespace}};

use Illuminate\Database\Eloquent\Model;

class {{modelName}} extends Model
{
    protected \$table = '{{tableName}}';

    protected \$fillable = [
        {{fillable}}
    ];

{{relationships}}
}
PHP;
        }

        $content = str_replace(
            ['{{modelName}}', '{{tableName}}', '{{fillable}}', '{{namespace}}', '{{relationships}}'],
            [$modelName, $tableName, $fillableCode, $namespace, $relationsCode],
            $stub
        );

        $this->saveFileWithPrompt("{$modelPath}/{$modelName}.php", $content);
        $this->info("   - Model created: {$modelName}.php");
    }

    /**
     * Build relationships code block for a model from an array like ["role:belongsTo", "workers:hasMany"]
     */
    protected function generateRelationships(array $relations = []): string
    {
        if (empty($relations)) return '';

        $code = '';
        $modelNamespace = trim($this->getModuleNamespace('\\Models\\'), '\\') . '\\';

        foreach ($relations as $rel) {
            if (!is_string($rel) || !str_contains($rel, ':')) continue;
            [$name, $type] = explode(':', $rel, 2);
            $method = Str::camel($name);
            $relatedModel = ucfirst(Str::studly(Str::singular($name)));

            switch (Str::lower($type)) {
                case 'belongsto':
                    $code .= "    public function {$method}()\n    {\n        return \$this->belongsTo(\\{$modelNamespace}{$relatedModel}::class);\n    }\n\n";
                    break;

                case 'hasmany':
                    $code .= "    public function {$method}()\n    {\n        return \$this->hasMany(\\{$modelNamespace}{$relatedModel}::class);\n    }\n\n";
                    break;

                case 'hasone':
                    $code .= "    public function {$method}()\n    {\n        return \$this->hasOne(\\{$modelNamespace}{$relatedModel}::class);\n    }\n\n";
                    break;

                case 'belongstomany':
                    $code .= "    public function {$method}()\n    {\n        return \$this->belongsToMany(\\{$modelNamespace}{$relatedModel}::class);\n    }\n\n";
                    break;

                default:
                    // unknown type: skip
                    break;
            }
        }

        return $code;
    }

    // --- Controller generation ---

    /**
     * Generate controller for a given table (returns base resource name used for route generation)
     */
    protected function generateController(array $table): ?string
    {
        $tableName = $table['name'] ?? ($table['table'] ?? null);
        if (!$tableName) return null;

        $modelBase = ucfirst(Str::singular($tableName));
        $controllerName = $modelBase . 'Controller';

        // if user declared same controller in top-level controllers, skip generating duplicate
        $declaredControllers = array_map(fn($c) => is_array($c) ? ($c['name'] ?? '') : $c, $this->definition['controllers'] ?? []);
        if (in_array($controllerName, $declaredControllers)) {
            $this->warn("   - Controller {$controllerName} declared in 'controllers'. Skipping auto-generation here.");
            return $modelBase; // still return base name for route generation (assuming their controller will exist)
        }

        // create controller file
        $this->createControllerFile($controllerName, $modelBase);

        return $modelBase;
    }

    /**
     * Create controller file. $controllerDef may be string name or array with name/table/model
     */
    protected function createControllerFile(string|array $controllerDef, ?string $modelNameFallback = null): void
    {
        $controllerPath = $this->controllerPath();
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        $controllerName = is_array($controllerDef) ? ($controllerDef['name'] ?? 'UnnamedController') : $controllerDef;
        $inferredModel = Str::studly(str_replace('Controller', '', $controllerName));
        $modelName = is_array($controllerDef) ? ($controllerDef['model'] ?? ($modelNameFallback ?? $inferredModel)) : ($modelNameFallback ?? $inferredModel);

        $modelName = ucfirst($modelName);
        $modelVariable = Str::camel($modelName);
        $controllerNamespace = $this->getModuleNamespace('\\Http\\Controllers');
        $modelNamespace = $this->getModuleNamespace('\\Models');

        // load stub
        $stubPath = __DIR__ . '/../../resources/stubs/controller.stub';
        if ($this->files->exists($stubPath)) {
            $stub = $this->files->get($stubPath);
        } else {
            // fallback simple resource controller if stub missing
            $stub = <<<PHP
<?php

namespace {{controllerNamespace}};

use {{modelNamespace}}\{{modelName}};
use Illuminate\Http\Request;

class {{controllerName}} extends Controller
{
    public function index()
    {
        \$items = {{modelName}}::paginate(10);
        return view('{{viewFolder}}.index', compact('items'));
    }

    public function create()
    {
        return view('{{viewFolder}}.create');
    }

    public function store(Request \$request)
    {
        \$data = \$request->validate([
            // TODO: add validation rules
        ]);

        {{modelName}}::create(\$data);

        return redirect()->route('{{routeName}}.index')->with('success', '{{modelName}} created.');
    }

    public function edit({{modelName}} \${{modelVariable}})
    {
        return view('{{viewFolder}}.edit', compact('{{modelVariable}}'));
    }

    public function update(Request \$request, {{modelName}} \${{modelVariable}})
    {
        \$data = \$request->validate([
            // TODO: add validation rules
        ]);

        \${{modelVariable}}->update(\$data);

        return redirect()->route('{{routeName}}.index')->with('success', '{{modelName}} updated.');
    }

    public function destroy({{modelName}} \${{modelVariable}})
    {
        \${{modelVariable}}->delete();
        return redirect()->route('{{routeName}}.index')->with('success', '{{modelName}} deleted.');
    }
}
PHP;
        }

        // view folder and route name derived from model lower plural
        $viewFolder = Str::snake(Str::plural($modelName));
        $routeName = Str::snake(Str::plural($modelName));

        $content = str_replace(
            ['{{controllerNamespace}}', '{{modelNamespace}}', '{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{viewFolder}}', '{{routeName}}'],
            [$controllerNamespace, $modelNamespace, $controllerName, $modelName, $modelVariable, $viewFolder, $routeName],
            $stub
        );

        $this->saveFileWithPrompt("{$controllerPath}/{$controllerName}.php", $content);
        $this->info("   - Controller created: {$controllerName}.php");
    }

    // --- Views generation ---

    protected function generateTableViews(array $table): void
    {
        $views = $table['views'] ?? [];
        foreach ($views as $v) {
            $this->createViewFile($v, $table['name']);
        }
    }

    /**
     * Create view files given viewDefinition like "admin/users/[index,create,edit]" or "admin/includes/head"
     */
    protected function createViewFile(string $viewDefinition, ?string $tableName = null): void
    {
        $line = trim($viewDefinition);
        $filesToCreate = [];

        // folder/[file1,file2]
        if (preg_match('/(.*?)\/\[(.*?)\]/', $line, $m)) {
            $folder = trim($m[1], '/');
            $files = array_map('trim', explode(',', $m[2]));
            $filesToCreate = array_map(fn($f) => $f . '.blade.php', $files);
        } else if (strpos($line, '/') !== false) {
            $parts = explode('/', trim($line, '/'));
            $folder = array_shift($parts);
            $filename = implode('/', $parts);
            $filesToCreate = [Str::endsWith($filename, '.blade.php') ? $filename : $filename . '.blade.php'];
        } else {
            $folder = $tableName ? Str::snake($tableName) : 'default';
            $filesToCreate = [Str::endsWith($line, '.blade.php') ? $line : $line . '.blade.php'];
        }

        $basePath = $this->basePath . '/resources/views/' . $folder;
        foreach ($filesToCreate as $file) {
            $fullPath = $basePath . '/' . $file;
            $dir = dirname($fullPath);
            if (!$this->files->isDirectory($dir)) $this->files->makeDirectory($dir, 0755, true);

            $stubPath = __DIR__ . '/../../resources/stubs/view.stub';
            if ($this->files->exists($stubPath)) {
                $stub = $this->files->get($stubPath);
                $content = str_replace('{{tableName}}', $tableName ?? '', $stub);
            } else {
                // fallback minimal template
                $content = "<!-- Auto-generated view for {$folder}/{$file} -->\n<div>\n    <h1>" . ucfirst(str_replace('.blade.php', '', $file)) . "</h1>\n</div>\n";
            }

            $this->saveFileWithPrompt($fullPath, $content);
            $this->info("   - View created: /resources/views/{$folder}/{$file}");
        }
    }

    // --- Routes update logic ---

    /**
     * Update routes/web.php: asks user if auto-generated block exists: Replace / Merge / Skip.
     * For Merge: append only new resource route lines that are not already present in the auto-generated block.
     *
     * @param array $controllersBaseNames e.g., ['User', 'Task']
     */
    protected function updateWebRoutes(array $controllersBaseNames): void
    {
        $webFile = $this->basePath . '/routes/web.php';
        if (!$this->files->exists($webFile)) {
            $this->error("web.php not found at {$webFile}. Skipping route update.");
            return;
        }

        $existing = $this->files->get($webFile);

        $blockHeader = "// -----------------------------------------------\n";
        $blockHeader .= "// AUTO-GENERATED ROUTES BY SystemBuilder PACKAGE\n";
        $blockHeader .= "// You can modify them as needed.\n";
        $blockHeader .= "// -----------------------------------------------\n";

        // build lines to add
        $linesToAdd = [];
        foreach ($controllersBaseNames as $base) {
            $resource = Str::snake(Str::plural($base));
            $controllerClass = $this->getModuleNamespace('\\Http\\Controllers\\') . $base . 'Controller';
            $linesToAdd[] = "Route::resource('{$resource}', \\{$controllerClass}::class);";
        }

        // detect existing auto-generated block
        $autoBlockExists = Str::contains($existing, 'AUTO-GENERATED ROUTES BY SystemBuilder PACKAGE');

        if ($autoBlockExists && !$this->force) {
            // ask user
            $choice = $this->choice(
                "web.php already contains an auto-generated route block. What do you want to do?",
                ['Replace', 'Merge', 'Skip'],
                1
            );

            if ($choice === 'Skip') {
                $this->info("⏩ Skipped updating web.php.");
                return;
            }

            if ($choice === 'Replace') {
                // remove the old block entirely (non-greedy between header and next blank line or file end)
                $existing = preg_replace('/\/\/ -----------------------------------------------(.|\s)*?\/\/ -----------------------------------------------\s*/', '', $existing);
                // new content becomes existing + new block
                $final = trim($existing) . "\n\n" . $blockHeader . implode("\n", $linesToAdd) . "\n";
                $this->files->put($webFile, $final);
                $this->info("✅ web.php replaced auto-generated route block.");
                return;
            }

            if ($choice === 'Merge') {
                // extract existing auto block content if present
                preg_match('/\/\/ -----------------------------------------------(.|\s)*?\/\/ -----------------------------------------------\s*/', $existing, $matches);
                $existingBlock = $matches[0] ?? '';

                // compute which lines are missing from existingBlock
                $existingLines = array_filter(array_map('trim', explode("\n", $existingBlock)));
                $existingRoutes = [];
                foreach ($existingLines as $l) {
                    if (Str::startsWith(trim($l), 'Route::resource')) {
                        $existingRoutes[] = trim($l, " \t\n\r\0\x0B;");
                    }
                }

                $toAppend = [];
                foreach ($linesToAdd as $ln) {
                    $lnTrim = trim($ln, " \t\n\r\0\x0B;");
                    if (!in_array($lnTrim, $existingRoutes)) {
                        $toAppend[] = $ln;
                    }
                }

                if (empty($toAppend)) {
                    $this->info("No new routes to merge — web.php already contains them.");
                    return;
                }

                // Append new routes right after the existing block if exists, otherwise append a new block
                if ($existingBlock) {
                    $final = str_replace($existingBlock, rtrim($existingBlock) . "\n" . implode("\n", $toAppend) . "\n", $existing);
                } else {
                    $final = trim($existing) . "\n\n" . $blockHeader . implode("\n", array_merge($existingRoutes, $toAppend)) . "\n";
                }

                $this->files->put($webFile, $final);
                $this->info("✅ Routes merged into web.php (added " . count($toAppend) . " new routes).");
                return;
            }
        }

        // default behavior: append new block if not found or --force
        $final = rtrim($existing) . "\n\n" . $blockHeader . implode("\n", $linesToAdd) . "\n";
        $this->files->put($webFile, $final);
        $this->info("✅ Routes appended to web.php");
    }

    // --- Helpers: columns / fillable / file saving / prompting ---

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
        $out = [];
        foreach ($columns as $c) {
            if (empty($c['name'])) continue;
            if (in_array($c['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) continue;
            $out[] = $c['name'];
        }
        return $out;
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

    /**
     * Save file but prompt if it already exists (unless force).
     */
    protected function saveFileWithPrompt(string $path, string $content): void
    {
        // ensure directory exists
        $dir = dirname($path);
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        if ($this->files->exists($path)) {
            if (!$this->askPermission("File already exists: " . basename($path) . ". Replace?")) {
                $this->info("Skipped: " . basename($path));
                return;
            }
        }

        try {
            $this->files->put($path, $content);
        } catch (\Throwable $e) {
            $this->error("Failed to write file {$path}: {$e->getMessage()}");
        }
    }
}
