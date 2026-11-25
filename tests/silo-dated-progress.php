<?php

// Run: php tests/silo-dated-progress.php
// Silo writes carry the source's view time and runtime, so a sync never dates
// old progress as now. Undated items and stale resume points are skipped.

$public = __DIR__ . '/../root/app/www/public';
require $public . '/classes/interfaces/MediaApps.php';
require $public . '/functions/helpers/datetime.php';
require $public . '/classes/traits/MediaApps/Silo.php';

class SiloHarness
{
    use Silo;
}

$fail   = [];
$silo   = new SiloHarness();
$viewed = 1700000000;
$cutoff = $viewed - 86400;

$item = $silo->siloProgressItem('movie-1', 600, 0, ['viewed_at' => $viewed, 'runtime' => 5400], $cutoff);
if ($item != ['media_item_id' => 'movie-1', 'position_ms' => 600000, 'duration_ms' => 5400000, 'updated_at' => '2023-11-14T22:13:20Z']) {
    $fail[] = 'resume point not dated: ' . json_encode($item);
}

$item = $silo->siloProgressItem('episode-1', 0, 2700, ['viewed_at' => $viewed], $cutoff);
if (($item['position_ms'] ?? 0) != 2700000 || ($item['duration_ms'] ?? 0) != 2700000 || ($item['updated_at'] ?? '') != '2023-11-14T22:13:20Z') {
    $fail[] = 'finished item not sent at full runtime with its date: ' . json_encode($item);
}

if (($silo->siloProgressItem('movie-1', 600, 0, [], $cutoff)['skip'] ?? '') != 'no view date') {
    $fail[] = 'undated item not skipped';
}
if (empty($silo->siloProgressItem('movie-1', 600, 0, ['viewed_at' => $cutoff - 1, 'runtime' => 5400], $cutoff)['skip'])) {
    $fail[] = 'stale resume point not skipped';
}
if (!empty($silo->siloProgressItem('movie-1', 0, 5400, ['viewed_at' => $cutoff - 1], $cutoff)['skip'])) {
    $fail[] = 'old finished item skipped by the resume window';
}
if (($silo->siloProgressItem('movie-1', 0, 1, ['viewed_at' => $viewed], $cutoff)['skip'] ?? '') != 'no runtime') {
    $fail[] = 'finished item without runtime not skipped';
}

$merged = mergeWatchState(['started' => 1, 'inprogress' => 0, 'finished' => 0, 'viewed_at' => 100], ['started' => 1, 'inprogress' => 300, 'finished' => 0, 'viewed_at' => 200, 'runtime' => 1800]);
if (($merged['viewed_at'] ?? 0) != 200 || ($merged['runtime'] ?? 0) != 1800 || $merged['inprogress'] != 300) {
    $fail[] = 'merge lost the newest view time or runtime: ' . json_encode($merged);
}
$merged = mergeWatchState([], ['started' => 1, 'inprogress' => 0, 'finished' => 1800, 'viewed_at' => 50]);
if (($merged['viewed_at'] ?? 0) != 50 || !watchStatesEqual($merged, ['started' => 1, 'inprogress' => 0, 'finished' => 1800])) {
    $fail[] = 'first merge dropped the view time or changed the compare';
}
if (array_key_exists('viewed_at', mergeWatchState([], ['started' => 1, 'inprogress' => 5, 'finished' => 0]))) {
    $fail[] = 'view time invented for an undated state';
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK Silo dated progress and merge meta' . PHP_EOL;
