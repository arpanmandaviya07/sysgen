<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;
use Illuminate\Support\Str;

class InteractiveBuildCommand extends Command
{
    // Update the signature to the new interactive one
    protected $signature = 'system:interactive
                            {--module= : The name of the module/folder to generate files into (e.g., Invoices)}
                            {--force : Overwrite existing files without asking}';

    protected $description = 'Interactively generate migrations, models, controllers, and views.';

    public function handle()
    {
        $this->info('🚀 Starting Interactive System Builder...');

        $module = $this->option('module');
        if ($module && !preg_match('/^[a-zA-Z]+$/', $module)) {
            $this->error("Invalid module name. Use only letters (e.g., Invoices).");
            return 1;
        }

        // 1. Get Table Definition
        $tables = $this->askForTables();

        if (empty($tables)) {
            $this->info("No tables defined. Operation cancelled.");
            return 0;
        }

        // 2. Prepare Definition for Builder
        $definition = ['tables' => $tables];

        // 3. Build System
        try {
            // Instantiate the builder and pass the Command's output for professional logging
            $builder = new SystemBuilder($definition, base_path(), $this->option('force'), $module);
            $builder->setOutput($this->output); // Inject the output for $this->info() etc.

            $builder->build();

            $this->info('✅ All files generated successfully!');

            // Helpful next step
            $this->comment('✨ Don\'t forget to run "php artisan migrate" and setup your routes.');

            return 0;
        } catch (\Exception $e) {
            $this->error('An unexpected error occurred during build: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Guides the user through defining tables, columns, and foreign keys.
     * @return array
     */
    protected function askForTables(): array
    {
        $tables = [];

        do {
            $tableName = $this->ask("Table name (e.g., posts, users, or leave empty to finish)", '');

            if (empty($tableName)) {
                break;
            }

            $tableName = Str::snake($tableName); // Ensure snake_case

            $table = [
                'name' => $tableName,
                'columns' => $this->askForColumns($tableName),
                'timestamps' => $this->confirm("Add timestamps (created_at, updated_at)?", true)
            ];

            $tables[] = $table;
        } while (true);

        return $tables;
    }

    /**
     * Guides the user through defining columns.
     * @param string $tableName
     * @return array
     */
    protected function askForColumns(string $tableName): array
    {
        $this->comment("Defining columns for table: {$tableName}");
        $columns = [];
        $hasPrimary = false;

        // Default primary key for a professional feel
        if ($this->confirm("Add default primary key (\$table->id())?", true)) {
            $columns[] = ['name' => 'id', 'type' => 'id'];
            $hasPrimary = true;
        }

        do {
            $colName = $this->ask("Column name (e.g., title, user_id, or leave empty to finish columns)", '');

            if (empty($colName)) {
                break;
            }

            // Standardize column name
            $colName = Str::snake($colName);

            $type = $this->choice(
                "Select data type for '{$colName}'",
                [
                    'string', 'integer', 'text', 'bigInteger',
                    'boolean', 'date', 'dateTime', 'float', 'json'
                ],
                0 // Default choice is 'string'
            );

            $column = [
                'name' => $colName,
                'type' => $type,
            ];

            // Ask for additional modifiers
            if ($type === 'string') {
                $column['length'] = (int) $this->ask("String length (optional, e.g., 255)", 255);
            }
            if ($this->confirm("Is '{$colName}' nullable?", false)) {
                $column['nullable'] = true;
            }
            if ($this->confirm("Is '{$colName}' unique?", false)) {
                $column['unique'] = true;
            }

            // Ask about foreign key for professional relational schema
            if ($this->confirm("Is '{$colName}' a foreign key?", false)) {
                $column['foreign'] = $this->askForForeignKey($colName);
            }

            $columns[] = $column;

        } while (true);

        return $columns;
    }

    /**
     * Guides the user through defining a foreign key relationship.
     * @param string $columnName
     * @return array
     */
    protected function askForForeignKey(string $columnName): array
    {
        $fk = [];

        $fk['on'] = $this->ask("References table (e.g., users)");
        $fk['references'] = $this->ask("References column (e.g., id)", 'id');

        // Professional default cascade options
        $deleteAction = $this->choice(
            "On Delete action",
            ['no action', 'restrict', 'cascade', 'set null'],
            1 // Default is 'restrict'
        );
        if ($deleteAction !== 'no action') {
            $fk['onDelete'] = $deleteAction;
        }

        $updateAction = $this->choice(
            "On Update action",
            ['no action', 'restrict', 'cascade', 'set null'],
            1 // Default is 'restrict'
        );
        if ($updateAction !== 'no action') {
            $fk['onUpdate'] = $updateAction;
        }

        return $fk;
    }
}