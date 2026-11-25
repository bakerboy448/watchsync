<?php

// Run: php tests/library-stale-remote.php
// A Jellyfin, Emby or Silo row matched by path takes the live remote id when
// the stored one is stale, so history pushes stop hitting a 404. Plex keeps its id.

$public = __DIR__ . '/../root/app/www/public';
define('EPISODE_TABLE', 'episode');
define('MOVIE_TABLE', 'movie');
require $public . '/classes/interfaces/MediaApps.php';
require $public . '/classes/traits/Database/MediaLibrary.php';
require $public . '/classes/traits/Cron/Library.php';

function logger($logfile, $msg) {}

class StubDatabase
{
    use MediaLibrary;

    public $queries = [];

    public function query($sql)
    {
        $this->queries[] = $sql;

        return true;
    }

    public function prepare($value)
    {
        return addslashes($value);
    }
}

class LibraryHarness
{
    use Library;

    public $database;
    protected $libraryImportIndex = null;
    protected $logfile            = '';
}

$fail = [];
$db   = new StubDatabase();
$harness           = new LibraryHarness();
$harness->database = $db;
$row = ['id' => 5, 'path' => '/media/Movies/Heat (1995)', 'jellyfin' => 1, 'jellyfin_remote_id' => 'old', 'emby' => 1, 'emby_remote_id' => 'old', 'silo' => 1, 'silo_remote_id' => 'old', 'plex' => 1, 'plex_remote_id' => '10'];

foreach ([MediaPlatforms::JELLYFIN => 'jellyfin', MediaPlatforms::EMBY => 'emby', MediaPlatforms::SILO => 'silo'] as $platform => $name) {
    if ($harness->libraryItemOutcomeFromRow($row, $platform, 'new', '/media/Movies/Heat (1995)') != 'updated') {
        $fail[] = $name . ' stale id not updated';
    }
    if ($harness->libraryItemOutcomeFromRow($row, $platform, 'new', '/media/Movies/Other') != 'unchanged') {
        $fail[] = $name . ' id replaced on a different path';
    }
    if (!$harness->libraryImportRowMatchesRemote($row, $platform, 'new')) {
        $fail[] = $name . ' path hit rejected for a stale id';
    }
    $db->queries = [];
    $out = $harness->applyLibraryImportRowUpdate(MOVIE_TABLE, $platform, 'new', '/media/Movies/Heat (1995)', $row);
    if (($out[$name . '_remote_id'] ?? '') != 'new' || count($db->queries) != 1 || !str_contains($db->queries[0], '`' . $name . "_remote_id` = 'new'")) {
        $fail[] = $name . ' stale id not rewritten';
    }
}

if ($harness->libraryItemOutcomeFromRow($row, MediaPlatforms::PLEX, '11', '/media/Movies/Heat (1995)') != 'unchanged') {
    $fail[] = 'plex id replaced';
}
if ($harness->libraryImportRowMatchesRemote($row, MediaPlatforms::PLEX, '11')) {
    $fail[] = 'plex path hit accepted with another id';
}
$db->queries = [];
$harness->applyLibraryImportRowUpdate(MOVIE_TABLE, MediaPlatforms::JELLYFIN, 'old', '/media/Movies/Heat (1995)', $row);
if ($db->queries) {
    $fail[] = 'row written when the id is current';
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK stale remote refresh' . PHP_EOL;
