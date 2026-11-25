<?php

// Run: php tests/jellyfin-dated-watch.php
// Jellyfin writes carry the source's view time, never the sync time. An
// undated write sends no date, so Jellyfin keeps its stored LastPlayedDate.

$public = __DIR__ . '/../root/app/www/public';
require $public . '/classes/interfaces/MediaApps.php';
require $public . '/classes/traits/MediaApps/Jellyfin.php';

const APP_NAME = 'Watchsync';

$calls = [];
function curl($url, $headers = [], $method = 'GET', $payload = '')
{
    global $calls;
    $calls[] = ['url' => $url, 'method' => $method, 'payload' => $payload ? json_decode($payload, true) : null];

    return $method == 'GET' ? ['code' => 200, 'response' => ['Id' => 'item-1', 'UserData' => ['PlayCount' => 2, 'Played' => false]]] : ['code' => 204];
}

class JellyfinHarness
{
    use Jellyfin;
}

$fail     = [];
$jellyfin = new JellyfinHarness();
$viewed   = 1700000000;
$date     = '2023-11-14T22:13:20Z';

$calls = [];
$jellyfin->jellyfinSetWatchStatus('http://jf', 'key', 'user-1', 'item-1', 1, 0, 2700, ['viewed_at' => $viewed, 'runtime' => 2700]);
$played = $calls[1] ?? [];
$data   = $calls[2] ?? [];
parse_str(parse_url($played['url'] ?? '', PHP_URL_QUERY) ?? '', $query);
if (($played['method'] ?? '') != 'POST' || ($query['DatePlayed'] ?? '') != $date) {
    $fail[] = 'finished mark not dated: ' . json_encode($played);
}
if (($data['payload']['LastPlayedDate'] ?? '') != $date || empty($data['payload']['Played'])) {
    $fail[] = 'finished user data not dated: ' . json_encode($data);
}

$calls = [];
$jellyfin->jellyfinSetWatchStatus('http://jf', 'key', 'user-1', 'item-1', 1, 600, 0, ['viewed_at' => $viewed]);
$data = $calls[1] ?? [];
if (($data['payload']['LastPlayedDate'] ?? '') != $date || ($data['payload']['PlaybackPositionTicks'] ?? 0) != 6000000000) {
    $fail[] = 'resume point not dated: ' . json_encode($data);
}

$calls = [];
$jellyfin->jellyfinSetWatchStatus('http://jf', 'key', 'user-1', 'item-1', 1, 0, 2700);
if (str_contains($calls[1]['url'] ?? '', 'DatePlayed') || array_key_exists('LastPlayedDate', $calls[2]['payload'] ?? [])) {
    $fail[] = 'undated finished write sent a date: ' . json_encode($calls);
}

$calls = [];
$jellyfin->jellyfinSetWatchStatus('http://jf', 'key', 'user-1', 'item-1', 1, 600, 0, []);
if (array_key_exists('LastPlayedDate', $calls[1]['payload'] ?? [])) {
    $fail[] = 'undated resume point sent a date: ' . json_encode($calls);
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK Jellyfin dated watch writes' . PHP_EOL;
