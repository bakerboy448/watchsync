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

    public function siloGetAll($url, $path, $apikey, $profileId = '', $query = [])
    {
        $items  = [];
        $cursor = '';
        do {
            $params = $query + ['limit' => 200];
            if ($cursor != '') {
                $params['cursor'] = $cursor;
            }
            $curl     = curl(sprintf($path, $url) . '?' . http_build_query($params), $this->siloHeaders($apikey, $profileId), 'GET');
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

        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_IDENTITY, $url), ['Accept: application/json'], 'GET');
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
        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_USERS, $apikey);
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

        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_ME, $url), $this->siloHeaders($apikey), 'GET');
        $me   = is_array($curl['response'] ?? null) ? $curl['response'] : [];

        return strval($me['id'] ?? '') === strval($user['remote_id'] ?? '') ? $apikey : '';
    }

    public function siloPrimaryProfileId($url, $key)
    {
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_PROFILES, $url), $this->siloHeaders($key), 'GET');
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
            $curl  = curl(sprintf(MediaAppEndpoints::ENDPOINT_SILO_CATALOG_ITEMS, $url) . '?limit=200&' . $query, $this->siloHeaders($apikey), 'GET');
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

        $rows = $this->siloGetAll($url, MediaAppEndpoints::ENDPOINT_SILO_PROGRESS, $key, $profileId);
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
}
