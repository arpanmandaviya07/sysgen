<?php

namespace Arpanmandaviya\SystemBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class SystemViewCommand extends Command
{
    protected $signature   = 'system:view {name? : Name of the view file(s)}';
    protected $description = 'Generate one or multiple Blade views with optional Bootstrap layout and content type.';
    protected $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $input = $this->argument('name') ?: $this->ask("Enter view name (Example: dashboard or admin/pages/[home,edit,create])");
        $input = trim($input);

        if (Str::contains($input, ',')) {
            // Logic to convert comma-separated names without [] into the [name,name] format
            if (!Str::contains($input, ['[', ']'])) {
                $folder = Str::contains($input, '/') ? Str::beforeLast($input, '/') : '';
                $list = Str::afterLast($input, '/');
                $input = $folder !== '' ? "{$folder}/[{$list}]" : "[{$list}]";
            }
            $this->processMultipleViews($input);
        } else {
            $this->processSingleView($input);
        }
    }

    private function processMultipleViews($input)
    {
        $folder = Str::before($input, '[');
        $folder = trim($folder, "/ ");

        $rawNames = Str::between($input, '[', ']');
        $names = array_map('trim', explode(',', $rawNames));

        // Clean invalid characters
        $names = array_map(function ($name) {
            return preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        }, $names);

        // Layout choice
        $layoutChoice = $this->choice(
            "Choose template for ALL views",
            ['0 = Normal HTML (default)', '1 = Bootstrap 5 Layout'],
            0
        );
        $isBootstrap = Str::contains($layoutChoice, '1');

        foreach ($names as $viewName) {
            $fileName = $this->normalizeFileName($viewName);
            $path = resource_path("views/{$folder}/{$fileName}");

            // Generate content
            $content = $this->askPageTypeAndGenerate($viewName, $isBootstrap);

            // Save file with overwrite check
            $this->saveFileWithPrompt($path, $content, $viewName);
        }

        $this->info("🎉 All view files processed in: resources/views/{$folder}/");
    }

    private function processSingleView($name)
    {
        // Check typo
        if (Str::contains($name, ['dashbaord', 'dasboard', 'usr'])) {
            if (!$this->confirm("⚠ The name '$name' looks misspelled. Continue?", false)) {
                $name = $this->ask("Enter corrected name:");
            }
        }

        // Clean invalid characters
        $name = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $name);

        // Remove .blade.php if present to correctly determine folder path
        $baseName = Str::replaceLast('.blade.php', '', $name);
        $fileName = $this->normalizeFileName($baseName);

        $path = resource_path("views/{$fileName}");

        // Layout choice
        $layoutChoice = $this->choice(
            "Choose view template type",
            ['0 = Normal HTML (default)', '1 = Bootstrap 5 Layout'],
            0
        );
        $isBootstrap = Str::contains($layoutChoice, '1');

        // Generate content
        $content = $this->askPageTypeAndGenerate($baseName, $isBootstrap);

        // Save file with overwrite check
        $this->saveFileWithPrompt($path, $content, $baseName);

        // $this->info("✅ View created: resources/views/{$fileName}"); // Moved into saveFileWithPrompt
    }

    // --- FIX 1: New method to handle file saving with conflict check ---
    protected function saveFileWithPrompt(string $path, string $content, string $name): void
    {
        $dir = dirname($path);
        $this->files->ensureDirectoryExists($dir);

        if ($this->files->exists($path)) {
            $choice = $this->choice(
                "File already exists: " . basename($path) . ". What do you want to do?",
                ['Skip', 'Replace'],
                'Skip'
            );

            if ($choice === 'Skip') {
                $this->info("⏩ Skipped view creation for '{$name}'.");
                return;
            }
        }

        try {
            $this->files->put($path, $content);
            $this->info("✅ View created: resources/views/" . Str::after(dirname($path), 'views/') . '/' . basename($path));
        } catch (\Exception $e) {
            $this->error("❌ Failed to write file {$path}: " . $e->getMessage());
        }
    }


    private function askPageTypeAndGenerate($baseName, $isBootstrap)
    {
        $pageOption = $this->choice(
            "Select page type",
            ['0 = Blank Page', '1 = Table', '2 = Cards', '3 = Form'],
            0
        );

        // Convert string choice to integer index
        $pageOptionIndex = (int) substr($pageOption, 0, 1);

        switch ($pageOptionIndex) {
            case 1: // Table
                return $this->generateTable($baseName, $isBootstrap);
            case 2: // Cards
                return $this->generateCards($baseName, $isBootstrap);
            case 3: // Form
                return $this->generateForm($baseName, $isBootstrap);
            default: // Blank
                return $isBootstrap ? $this->bootstrapTemplate($baseName) : $this->simpleTemplate($baseName);
        }
    }

    private function normalizeFileName($name)
    {
        $name = trim($name, "/ ");
        // If the path contains folders (e.g., admin/home), ensure only the final part gets .blade.php
        $name = Str::replaceLast('.blade.php', '', $name);

        // This is simplified for a single file:
        return $name . '.blade.php';
    }

    // -------------------- Generators --------------------

    private function generateTable($name, $isBootstrap)
    {
        $tableOption = $this->choice(
            "Choose table type",
            ['0 = Dummy Table', '1 = Foreach table with variable'],
            0
        );

        // Convert choice to index
        $tableOptionIndex = (int) substr($tableOption, 0, 1);

        if ($tableOptionIndex == 1) {
            $varName = $this->ask("Enter foreach variable name (example: users, products)");
            return $this->tableForeachTemplate($name, $varName, $isBootstrap);
        } else {
            return $this->dummyTableTemplate($name, $isBootstrap);
        }
    }

    private function generateCards($name, $isBootstrap)
    {
        $cardOption = $this->choice(
            "Choose card type",
            ['0 = Dummy Card', '1 = Foreach cards with variable'],
            0
        );

        // Convert choice to index
        $cardOptionIndex = (int) substr($cardOption, 0, 1);


        // --- FIX 2: Added missing prompt for variable name when 'Foreach' is selected ---
        if ($cardOptionIndex == 1) {
            $varName = $this->ask("Enter foreach variable name (example: products, posts)");
            return $this->cardsForeachTemplate($name, $varName, $isBootstrap);
        } else {
            return $this->dummyCardsTemplate($name, $isBootstrap);
        }
    }

    private function generateForm($name, $isBootstrap)
    {
        $formOption = $this->choice(
            "Choose form type",
            ['0 = Login', '1 = Registration', '2 = Custom Form'],
            0
        );

        // Convert choice to index
        $formOptionIndex = (int) substr($formOption, 0, 1);

        $fields = [];
        $routeName = $this->ask('Enter route name for form action (e.g., posts.store) or leave blank for "#"');
        $actionUrl = $routeName ? "route('{$routeName}')" : "'#'";
        $formMethod = $formOptionIndex == 2 ? $this->choice("Choose form HTTP method", ['POST', 'GET', 'PUT', 'DELETE'], 'POST') : 'POST';

        switch ($formOptionIndex) {
            case 0:
                // Login: name, type, placeholder, id
                $fields = [
                    ['name' => 'email', 'type' => 'email', 'placeholder' => 'Your email address', 'id' => 'email'],
                    ['name' => 'password', 'type' => 'password', 'placeholder' => 'Your password', 'id' => 'password'],
                ];
                break;
            case 1:
                // Registration
                $fields = [
                    ['name' => 'fullname', 'type' => 'text', 'placeholder' => 'Full Name', 'id' => 'fullname'],
                    ['name' => 'email', 'type' => 'email', 'placeholder' => 'Email Address', 'id' => 'email'],
                    ['name' => 'password', 'type' => 'password', 'placeholder' => 'Password', 'id' => 'password'],
                    ['name' => 'confirm_password', 'type' => 'password', 'placeholder' => 'Confirm Password', 'id' => 'confirm_password'],
                    ['name' => 'contact_no', 'type' => 'number', 'placeholder' => 'Contact Number', 'id' => 'contact_no'],
                ];
                break;
            case 2:
                // Custom Form
                $this->info("Starting custom field definition. Press enter to finish.");
                while (true) {
                    $fieldName = $this->ask("Enter field name (or leave blank to finish)");
                    if (empty($fieldName)) break;

                    $fieldType = $this->choice(
                        "Choose type for '{$fieldName}'",
                        ['text', 'number', 'email', 'password', 'textarea', 'checkbox', 'radio', 'select'],
                        'text'
                    );

                    $fieldPlaceholder = $this->ask("Enter placeholder (optional)");
                    $fieldId = Str::snake($fieldName); // Default ID based on name

                    $field = [
                        'name' => Str::snake($fieldName),
                        'type' => $fieldType,
                        'placeholder' => $fieldPlaceholder,
                        'id' => $fieldId,
                    ];

                    // Handle options for select, radio, and checkbox
                    if (in_array($fieldType, ['checkbox', 'radio', 'select'])) {
                        $optionsInput = $this->ask("Enter comma-separated options for '{$fieldName}' (Example: Option1,Option2,Option3)");
                        $field['options'] = array_map('trim', explode(',', $optionsInput));
                    }

                    $fields[] = $field;
                }
                break;
        }

        return $this->formTemplate($name, $fields, $isBootstrap, $actionUrl, $formMethod);
    }

    // -------------------- Templates --------------------

    protected function simpleTemplate($name)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body>
