<?php

/*
----------------------------------
------  Created: 110226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Silo native API v2. An API key acts only as its owning account, so a key
 * cannot read another account's watch state. The media app key lists users and
 * libraries; each other user needs their own key in the user token field.
 */
trait Silo
{
    /*
     * Silo answers 429 under bulk paging. The curl helper does not return the
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
        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ONLINE, $url), ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function siloGetAll($url, $path, $apikey, $profileId = '', $query = [])
    {
        $items  = [];
        $cursor = '';
        do {
            $params = $query;
            if ($cursor != '') {
                $params['cursor'] = $cursor;
            }
            $curl     = $this->siloCurl(sprintf($path, $url) . ($params ? '?' . http_build_query($params) : ''), $this->siloHeaders($apikey, $profileId), 'GET');
            $code     = intval($curl['code'] ?? 0);
            $response = is_array($curl['response'] ?? null) ? $curl['response'] : [];
            if ($code < 200 || $code > 299 || !is_array($response['items'] ?? null)) {
                return ['error' => true, 'code' => $code, 'items' => []];
            }

            $items  = array_merge($items, $response['items']);
            $next   = !empty($response['page']['has_more']) ? strval($response['page']['next_cursor'] ?? '') : '';
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
            return ['error' => true, 'message' => translate('mediaAppConnectionFailed')];
        }

        $users = $this->siloGetUsers($url, $apikey);
        if ($users['error']) {
            return $users;
        }

        return [
            'error'       => false,
            'server_id'   => $serverId,
            'server_name' => 'Silo',
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
            if (empty($user['enabled'])) {
                continue;
            }
            $lastActive = strtotime(strval($user['last_active_at'] ?? ''));
            $users[]    = [
                'remote_id' => strval($user['id'] ?? ''),
                'username'  => strval($user['username'] ?? ($user['id'] ?? '')),
                'email'     => strval($user['email'] ?? ''),
                'user_type' => '',
                'is_admin'  => ($user['role'] ?? '') == 'admin' ? 1 : 0,
                'last_seen' => $lastActive ?: 0,
            ];
            $keys = array_map('strval', $user['effective_policy']['library_ids'] ?? []);
            foreach ([strval($user['id'] ?? ''), strtolower(strval($user['username'] ?? ''))] as $ident) {
                if ($ident != '') {
                    $access[$ident] = ['all' => false, 'keys' => $keys];
                }
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
        $url      = rtrim(trim($url), '/');
        $remoteId = strval($user['remote_id'] ?? '');
        if ($remoteId == '') {
            return ['all' => false, 'keys' => []];
        }

        return $this->siloGetUsers($url, $apikey)['access'][$remoteId] ?? ['all' => false, 'keys' => []];
    }

    public function siloUserKey($url, $apikey, $user)
    {
        $token = trim(strval($user['token'] ?? ''));
        if ($token != '') {
            return $token;
        }

        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $this->siloHeaders($apikey), 'GET');
        $me   = is_array($curl['response'] ?? null) ? $curl['response'] : [];

        return strval($me['id'] ?? '') === strval($user['remote_id'] ?? '') ? $apikey : '';
    }

    /*
     * A user key is valid when it authenticates as the Silo account that this
     * user row was imported from.
     */
    public function validateSiloUserToken($user, $mediaApp, $token)
    {
        $token = trim(strval($token ?? ''));
        if ($token == '') {
            return ['error' => true, 'message' => translate('tokenRequired')];
        }

        $url  = rtrim(trim($mediaApp['url']), '/');
        $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $this->siloHeaders($token), 'GET');
        $me   = is_array($curl['response'] ?? null) ? $curl['response'] : [];
        $ok   = intval($curl['code'] ?? 0) == 200 && strval($me['id'] ?? '') !== '' && strval($me['id']) === strval($user['remote_id'] ?? '');

        return [
            'error'     => !$ok,
            'message'   => translate($ok ? 'tokenValid' : 'tokenInvalid'),
            'icon_html' => mediaAppUserTokenIconFromRow($user),
        ];
    }

    public function siloPrimaryProfileId($url, $key)
    {
        $curl     = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_PROFILES, $url), $this->siloHeaders($key), 'GET');
        $response = is_array($curl['response'] ?? null) ? $curl['response'] : [];
        foreach ($response['items'] ?? [] as $profile) {
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
            $query = implode('&', array_map(fn($id) => 'content_ids=' . rawurlencode($id), $chunk));
            $curl  = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $url) . '?limit=200&' . $query, $this->siloHeaders($apikey), 'GET');
            if (intval($curl['code'] ?? 0) < 200 || intval($curl['code'] ?? 0) > 299) {
                return false;
            }
            foreach (($curl['response']['items'] ?? []) as $item) {
                $types[strval($item['content_id'] ?? '')] = strval($item['type'] ?? '');
            }
        }

        return $types;
    }

    public function siloGetWatchStatus($url, $apikey, $remoteId, $userId = 0, $user = [])
    {
        $status = ['movies' => [], 'episodes' => []];
        $url    = rtrim(trim($url), '/');
        if ($userId && !array_key_exists('token', $user)) {
            $user = array_merge($user, $this->database->getMediaAppUser($userId) ?: []);
        }
        $user['remote_id'] = $remoteId;
        $key    = $this->siloUserKey($url, $apikey, $user);
        if ($key == '') {
            $status['error'] = true;
            return $status;
        }

        $profileId = $this->siloPrimaryProfileId($url, $key);
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

        return $status;
    }

    public function siloGetItems($url, $apikey, $libraryKeys = [])
    {
        $url   = rtrim(trim($url), '/');
        $items = ['movies' => [], 'series' => [], 'episodes' => []];
        foreach ($libraryKeys ?: [''] as $libraryKey) {
            $query = ['limit' => 200] + ($libraryKey !== '' ? ['library_id' => $libraryKey] : []);
            $rows  = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $apikey, '', $query);
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
                    'library'          => strval($libraryKey),
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
     * Uses only the key stored on the user, never the media app key, so a write
     * cannot land on the media app key's account by fallback.
     */
    public function siloSetWatchStatus($url, $user, $remoteId, $started, $inprogress, $finished)
    {
        $url = rtrim(trim($url), '/');
        $key = trim(strval($user['token'] ?? ''));
        if ($key == '') {
            return ['error' => true, 'code' => 0];
        }

        $profileId = $this->siloPrimaryProfileId($url, $key);
        if ($profileId == '') {
            return ['error' => true, 'code' => 0];
        }

        $headers = $this->siloHeaders($key, $profileId);
        $watched = sprintf(MediaAppEndpoints::ENDPOINT_SILO_WATCHED, $url, rawurlencode($remoteId));
        if ($finished) {
            $curl = $this->siloCurl($watched, $headers, 'POST');
        } else if ($started && $inprogress) {
            $curl = $this->siloCurl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_SYNC_PROGRESS, $url), $headers, 'POST', json_encode([
                'items' => [[
                    'media_item_id'   => strval($remoteId),
                    'position_ms'     => intval($inprogress) * 1000,
                    'duration_ms'     => 0,
                    'updated_at'      => gmdate('Y-m-d\TH:i:s\Z'),
                    'force_overwrite' => true,
                ]],
            ]));
        } else {
            $curl = $this->siloCurl($watched, $headers, 'DELETE');
        }

        $code = intval($curl['code'] ?? 0);

        return ['error' => $code < 200 || $code > 299, 'code' => $code];
    }
}
