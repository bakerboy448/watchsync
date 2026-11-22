<?php

/*
----------------------------------
------  Created: 112226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Silo native API v2, listener only. An API key acts only as its own account,
 * so the media app key lists users, libraries and the catalog, and each linked
 * user needs their own key in the user token field to read and write watch state.
 */
trait Silo
{
    /*
     * Silo answers 429 under bulk paging. curl() does not return the
     * Retry-After header, so the wait grows by two seconds per attempt.
     */
    public function siloCurl($url, $headers = [], $method = 'GET', $payload = '', $userPass = [], $timeout = 60)
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $curl = curl($url, $headers, $method, $payload, $userPass, $timeout);
            if (intval($curl['code'] ?? 0) != 429) {
                break;
            }
            sleep($attempt * 2);
        }

        return $curl;
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

    public function siloGetLibraries($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_LIBRARIES, $apikey);
        if ($rows['error']) {
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
                'paths'      => is_array($library['paths'] ?? null) ? $library['paths'] : [],
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
     * The user's own key, or the media app key when the user is the account
     * that owns it. Never another account's key.
     */
    public function siloUserKey($url, $apikey, $user)
    {
        $token = trim(strval($user['token'] ?? ''));
        if ($token != '') {
            return $token;
        }

        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $this->siloHeaders($apikey), 'GET');
        $me   = is_array($curl['response'] ?? null) ? $curl['response'] : [];
        if (strval($me['id'] ?? '') == '' || strval($me['id']) !== strval($user['remote_id'] ?? '')) {
            return '';
        }

        return $apikey;
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

        $key = $this->siloUserKey($url, $apikey, $user);
        if ($key == '') {
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

    public function siloGetItems($url, $apikey, $libraryKeys = [])
    {
        $items = [
            'movies'   => [],
            'series'   => [],
            'episodes' => [],
        ];

        global $cron;

        $url  = rtrim(trim($url), '/');
        $keys = [];
        foreach ($libraryKeys as $entry) {
            if (is_array($entry) && !empty($entry['key'])) {
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

        foreach ($keys as $libraryKey => $libraryTitle) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . ($libraryTitle ?: $libraryKey));
            }
            $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $apikey, '', ['limit' => 200, 'library_id' => $libraryKey]);
            if ($rows['error']) {
                $items['error'] = true;

                return $items;
            }
            foreach ($rows['items'] as $row) {
                $added = strtotime(strval($row['added_at'] ?? ''));
                $item  = [
                    'remote_id'        => strval($row['content_id'] ?? ''),
                    'title'            => strval($row['title'] ?? ''),
                    'year'             => intval($row['year'] ?? 0),
                    'path'             => '',
                    'series_remote_id' => strval($row['series_id'] ?? ''),
                    'series'           => strval($row['series_title'] ?? ''),
                    'season'           => intval($row['season_number'] ?? 0),
                    'episode'          => intval($row['episode_number'] ?? 0),
                    'library'          => $libraryTitle,
                    'updated_at'       => $added ?: 0,
                    'duration'         => 0,
                    'provider_ids'     => $this->normalizeProviderIds([
                        'imdb' => $row['imdb_id'] ?? '',
                        'tmdb' => $row['tmdb_id'] ?? '',
                        'tvdb' => $row['tvdb_id'] ?? '',
                    ]),
                ];
                $type = strval($row['type'] ?? '');
                if ($type == 'movie') {
                    $items['movies'][] = $item;
                } else if ($type == 'series') {
                    $items['series'][] = $item;
                } else if ($type == 'episode') {
                    $items['episodes'][] = $item;
                }
            }
        }

        return $items;
    }

    /*
     * Silo takes no play date and no position on a mark, so only finished and
     * cleared states are written. An in progress state is left as it is.
     */
    public function siloSetWatchStatus($url, $apikey, $user, $remoteId, $started, $inprogress, $finished)
    {
        global $cron;

        $url = rtrim(trim($url), '/');
        if (!$finished && $started && $inprogress) {
            if (!empty($cron)) {
                $cron->log('silo skip setWatchStatus item=' . $remoteId . ' in progress is not supported');
            }

            return ['error' => true, 'code' => 0];
        }

        $key = $this->siloUserKey($url, $apikey, $user);
        if ($key == '') {
            if (!empty($cron)) {
                $cron->log('silo skip setWatchStatus item=' . $remoteId . ' user=' . ($user['remote_id'] ?? '') . ' no user key');
            }

            return ['error' => true, 'code' => 0];
        }

        $profileId = $this->siloProfileId($url, $key);
        if ($profileId == '') {
            return ['error' => true, 'code' => 0];
        }

        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_WATCHED, $url, rawurlencode($remoteId)), $this->siloHeaders($key, $profileId), $finished ? 'POST' : 'DELETE');
        $code = intval($curl['code'] ?? 0);

        return ['error' => $code < 200 || $code > 299, 'code' => $code];
    }
}
