<?php

namespace Arpanmandaviya\SystemBuilder;

use Composer\Script\Event;

class InstallScript
{
    public static function postInstall(Event $event)
    {
        // Get project root (not using Laravel helpers)
        $projectRoot = getcwd();

// Storage path in project
        $storagePath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public';

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

// Source files (inside package)
        $sourceSystem = __DIR__ . '/../resources/system.json';
        $sourceCopy   = __DIR__ . '/../resources/system-copy.json';

// Destination files
        $destinationSystem = $storagePath . '/system.json';
        $destinationCopy   = $storagePath . '/system-copy.json';

// Copy only if doesn't exist
        if (!file_exists($destinationSystem)) {
            copy($sourceSystem, $destinationSystem);
        }

        if (!file_exists($destinationCopy)) {
            copy($sourceCopy, $destinationCopy);
        }

        echo "\n---------------------------------------\n";
        echo "📦 Laravel System Builder Installed\n";
        echo "📁 system.json & system-copy.json published to /storage/app/public\n";
        echo "---------------------------------------\n\n";
    }
}
