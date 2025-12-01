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
            foreach ($tables as $table) {
                $existing = $this->migrationExists($table['name']);

                if ($existing) {
                    $choice = $this->choice(
                        "⚠ Migration for '{$table['name']}' already exists. What do you want?",
                        ['Skip', 'Replace'],
                        0
                    );

                    if ($choice === 'Skip') {
                        $this->info("⏭ Skipped: {$table['name']}");
                        continue;
                    }
                }

                $fileName = date('Y_m_d_His') . "_create_{$table['name']}_table.php";
                $path = database_path("migrations/$fileName");

                File::put($path, $this->generateMigrationContent($table));

                $this->info("✔ Migration created: $fileName");
                $this->info("👉 Run: php artisan migrate");
            }
        } catch (\Exception $e) {
            $this->errorExit($e->getMessage());
        }
    }

    // ---------------- Helper UI Methods ----------------
    protected function migrationExists($tableName)
    {
        $files = File::files(database_path('migrations'));

        foreach ($files as $file) {
            if (str_contains($file->getFilename(), $tableName)) {
                return $file->getPathname();
            }
        }

        return false;
    }

    protected function generateMigrationContent($table)
    {
        $tableName = $table['name'];
        $columns = $table['columns'];

        $schema = "";

        foreach ($columns as $col) {
            $name = $col['name'];
            $type = $col['type'];

            if ($type === 'id') {
                $schema .= "\t\t\t\$table->id();\n";
                continue;
            }

            if ($type === 'enum') {
                $values = "['" . implode("','", $col['enumValues']) . "']";
                $line = "\t\t\t\$table->enum('$name', $values)";
            } else if ($type === 'string') {
                $length = $col['length'] ?? 255;
                $line = "\t\t\t\$table->string('$name', $length)";
            } else {
                $line = "\t\t\t\$table->$type('$name')";
            }

            if (!empty($col['nullable'])) $line .= "->nullable()";
            if (!empty($col['unique'])) $line .= "->unique()";
            if (!empty($col['default'])) $line .= "->default('{$col['default']}')";
            if (!empty($col['comment'])) $line .= "->comment('{$col['comment']}')";

            $schema .= "$line;\n";

            if (!empty($col['foreign'])) {
                $fk = $col['foreign'];
                $schema .= "\t\t\t\$table->foreign('$name')"
                    . "->references('{$fk['references']}')"
                    . "->on('{$fk['on']}')"
                    . "->onDelete('{$fk['onDelete']}')"
                    . "->onUpdate('{$fk['onUpdate']}');\n";
            }
        }

        if (!empty($table['timestamps'])) $schema .= "\t\t\t\$table->timestamps();\n";
        if (!empty($table['softDeletes'])) $schema .= "\t\t\t\$table->softDeletes();\n";

        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('$tableName', function (Blueprint \$table) {
$schema
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('$tableName');
    }
};
PHP;
    }


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
