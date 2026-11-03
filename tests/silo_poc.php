<?php

/*
----------------------------------
------  Created: 110226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * POC runner for the Silo trait. Reads only unless SILO_WRITE_TEST=1. Drop before an upstream PR.
 * Env: SILO_URL, SILO_API_KEY (admin key), SILO_USER_KEY (optional),
 * SILO_USER_ID (optional, the account that owns SILO_USER_KEY).
 * Write test: SILO_WRITE_TEST=1, SILO_TEST_USERNAME, SILO_TEST_ITEM. It refuses any
 * key whose account name differs from SILO_TEST_USERNAME.
 * With no SILO_API_KEY it runs the unauthenticated checks only.
 */

const APP_NAME = 'watchsync-silo-poc';
$root = dirname(__DIR__) . '/root/app/www/public/';
require $root . 'functions/curl.php';
require $root . 'functions/helpers/datetime.php';
require $root . 'classes/interfaces/MediaApps.php';
require $root . 'classes/traits/MediaApps/Silo.php';
require $root . 'classes/traits/MediaApps/ProviderIds.php';

function translate($key)
{
    return $key;
}

$silo = new class {
    use Silo;
    use ProviderIds;
};

$url    = rtrim(strval(getenv('SILO_URL')), '/');
$apikey = strval(getenv('SILO_API_KEY'));
echo 'online=' . ($silo->siloIsOnline($url) ? 'yes' : 'no') . PHP_EOL;

$identity = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_IDENTITY, $url), ['Accept: application/json']);
echo 'identity code=' . $identity['code'] . ' server_id=' . ($identity['response']['server_id'] ?? '') . PHP_EOL;

$anonymous = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_USERS, $url), ['Accept: application/json']);
echo 'anonymous admin/users code=' . $anonymous['code'] . PHP_EOL;

if ($apikey == '') {
    echo 'no SILO_API_KEY: stopped after the unauthenticated checks' . PHP_EOL;
    exit(0);
}

$test = $silo->siloTestConnection($url, $apikey);
if ($test['error']) {
    echo 'connection test failed: ' . $test['message'] . PHP_EOL;
    exit(1);
}
echo 'connection test ok server=' . $test['server_name'] . ' users=' . count($test['users']) . PHP_EOL;
foreach ($test['users'] as $user) {
    echo '  user id=' . $user['remote_id'] . ' name=' . $user['username'] . ' admin=' . $user['is_admin'] . PHP_EOL;
}

$libraries = $silo->siloGetLibraries($url, $apikey);
echo 'libraries=' . count($libraries) . PHP_EOL;
foreach ($libraries as $library) {
    echo '  library key=' . $library['key'] . ' title=' . $library['title'] . ' type=' . $library['type'] . ' paths=' . implode(',', $library['paths']) . PHP_EOL;
}

$userKey = strval(getenv('SILO_USER_KEY'));
$userId  = strval(getenv('SILO_USER_ID'));
$owner   = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $silo->siloHeaders($apikey))['response']['id'] ?? '';
$target  = $userId != '' ? $userId : strval($owner);
$status  = $silo->siloGetWatchStatus($url, $apikey, $target, 0, $userKey != '' ? ['token' => $userKey] : ['token' => '']);
echo 'watch user=' . $target . ' error=' . (!empty($status['error']) ? 'yes' : 'no')
    . ' movies=' . count($status['movies']) . ' episodes=' . count($status['episodes']) . PHP_EOL;
foreach (['movies', 'episodes'] as $bucket) {
    foreach ($status[$bucket] as $id => $entry) {
        echo '  sample ' . $bucket . ' id=' . $id . ' ' . json_encode($entry) . PHP_EOL;
        break;
    }
}

$history = curl(sprintf('%s/api/v2/admin/playback-history?limit=1&completed=true', $url), $silo->siloHeaders($apikey));
$play    = $history['response']['items'][0] ?? [];
echo 'real completed play code=' . $history['code'] . ' profile=' . ($play['profile_name'] ?? '') . ' type=' . ($play['media_type'] ?? '')
    . ' title=' . ($play['media_title'] ?? '') . ' item=' . ($play['media_item_id'] ?? '') . ' completed=' . json_encode($play['completed'] ?? null) . PHP_EOL;

$items = $silo->siloGetItems($url, $apikey, array_slice(array_column($libraries, 'key'), 0, 1));
echo 'catalog first library movies=' . count($items['movies']) . ' series=' . count($items['series']) . ' episodes=' . count($items['episodes']) . PHP_EOL;
foreach (['movies', 'series', 'episodes'] as $bucket) {
    $type = $bucket == 'movies' ? 'movie' : ($bucket == 'series' ? 'series' : 'episode');
    $ids  = 0;
    foreach ($items[$bucket] as $item) {
        $ids += !empty($item['provider_ids']) ? 1 : 0;
    }
    $self = $silo->matchItemsByProviderIds($type, array_slice($items[$bucket], 0, 500), array_slice($items[$bucket], 0, 500));
    echo '  ' . $bucket . ' with provider ids=' . $ids . ' self match first 500=' . count($self) . PHP_EOL;
}
$sample = current(array_filter($items['movies'], fn($item) => !empty($item['provider_ids'])));
if ($sample) {
    $plexRow = ['remote_id' => 'plex-shaped', 'provider_ids' => $silo->providerIdsFromPlexGuids(array_map(
        fn($provider, $id) => ['id' => $provider . '://' . $id],
        array_keys($sample['provider_ids']),
        $sample['provider_ids']
    ))];
    echo '  plex-shaped guid match ' . json_encode($silo->matchItemsByProviderIds('movie', [$sample], [$plexRow])) . PHP_EOL;
}

if (getenv('SILO_WRITE_TEST') !== '1') {
    exit(0);
}

$username = strval(getenv('SILO_TEST_USERNAME'));
$itemId   = strval(getenv('SILO_TEST_ITEM'));
$me       = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $silo->siloHeaders($userKey))['response'];
if ($username == '' || $itemId == '' || strval($me['username'] ?? '') !== $username) {
    echo 'write test refused: key does not belong to the test user' . PHP_EOL;
    exit(1);
}

$user  = ['token' => $userKey, 'remote_id' => strval($me['id'] ?? '')];
$state = function () use ($silo, $url, $apikey, $user, $itemId) {
    $status = $silo->siloGetWatchStatus($url, $apikey, $user['remote_id'], 0, $user);

    return json_encode($status['movies'][$itemId] ?? ($status['episodes'][$itemId] ?? null));
};
echo 'write test account=' . $username . ' item=' . $itemId . PHP_EOL;
echo '  before    ' . $state() . PHP_EOL;
echo '  position  ' . json_encode($silo->siloSetWatchStatus($url, $user, $itemId, 1, 600, 0)) . ' -> ' . $state() . PHP_EOL;
echo '  finished  ' . json_encode($silo->siloSetWatchStatus($url, $user, $itemId, 1, 0, 1)) . ' -> ' . $state() . PHP_EOL;
echo '  unwatched ' . json_encode($silo->siloSetWatchStatus($url, $user, $itemId, 0, 0, 0)) . ' -> ' . $state() . PHP_EOL;
