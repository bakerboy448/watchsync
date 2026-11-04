<?php

/*
----------------------------------
------  Created: 110326   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Offline checks for provider id handling and Silo id parsing. Run: php tests/silo_match_test.php
 */

$root = dirname(__DIR__) . '/root/app/www/public/';
require $root . 'classes/traits/MediaApps/ProviderIds.php';
require $root . 'classes/traits/Cron/SiloLibrary.php';

$subject = new class {
    use ProviderIds;
    use SiloLibrary;
};

$failed = 0;
function check($name, $actual, $expected)
{
    global $failed;
    $ok = $actual === $expected;
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : ' got ' . json_encode($actual) . ' want ' . json_encode($expected)) . PHP_EOL;
}

check('normalize drops unknown and zero', $subject->normalizeProviderIds(['IMDB' => ' TT1 ', 'tmdb' => '0', 'foo' => 'x', 'tvdb' => '5']), ['imdb' => 'tt1', 'tvdb' => '5']);
check('plex guids', $subject->providerIdsFromPlexGuids([['id' => 'imdb://tt1'], ['id' => 'tmdb://2'], ['id' => 'plex://movie/abc']]), ['imdb' => 'tt1', 'tmdb' => '2']);
check('plex single guid', $subject->providerIdsFromPlexGuids(['id' => 'tvdb://9']), ['tvdb' => '9']);
check('silo series id imdb', $subject->siloSeriesProviderIds('series-imdb-tt0053502'), ['imdb' => 'tt0053502']);
check('silo series id local has no ids', $subject->siloSeriesProviderIds('local-2a39f02f'), []);
check('unique match', $subject->matchItemsByProviderIds('movie', [['remote_id' => 's1', 'provider_ids' => ['imdb' => 'tt1']]], [['remote_id' => 'p1', 'provider_ids' => ['imdb' => 'tt1']]]), ['s1' => 'p1']);
check('ambiguous target is skipped', $subject->matchItemsByProviderIds('movie', [['remote_id' => 's1', 'provider_ids' => ['imdb' => 'tt1']]], [['remote_id' => 'p1', 'provider_ids' => ['imdb' => 'tt1']], ['remote_id' => 'p2', 'provider_ids' => ['imdb' => 'tt1']]]), []);
check('episode matches on series ids plus s/e', $subject->matchItemsByProviderIds('episode', [['remote_id' => 's1', 'season' => 1, 'episode' => 2, 'series_remote_id' => 'S']], [['remote_id' => 'p1', 'season' => 1, 'episode' => 2, 'series_remote_id' => 'P']], ['S' => ['imdb' => 'tt9']], ['P' => ['imdb' => 'tt9']]), ['s1' => 'p1']);

echo $failed ? $failed . ' failed' . PHP_EOL : 'all passed' . PHP_EOL;
exit($failed ? 1 : 0);
