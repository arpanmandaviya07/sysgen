<?php

namespace Arpanmandaviya\SystemBuilder;

use Composer\Script\Event;

class InstallScript
{
    public static function postInstall(Event $event)
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $targetPath = base_path("public/system.json");
        $targetCopyPath = base_path("public/system-copy.json");

        $source = __DIR__ . '/../resources/system.json';
        $sourceCopy = __DIR__ . '/../resources/system-copy.json';

        // Ensure public folder exists
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }

        if (!file_exists($targetPath)) {
            copy($source, $targetPath);
        }

        if (!file_exists($targetCopyPath)) {
            copy($sourceCopy, $targetCopyPath);
        }

        echo "\n📁 system.json and system-copy.json published successfully.\n";
    }
}
