<?php

// Run: php tests/path-map-stale-remote.php
// An app path map rewrites a Windows root (D:\Media) to the main app's
// container root before matching. A Jellyfin, Emby or Silo row matched by path
// takes the live remote id when the stored one is stale; Plex keeps its id.

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
$app  = ['path_maps' => "D:\\Media => /media\r\nD:\\Media\\4K => /media4k\n\nnot a map"];

$cases = [
    'D:\\Media\\Movies\\Heat (1995)\\Heat.mkv' => '/media/Movies/Heat (1995)/Heat.mkv',
    'd:/media/TV/Show/S01E01.mkv'              => '/media/TV/Show/S01E01.mkv',
    'D:\\Media\\4K\\Heat\\Heat.mkv'            => '/media4k/Heat/Heat.mkv',
    'D:\\Media'                                => '/media',
    'D:\\MediaOther\\x.mkv'                    => 'D:\\MediaOther\\x.mkv',
    '/media/Movies/x.mkv'                      => '/media/Movies/x.mkv',
];
foreach ($cases as $in => $want) {
    $got = $db->mapMediaAppPath($app, $in);
    if ($got !== $want) {
        $fail[] = 'map ' . $in . ' gave ' . $got;
    }
}
if ($db->mapMediaAppPath([], 'D:\\Media\\x') !== 'D:\\Media\\x') {
    $fail[] = 'app without maps changed a path';
}
$items = $db->mapMediaAppItems($app, ['movies' => [['path' => 'D:\\Media\\M\\a.mkv']], 'episodes' => [['path' => 'D:\\Media\\T\\b.mkv']]]);
if ($items['movies'][0]['path'] != '/media/M/a.mkv' || $items['episodes'][0]['path'] != '/media/T/b.mkv') {
    $fail[] = 'items not mapped';
}
$libs = $db->mapMediaAppLibraries($app, [['title' => 'Movies', 'paths' => ['D:\\Media\\Movies']]]);
if ($libs[0]['paths'][0] != '/media/Movies') {
    $fail[] = 'library paths not mapped';
}

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
echo 'OK path map and stale remote refresh' . PHP_EOL;
