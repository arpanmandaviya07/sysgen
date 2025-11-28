<?php

namespace Arpanmandaviya\SystemBuilder\Builders;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;

class SystemBuilder
{
    protected $definition;
    protected $basePath;
    protected $files;
    protected $force = false;
    protected $modulePrefix = '';
    protected OutputInterface $output;

    protected $createdTables = [];
    protected $overwriteAll = false;
    protected $skipAll = false;

    public function __construct(array $definition, string $basePath, bool $force = false, ?string $modulePrefix = null)
    {
        $this->definition = $definition;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->files = new Filesystem();
        $this->force = $force;
        $this->modulePrefix = $modulePrefix ? Str::studly($modulePrefix) : '';
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    protected function info(string $message): void
    {
        if (isset($this->output)) $this->output->writeln("<info>{$message}</info>");
    }

    protected function comment(string $message): void
    {
        if (isset($this->output)) $this->output->writeln("<comment>{$message}</comment>");
    }

    protected function error(string $message): void
    {
        if (isset($this->output)) $this->output->writeln("<error>{$message}</error>");
    }

    // --- Core Build Logic ---

    public function build()
    {
        $this->comment("Parsing minimal schema definition...");

        foreach ($this->definition as $command) {
            if (!is_string($command) || strpos($command, ':') === false) {
                $this->error("Skipping invalid command format: " . json_encode($command));
                continue;
            }

            list($type, $arguments) = explode(':', $command, 2);
            $type = Str::snake($type);

            try {
                switch ($type) {
                    case 'table':
                        $this->parseAndGenerateTable($arguments);
                        break;
                    case 'model':
                        $this->generateModel(trim($arguments));
                        break;
                    case 'controller':
                        $this->generateController(trim($arguments));
                        break;
                    case 'view':
                        $this->generateView(trim($arguments));
                        break;
                    default:
                        $this->error("Unknown command type: {$type}");
                }
            } catch (\Exception $e) {
                $this->error("Failed to process command '{$command}': " . $e->getMessage());
            }
        }
    }

    // --- Parser Functions ---

    protected function parseAndGenerateTable(string $arguments): void
    {
        $parts = array_map('trim', explode(' ', $arguments, 2));
        $tableName = array_shift($parts);
        $columnsString = array_shift($parts) ?? '';

        $tableName = Str::snake($tableName);
        $this->info("-> Generating TABLE: {$tableName}");

        // 1. Process Columns
        $columns = [['name' => 'id', 'type' => 'id']]; // Default ID

        foreach (array_map('trim', explode(',', $columnsString)) as $colDef) {
            if (empty($colDef)) continue;

            $colParts = array_map('trim', explode('|', $colDef));
            $colName = array_shift($colParts);
            $colType = array_shift($colParts) ?? 'string';

            $column = [
                'name' => Str::snake($colName),
                'type' => $colType,
                'foreign' => null
            ];

            // 2. Process Modifiers and Relations
            foreach ($colParts as $modifier) {
                $modifier = Str::lower($modifier);

                if (Str::startsWith($modifier, 'len(')) {
                    $column['length'] = (int) Str::between($modifier, 'len(', ')');
                } elseif ($modifier === 'nullable') {
                    $column['nullable'] = true;
                } elseif ($modifier === 'unique') {
                    $column['unique'] = true;
                } elseif ($modifier === 'index') {
                    $column['index'] = true;
                } elseif (Str::startsWith($modifier, 'rel(')) {
                    // rel(posts,id,cascade) -> [table, references, onDelete]
                    $relParts = array_map('trim', explode(',', Str::between($modifier, 'rel(', ')')));
                    $column['foreign'] = [
                        'on' => $relParts[0],
                        'references' => $relParts[1] ?? 'id',
                        'onDelete' => $relParts[2] ?? null,
                    ];
                }
                // Add more logic for comment, default, etc.
            }
            $columns[] = $column;
        }

        // 3. Generate Files
        $tableDefinition = [
            'name' => $tableName,
            'columns' => $columns,
            'timestamps' => true // Default to true
        ];

        $this->generateMigration($tableDefinition);
        $this->createdTables[] = $tableName;
    }

    protected function generateModel(string $arguments): void
    {
        $modelName = trim($arguments);
        $this->info("-> Generating MODEL: {$modelName}");

        $modelPath = $this->basePath . '/app/Models/' . ($this->modulePrefix ? "{$this->modulePrefix}/" : '');
        if (!$this->files->isDirectory($modelPath)) $this->files->makeDirectory($modelPath, 0755, true);

        $className = ucfirst(Str::singular($modelName));
        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/model.stub');

        // Placeholder for a proper Model generator that can infer fillable from created tables
        $content = str_replace(['{{modelName}}', '{{tableName}}', '{{fillable}}', '{{namespace}}'], [
            $className,
            Str::snake(Str::plural($modelName)),
            "/* Add fillable properties */",
            "App\Models" . ($this->modulePrefix ? "\\" . $this->modulePrefix : '')
        ], $stub);

        $this->saveFileWithPrompt("$modelPath/{$className}.php", $content);
    }

    protected function generateController(string $arguments): void
    {
        $parts = array_map('trim', explode(' ', $arguments));
        $controllerName = array_shift($parts);
        $isResource = in_array('--resource', $parts);

        $this->info("-> Generating CONTROLLER: {$controllerName}" . ($isResource ? ' (Resource)' : ''));

        $controllerPath = $this->basePath . '/app/Http/Controllers/' . ($this->modulePrefix ? "{$this->modulePrefix}/" : '');
        if (!$this->files->isDirectory($controllerPath)) $this->files->makeDirectory($controllerPath, 0755, true);

        $className = Str::endsWith($controllerName, 'Controller') ? $controllerName : $controllerName . 'Controller';
        $modelName = Str::singular(str_replace('Controller', '', $className));

        $stubName = $isResource ? 'controller.resource.stub' : 'controller.stub';
        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/' . $stubName);

        $content = str_replace(['{{controllerName}}', '{{modelName}}', '{{modelVariable}}', '{{namespace}}'], [
            $className,
            $modelName,
            Str::camel($modelName),
            "App\Http\Controllers" . ($this->modulePrefix ? "\\" . $this->modulePrefix : '')
        ], $stub);

        $this->saveFileWithPrompt("$controllerPath/{$className}.php", $content);
    }

    protected function generateView(string $arguments): void
    {
        $viewDir = trim($arguments);
        $this->info("-> Generating VIEW Directory: {$viewDir}");

        $viewPath = $this->basePath . '/resources/views/' . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $viewDir);
        if (!$this->files->isDirectory($viewPath)) $this->files->makeDirectory($viewPath, 0755, true);

        // This is highly simplified and only creates an empty directory structure based on the input
        $this->comment("   Created directory structure: {$viewPath}");
        // You would typically add logic here to generate index.blade.php, create.blade.php, etc.
    }


