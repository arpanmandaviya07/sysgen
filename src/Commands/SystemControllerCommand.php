<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class SystemControllerCommand extends Command
{
    protected $signature   = 'system:controller {name?} {--force}';
    protected $description = 'Ultimate: generate a controller with smart CRUD logic, model detection, blade form scanner, JSON/HTML modes and robust output.';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $this->info("\n➡️  System Controller Generator (Ultimate)\n");

        // 1. Controller name (argument or ask)
        $controllerName = $this->argument('name') ?: $this->askUntilValid("Enter controller name (Example: ProductController)", fn($v) => !empty($v));
        $controllerName = Str::studly($controllerName);
        if (!Str::endsWith($controllerName, 'Controller')) {
            $controllerName .= 'Controller';
        }

        // Confirm
        if (!$this->confirm("Create controller named '{$controllerName}'?", true)) {
            $this->warn("Cancelled by user.");
            return self::SUCCESS;
        }

        $controllerPath = app_path("Http/Controllers/{$controllerName}.php");
        if ($this->fileExistsDecision($controllerPath)) {
            $this->info("Aborting.");
            return self::FAILURE;
        }


        // 2. Mode: html or json
        $responseMode = $this->choice("Response type for controller methods:", ['html', 'json'], 0);

        // 3. Controller type: Resource or Normal
        $controllerType = $this->choice("Controller style:", ['resource', 'normal'], 0);

// SHORT-CIRCUIT for normal/empty controllers
        if ($controllerType === 'normal') {
            $content = <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class {$controllerName} extends Controller
{
    //
}
PHP;

            $this->files->put($controllerPath, $content);

            $this->info("\n✅ Normal controller '{$controllerName}' created at: {$controllerPath}\n");
            return self::SUCCESS; // exit early, no more prompts
        }

        $logicLevel = trim($this->choice("Logic level:", [
            'Empty Methods',
            'Auto CRUD Logic (Model + DB fields)',
            'Simulated API CRUD (in-memory, like ProductController)',
        ], 1));
        // 5. If Auto CRUD -> require model
        $modelName = null;
        $modelExists = false;
        $tableFields = [];

        if (str_contains($logicLevel, 'Auto CRUD Logic (Model + DB fields)') || str_contains($logicLevel, 'Simulated API CRUD (in-memory, like ProductController)')) {

            while (true) {
                $rawModel = $this->ask("Enter model name (App\\Models\\ModelName) or 'T' to terminate:");
                if ($this->terminate($rawModel)) return self::SUCCESS;
                $rawModel = Str::studly(trim($rawModel));

                if (!$rawModel) {
                    $this->error("Model name is required for Auto CRUD Logic.");
                    continue;
                }

                if (!class_exists("App\\Models\\$rawModel")) {
                    $this->error("Model App\\Models\\$rawModel not found. Try again or create the model first.");
                    continue;
                }

                $modelName = $rawModel;
                $modelExists = true;
                break;
            }

            // Detect DB fields
            $table = Str::snake(Str::plural($modelName));
            if (!Schema::hasTable($table)) {
                $this->warn("Model found but table '{$table}' does not exist. Auto DB detection will be skipped unless you enter a blade view.");
            } else {
                $fields = Schema::getColumnListing($table);
                // remove common meta fields
                $tableFields = array_values(array_filter($fields, fn($c) => !in_array($c, ['id', 'created_at', 'updated_at', 'deleted_at'])));
                $this->info("Detected table fields: " . implode(', ', $tableFields));
            }
        }

        // 6. Ask if user wants to scan a blade form for fields
        $useView = $this->confirm("Do you want to detect form inputs from a blade view file? (recommended)", false);

        $formFields = [];
        $chosenFormSelector = null;

        if ($useView) {
            while (true) {
                $viewPath = $this->ask("Enter blade path (example: tasks.create => resources/views/tasks/create.blade.php) or T to terminate:");
                if ($this->terminate($viewPath)) return self::SUCCESS;
                $viewPath = trim($viewPath);

                // normalize: if user typed dot notation remove .blade.php if present
                $fullBlade = $viewPath;
                if (Str::endsWith($fullBlade, '.blade.php')) {
                    // convert filesystem path to view path
                    // we can still accept it below
                }

                // try multiple plausible full paths
                $possible = $this->possibleViewFilesystemPaths($fullBlade);

                $found = null;
                foreach ($possible as $p) {
                    if ($this->files->exists($p)) {
                        $found = $p;
                        break;
                    }
                }

                if ($found === null) {
                    $this->error("Blade view file not found at: " . implode(' or ', $possible));
                    if (!$this->confirm("Try again?", true)) break;
                    continue;
                }

                // read content
                $content = $this->files->get($found);

                // find forms
                preg_match_all('#<form\b[^>]*>(.*?)</form>#is', $content, $formMatches, PREG_SET_ORDER);

                if (empty($formMatches)) {
                    $this->warn("No <form> tag found in that blade file.");
                    if ($this->confirm("Use this file anyway and attempt to extract inputs outside forms?", false)) {
                        // fallback to extract input name attributes globally
                        preg_match_all('/name="([^"]+)"/', $content, $names);
                        $formFields = array_values(array_unique($names[1] ?? []));
                        break;
                    }
                    if (!$this->confirm("Try a different file?", true)) break;
                    continue;
                }

                // if multiple forms, ask which one or let user pass a CSS selector
                if (count($formMatches) > 1) {
                    $this->info("Found " . count($formMatches) . " <form> tags in the file.");
                    $choice = $this->ask("Enter form index (1.." . count($formMatches) . ") or CSS id/class (like #myForm or .my-form) to select form, or press Enter to pick the first form:");
                    if ($this->terminate($choice)) return self::SUCCESS;

                    if (is_numeric($choice) && intval($choice) >= 1 && intval($choice) <= count($formMatches)) {
                        $idx = intval($choice) - 1;
                        $selectedFormHtml = $formMatches[$idx][0];
                    } else if (!empty($choice)) {
                        // try to match by id/class attribute in forms
                        $selectedFormHtml = null;
                        foreach ($formMatches as $match) {
                            if (preg_match('#<form\b[^>]*' . preg_quote($choice, '#') . '[^>]*>#i', $match[0])) {
                                $selectedFormHtml = $match[0];
                                break;
                            }
                        }
                        if (!$selectedFormHtml) {
                            $this->warn("Could not find a form matching selector '{$choice}'. Using first form.");
                            $selectedFormHtml = $formMatches[0][0];
                        }
                    } else {
                        $selectedFormHtml = $formMatches[0][0];
                    }
                } else {
                    $selectedFormHtml = $formMatches[0][0];
                }

                // extract input/select/textarea names from selectedFormHtml
                preg_match_all('/\bname\s*=\s*"([^"]+)"/i', $selectedFormHtml, $nm);
                $formFields = array_values(array_unique($nm[1] ?? []));

                $this->info("Extracted fields from view/form: " . implode(', ', $formFields));

                if (empty($formFields) && $this->confirm("No named inputs found inside the form. Do you want to try the entire file for inputs?", true)) {
                    preg_match_all('/\bname\s*=\s*"([^"]+)"/i', $content, $nm);
                    $formFields = array_values(array_unique($nm[1] ?? []));
                    $this->info("Extracted fields from entire file: " . implode(', ', $formFields));
                }

                break;
            } // end while view ask
        }

        // choose final fields: view fields preferred over DB fields, else DB, else empty
        $finalFields = [];
        if (!empty($formFields)) {
            $finalFields = $formFields;
        } else if (!empty($tableFields)) {
            $finalFields = $tableFields;
        } else {
            $finalFields = [];
        }

        // 7. ask for post-action views (redirects) if HTML mode
        $postActionViews = [
            'store' => null,
            'update' => null,
            'destroy' => null,
        ];

        if ($responseMode === 'html') {
            foreach (array_keys($postActionViews) as $action) {
                $ask = $this->ask("Optional: view or route to redirect after '{$action}' (example: tasks.index or /tasks) or press Enter to default (back):");
                if ($this->terminate($ask)) return self::SUCCESS;
                $ask = trim($ask);
                if ($ask) {
                    // for view path validate file exists if it looks like a view (no starting slash)
                    if (!Str::startsWith($ask, '/')) {
                        $possible = $this->possibleViewFilesystemPaths($ask);
                        $exists = false;
                        foreach ($possible as $p) {
                            if ($this->files->exists($p)) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $this->warn("View file for '{$ask}' was not found. It will be used as-is (developer must create it).");
                        }
                    }
                    $postActionViews[$action] = $ask;
                }
            }
        }

        // 8. Methods to generate
        $methods = $controllerType === 'resource'
            ? ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']
            : ['index', 'create', 'store', 'edit', 'update', 'destroy', 'show'];

        // 9. Generate controller content
        $content = $this->buildControllerContent(
            $controllerName,
            $modelName,
            $modelExists,
            $finalFields,
            $methods,
            $responseMode,
            $logicLevel,
            $postActionViews
        );

        $this->files->put($controllerPath, $content);

        $this->info("\n✅ Controller '{$controllerName}' created at: {$controllerPath}");
        $this->line("Response mode: {$responseMode}");
        $this->line("Logic Level: {$logicLevel}");
        if ($modelName) $this->line("Model: {$modelName} (" . ($modelExists ? 'found' : 'not found on DB') . ")");
        $this->line("Fields used: " . (empty($finalFields) ? '[none]' : implode(', ', $finalFields)));
        $this->info("\nYou can now open the generated controller and adjust validation rules or views as needed.\n");

        return self::SUCCESS;
    }

    /* -------------------- helpers -------------------- */

    protected function askUntilValid(string $question, callable $validator)
    {
        while (true) {
            $v = $this->ask($question);
            if ($this->terminate($v)) exit;
            if ($validator($v)) return $v;
            $this->error("Invalid input. Try again or enter 'T' to terminate.");
        }
    }

    protected function terminate($value): bool
    {
        if (is_string($value) && strtoupper(trim($value)) === 'T') {
            $this->warn("Process terminated by user.");
            return true;
        }
        return false;
    }

    protected function fileExistsDecision($path): bool
    {
        if ($this->files->exists($path) && !$this->option('force')) {
            if (!$this->confirm("File already exists at {$path}. Overwrite?", false)) {
                return true;
            }
        }
        return false;
    }

    protected function possibleViewFilesystemPaths(string $viewDotOrPath): array
    {
        $candidates = [];

        // If user supplied dot notation like tasks.create
        if (Str::contains($viewDotOrPath, '.')) {
            $dotToPath = str_replace('.', DIRECTORY_SEPARATOR, $viewDotOrPath);
            $candidates[] = resource_path("views/{$dotToPath}.blade.php");
        }

        // If user supplied a partial path without dot
        $candidates[] = resource_path("views/{$viewDotOrPath}.blade.php");
        $candidates[] = base_path($viewDotOrPath); // raw path
        return $candidates;
    }

    /* -------------------- builder -------------------- */

    protected function buildControllerContent(
        string $controller,
        ?string $model,
        bool $modelExists,
        array $fields,
        array $methods,
        string $responseMode,
        string $logicLevel,
        array $postViews
    ): string {
        $imports = [
            "use Illuminate\Http\Request;",
            "use Illuminate\Support\Facades\Log;",
        ];

        if ($model) {
            $imports[] = "use App\\Models\\{$model};";
        }

        $importsBlock = implode("\n", $imports);

        // Validation fields array
        $rulesArray = $this->makeValidationRules($fields);
        $rules = $this->validationRulesToPhpArray($rulesArray);

        return <<<PHP
<?php

namespace App\Http\Controllers;

{$importsBlock};

/*
|--------------------------------------------------------------------------
| Auto-Generated Controller (System Builder V3)
|--------------------------------------------------------------------------
| Model: {$model}
| Mode: JSON Based CRUD Controller
| Created: now()
| Developer: Auto-Builder 🤖
|--------------------------------------------------------------------------
*/

class {$controller} extends Controller
{
    public function index(Request \$request)
    {
        try {
            \$items = {$model}::latest()->paginate(10);

            return response()->json([
                'status' => true,
                'message' => '{$model} records fetched successfully.',
                'data' => \$items
            ], 200);

        } catch (\Exception \$e) {
            Log::error("Controller(index) Error: " . \$e->getMessage());
            return response()->json(['status' => false, 'message' => 'Something went wrong!'], 500);
        }
    }


    public function store(Request \$request)
    {
        try {
            \$validated = \$request->validate([
                {$rules}
            ]);

            \$record = {$model}::create(\$validated);

            return response()->json([
                'status' => true,
                'message' => '{$model} created successfully.',
                'data' => \$record
            ], 201);

        } catch (\Illuminate\Validation\ValidationException \$e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => \$e->errors()
            ], 422);

        }
    }


    public function show(string \$id)
    {
        try {
            \$item = {$model}::find(\$id);

            if (!\$item) {
                return response()->json(['status' => false, 'message' => '{$model} not found.'], 404);
            }

            return response()->json([
                'status' => true,
                'message' => '{$model} retrieved successfully.',
                'data' => \$item
            ], 200);

        } catch (\Exception \$e) {
            Log::error("Controller(show) Error: " . \$e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to fetch record.'], 500);
        }
    }


    public function update(Request \$request, string \$id)
    {
        try {
            \$validated = \$request->validate([
                {$rules}
            ]);

            \$record = {$model}::find(\$id);

            if (!\$record) {
                return response()->json(['status' => false, 'message' => '{$model} not found.'], 404);
            }

            \$record->update(\$validated);

            return response()->json([
                'status' => true,
                'message' => '{$model} updated successfully.',
                'data' => \$record
            ], 200);

        } catch (\Illuminate\Validation\ValidationException \$e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => \$e->errors()
            ], 422);
        }
    }


    public function destroy(string \$id)
    {
        try {
            \$record = {$model}::find(\$id);

            if (!\$record) {
                return response()->json(['status' => false, 'message' => '{$model} not found.'], 404);
            }

            \$record->delete();

            return response()->json([
                'status' => true,
                'message' => '{$model} deleted successfully.'
            ], 200);

        } catch (\Exception \$e) {
            Log::error("Controller(destroy) Error: " . \$e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to delete record.'], 500);
        }
    }
}
PHP;
    }


    protected function generateSimulatedProperty(string $logicLevel, array $fields): string
    {
        if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
            // create a small sample array with first field or generic fields
            $sample = [
                "['id' => 'abc-1', 'name' => 'Sample A', 'price' => 100.00]",
                "['id' => 'abc-2', 'name' => 'Sample B', 'price' => 200.00]",
            ];
            $items = implode(",\n        ", $sample);
            return <<<PHP
    private \$items = [
        {$items}
    ];
PHP;
        }

        return "";
    }

    protected function renderMethod(
        string $method,
        ?string $model,
        bool $modelExists,
        array $fields,
        string $responseMode,
        string $logicLevel,
        array $postViews,
        string $friendlyModelName
    ): string {
        // helper closures to build rules and validation arrays
        $validationRules = $this->makeValidationRules($fields);

        $rulesArrayString = $this->validationRulesToPhpArray($validationRules);

        // derived messages
        $createdMsg = "{$friendlyModelName} created successfully.";
        $updatedMsg = "{$friendlyModelName} updated successfully.";
        $deletedMsg = "{$friendlyModelName} deleted successfully.";

        // choose implementations
        switch ($method) {
            case 'index':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    if ($responseMode === 'json') {
                        return <<<PHP
    public function index()
    {
        return response()->json([
            'message' => '{$friendlyModelName} retrieved successfully.',
            'data' => \$this->items
        ]);
    }
PHP;
                    } else {
                        return <<<PHP
    public function index()
    {
        // In-memory sample: convert to collection so views can use paginate-like behaviour if needed.
        \$items = collect(\$this->items);
        return view('{$this->fallbackView($model, 'index')}', ['items' => \$items]);
    }
PHP;
                    }
                }

                // Real model
                if ($responseMode === 'json') {
                    return <<<PHP
    public function index()
    {
        \$data = {$model}::latest()->paginate(10);
        return response()->json([
            'message' => '{$friendlyModelName} retrieved successfully.',
            'data' => \$data
        ]);
    }
PHP;
                }

                return <<<PHP
    public function index()
    {
        \$items = {$model}::latest()->paginate(10);
        return view('{$this->fallbackView($model, 'index')}', ['items' => \$items]);
    }
PHP;

            case 'create':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    if ($responseMode === 'json') {
                        return <<<PHP
    public function create()
    {
        return response()->json(['message' => 'View for creating a new {$friendlyModelName}.']);
    }
PHP;
                    } else {
                        return <<<PHP
    public function create()
    {
        return view('{$this->fallbackView($model, 'create')}');
    }
PHP;
                    }
                }

                // model or normal
                if ($responseMode === 'json') {
                    return <<<PHP
    public function create()
    {
        return response()->json(['message' => 'View for creating a new {$friendlyModelName}.']);
    }
PHP;
                }

                return <<<PHP
    public function create()
    {
        return view('{$this->fallbackView($model, 'create')}');
    }
