<?php

/*
----------------------------------
------  Created: 112526   ------
------  bakerboy448       ------
----------------------------------
*/

$q   = [];
$q[] = "ALTER TABLE `" . MEDIA_APP_TABLE . "`
        ADD COLUMN IF NOT EXISTS `path_maps` text DEFAULT NULL AFTER `webhooks`";

$error = false;

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate')) {
            $error = true;
        }
    }
}

if (!$error) {
    $sql = "UPDATE " . SETTINGS_TABLE . "
            SET value = '005'
            WHERE name = 'migration'";
    $database->query($sql);
    if ($database->error()) {
        $error = true;
    }
}
