<?php

// Run: php tests/plex-user-token-write.php
// Plex marks watch state on the account whose token signs the request. A write
// for a non-owner user uses that user's token, never the server owner's, and
// is skipped when the user has no token.

$public = __DIR__ . '/../root/app/www/public';
require $public . '/classes/interfaces/MediaApps.php';
require $public . '/classes/traits/MediaApps/Plex.php';

const APP_NAME = 'Watchsync';

$calls = [];
function curl($url, $headers = [], $method = 'GET', $payload = '')
{
    global $calls;
    $token = '';
    foreach ($headers as $header) {
        if (str_starts_with($header, 'X-Plex-Token: ')) {
            $token = substr($header, strlen('X-Plex-Token: '));
        }
    }
    $calls[] = ['url' => $url, 'token' => $token];

    return ['code' => 200, 'response' => ''];
}

class FakeDatabase
{
    public $users = [];

    public function getMediaAppUser($id)
    {
        return $this->users[$id] ?? [];
    }
}

class PlexHarness
{
    use Plex;

    public $database;
    public $logged = [];

    public function plexWatchLog($message)
    {
        $this->logged[] = $message;
    }
}

$fail            = [];
$plex            = new PlexHarness();
$plex->database  = new FakeDatabase();
$plex->database->users = [
    1 => ['id' => 1, 'username' => 'owner', 'is_admin' => 1, 'token' => 'owner-user-token'],
    2 => ['id' => 2, 'username' => 'friend', 'is_admin' => 0, 'token' => 'friend-token'],
    3 => ['id' => 3, 'username' => 'notoken', 'is_admin' => 0, 'token' => ''],
];
$app = ['url' => 'http://plex', 'token' => 'server-owner-token'];

// Rows from getMediaAppUsers carry no token key, so the token loads by id.
$calls = [];
$plex->plexSetUserWatchStatus($app, ['id' => 2, 'username' => 'friend', 'is_admin' => 0], '455880', 1, 2549, 0);
if (count($calls) != 1 || $calls[0]['token'] != 'friend-token' || !str_contains($calls[0]['url'], '/:/progress?') || !str_contains($calls[0]['url'], 'time=2549000')) {
    $fail[] = 'non-owner progress not signed with the user token: ' . json_encode($calls);
}

$calls = [];
$plex->plexSetUserWatchStatus($app, ['id' => 2, 'username' => 'friend', 'token' => 'friend-token'], '455880', 1, 0, 2700);
if (count($calls) != 1 || $calls[0]['token'] != 'friend-token' || !str_contains($calls[0]['url'], '/:/scrobble?')) {
    $fail[] = 'non-owner scrobble not signed with the user token: ' . json_encode($calls);
}

$calls = [];
$plex->plexSetUserWatchStatus($app, ['id' => 1, 'username' => 'owner', 'is_admin' => 1], '455880', 0, 0, 0);
if (count($calls) != 1 || $calls[0]['token'] != 'server-owner-token' || !str_contains($calls[0]['url'], '/:/unscrobble?')) {
    $fail[] = 'owner write not signed with the server token: ' . json_encode($calls);
}

$calls        = [];
$plex->logged = [];
$wrote        = $plex->plexSetUserWatchStatus($app, ['id' => 3, 'username' => 'notoken', 'is_admin' => 0], '455880', 1, 0, 2700);
if ($calls || $wrote !== false || !$plex->logged || !str_contains($plex->logged[0], 'notoken')) {
    $fail[] = 'tokenless non-owner write not skipped and logged: ' . json_encode([$calls, $plex->logged]);
}

$calls = [];
$plex->plexSetUserWatchStatus($app, [], '455880', 1, 0, 2700);
if ($calls) {
    $fail[] = 'write with no user fell back to the server token: ' . json_encode($calls);
}

$sources = [
    $plex->plexWriteAccount($app, ['id' => 1])['source'],
    $plex->plexWriteAccount($app, ['id' => 2])['source'],
    $plex->plexWriteAccount($app, ['id' => 3])['source'],
];
if ($sources != ['owner', 'user', 'none']) {
    $fail[] = 'write account sources wrong: ' . json_encode($sources);
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK Plex writes signed with the user token' . PHP_EOL;
