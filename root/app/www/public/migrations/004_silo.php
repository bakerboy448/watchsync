<?php

/*
----------------------------------
------  Created: 112226   ------
------  bakerboy448       ------
----------------------------------
*/

$q = [];
foreach ([MOVIE_TABLE, SERIES_TABLE, EPISODE_TABLE] as $table) {
    $q[] = "ALTER TABLE `" . $table . "`
            ADD COLUMN IF NOT EXISTS `silo` int NOT NULL DEFAULT 0 AFTER `jellyfin`,
            ADD COLUMN IF NOT EXISTS `silo_remote_id` varchar(255) DEFAULT NULL AFTER `jellyfin_remote_id`";
    // Not unique: Silo gives one content_id to every file of a title, so an HD and a 4K path share it
    $q[] = "ALTER TABLE `" . $table . "`
            ADD KEY IF NOT EXISTS `silo_remote_id` (`silo_remote_id`)";
}

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
            SET value = '004'
            WHERE name = 'migration'";
    $database->query($sql);
    if ($database->error()) {
        $error = true;
    }
}
