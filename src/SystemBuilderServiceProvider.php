<?php

namespace Arpanmandaviya\SystemBuilder;

use Illuminate\Support\ServiceProvider;
use Arpanmandaviya\SystemBuilder\Commands\BuildSystemCommand;
use Arpanmandaviya\SystemBuilder\Commands\InteractiveBuildCommand;

class SystemBuilderServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        $filesystem = new Filesystem();
        $vendorPath = base_path('vendor/arpanmandaviya/laravel-system-builder/public');

        if (!$filesystem->exists($vendorPath)) {
            $filesystem->makeDirectory($vendorPath, 0755, true);
        }

        $systemJson = $vendorPath . '/system.json';
        $systemCopyJson = $vendorPath . '/system-copy.json';
        $systemHelpJson = $vendorPath . '/system-help.json';

        $systemJsonContent = <<<JSON
{
    "__meta__": {
        "description": "This file defines database tables and UI structure for the System Builder.",
        "column_rules": {
            "allowed_types": [
                "bigIncrements", "increments", "string", "integer", "text",
                "boolean", "float", "decimal", "enum", "json", "date",
                "datetime", "timestamp"
            ],
            "length": "Only applicable to string, integer, decimal types.",
            "nullable": "Use: {\\"nullable\\": true} to make a column optional.",
            "unique": "Use: {\\"unique\\": true} to enforce unique constraint.",
            "enum_usage": {
                "format": { "type": "enum", "values": ["0", "1", "2"] },
                "note": "You must define values array for enum type."
            },
            "comments": "Add developer note: {\\"comment\\": \\"your text\\"}",
            "foreign_key_format": {
                "example": {
                    "name": "profile_id",
                    "type": "integer",
                    "foreign": {
                        "on": "profiles",
                        "references": "id",
                        "onDelete": "cascade"
                    }
                }
            }
        }
    },
    "tables": [
        { "name": "users", "columns": [] },
        { "name": "workers", "columns": [] }
    ],
    "views": [
        "admin/includes/[head]",
        "admin/partials/[sidebar,navbar,footer,script]"
    ]
}
JSON;

        $helpContent = <<<JSON
{
   "help": {
      "purpose": "Instructions for System Builder JSON configuration.",
     
   }
}
JSON;

        if (!$filesystem->exists($systemJson)) {
            $filesystem->put($systemJson, $systemJsonContent);
        }

        if (!$filesystem->exists($systemCopyJson)) {
            $filesystem->put($systemCopyJson, $systemJsonContent);
        }

        if (!$filesystem->exists($systemHelpJson)) {
            $filesystem->put($systemHelpJson, $helpContent);
        }
    }

}
