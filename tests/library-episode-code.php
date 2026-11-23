<?php

// Run: php tests/library-episode-code.php
// An episode row stored as S00E00 with the series name as title gets its code
// and title from the next pull that knows them. A Silo 429 waits Retry-After.

$public = __DIR__ . '/../root/app/www/public';
define('EPISODE_TABLE', 'episode');
require $public . '/classes/traits/Cron/Library.php';
require $public . '/classes/traits/MediaApps/Silo.php';

class StubDatabase
{
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
}

class SiloHarness
{
    use Silo;
}

$fail    = [];
$harness = new LibraryHarness();
$harness->database = new StubDatabase();

$row = ['id' => 7, 'season' => 0, 'episode' => 0, 'title' => 'The Pitt', 'path' => '/tv/The Pitt/S02E14.mkv'];
$out = $harness->backfillEpisodeCode($row, ['season' => 2, 'episode' => 14, 'title' => '8:00 P.M.']);
if ($out['season'] != 2 || $out['episode'] != 14 || $out['title'] != '8:00 P.M.' || count($harness->database->queries) != 1) {
    $fail[] = 'S00E00 row not backfilled';
}

$harness->database->queries = [];
$harness->backfillEpisodeCode(['id' => 8, 'season' => 1, 'episode' => 3, 'title' => 'x'], ['season' => 2, 'episode' => 4, 'title' => 'y']);
$harness->backfillEpisodeCode(['id' => 9, 'season' => 0, 'episode' => 0, 'title' => 'Show'], ['season' => 0, 'episode' => 0, 'title' => 'Show']);
if ($harness->database->queries) {
    $fail[] = 'row written without a code to fill';
}

$silo = new SiloHarness();
if ($silo->siloRetryWait('5', 1) != 5 || $silo->siloRetryWait('', 3) != 6 || $silo->siloRetryWait('900', 1) != 60 || $silo->siloRetryWait('0', 1) != 1) {
    $fail[] = 'Retry-After wait';
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK S00E00 backfill and Retry-After wait' . PHP_EOL;