    // --- File Handling Functions (Migration, File Save, Prompts) ---

    protected function generateMigration(array $table)
    {
        // Logic for migration generation (kept largely similar for brevity)
        $migrationPath = $this->basePath . '/database/migrations';
        $this->files->ensureDirectoryExists($migrationPath);

        $columnsCode = $this->generateColumns($table['columns'] ?? []);
        $fkCode = $this->generateForeignKeys($table['columns'] ?? []);

        $stub = $this->files->get(__DIR__ . '/../../resources/stubs/migration.stub');

        $content = str_replace(['{{tableName}}', '{{columns}}', '{{foreign_keys}}', '{{timestamps}}'], [
            $table['name'],
            $columnsCode,
            $fkCode,
            isset($table['timestamps']) && $table['timestamps'] === false ? '' : "\n\$table->timestamps();",
        ], $stub);

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$table['name']}_table.php";
        $fullPath = $migrationPath . '/' . $fileName;

        $this->saveFileWithPrompt($fullPath, $content);
        $this->comment("   - Migration created: {$fileName}");
    }

    protected function generateColumns(array $columns): string
    {
        $lines = '';
        foreach ($columns as $col) {
            $base = "\$table->{$col['type']}('{$col['name']}'";

            if ($col['type'] === 'id') {
                $lines .= "\$table->id();\n";
                continue;
            }

            if (!empty($col['length']) && in_array($col['type'], ['string', 'char', 'varchar'])) {
                $base .= ", {$col['length']}";
            }
            $base .= ")";

            if (!empty($col['nullable'])) $base .= "->nullable()";
            if (!empty($col['unique'])) $base .= "->unique()";
            if (!empty($col['index'])) $base .= "->index()";
            // Add default, comment, etc. logic here

            $lines .= " $base;\n";
        }
        return $lines;
    }

    protected function generateForeignKeys(array $columns): string
    {
        $lines = '';
        foreach ($columns as $col) {
            if (isset($col['foreign']) && is_array($col['foreign'])) {
                $fk = $col['foreign'];
                $columnName = $col['name'];
                $on = $fk['on'] ?? null;
                $ref = $fk['references'] ?? 'id';

                if ($on) {
                    $lines .= "\$table->foreign('{$columnName}')->references('{$ref}')->on('{$on}')";
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
        // Simple file saving logic with force check, using CLI prompts for interactivity
        $fileName = basename($path);

        if ($this->files->exists($path) && !$this->force) {
            $this->comment("   - File exists: {$fileName}");
            if (!$this->askPermission("File already exists: {$fileName}. Overwrite?")) {
                return;
            }
        }

        $this->files->put($path, $content);
        $this->info("   - Created/Updated: {$fileName}");
    }

    // Interactive prompt logic for overwrite (simplified for non-interactive Builder class)
    protected function askPermission(string $message): bool
    {
        if ($this->overwriteAll) return true;
        if ($this->skipAll) return false;

        if (!isset($this->output)) return $this->force; // Non-interactive fallback

        $question = "\n⚠️  {$message} (y/n/all/skip-all) [n]: ";
        $answer = trim(readline($question));

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
}