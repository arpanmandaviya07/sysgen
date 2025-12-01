<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;
use Illuminate\Support\Str;

class InteractiveBuildCommand extends Command
{
    protected $signature = 'system:migrate
                        {--module= : The name of the module or folder (Example: Blog)}
                        {--force : Overwrite existing files without confirmation}
                        {--help : View detailed usage instructions}
                        ';

    protected $description = '⚡ Interactive mode to generate Migrations, Models, Controllers and Views with relationships.';

    public function handle()
    {
        $this->info("\n🚀 Welcome to Laravel System Interactive Builder\n--------------------------------------------------");

        $module = $this->option('module');

        if ($module && !preg_match('/^[a-zA-Z]+$/', $module)) {
            $this->error("❌ Module name must contain only letters (Example: CRM, HRM, Billing).");
            return 1;
        }

        $tables = $this->askForTables();

        if (!$tables) {
            $this->warn("⚠️ No tables defined. Exiting...");
            return 0;
        }

        // If user only wants help, show formatted data table and exit
        if ($this->option('help')) {

            $this->info("📌 System Migration Builder — Data Type Reference\n");

            $headers = ['Data Type', 'Laravel Blueprint', 'Notes / Behavior'];
            $rows = [
                ['id', '->id()', 'Auto-increment | Primary Key'],
                ['string', '->string(name, length)', 'Default length: 255'],
                ['integer', '->integer(name)', 'Signed integer'],
                ['bigInteger', '->bigInteger(name)', 'Use this when column contains `_id`'],
                ['unsignedBigInteger', '->unsignedBigInteger(name)', 'Auto-applied if column has `_id`'],
                ['text', '->text(name)', 'Large text content'],
                ['boolean', '->boolean(name)', 'true / false'],
                ['float', '->float(name, total, places)', 'Numeric with decimals'],
                ['json', '->json(name)', 'Stores JSON array / object'],
                ['date', '->date(name)', 'Only date'],
                ['dateTime', '->dateTime(name)', 'Date + Time'],
            ];

            $this->table($headers, $rows);

            $this->line("\n🔧 Required Setup Steps:");
            $this->line("  1. Name your module using --module");
            $this->line("  2. Define tables");
            $this->line("  3. Define columns");
            $this->line("  4. System will auto-detect foreign key if column ends with `_id`\n");

            $this->comment("👉 Example:");
            $this->comment("   php artisan system:migrate --module=Invoices\n");

            return Command::SUCCESS;
        }


        try {
            $builder = new SystemBuilder(['tables' => $tables], base_path(), $this->option('force'), $module);
            $builder->setOutput($this->output);
            $builder->build();

            $this->info("\n🎉 System generation completed successfully!");
            $this->comment("👉 Run: php artisan migrate");
            $this->comment("👉 Run: php artisan system:migrate --help command for help");
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return 1;
        }
    }

    protected function askForTables(): array
    {
        $tables = [];

        while (true) {
            $tableName = Str::snake($this->ask("\n📌 Enter table name (leave empty to stop):"));

            if (!$tableName) break;

            if (!preg_match('/^[a-zA-Z_]+$/', $tableName)) {
                $this->error("❌ Invalid name. Only letters and underscores allowed.");
                continue;
            }

            $this->line("🛠 Building: {$tableName}");

            $tables[] = [
                'name' => $tableName,
                'columns' => $this->askForColumns($tableName),
                'timestamps' => $this->confirm("Add timestamps? (created_at, updated_at)", true),
            ];
        }

        return $tables;
    }

    protected function askForColumns(string $tableName): array
    {
        $columns = [];
        $hasPrimaryKey = false;

        if ($this->confirm("Add default ID primary key? (\$table->id())", true)) {
            $columns[] = ['name' => 'id', 'type' => 'id', 'attributes' => []];
            $hasPrimaryKey = true;
        }

        while (true) {
            $colName = Str::snake($this->ask("\n➡ Column name (blank to continue):"));

            if (!$colName) break;

            if (!preg_match('/^[a-zA-Z_]+$/', $colName)) {
                $this->error("❌ Invalid name. Only letters and underscores allowed.");
                continue;
            }

            // Auto-detect FK
            $isForeignKey = Str::endsWith($colName, '_id');
            $defaultType = $isForeignKey ? 'unsignedBigInteger' : 'string';

            $type = $this->choice(
                "📌 Type for '{$colName}':",
                [
                    'string', 'text', 'integer', 'bigInteger', 'unsignedBigInteger',
                    'boolean', 'date', 'dateTime', 'float', 'double', 'json',
                ],
                $defaultType
            );

            $column = [
                'name' => $colName,
                'type' => $type,
                'attributes' => [],
            ];

            if ($type === 'string') {
                $column['length'] = (int)$this->ask("Length? (default 255)", 255);
            }

            $column['nullable'] = $this->confirm("Nullable?", false);
            $column['unique'] = $this->confirm("Unique?", false);

            if ($this->confirm("Add default value?", false)) {
                $column['default'] = $this->ask("Default value:");
            }

            if ($this->confirm("Add column comment?", false)) {
                $column['comment'] = $this->ask("Enter comment:");
            }

            if ($isForeignKey || $this->confirm("Is this a Foreign Key?", false)) {
                $column['foreign'] = $this->askForForeignKey($colName);
            }

            $columns[] = $column;
        }

        return $columns;
    }

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
