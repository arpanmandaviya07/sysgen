<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;

class InteractiveBuildCommand extends Command
{
    protected $signature = 'system:migrate
                        {--module= : Target module or folder (e.g. CRM, Blog)}
                        {--force : Overwrite existing files without confirmation}';

    protected $description = '⚡ Interactive mode to generate Migrations, Models, Controllers and Views with relationships.';

    public function handle()
    {
        $this->title("🚀 Laravel System Interactive Builder");

        $module = $this->option('module');

        if (!$module) {
            $module = $this->ask("📌 Enter the module/folder name (e.g. CRM, Blog):");
        }

        if ($module && !preg_match('/^[a-zA-Z]+$/', $module)) {
            return $this->errorExit("Module name must contain only letters!");
        }

        $tables = $this->askForTables();

        if (!$tables) return $this->warnExit("No tables defined. Exiting...");

        try {
            $builder = new SystemBuilder(['tables' => $tables], base_path(), $this->option('force'), $module);
            $builder->setOutput($this->output);
            $builder->build();

            $this->success("\n🎉 System generated successfully!");
            $this->info("👉 Run: php artisan migrate");

        } catch (\Exception $e) {
            $this->errorExit($e->getMessage());
        }
    }

    // ---------------- Helper UI Methods ----------------

    protected function title($text)
    {
        $this->info("\n$text\n" . str_repeat('-', strlen($text)));
    }

    protected function success($text)
    {
        $this->info("✔ $text");
    }

    protected function errorExit($message)
    {
        $this->error("❌ $message");
        return 1;
    }

    protected function warnExit($message)
    {
        $this->warn("⚠️ $message");
        return 0;
    }

    // ---------------- Main Table Builder ----------------

    protected function askForTables(): array
    {
        $tables = [];

        while (true) {
            $tableName = Str::snake($this->ask("\n📌 Enter table name (leave empty to finish):"));

            if (!$tableName) break;

            if (!preg_match('/^[a-zA-Z_]+$/', $tableName)) {
                $this->error("❌ Invalid name. Use only letters and underscores.");
                continue;
            }

            $this->info("🛠 Defining structure for: {$tableName}");

            $tables[] = [
                'name' => $tableName,
                'columns' => $this->askForColumns($tableName),
                'timestamps' => $this->confirm("Add timestamps?", true),
                'softDeletes' => $this->confirm("Enable soft deletes?", false),
            ];
        }

        return $tables;
    }

    // ---------------- Column Builder ----------------

    protected function askForColumns(string $tableName): array
    {
        $columns = [];

        if ($this->confirm("Add default ID primary key?", true)) {
            $columns[] = ['name' => 'id', 'type' => 'id'];
        }

        while (true) {
            $colName = Str::snake($this->ask("\n➡ Column name (blank to continue):"));

            if (!$colName) break;

            if (!preg_match('/^[a-zA-Z_]+$/', $colName)) {
                $this->error("❌ Invalid name.");
                continue;
            }

            // --- Type Choices ---
            $type = $this->choice(
                "📌 Type for '{$colName}':",
                [
                    'string', 'text', 'integer', 'bigInteger', 'unsignedBigInteger',
                    'float', 'double', 'json', 'boolean', 'date', 'dateTime', 'enum',
                ],
                Str::endsWith($colName, '_id') ? 'unsignedBigInteger' : 'string'
            );

            $column = [
                'name' => $colName,
                'type' => $type,
                'attributes' => [],
            ];

            // ENUM Support
            if ($type === 'enum') {
                $column['enumValues'] = explode(',', $this->ask("Enter enum values comma separated (e.g. active,inactive,pending):"));
            }

            if ($type === 'string') {
                $column['length'] = (int)$this->ask("Length? (default 255)", 255);
            }

            // Options
            $column['nullable'] = $this->confirm("Nullable?", false);
            $column['unique'] = $this->confirm("Unique?", false);

            if ($this->confirm("Add default value?", false)) {
                $column['default'] = $this->ask("Default value:");
            }

            if ($this->confirm("Add comment?", false)) {
                $column['comment'] = $this->ask("Enter comment:");
            }

            // Auto or Manual FK
            if (Str::endsWith($colName, '_id') || $this->confirm("Is this a foreign key?", false)) {
                $column['foreign'] = $this->askForForeignKey($colName);
            }

            $columns[] = $column;
        }

        return $columns;
    }

    // ---------------- Foreign Key Handler ----------------

    protected function askForForeignKey(string $columnName): array
    {
        $tableGuess = Str::plural(str_replace('_id', '', $columnName));

        return [
            'on' => $this->ask("Reference table?", $tableGuess),
            'references' => $this->ask("Reference column?", "id"),
            'onDelete' => $this->choice("On Delete?", ['cascade', 'restrict', 'set null', 'no action'], 0),
            'onUpdate' => $this->choice("On Update?", ['cascade', 'restrict', 'set null', 'no action'], 0),
        ];
    }
}
