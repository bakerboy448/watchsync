<?php

// Run: php tests/history-shared-remote.php
// An HD and a 4K row can share one listener remote id (Silo). Both rows must
// read that id's watched state back, or the other row plans a push every run.

$public = __DIR__ . '/../root/app/www/public';
require $public . '/classes/interfaces/MediaApps.php';
require $public . '/functions/helpers/datetime.php';
require $public . '/classes/traits/Cron/History.php';

function logger($logfile, $msg) {}
function loggerFlush($logfile = '') {}
function translate(string $key, array $args = []): string
{
    return $key;
}

class StubDatabase
{
    public function getMediaAppLibraries($id)
    {
        return [];
    }

    public function getSeriesRows()
    {
        return [];
    }

    public function getMovies()
    {
        return [
            ['id' => 1, 'title' => 'Film', 'year' => 2020, 'path' => '/movies/Film (2020)', 'plex' => 1, 'plex_remote_id' => 'p1', 'silo' => 1, 'silo_remote_id' => 's1'],
            ['id' => 2, 'title' => 'Film', 'year' => 2020, 'path' => '/movies-4k/Film (2020)', 'plex' => 1, 'plex_remote_id' => 'p2', 'silo' => 1, 'silo_remote_id' => 's1'],
        ];
    }

    public function getEpisodes()
    {
        return [];
    }

    public function getUserWatchState($userId, $platform)
    {
        return ['user' => [], 'movie' => [], 'episode' => []];
    }

    public function normalizeLibraryPath($path)
    {
        return strval($path);
    }
}

class StubMediaApps
{
    public function getWatchStatus($app, $remoteId, $name, $userId, $user)
    {
        $watched = ['started' => 1, 'inprogress' => 0, 'finished' => 1];
        if ($app['platform'] == MediaPlatforms::PLEX) {
            return ['movies' => ['p1' => $watched, 'p2' => $watched], 'episodes' => []];
        }

        return ['movies' => ['s1' => $watched], 'episodes' => []];
    }
}

class HistoryHarness
{
    use History;

    public $database;
    public $logfile    = '';
    public $currentJob = [];

    public function stopIfCancelled() {}

    public function addStat($name, $count, $app) {}

    public function __call($name, $args)
    {
        return null;
    }
}

$mediaApps = new StubMediaApps();
$harness   = new HistoryHarness();
$harness->database = new StubDatabase();

$plex    = ['id' => 1, 'name' => 'Plex', 'platform' => MediaPlatforms::PLEX, 'role' => MediaAppRoles::MASTER];
$silo    = ['id' => 3, 'name' => 'Silo', 'platform' => MediaPlatforms::SILO, 'role' => MediaAppRoles::LISTENER];
$members = [
    ['app' => $plex, 'user' => ['id' => 10, 'username' => 'u', 'remote_id' => 'pu']],
    ['app' => $silo, 'user' => ['id' => 11, 'username' => 'u', 'remote_id' => 'su']],
];

$index    = $harness->watchItemIndex([$plex, $silo]);
$dbRows   = [];
$pushRows = [];
$dryPlans = [];
$drySkips = [];
$harness->syncWatchUser($members, $index, true, true, false, $dbRows, $pushRows, $dryPlans, $drySkips);

$pushes = [];
foreach ($dryPlans as $plan) {
    if (!empty($plan['needs_push'])) {
        $pushes[] = $plan['app']['name'] . ' item ' . $plan['item_id'];
    }
}
if ($pushes || $drySkips) {
    fwrite(STDERR, 'FAIL repeat pushes: ' . implode(', ', $pushes) . ' skips: ' . count($drySkips) . PHP_EOL);
    exit(1);
}
echo 'OK shared listener remote id reads back to every row' . PHP_EOL;
