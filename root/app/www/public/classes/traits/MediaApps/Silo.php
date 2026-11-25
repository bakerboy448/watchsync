<?php

/*
----------------------------------
------  Created: 112226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Silo native API v2, listener only. The media app key lists users, libraries
 * and the catalog with file paths. A key acts only as its own account, so each
 * linked user needs their own key in the user token field for watch state.
 */
trait Silo
{
    protected $siloCatalogs   = [];
    protected $siloProfileIds = [];

    /*
     * Silo answers 429 under bulk paging. The wait follows Retry-After, and
     * grows by two seconds per attempt when the header is missing.
     */
    public function siloCurl($url, $headers = [], $method = 'GET', $payload = '', $userPass = [], $timeout = 60)
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $curl = curl($url, $headers, $method, $payload, $userPass, $timeout);
            if (intval($curl['code'] ?? 0) != 429 || $attempt == 8) {
                break;
            }
            sleep($this->siloRetryWait($curl['retry_after'] ?? '', $attempt));
        }

        return $curl;
    }

    public function siloRetryWait($retryAfter, $attempt)
    {
        $retryAfter = trim(strval($retryAfter));
        if (ctype_digit($retryAfter)) {
            $wait = intval($retryAfter);
        } else if ($retryAfter != '' && ($at = strtotime($retryAfter)) !== false) {
            $wait = $at - time();
        } else {
            $wait = $attempt * 2;
        }

        return max(1, min(60, $wait));
    }

    public function siloHeaders($apikey, $profileId = '')
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $apikey,
        ];
        if ($profileId != '') {
            $headers[] = 'X-Profile-Id: ' . $profileId;
        }

        return $headers;
    }

    public function siloIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ONLINE, $url), ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function siloGetAll($url, $endpoint, $apikey, $profileId = '', $query = [])
    {
        $items  = [];
        $cursor = '';
        $code   = 0;
        do {
            $params = $query;
            if ($cursor != '') {
                $params['cursor'] = $cursor;
            }
            $curl     = $this->siloCurl(sprintf($endpoint, $url) . ($params ? '?' . http_build_query($params) : ''), $this->siloHeaders($apikey, $profileId), 'GET');
            $code     = intval($curl['code'] ?? 0);
            $response = is_array($curl['response'] ?? null) ? $curl['response'] : [];
            if ($code < 200 || $code > 299 || !is_array($response['items'] ?? null)) {
                return ['error' => true, 'code' => $code, 'items' => []];
            }

            $items = array_merge($items, $response['items']);
            $next  = !empty($response['page']['has_more']) ? strval($response['page']['next_cursor'] ?? '') : '';
            if ($next != '' && $next == $cursor) {
                return ['error' => true, 'code' => $code, 'items' => []];
            }
            $cursor = $next;
        } while ($cursor != '');

        return ['error' => false, 'code' => $code, 'items' => $items];
    }

    public function siloTestConnection($url, $apikey)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$apikey) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        $curl     = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_IDENTITY, $url), ['Accept: application/json'], 'GET');
        $response = is_array($curl['response'] ?? null) ? $curl['response'] : [];
        $serverId = strval($response['server_id'] ?? '');
        if ($curl['code'] == 404) {
            return ['error' => true, 'message' => translate('mediaAppInvalidUrl')];
        }
        if ($serverId == '') {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppConnectionFailed')];
        }

        $users = $this->siloGetUsers($url, $apikey);
        if ($users['error']) {
            return $users;
        }

        return [
            'error'       => false,
            'server_id'   => $serverId,
            'server_name' => translate('silo'),
            'apikey'      => $apikey,
            'users'       => $users['users'],
        ];
    }

    public function siloGetUsers($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_USERS, $apikey, '', ['limit' => 200]);
        if (in_array($rows['code'], [401, 403])) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }
        if ($rows['error']) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        $users  = [];
        $access = [];
        foreach ($rows['items'] as $user) {
            $remoteId = strval($user['id'] ?? '');
            $username = trim(strval($user['username'] ?? ''));
            if (empty($user['enabled']) || $remoteId == '' || $username == '') {
                continue;
            }

            $email      = trim(strval($user['email'] ?? ''));
            $lastActive = strtotime(strval($user['last_active_at'] ?? ''));
            $users[]    = [
                'remote_id'   => $remoteId,
                'username'    => $username,
                'email'       => $email,
                'user_type'   => '',
                'is_admin'    => ($user['role'] ?? '') == 'admin' ? 1 : 0,
                'last_seen'   => $lastActive ?: 0,
                'match_names' => $email != '' ? [$username, $email] : [$username],
            ];

            $libraryIds = $user['effective_policy']['library_ids'] ?? null;
            $userAccess = is_array($libraryIds) ? ['all' => false, 'keys' => array_map('strval', $libraryIds)] : ['all' => true, 'keys' => []];
            foreach ([$remoteId, strtolower($username)] as $ident) {
                $access[$ident] = $userAccess;
            }
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'users' => $users, 'access' => $access];
    }

    public function siloLog($message)
    {
        global $cron;

        if (!empty($cron)) {
            $cron->log('silo ' . $message);
        }
    }

    public function siloGetLibraries($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_LIBRARIES, $apikey);
        if ($rows['error']) {
            $this->siloLog('libraries failed code=' . $rows['code'] . ($rows['code'] == 403 ? ' the media app key needs the libraries:read scope' : ''));

            return [];
        }

        $libraries = [];
        foreach ($rows['items'] as $library) {
            if (array_key_exists('enabled', $library) && !$library['enabled']) {
                continue;
            }
            $lastScanned = strtotime(strval($library['last_scanned_at'] ?? ''));
            $libraries[] = [
                'key'        => strval($library['id'] ?? ''),
                'title'      => strval($library['name'] ?? ''),
                'type'       => strval($library['type'] ?? ''),
                'paths'      => [],
                'updated_at' => 0,
                'scanned_at' => $lastScanned ?: 0,
            ];
        }

        return $libraries;
    }

    public function siloGetUserLibraryKeys($url, $apikey, $user)
    {
        $remoteId = strval($user['remote_id'] ?? '');
        if ($remoteId == '') {
            return ['all' => false, 'keys' => []];
        }

        return $this->siloGetUsers($url, $apikey)['access'][$remoteId] ?? ['all' => false, 'keys' => []];
    }

    /*
     * Only the user's own key. The media app key is scoped to admin reads and
     * cannot act as any account.
     */
    public function siloUserKey($user)
    {
        return trim(strval($user['token'] ?? ''));
    }

    public function validateSiloUserToken($user, $mediaApp, $token)
    {
        $token = trim(strval($token ?? ''));
        if ($token == '') {
            return ['error' => true, 'message' => translate('tokenRequired')];
        }

        $url  = rtrim(trim($mediaApp['url'] ?? ''), '/');
        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $this->siloHeaders($token), 'GET');
        $me   = is_array($curl['response'] ?? null) ? $curl['response'] : [];
        $ok   = intval($curl['code'] ?? 0) == 200 && strval($me['id'] ?? '') != '' && strval($me['id']) === strval($user['remote_id'] ?? '');

        return [
            'error'     => !$ok,
            'message'   => translate($ok ? 'tokenValid' : 'tokenInvalid'),
            'icon_html' => mediaAppUserTokenIconFromRow($user),
        ];
    }

    /*
     * An account key acts as the profile named in X-Profile-Id, a profile key
     * lists only its own profile.
     */
    public function siloProfileId($url, $key)
    {
        $cacheKey = hash('sha256', $url . '|' . $key);
        if (array_key_exists($cacheKey, $this->siloProfileIds)) {
            return $this->siloProfileIds[$cacheKey];
        }

        $this->siloProfileIds[$cacheKey] = $this->siloFetchProfileId($url, $key);

        return $this->siloProfileIds[$cacheKey];
    }

    public function siloFetchProfileId($url, $key)
    {
        $curl     = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_PROFILES, $url), $this->siloHeaders($key), 'GET');
        $profiles = is_array($curl['response']['items'] ?? null) ? $curl['response']['items'] : [];
        if (count($profiles) == 1) {
            return strval($profiles[0]['id'] ?? '');
        }
        foreach ($profiles as $profile) {
            if (!empty($profile['is_primary'])) {
                return strval($profile['id'] ?? '');
            }
        }

        return '';
    }

    public function siloItemTypes($url, $apikey, $ids)
    {
        $types = [];
        foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
            $query = 'limit=200&' . implode('&', array_map(fn($id) => 'content_ids=' . rawurlencode($id), $chunk));
            $curl  = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $url) . '?' . $query, $this->siloHeaders($apikey), 'GET');
            $code  = intval($curl['code'] ?? 0);
            if ($code < 200 || $code > 299) {
                return false;
            }
            foreach ($curl['response']['items'] ?? [] as $item) {
                $types[strval($item['content_id'] ?? '')] = strval($item['type'] ?? '');
            }
        }

        return $types;
    }

    /*
     * Reads /progress as the user. Admin playback history is not used: it keeps
     * plays after a mark unwatched, so it would report a cleared item as watched.
     */
    public function siloGetWatchStatus($url, $apikey, $remoteId, $userId = 0, $user = [])
    {
        $status = [
            'movies'   => [],
            'episodes' => [],
        ];
        $url = rtrim(trim($url), '/');
        if ($userId && !array_key_exists('token', $user)) {
            $user = array_merge($user, $this->database->getMediaAppUser($userId) ?: []);
        }
        $user['remote_id'] = $remoteId;

        $key = $this->siloUserKey($user);
        if ($key == '') {
            $this->siloLog('skip watch status user=' . $remoteId . ' no user key');
            $status['error'] = true;

            return $status;
        }

        $profileId = $this->siloProfileId($url, $key);
        if ($profileId == '') {
            $status['error'] = true;

            return $status;
        }

        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_PROGRESS, $key, $profileId, ['limit' => 200]);
        if ($rows['error']) {
            $status['error'] = true;

            return $status;
        }

        $types = $this->siloItemTypes($url, $apikey, array_map(fn($row) => strval($row['media_item_id'] ?? ''), $rows['items']));
        if ($types === false) {
            $status['error'] = true;

            return $status;
        }

        foreach ($rows['items'] as $row) {
            $id = strval($row['media_item_id'] ?? '');
            if ($id == '') {
                continue;
            }
            $finished = !empty($row['completed']) ? 1 : 0;
            $position = intval($row['position_seconds'] ?? 0);
            $entry    = [
                'started'    => ($finished || $position) ? 1 : 0,
                'inprogress' => $finished ? 0 : $position,
                'finished'   => watchFinishedSeconds($finished, $row['duration_seconds'] ?? 0),
            ];
            $type = $types[$id] ?? '';
            if ($type == 'episode') {
                $status['episodes'][$id] = $entry;
            } else if ($type == 'movie') {
                $status['movies'][$id] = $entry;
            }
        }

        global $cron;
        if (!empty($cron)) {
            $cron->log('overview movies=' . count($status['movies']) . ' episodes=' . count($status['episodes']));
        }

        return $status;
    }

    /*
     * The whole catalog with file paths, fetched once per run. Silo gives one
     * content_id to a title and lists each of its files as a version, so the
     * library_id filter is not used: it would return every version of a title
     * that is in the library, including files in other libraries.
     */
    public function siloCatalog($url, $apikey)
    {
        $cacheKey = hash('sha256', $url . '|' . $apikey);
        if (array_key_exists($cacheKey, $this->siloCatalogs)) {
            return $this->siloCatalogs[$cacheKey];
        }

        $this->siloLog('fetching catalog');
        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $apikey, '', ['limit' => 200, 'include_file_paths' => 'true']);
        if ($rows['error']) {
            $this->siloLog('catalog failed code=' . $rows['code'] . ($rows['code'] == 403 ? ' the media app key needs the admin:catalog:paths scope' : ''));

            return false;
        }

        $this->siloCatalogs[$cacheKey] = $rows['items'];

        return $rows['items'];
    }

    public function siloGetItems($url, $apikey, $libraryKeys = [])
    {
        $items = [
            'movies'   => [],
            'series'   => [],
            'episodes' => [],
        ];

        $url  = rtrim(trim($url), '/');
        $keys = [];
        foreach ($libraryKeys as $entry) {
            if (is_array($entry) && strval($entry['key'] ?? '') != '') {
                $keys[strval($entry['key'])] = strval($entry['title'] ?? '');
            } else if (!is_array($entry) && strval($entry) != '') {
                $keys[strval($entry)] = '';
            }
        }
        if (!$keys) {
            foreach ($this->siloGetLibraries($url, $apikey) as $library) {
                if ($library['key'] != '') {
                    $keys[$library['key']] = $library['title'];
                }
            }
        }

        $catalog = $this->siloCatalog($url, $apikey);
        if ($catalog === false) {
            $items['error'] = true;

            return $items;
        }

        foreach ($catalog as $row) {
            $type = strval($row['type'] ?? '');
            if ($type != 'movie' && $type != 'episode') {
                continue;
            }
            $added = strtotime(strval($row['added_at'] ?? ''));
            foreach (is_array($row['versions'] ?? null) ? $row['versions'] : [] as $version) {
                $libraryKey = strval($version['library_id'] ?? '');
                $path       = strval($version['file_path'] ?? '');
                if ($path == '' || !array_key_exists($libraryKey, $keys)) {
                    continue;
                }
                $item = [
                    'remote_id'        => strval($row['content_id'] ?? ''),
                    'title'            => strval($row['title'] ?? ''),
                    'year'             => intval($row['year'] ?? 0),
                    'path'             => $path,
                    'series_remote_id' => strval($row['series_id'] ?? ''),
                    'series'           => strval($row['series_title'] ?? ''),
                    'season'           => intval($row['season_number'] ?? 0),
                    'episode'          => intval($row['episode_number'] ?? 0),
                    'library'          => $keys[$libraryKey],
                    'updated_at'       => $added ?: 0,
                    'duration'         => 0,
                ];
                $items[$type == 'movie' ? 'movies' : 'episodes'][] = $item;
            }
        }

        return $items;
    }

    /*
     * Started and finished items go through the progress sync with the
     * source's view time as updated_at, so Silo keeps the real date and never
     * replaces newer local playback. A finished item is sent at its full
     * runtime and Silo's watched threshold marks it complete. Silo's
     * markWatched takes no date, so it is not used. An item with no known
     * view time is skipped, as is a resume point older than the cutoff.
     * Anything else is marked unwatched.
     */
    public function siloSetWatchStatus($url, $apikey, $user, $remoteId, $started, $inprogress, $finished, $watch = [], $resumeCutoff = 0)
    {
        $url = rtrim(trim($url), '/');
        $key = $this->siloUserKey($user);
        if ($key == '') {
            $this->siloLog('skip setWatchStatus item=' . $remoteId . ' user=' . ($user['remote_id'] ?? '') . ' no user key');

            return ['error' => true, 'code' => 0];
        }

        $profileId = $this->siloProfileId($url, $key);
        if ($profileId == '') {
            $this->siloLog('skip setWatchStatus item=' . $remoteId . ' user=' . ($user['remote_id'] ?? '') . ' no profile');

            return ['error' => true, 'code' => 0];
        }

        $headers = $this->siloHeaders($key, $profileId);
        if ($finished || ($started && $inprogress)) {
            $item = $this->siloProgressItem($remoteId, $inprogress, $finished, $watch, $resumeCutoff);
            if (!empty($item['skip'])) {
                $this->siloLog('skip setWatchStatus item=' . $remoteId . ' user=' . ($user['remote_id'] ?? '') . ' ' . $item['skip']);

                return ['error' => false, 'code' => 0, 'skipped' => $item['skip']];
            }
            $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_SYNC_PROGRESS, $url), $headers, 'POST', json_encode(['items' => [$item]]));
        } else {
            $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_WATCHED, $url, rawurlencode($remoteId)), $headers, 'DELETE');
        }
        $code = intval($curl['code'] ?? 0);
        if ($code < 200 || $code > 299) {
            $this->siloLog('setWatchStatus item=' . $remoteId . ' user=' . ($user['remote_id'] ?? '') . ' failed code=' . $code);
        }

        return ['error' => $code < 200 || $code > 299, 'code' => $code];
    }

    public function siloProgressItem($remoteId, $inprogress, $finished, $watch, $resumeCutoff)
    {
        $viewedAt = intval($watch['viewed_at'] ?? 0);
        $runtime  = max(intval($watch['runtime'] ?? 0), intval($finished));
        if ($viewedAt <= 0) {
            return ['skip' => 'no view date'];
        }
        if ($finished && $runtime <= 1) {
            return ['skip' => 'no runtime'];
        }
        if (!$finished && $resumeCutoff && $viewedAt < $resumeCutoff) {
            return ['skip' => 'resume point older than continue watching window'];
        }

        return [
            'media_item_id' => strval($remoteId),
            'position_ms'   => ($finished ? $runtime : intval($inprogress)) * 1000,
            'duration_ms'   => $runtime * 1000,
            'updated_at'    => gmdate('Y-m-d\TH:i:s\Z', $viewedAt),
        ];
    }
}
