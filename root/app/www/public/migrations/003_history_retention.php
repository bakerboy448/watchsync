<?php

/*
----------------------------------
------  Created: 111226   ------
------  Austin Best       ------
----------------------------------
*/

$q   = [];
$q[] = "INSERT INTO " . SETTINGS_TABLE . "
        (`name`, `value`)
        VALUES
        ('syncHistoryRetention', '1000'),
        ('webhookHistoryRetention', '1000')";

$error = false;

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
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
