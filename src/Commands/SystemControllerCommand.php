<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Arpanmandaviya\SystemBuilder\Builders\SystemBuilder;

class SystemControllerCommand extends Command
{
    protected $signature = <<<SIGNATURE
system:controller
    {name : Name of the controller (Example: UserController, InvoiceController)}
    {--resource : Create a full Laravel resource controller}
    {--force : Overwrite existing controller if it exists}
    {--help : Display this help menu}
SIGNATURE;

    protected $description = 'Generate a controller with optional resource structure.';

    protected $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        if ($this->option('help')) {
            return $this->helpMenu();
        }

        $name = Str::studly($this->argument('name'));
        $namespace = "App\\Http\\Controllers";

        // Ensure the name ends with "Controller"
        if (!Str::endsWith($name, 'Controller')) {
            $name .= 'Controller';
        }

        $path = app_path("Http/Controllers/{$name}.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->error("⚠ Controller already exists: {$name}. Use --force to overwrite.");
            return Command::FAILURE;
        }

        $isResource = $this->option('resource') ?: $this->confirm("Generate Resourceful Controller?", true);

        $modelName = Str::before($name, 'Controller');
        $modelClass = "App\\Models\\{$modelName}";
        $modelExists = class_exists($modelClass);

        $stub = $isResource
            ? $this->resourceStub($name, $modelExists ? $modelName : null)
            : $this->basicStub($name);

        $this->files->put($path, $stub);

        $this->info("✅ Controller created: {$name}");
        return Command::SUCCESS;
    }

    protected function basicStub($name)
    {
        return <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class {$name} extends Controller
{
    public function index()
    {
        return view(strtolower(str_replace('Controller', '', '{$name}')).'.index');
    }
}
PHP;
    }

    protected function resourceStub($name, $model = null)
    {
        $modelUse = $model ? "use App\\Models\\{$model};\n" : "";

        $binding = $model
            ? "{$model} \${$model}"
            : "int \$id";

        return <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
{$modelUse}
class {$name} extends Controller
{
    public function index()
    {
        return view(strtolower(str_replace('Controller', '', '{$name}')).'.index');
    }

    public function create()
    {
        return view(strtolower(str_replace('Controller', '', '{$name}')).'.create');
    }

    public function store(Request \$request)
    {
        // TODO: Validate and store data
    }

    public function show({$binding})
    {
        // TODO: Show single record
    }

    public function edit({$binding})
    {
        return view(strtolower(str_replace('Controller', '', '{$name}')).'.edit');
    }

    public function update(Request \$request, {$binding})
    {
        // TODO: Update record
    }

    public function destroy({$binding})
    {
        // TODO: Delete record
    }
}
PHP;
    }

    protected function helpMenu()
    {
        $this->info("📘 System Controller Generator — Help Menu\n");

        $this->comment("🧾 Usage:");
        $this->line("  php artisan system:controller ControllerName");
        $this->line("");

        $this->comment("📌 Arguments:");
        $this->table(
            ['Argument', 'Required', 'Description'],
            [
                ['name', 'YES', 'Controller name (auto-adds Controller suffix)'],
            ]
        );

        $this->comment("⚙ Options:");
        $this->table(
            ['Option', 'Default', 'Description'],
            [
                ['--resource', 'false', 'Generate full RESTful methods'],
                ['--force', 'false', 'Overwrite if file exists'],
                ['--help', 'false', 'Show this help table'],
            ]
        );

        $this->comment("📍 Examples:");
        $this->line("  ➤ Create normal controller:");
        $this->line("     php artisan system:controller BlogController");
        $this->line("");
        $this->line("  ➤ Create resource controller:");
        $this->line("     php artisan system:controller BlogController --resource");
        $this->line("");
        $this->line("  ➤ Overwrite existing:");
        $this->line("     php artisan system:controller UserController --force");

        return Command::SUCCESS;
    }
}