PHP;

            case 'store':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    // build validation snippet
                    $valSnippet = $this->validationRulesInline($validationRules);
                    return <<<PHP
    public function store(Request \$request)
    {
{$valSnippet}
        \$new = [
            'id' => (string) Str::uuid(),
{$this->sampleDataFromFields($fields)}
        ];

        \$this->items[] = \$new;

        return response()->json([
            'message' => '{$friendlyModelName} created successfully.',
            'data' => \$new
        ], 201);
    }
PHP;
                }

                // Real model
                $postRedirect = $postViews['store'] ?? null;
                $postRedirectReturn = $this->buildPostActionReturn($postRedirect, $responseMode, $createdMsg);

                return <<<PHP
    public function store(Request \$request)
    {
        try {
            \$validated = \$request->validate([
{$rulesArrayString}
            ]);

            \$record = {$model}::create(\$validated);

            {$postRedirectReturn}
        } catch (\\Exception \$e) {
            if ('{$responseMode}' === 'json') {
                return response()->json(['message' => \$e->getMessage()], 500);
            }
            return redirect()->back()->with('error', \$e->getMessage());
        }
    }
PHP;

            case 'show':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    return <<<PHP
    public function show(string \$id)
    {
        \$item = collect(\$this->items)->firstWhere('id', \$id);
        if (!\$item) {
            return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        }
        return response()->json(['message' => '{$friendlyModelName} details retrieved.', 'data' => \$item]);
    }
