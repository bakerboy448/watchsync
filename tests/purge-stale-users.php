<?php

// Run: php tests/purge-stale-users.php
// A listener user the remote app no longer lists is pruned, even when it is
// linked to a live main user. A failed or empty lookup prunes nothing, and an
// admin (owner) row or a user the remote lists but did not return is kept.

$public = __DIR__ . '/../root/app/www/public';
define('RELATIVE_PATH', $public . '/');
define('CRON_HOUSEKEEPER_LOG', 'housekeeper');
require $public . '/classes/interfaces/MediaApps.php';

function loadClassTraits($dir)
{
    foreach (glob($dir . '*.php') as $file) {
        require $file;
    }
}
function logger($logfile, $msg) {}
function loggerFlush($logfile = '') {}
function translate(string $key, array $args = []): string
{
    return $key;
}

class StubDatabase
{
    public $apps    = [];
    public $users   = [];
    public $links   = [];
    public $deleted = [];

    public function getMediaApps()
    {
        return array_values($this->apps);
    }

    public function getMediaApp($id)
    {
        return $this->apps[intval($id)] ?? [];
    }

    public function getMediaAppUsers($mediaAppId, $alignUsers = [])
    {
        $rows = [];
        foreach ($this->users as $user) {
            if (intval($user['media_app_id']) == intval($mediaAppId) && !in_array(intval($user['id']), $this->deleted)) {
                $rows[] = $user;
            }
        }

        return $rows;
    }

    public function replaceMediaAppUsers($mediaAppId, $users) {}

    public function getMediaAppUserLinks()
    {
        return $this->links;
    }

    public function getMediaAppUserLink($id)
    {
        return [];
    }

    public function deleteMediaAppUsersByIds($ids)
    {
        foreach ($ids as $id) {
            $this->deleted[] = intval($id);
        }

        return true;
    }

    public function getJsonSetting($setting)
    {
        return [];
    }

    public function setJsonSetting($setting, $value) {}

    public function mediaAppUserMatchName($name)
    {
        return strtolower(trim(strval($name)));
    }
}

$database = new StubDatabase();
require $public . '/classes/MediaApps.php';

class MediaAppsHarness extends MediaApps
{
    public $listenerResult = [];

    public function isOnline($mediaApp)
    {
        return true;
    }

    public function plexGetUsers($url, $token, $serverId = '', $local = false, $fetchHomeTokens = true, $manualTokenNames = [])
    {
        return ['error' => false, 'users' => [['remote_id' => 'p1', 'username' => 'bakerboy448', 'is_admin' => 1]]];
    }

    public function plexDeletedIdents($url, $token, $serverId = '')
    {
        return [];
    }

    public function jellyfinGetUsers($url, $apikey)
    {
        return $this->listenerResult;
    }
}

function freshDatabase()
{
    $db       = new StubDatabase();
    $db->apps = [
        1 => ['id' => 1, 'name' => 'Plex', 'platform' => MediaPlatforms::PLEX, 'role' => MediaAppRoles::MASTER, 'active' => 1, 'url' => 'http://plex', 'token' => 't', 'server_id' => 's'],
        3 => ['id' => 3, 'name' => 'Jellyfin', 'platform' => MediaPlatforms::JELLYFIN, 'role' => MediaAppRoles::LISTENER, 'active' => 1, 'url' => 'http://jellyfin', 'apikey' => 'k'],
    ];
    $db->users = [
        ['id' => 1, 'media_app_id' => 1, 'remote_id' => 'p1', 'username' => 'bakerboy448', 'email' => '', 'is_admin' => 1],
        ['id' => 305, 'media_app_id' => 3, 'remote_id' => 's1', 'username' => 'bakerboy448', 'email' => '', 'is_admin' => 0],
        ['id' => 306, 'media_app_id' => 3, 'remote_id' => 's2', 'username' => 'owner', 'email' => '', 'is_admin' => 1],
        ['id' => 307, 'media_app_id' => 3, 'remote_id' => 's3', 'username' => 'watchsync-test', 'email' => '', 'is_admin' => 0],
        ['id' => 308, 'media_app_id' => 3, 'remote_id' => 's4', 'username' => 'leona', 'email' => '', 'is_admin' => 0],
        ['id' => 309, 'media_app_id' => 3, 'remote_id' => 's5', 'username' => 'paused', 'email' => '', 'is_admin' => 0],
    ];
    $db->links = [
        ['media_app_user_id' => 1, 'linked_media_app_user_id' => 305],
        ['media_app_user_id' => 1, 'linked_media_app_user_id' => 307],
    ];

    return $db;
}

function runPurge($listenerResult)
{
    global $database;

    $database             = freshDatabase();
    $harness              = new MediaAppsHarness();
    $harness->listenerResult  = $listenerResult;
    $result               = $harness->purgeDeletedUsers();
    sort($database->deleted);

    return [$database->deleted, $result];
}

$fail = [];
$live = [
    'error'  => false,
    'users'  => [
        ['remote_id' => 's1', 'username' => 'bakerboy448'],
        ['remote_id' => 's4', 'username' => 'leona'],
    ],
    'listed' => [
        ['remote_id' => 's1', 'username' => 'bakerboy448'],
        ['remote_id' => 's4', 'username' => 'leona'],
        ['remote_id' => 's5', 'username' => 'paused'],
    ],
];

[$deleted, $result] = runPurge($live);
if ($deleted != [307]) {
    $fail[] = 'stale prune deleted ' . json_encode($deleted) . ', want [307]';
}
if (($result['removedByApp'][3] ?? []) != ['watchsync-test']) {
    $fail[] = 'removedByApp ' . json_encode($result['removedByApp'] ?? []);
}
$keptIds = array_map(fn($u) => intval($u['id']), $result['users'][3] ?? []);
if (in_array(307, $keptIds) || !in_array(306, $keptIds) || !in_array(309, $keptIds)) {
    $fail[] = 'kept users ' . json_encode($keptIds);
}

[$deleted] = runPurge(['error' => true, 'message' => 'Plex Home users lookup failed (0)']);
if ($deleted) {
    $fail[] = 'failed lookup deleted ' . json_encode($deleted);
}

[$deleted] = runPurge(['error' => false, 'users' => []]);
if ($deleted) {
    $fail[] = 'empty lookup deleted ' . json_encode($deleted);
}

if ($fail) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $fail) . PHP_EOL);
    exit(1);
}
echo 'OK purge stale users' . PHP_EOL;
