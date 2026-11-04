<?php

/*
----------------------------------
------  Created: 110326   ------
------  bakerboy448       ------
----------------------------------
*/

$q = [];
foreach ([MOVIE_TABLE, SERIES_TABLE, EPISODE_TABLE] as $table) {
    $q[] = "ALTER TABLE `" . $table . "`
            ADD COLUMN IF NOT EXISTS `silo` int NOT NULL DEFAULT 0 AFTER `jellyfin`,
            ADD COLUMN IF NOT EXISTS `silo_remote_id` varchar(255) DEFAULT NULL AFTER `jellyfin_remote_id`";
    $q[] = "ALTER TABLE `" . $table . "`
            ADD UNIQUE KEY IF NOT EXISTS `silo_remote_id` (`silo_remote_id`)";
}

$q[] = "CREATE TABLE IF NOT EXISTS `" . PROVIDER_ID_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `item_table` varchar(20) NOT NULL,
        `item_id` int NOT NULL,
        `provider` varchar(10) NOT NULL,
        `value` varchar(64) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `item_provider` (`item_table`, `item_id`, `provider`),
        KEY `lookup` (`item_table`, `provider`, `value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

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
            SET value = '003'
            WHERE name = 'migration'";
    $database->query($sql);
    if ($database->error()) {
        $error = true;
    }
}