PHP;
                }

                if ($responseMode === 'json') {
                    return <<<PHP
    public function show(\$id)
    {
        \$item = {$model}::find(\$id);
        if (!\$item) return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        return response()->json(['data' => \$item]);
    }
PHP;
                }

                return <<<PHP
    public function show(\$id)
    {
        \$item = {$model}::findOrFail(\$id);
        return view('{$this->fallbackView($model, 'show')}', ['item' => \$item]);
    }
PHP;

            case 'edit':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    return <<<PHP
    public function edit(string \$id)
    {
        \$item = collect(\$this->items)->firstWhere('id', \$id);
        if (!\$item) {
            return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        }
        return response()->json(['message' => 'View for editing {$friendlyModelName}.', 'data' => \$item]);
    }
PHP;
                }

                if ($responseMode === 'json') {
                    return <<<PHP
    public function edit(\$id)
    {
        \$item = {$model}::find(\$id);
        if (!\$item) return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        return response()->json(['data' => \$item]);
    }
PHP;
                }

                return <<<PHP
    public function edit(\$id)
    {
        \$item = {$model}::findOrFail(\$id);
        return view('{$this->fallbackView($model, 'edit')}', ['item' => \$item]);
    }
PHP;

            case 'update':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    $valSnippet = $this->validationRulesInline($validationRules);
                    return <<<PHP
    public function update(Request \$request, string \$id)
    {
{$valSnippet}
        \$idx = collect(\$this->items)->search(fn(\$p) => \$p['id'] === \$id);
        if (\$idx === false) {
            return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        }

        \$this->items[\$idx] = array_merge(\$this->items[\$idx], \$request->only(array_keys(\$request->all())));
        return response()->json(['message' => '{$friendlyModelName} updated successfully.', 'data' => \$this->items[\$idx]]);
    }
