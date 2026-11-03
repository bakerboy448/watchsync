<?php

/*
----------------------------------
------  Created: 110226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * POC runner for the Silo trait. Reads only. Drop before an upstream PR.
 * Env: SILO_URL, SILO_API_KEY (admin key), SILO_USER_KEY (optional),
 * SILO_USER_ID (optional, the account that owns SILO_USER_KEY).
 * With no SILO_API_KEY it runs the unauthenticated checks only.
 */

const APP_NAME = 'watchsync-silo-poc';
$root = dirname(__DIR__) . '/root/app/www/public/';
require $root . 'functions/curl.php';
require $root . 'functions/helpers/datetime.php';
require $root . 'classes/interfaces/MediaApps.php';
require $root . 'classes/traits/MediaApps/Silo.php';

function translate($key)
{
    return $key;
}

$silo = new class {
    use Silo;
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
