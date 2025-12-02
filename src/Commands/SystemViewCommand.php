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

        $layoutChoice = $this->choice(
            "Choose template for ALL views",
            ['0 = Normal HTML (default)', '1 = Bootstrap 5 Layout'],
            0
        );

        $isBootstrap = Str::contains($layoutChoice, '1');

        foreach ($names as $viewName) {
            $fileName = $this->normalizeFileName($viewName);
            $path = resource_path("views/{$folder}/{$fileName}");
            $this->generateView($path, $fileName, $isBootstrap);
        }

        $this->info("🎉 All view files created in: resources/views/{$folder}/");
    }

    private function processSingleView($name)
    {
        // Check typo for single view
        if (Str::contains($name, ['dashbaord', 'dasboard', 'usr'])) {
            if (!$this->confirm("⚠ The name '$name' looks misspelled. Continue?", false)) {
                $name = $this->ask("Enter corrected name:");
            }
        }

        // Clean invalid characters
        $name = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $name);
        $fileName = $this->normalizeFileName($name);
        $path = resource_path("views/{$fileName}");

        // Step 1: Layout choice
        $layoutChoice = $this->choice(
            "Choose view template type",
            ['0 = Normal HTML (default)', '1 = Bootstrap 5 Layout'],
            0
        );
        $isBootstrap = Str::contains($layoutChoice, '1');

        // Step 2: Page content type
        $pageOption = $this->choice(
            "Select page type",
            ['0 = Blank Page', '1 = Table', '2 = Cards', '3 = Form'],
            0
        );

        // Step 3: Generate content based on type
        $content = '';
        switch ($pageOption) {
            case 1: // Table
                $content = $this->generateTable($fileName, $isBootstrap);
                break;
            case 2: // Cards
                $content = $this->generateCards($fileName, $isBootstrap);
                break;
            case 3: // Form
                $content = $this->generateForm($fileName, $isBootstrap);
                break;
            default:
                $content = $isBootstrap ? $this->bootstrapTemplate($fileName) : $this->simpleTemplate($fileName);
                break;
        }

        // Create directories if needed
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
        $this->info("✅ View created: resources/views/{$fileName}");
    }

    private function normalizeFileName($name)
    {
        $name = trim($name, "/ ");
        if (!Str::endsWith($name, '.blade.php')) {
            $name .= '.blade.php';
        }
        return $name;
    }

    private function generateView($path, $name, $isBootstrap)
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $content = $isBootstrap ? $this->bootstrapTemplate($name) : $this->simpleTemplate($name);
        $this->files->put($path, $content);
    }

    // -------------------- Page Generators --------------------

    private function generateTable($name, $isBootstrap)
    {
        $tableOption = $this->choice(
            "Choose table type",
            ['0 = Dummy Table', '1 = Foreach table with variable'],
            0
        );

        if ($tableOption == 1) {
            $varName = $this->ask("Enter foreach variable name (example: users, products)");
            $content = $this->tableForeachTemplate($name, $varName, $isBootstrap);
        } else {
            $content = $this->dummyTableTemplate($name, $isBootstrap);
        }

        return $content;
    }

    private function generateCards($name, $isBootstrap)
    {
        $cardOption = $this->choice(
            "Choose card type",
            ['0 = Dummy Card', '1 = Foreach cards with variable'],
            0
        );

        if ($cardOption == 1) {
            $varName = $this->ask("Enter foreach variable name (example: products, posts)");
            $content = $this->cardsForeachTemplate($name, $varName, $isBootstrap);
        } else {
            $content = $this->dummyCardsTemplate($name, $isBootstrap);
        }

        return $content;
    }

    private function generateForm($name, $isBootstrap)
    {
        $formOption = $this->choice(
            "Choose form type",
            ['0 = Login', '1 = Registration', '2 = Custom Form'],
            0
        );

        $fields = [];
        switch ($formOption) {
            case 0: // Login
                $fields = ['email', 'password'];
                break;
            case 1: // Registration
                $fields = ['fullname', 'mobileno', 'age', 'email', 'password', 'confirm-password'];
                break;
            case 2: // Custom
                $fieldInput = $this->ask("Enter comma-separated field names (example: name,email,age)");
                $fields = array_map('trim', explode(',', $fieldInput));
                break;
        }

        return $this->formTemplate($name, $fields, $isBootstrap);
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

    protected function bootstrapTemplate($name)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
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
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="text-center">
        <h1 class="fw-bold text-primary">{$title}</h1>
        <p class="lead text-muted">Generated using Laravel System Builder (Bootstrap 5 Layout).</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    // -------------------- Table Templates --------------------

    private function dummyTableTemplate($name, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $tableClass = $isBootstrap ? 'table table-bordered' : '';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
<h1>{$title}</h1>
<table class="{$tableClass}">
    <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Email</th>
    </tr>
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
</table>
</body>
</html>
HTML;
    }

    private function tableForeachTemplate($name, $varName, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $tableClass = $isBootstrap ? 'table table-striped' : '';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
<h1>{$title}</h1>
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
</body>
</html>
HTML;
    }

    // -------------------- Cards Templates --------------------

    private function dummyCardsTemplate($name, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $cardClass = $isBootstrap ? 'card text-center m-2 p-3 shadow' : '';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
<h1>{$title}</h1>
<div class="{$cardClass}">
    <h2>Card Title</h2>
    <p>Card description goes here.</p>
</div>
</body>
</html>
HTML;
    }

    private function cardsForeachTemplate($name, $varName, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $cardClass = $isBootstrap ? 'card text-center m-2 p-3 shadow' : '';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
</head>
<body>
<h1>{$title}</h1>
@foreach(\${$varName} as \$item)
<div class="{$cardClass}">
    <h2>{{ \$item->name }}</h2>
    <p>{{ \$item->description }}</p>
</div>
@endforeach
</body>
</html>
HTML;
    }

    // -------------------- Form Template --------------------

    private function formTemplate($name, $fields, $isBootstrap)
    {
        $title = ucwords(str_replace(['-', '_', '.blade.php'], ' ', $name));
        $formClass = $isBootstrap ? 'class="container mt-5 w-50"' : '';
        $inputClass = $isBootstrap ? 'class="form-control mb-3"' : '';

        $bootstrapLink = $isBootstrap
            ? '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">'
            : '';

        $inputs = '';
        foreach ($fields as $field) {
            $label = ucwords(str_replace('-', ' ', $field));
            $type = Str::contains($field, 'password') ? 'password' : 'text';
            if ($field === 'email') $type = 'email';
            $inputs .= <<<HTML
<label>{$label}</label>
<input type="{$type}" name="{$field}" {$inputClass}>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    {$bootstrapLink}
</head>
<body>
<div {$formClass}>
<h1>{$title}</h1>
<form method="POST">
    @csrf
    {$inputs}
    <button type="submit" {$inputClass}>Submit</button>
</form>
</div>
</body>
</html>
HTML;
    }

}