PHP;
                }

                $postRedirect = $postViews['update'] ?? null;
                $postRedirectReturn = $this->buildPostActionReturn($postRedirect, $responseMode, $updatedMsg, '$record');

                return <<<PHP
    public function update(Request \$request, \$id)
    {
        try {
            \$validated = \$request->validate([
{$rulesArrayString}
            ]);

            \$record = {$model}::findOrFail(\$id);
            \$record->update(\$validated);

            {$postRedirectReturn}
        } catch (\\Exception \$e) {
            if ('{$responseMode}' === 'json') {
                return response()->json(['message' => \$e->getMessage()], 500);
            }
            return redirect()->back()->with('error', \$e->getMessage());
        }
    }
PHP;

            case 'destroy':
                if ($logicLevel === 'Simulated API CRUD (in-memory, like ProductController)') {
                    return <<<PHP
    public function destroy(string \$id)
    {
        \$index = collect(\$this->items)->search(fn(\$p) => \$p['id'] === \$id);
        if (\$index === false) return response()->json(['message' => '{$friendlyModelName} not found.'], 404);
        array_splice(\$this->items, \$index, 1);
        return response()->json(['message' => '{$friendlyModelName} deleted successfully.'], 204);
    }
PHP;
                }

                $postRedirect = $postViews['destroy'] ?? null;
                $postRedirectReturn = $this->buildPostActionReturn($postRedirect, $responseMode, $deletedMsg);

                return <<<PHP
    public function destroy(\$id)
    {
        try {
            {$model}::destroy(\$id);

            {$postRedirectReturn}
        } catch (\\Exception \$e) {
            if ('{$responseMode}' === 'json') {
                return response()->json(['message' => \$e->getMessage()], 500);
            }
            return redirect()->back()->with('error', \$e->getMessage());
        }
    }
