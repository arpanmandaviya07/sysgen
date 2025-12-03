<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

class SystemControllerCommand extends Command
{
    protected $signature = 'system:controller {name?} {--resource} {--force}';
    protected $description = 'Generate controllers with smart CRUD logic and model detection.';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $name = $this->askControllerName();
        $path = app_path("Http/Controllers/{$name}.php");

        if ($this->fileExists($path)) return Command::FAILURE;

        $logicType = $this->askLogicType();

        // Ask model if logic requires model
        $modelName = null;
        $fields = [];
        $modelExists = false;

        if ($logicType !== "Empty Methods") {
            $modelName = $this->askModelName();
            $modelExists = true;

            $tableName = Str::snake(Str::plural($modelName));

            if (Schema::hasTable($tableName)) {
                $fields = Schema::getColumnListing($tableName);
                $fields = array_filter($fields, fn($f)=> !in_array($f, ['id','created_at','updated_at']));
                $this->info("🧩 Fields detected: " . implode(', ', $fields));
            } else {
                $this->warn("⚠ Table `$tableName` not found. Generating controller without DB field autofill.");
            }
        }

        $methods = $this->option('resource')
            ? ['index','create','store','show','edit','update','destroy']
            : ['index','create','store','edit','update','destroy','show'];

        $controllerContent = $this->generateController($name, $logicType, $modelName, $fields, $methods);

        $this->files->put($path, $controllerContent);

        $this->successMessage($name, $logicType, $modelExists);

        return self::SUCCESS;
    }

    protected function askControllerName()
    {
        $name = $this->argument('name') ?? $this->ask("📝 Enter Controller Name:");

        return Str::endsWith($name, 'Controller') ? $name : $name.'Controller';
    }

    protected function askLogicType()
    {
        return $this->choice("⚙ Controller Logic Type:", [
            "Empty Methods",
            "Simulated API CRUD",
            "Auto CRUD Logic (Model + DB Fields)"
        ], 0);
    }

    protected function askModelName()
    {
        while (true) {
            $name = Str::studly($this->ask("📦 Enter Model Name:"));
            if (class_exists("App\\Models\\$name")) return $name;

            $this->error("❌ Model `$name` not found in App\\Models. Try again.");
        }
    }

    protected function fileExists($path)
    {
        if ($this->files->exists($path) && !$this->option('force')) {
            return !$this->confirm("⚠ File already exists. Overwrite?", false);
        }
        return false;
    }

    protected function generateController($name, $logicType, $model, $fields, $methods)
    {
        $methodCode = "";

        foreach ($methods as $m) {
            $methodCode .= $this->generateMethod($m, $logicType, $model, $fields);
        }

        $modelImport = $model ? "use App\Models\\{$model};\n" : "";
        $strImport   = $logicType === "Simulated API CRUD" ? "use Illuminate\Support\Str;\n" : "";

        return <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
{$modelImport}{$strImport}

class {$name} extends Controller
{

    protected \$items = [
        ['id'=> '1', 'name'=> 'Sample Item', 'price'=> 100]
    ];

$methodCode
}
PHP;
    }

    protected function generateMethod($method, $logicType, $model, $fields)
    {
        if ($logicType === "Empty Methods") {
            return "\n    public function $method(){}\n";
        }

        if ($logicType === "Simulated API CRUD") {
            return $this->simulatedLogic($method);
        }

        if ($logicType === "Auto CRUD Logic (Model + DB Fields)") {
            return $this->databaseLogic($method, $model, $fields);
        }

        return "";
    }

    protected function simulatedLogic($method)
    {
        return match($method) {
            'index' => "\n    public function index() { return response()->json(['data'=>\$this->items]); }\n",
            'store' => <<<PHP

    public function store(Request \$request) {
        \$request->validate(['name'=>'required','price'=>'required|numeric']);
        \$item = ['id'=>Str::uuid(), 'name'=>\$request->name, 'price'=>\$request->price];
        \$this->items[] = \$item;
        return response()->json(['message'=>'Created','data'=>\$item],201);
    }

PHP,
            'update' => <<<PHP

    public function update(Request \$request, \$id) {
        \$key = collect(\$this->items)->search(fn(\$i)=>\$i['id']==\$id);
        if(\$key===false) return response()->json(['message'=>'Not Found'],404);
        \$this->items[\$key]['name'] = \$request->name;
        \$this->items[\$key]['price'] = \$request->price;
        return response()->json(['message'=>'Updated']);
    }

PHP,
            'destroy' => "\n    public function destroy(\$id){ return response()->json(['message'=>'Deleted']); }\n",
            default => "\n    public function $method(){}\n",
        };
    }

    protected function databaseLogic($method, $model, $fields)
    {
        $validation = implode("','", $fields);

        return match($method) {
            'index' => "\n    public function index(){ return {$model}::paginate(10); }\n",
            'store' => <<<PHP

    public function store(Request \$request){
        \$validated = \$request->validate([
            '{$validation}' => 'required'
        ]);

        return {$model}::create(\$validated);
    }

PHP,
            'update' => <<<PHP

    public function update(Request \$request, \$id){
        \$validated = \$request->validate([
            '{$validation}' => 'required'
        ]);

        \$item = {$model}::findOrFail(\$id);
        \$item->update(\$validated);
        return \$item;
    }

PHP,
            'destroy' => <<<PHP

    public function destroy(\$id){
        return {$model}::destroy(\$id);
    }

PHP,
            'show' => "\n    public function show(\$id){ return {$model}::findOrFail(\$id); }\n",
            default => "\n    public function $method(){}\n",
        };
    }

    protected function successMessage($name, $logicType, $modelLinked)
    {
        $this->info("\n🎉 Controller `$name` generated successfully!");
        $this->line("⚙ Logic: $logicType");
    }
}