<h1>{$title}</h1>
<p>Generated by System Builder.</p>
</body>
</html>
HTML;
    }

    protected function bootstrapTemplate($name, $content = '')
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $bodyContent = $content ?: <<<CONTENT
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="text-center">
        <h1 class="fw-bold text-primary">{$title}</h1>
        <p class="lead text-muted">Generated using Laravel System Builder (Bootstrap 5 Layout).</p>
    </div>
</div>
CONTENT;
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
{$bodyContent}
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
    }

    // -------------------- Table --------------------

    private function dummyTableTemplate($name, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $tableClass = $isBootstrap ? 'table table-bordered' : '';

        $content = <<<CONTENT
<div class="container mt-5">
    <h1 class="mb-4">{$title}</h1>
    <table class="{$tableClass}">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>John Doe</td>
                <td>john@example.com</td>
            </tr>
            <tr>
                <td>2</td>
                <td>Jane Smith</td>
                <td>jane@example.com</td>
            </tr>
        </tbody>
    </table>
</div>
CONTENT;
        return $isBootstrap ? $this->bootstrapTemplate($name, $content) : $this->wrapSimple($name, $content);
    }

    private function tableForeachTemplate($name, $varName, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $tableClass = $isBootstrap ? 'table table-striped' : '';

        $content = <<<CONTENT
<div class="container mt-5">
    <h1 class="mb-4">{$title}</h1>
    <table class="{$tableClass}">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
            </tr>
        </thead>
        <tbody>
            @foreach(\${$varName} as \$item)
                <tr>
                    <td>{{ \$item->id }}</td>
                    <td>{{ \$item->name }}</td>
                    <td>{{ \$item->email }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
CONTENT;
        return $isBootstrap ? $this->bootstrapTemplate($name, $content) : $this->wrapSimple($name, $content);
    }

    // -------------------- Cards --------------------

    private function dummyCardsTemplate($name, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $cardClass = $isBootstrap ? 'card shadow-sm' : 'card';

        // --- FIX 3: Apply Bootstrap wrappers correctly and use a container
        $content = <<<CONTENT
<div class="container mt-5">
    <h1 class="mb-4">{$title}</h1>
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="{$cardClass}">
                <div class="card-body">
                    <h5 class="card-title">Card Title</h5>
                    <p class="card-text">Card description goes here. This is a dummy card example.</p>
                </div>
            </div>
        </div>
    </div>
</div>
CONTENT;
        return $isBootstrap ? $this->bootstrapTemplate($name, $content) : $this->wrapSimple($name, $content);
    }

    private function cardsForeachTemplate($name, $varName, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $cardClass = $isBootstrap ? 'card shadow-sm' : 'card';

        // --- FIX 3: Apply Bootstrap wrappers correctly and use a container
        $content = <<<CONTENT
<div class="container mt-5">
    <h1 class="mb-4">{$title}</h1>
    <div class="row">
        @foreach(\${$varName} as \$item)
            <div class="col-md-4 mb-4">
                <div class="{$cardClass}">
                    <div class="card-body">
                        <h5 class="card-title">{{ \$item->name ?? 'Card Item' }}</h5>
                        <p class="card-text">{{ \$item->description ?? 'Description not available.' }}</p>
                        <a href="#" class="btn btn-primary btn-sm">View Details</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
CONTENT;
        return $isBootstrap ? $this->bootstrapTemplate($name, $content) : $this->wrapSimple($name, $content);
    }

    // -------------------- Form --------------------

    private function formTemplate($name, $fields, $isBootstrap, $actionUrl, $formMethod)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $formClass = $isBootstrap ? 'w-50 mx-auto p-4 border bg-white rounded shadow-sm' : '';
        $inputGroupClass = $isBootstrap ? 'mb-3' : '';
        $inputClass = $isBootstrap ? 'form-control' : '';
        $buttonClass = $isBootstrap ? 'btn btn-primary' : '';

        $inputs = '';

        foreach ($fields as $field) {
            $label = ucwords(str_replace(['-', '_'], ' ', $field['name']));
            $nameAttr = $field['name'];
            $idAttr = $field['id'];
            $placeholderAttr = !empty($field['placeholder']) ? "placeholder=\"{$field['placeholder']}\"" : '';
            $requiredAttr = in_array($formMethod, ['POST', 'PUT']) ? 'required' : '';

            $inputHtml = '';

            switch ($field['type']) {
                case 'textarea':
                    $inputHtml = "<textarea name=\"{$nameAttr}\" id=\"{$idAttr}\" class=\"{$inputClass}\" {$placeholderAttr} {$requiredAttr}></textarea>";
                    break;

                case 'select':
                    $inputHtml .= "<select name=\"{$nameAttr}\" id=\"{$idAttr}\" class=\"{$inputClass}\" {$requiredAttr}>\n";
                    $inputHtml .= "    <option value=\"\">Select {$label}</option>\n";
                    if (!empty($field['options'])) {
                        foreach ($field['options'] as $option) {
                            $value = Str::slug($option, '_');
                            $inputHtml .= "    <option value=\"{$value}\">{$option}</option>\n";
                        }
                    }
                    $inputHtml .= "</select>";
                    break;

                case 'checkbox':
                    $inputHtml = "<div class=\"form-check-group\">";
                    foreach ($field['options'] ?? [] as $option) {
                        $value = Str::slug($option, '_');
                        $inputHtml .= <<<HTML
<div class="form-check {$inputGroupClass}">
    <input class="form-check-input" type="checkbox" name="{$nameAttr}[]" value="{$value}" id="{$idAttr}_{$value}">
    <label class="form-check-label" for="{$idAttr}_{$value}">{$option}</label>
</div>
HTML;
                    }
                    $inputHtml .= "</div>";
                    break;

                case 'radio':
                    $inputHtml = "<div class=\"form-check-group\">";
                    foreach ($field['options'] ?? [] as $option) {
                        $value = Str::slug($option, '_');
                        $inputHtml .= <<<HTML
<div class="form-check form-check-inline">
    <input class="form-check-input" type="radio" name="{$nameAttr}" id="{$idAttr}_{$value}" value="{$value}" {$requiredAttr}>
    <label class="form-check-label" for="{$idAttr}_{$value}">{$option}</label>
</div>
HTML;
                    }
                    $inputHtml .= "</div>";
                    break;

                default: // text, number, email, password
                    $typeAttr = $field['type'];
                    $inputHtml = "<input type=\"{$typeAttr}\" name=\"{$nameAttr}\" id=\"{$idAttr}\" class=\"{$inputClass}\" {$placeholderAttr} {$requiredAttr}>";
                    break;
            }

            // Only wrap standard inputs (not checkbox/radio groups which have their own wrapper)
            if (!in_array($field['type'], ['checkbox', 'radio'])) {
                $inputs .= <<<HTML
<div class="{$inputGroupClass}">
    <label for="{$idAttr}" class="form-label">{$label}</label>
    {$inputHtml}
</div>
HTML;
            } else {
                // For radio/checkbox, just add the group with a label
                $inputs .= <<<HTML
<div class="{$inputGroupClass}">
    <label class="form-label d-block">{$label}</label>
    {$inputHtml}
</div>
HTML;
            }
        }

        // Determine method spoofing for PUT/DELETE
        $methodSpoofing = '';
        if (in_array($formMethod, ['PUT', 'DELETE'])) {
            $methodSpoofing = "@method('{$formMethod}')\n";
        }
        $finalMethod = in_array($formMethod, ['PUT', 'DELETE']) ? 'POST' : $formMethod;


        $content = <<<CONTENT
<div class="container mt-5">
    <h1 class="text-center mb-4">{$title}</h1>
    <form method="{$finalMethod}" action="{{ {$actionUrl} }}" class="{$formClass}">
        @csrf
        {$methodSpoofing}
        {$inputs}
        <button type="submit" class="{$buttonClass}">Submit</button>
    </form>
</div>
CONTENT;
        return $isBootstrap ? $this->bootstrapTemplate($name, $content) : $this->wrapSimple($name, $content);
    }

    /**
     * Helper to wrap simple HTML content with basic HTML structure
     */
    protected function wrapSimple($name, $content)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
{$content}
</body>
</html>
HTML;
    }
}