PHP;
        }

        return "\n    // method {$method} not implemented\n";
    }

    protected function fallbackView(?string $model, string $action): string
    {
        // default view namespace: snake plural of model, e.g., tasks.index
        if (!$model) return 'errors.404';
        return Str::snake(Str::plural($model)) . '.' . $action;
    }

    protected function makeValidationRules(array $fields, ?array $fieldTypes = null): array
    {
        $rules = [];
        $passwordFields = ['password', 'confirm_password', 'password_confirmation', 'confirm-password', 'password-confirmation', 'password-confirmation', 'password-confirm'];

        foreach ($fields as $field) {
            $lower = Str::lower($field);

            // Auto-detect type from name
            $type = null;
            if ($fieldTypes && isset($fieldTypes[$field])) {
                $type = $fieldTypes[$field];
            } else {
                if (Str::contains($lower, ['email'])) {
                    $type = 'email';
                } else if (Str::contains($lower, ['phone', 'contact', 'mobile', 'cntact_no', 'contact-no', 'mo_no', 'mobile_no', 'mo-no', 'mobile-no'])) {
                    $type = 'phone';
                } else if (Str::contains($lower, ['price', 'amount', 'total', 'cost', 'qty', 'quantity', 'count'])) {
                    $type = 'numeric';
                } else if (Str::contains($lower, ['date', 'time', 'at', 'created_at', 'updated_at', 'joined_at', 'joined-at', 'created-at'])) {
                    $type = 'date';
                } else if (Str::contains($lower, ['description', 'address', 'notes'])) {
                    $type = 'textarea';
                } else if (Str::contains($lower, ['password'])) {
                    $type = 'password';
                } else if (Str::contains($lower, ['gender', 'option', 'choice', 'role'])) {
                    $type = 'radio';
                } else if (Str::contains($lower, ['agree', 'hobbies', 'permissions'])) {
                    $type = 'checkbox';
                } else {
                    $type = 'string';
                }
            }

            // Apply validation based on type
            switch ($type) {
                case 'email':
                    $rules[$field] = 'required|email|max:255';
                    break;

                case 'phone':
                    $rules[$field] = 'required|string|max:15|regex:/^\+?[0-9]{7,15}$/';
                    break;

                case 'numeric':
                    $rules[$field] = 'required|integer|min:0';
                    break;

                case 'date':
                    $rules[$field] = 'required|date';
                    break;

                case 'textarea':
                    $rules[$field] = 'required|string|max:500';
                    break;

                case 'password':
                    if ($lower === 'confirm_password' || $lower === 'password_confirmation' || $lower === 'confirm-password' || $lower === 'password-confirmation' || $lower === 'password-confirm' || $lower === 'confirmation-password') {
                        $rules[$field] = 'required|string|same:password|min:6';
                    } else {
                        $rules[$field] = 'required|string|min:6|confirmed';
                    }
                    break;

                case 'radio':
                    // You can enhance this by passing allowed options from the view if needed
                    $rules[$field] = 'required|string|in:male,female,other';
                    break;

                case 'checkbox':
                    // Accept array for multiple selections
                    $rules[$field] = 'required|array|min:1';
                    break;

                case 'string':
                default:
                    $rules[$field] = 'required|string|max:255';
                    break;
            }
        }

        return $rules;
    }


    protected function validationRulesToPhpArray(array $rules): string
    {
        $lines = [];
        foreach ($rules as $field => $rule) {
            $lines[] = "                '{$field}' => '{$rule}',";
        }
        return implode("\n", $lines);
    }

    protected function sampleDataFromFields(array $fields): string
    {
        if (empty($fields)) {
            return "            'name' => '',\n            'price' => 0";
        }
        $lines = [];
        foreach ($fields as $f) {
            $key = addslashes($f);
            if (Str::contains(Str::lower($f), ['price', 'amount', 'total', 'cost', 'qty', 'quantity'])) {
                $lines[] = "            '{$key}' => \$request->input('{$key}', 0),";
            } else {
                $lines[] = "            '{$key}' => \$request->input('{$key}', ''),";
            }
        }
        return implode("\n", $lines);
    }

    protected function buildPostActionReturn(?string $destination, string $responseMode, string $message, string $returnVar = '$record'): string
    {
        // destination can be a view dot string or a route/url
        if ($responseMode === 'json') {
            return "return response()->json(['message' => '{$message}', 'data' => {$returnVar}]);";
        }

        if (!$destination) {
            return "return redirect()->back()->with('success', '{$message}');";
        }

        // if destination starts with '/', use it as url, otherwise treat as view/route or path
        if (Str::startsWith($destination, '/')) {
            return "return redirect('{$destination}')->with('success', '{$message}');";
        }

        // treat as route or view - we'll redirect to route() if route exists else to view path
        // we can't check route existence reliably here; prefer route helper if dotted or slashless.
        return "return redirect()->route('{$destination}')->with('success', '{$message}');";
    }

    protected function validationRulesInline(array $rules): string
    {
        if (empty($rules)) {
            return "        // no validation rules detected (none provided)\n";
        }
        $lines = [];
        foreach ($rules as $f => $r) {
            $lines[] = "            '{$f}' => '{$r}',";
        }
        $body = implode("\n", $lines);
        return <<<PHP
        \$request->validate([
{$body}
        ]);
PHP;
    }
}
