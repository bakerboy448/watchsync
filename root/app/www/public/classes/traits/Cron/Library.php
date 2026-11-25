<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Library
{
    public function platformFlags($platform, $remoteId)
    {
        $flags = [
            'plex'               => 0,
            'emby'               => 0,
            'jellyfin'           => 0,
            'plex_remote_id'     => '',
            'emby_remote_id'     => '',
            'jellyfin_remote_id' => '',
        ];

        switch (intval($platform)) {
            case MediaPlatforms::EMBY:
                $flags['emby']           = 1;
                $flags['emby_remote_id'] = $remoteId;
                break;
            case MediaPlatforms::JELLYFIN:
                $flags['jellyfin']           = 1;
                $flags['jellyfin_remote_id'] = $remoteId;
                break;
            case MediaPlatforms::SILO:
                break;
            default:
                $flags['plex']           = 1;
                $flags['plex_remote_id'] = $remoteId;
                break;
        }

        return $flags;
    }

    public function targetApps()
    {
        $mediaAppId = intval($this->currentJob['media_app_id'] ?? 0);
        if ($mediaAppId) {
            $mediaApp = $this->database->getMediaApp($mediaAppId);
            if ($mediaApp) {
                return [$mediaApp];
            }

            return [];
        }

        $apps      = [];
        $master    = [];
        $listeners = [];

        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            } else {
                $listeners[] = $mediaApp;
            }
        }

        if ($master) {
            $apps[] = $master;
        }

        $libraryAppIds = $this->libraryAppIds();
        foreach ($listeners as $listener) {
            if ($libraryAppIds && !in_array(intval($listener['id']), $libraryAppIds)) {
                continue;
            }
            if (!$mediaAppId || $listener['id'] == $mediaAppId) {
                $apps[] = $listener;
            }
        }

        if ($libraryAppIds && $master && !in_array(intval($master['id']), $libraryAppIds)) {
            $filtered = [];
            foreach ($apps as $mediaApp) {
                if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                    continue;
                }
                $filtered[] = $mediaApp;
            }
            $apps = $filtered;
        }

        return $apps;
    }

    public function libraryAppIds()
    {
        $ids = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (!empty($library['media_app_id'])) {
                $ids[] = intval($library['media_app_id']);
            }
        }

        return array_values(array_unique($ids));
    }

    public function libraryKeys($mediaAppId)
    {
        $keys = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (intval($library['media_app_id'] ?? 0) == intval($mediaAppId) && ($library['key'] ?? '') != '') {
                $keys[] = $library['key'];
            }
        }

        return $keys;
    }

    public function fetchItems($mediaApp)
    {
        global $mediaApps;

        return $mediaApps->getItems($mediaApp, $this->libraryKeys($mediaApp['id']));
    }

    public function selectedLibraries($mediaApp)
    {
        global $mediaApps;

        $keys = $this->libraryKeys($mediaApp['id']);
        if (!$keys && !empty($this->currentJob['libraries'])) {
            return [];
        }
        if (!$keys) {
            foreach ($mediaApps->selectedScanLibraries() as $library) {
                if (intval($library['media_app_id'] ?? 0) == intval($mediaApp['id']) && ($library['key'] ?? '') != '') {
                    $keys[] = $library['key'];
                }
            }
        }
        if (!$keys) {
            return [];
        }

        $libraries = [];
        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            if (!$library['key'] || !in_array($library['key'], $keys)) {
                continue;
            }
            $libraries[] = $library;
        }

        return $libraries;
    }

    public function pullMediaLibrary()
    {
        global $mediaApps;

        $apps = $this->targetApps();
        if (!$apps) {
            logger($this->logfile, 'no active media apps');
            return;
        }

        $scan = intval($this->currentJob['scan'] ?? MediaLibraryScans::LAST_SCAN);
        $this->libraryPathMoves      = [];
        $this->librarySeenRemoteIds  = [];
        $this->pendingLibraryUpdates = ['movies' => 0, 'series' => 0, 'episodes' => 0];

        $knownBefore  = [];
        $storedBefore = [];
        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            if (!$this->mediaAppOnline($mediaApp)) {
                continue;
            }
            $storedBefore[intval($mediaApp['id'])] = $this->database->getMediaAppLibraries($mediaApp['id']);
            foreach ($mediaApps->getLibraries($mediaApp, [], false) as $library) {
                $id = intval($mediaApp['id']) . ':' . strval($library['key'] ?? '');
                if ($id != '0:') {
                    $knownBefore[$id] = true;
                }
            }
            $mediaApps->refreshStoredLibraries($mediaApp);
        }
        $this->libraryPathMoves = $this->libraryFolderMoves($storedBefore, $apps);
        if ($this->libraryPathMoves) {
            $this->pushLibraryPathMoves();
            $this->applyLibraryPathMoves();
        }

        if ($this->isAutomaticJob() && $this->database->settingEnabled('syncLibraryAutoMeta')) {
            $state = $mediaApps->scanLibraryState();
            if ($state) {
                $changed      = false;
                $jobLibraries = is_array($this->currentJob['libraries'] ?? null) ? $this->currentJob['libraries'] : [];
                $have         = [];
                foreach ($jobLibraries as $library) {
                    $id = intval($library['media_app_id'] ?? 0) . ':' . strval($library['key'] ?? '');
                    if ($id != '0:') {
                        $have[$id] = true;
                    }
                }
                foreach ($apps as $mediaApp) {
                    if (!$mediaApp['active']) {
                        continue;
                    }
                    foreach ($mediaApps->getLibraries($mediaApp, [], false) as $library) {
                        $key = strval($library['key'] ?? '');
                        if ($key == '') {
                            continue;
                        }
                        $id = intval($mediaApp['id']) . ':' . $key;
                        if (!empty($knownBefore[$id]) || array_key_exists($id, $state)) {
                            continue;
                        }
                        if (!$mediaApps->libraryAllowedByParity(intval($mediaApp['id']), $key)) {
                            continue;
                        }
                        $state[$id] = 1;
                        $changed    = true;
                        if (empty($have[$id])) {
                            $jobLibraries[] = ['media_app_id' => intval($mediaApp['id']), 'key' => $key];
                            $have[$id]      = true;
                        }
                    }
                }
                if ($changed) {
                    $mediaApps->setScanLibraries($state);
                }
                $this->currentJob['libraries'] = $jobLibraries;
            }
        }

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            if (!$this->mediaAppOnline($mediaApp)) {
                continue;
            }
            $this->librarySeenRemoteIds = [];
            $libraries = $this->selectedLibraries($mediaApp);
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' libraries ' . count($libraries));
            $platformName = $mediaApps->getPlatformName($mediaApp['platform']);
            $added        = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $updated      = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $unchanged    = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $totals       = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $this->warmLibraryImportIndex($mediaApp['platform']);

            $since     = $scan == MediaLibraryScans::FULL ? 0 : intval($mediaApp['last_scan'] ?? 0);
            $scannedAt = time();
            $seenIds   = [];
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' ' . ($since ? 'since last scan' : 'full library scan'));
            foreach ($libraries as $library) {
                $this->stopIfCancelled();
                $label = 'media library ' . $mediaApp['name'] . ' ' . ($library['title'] ?? $library['key']);
                if ($since) {
                    logger($this->logfile, $label . ' changes since ' . date('Y-m-d H:i:s', $since));
                } else {
                    logger($this->logfile, $label);
                }
                $items               = $mediaApps->getItems($mediaApp, [$library], '', $since);
                $totals['movies']   += count($items['movies'] ?? []);
                $totals['series']   += count($items['series'] ?? []);
                $totals['episodes'] += count($items['episodes'] ?? []);
                if (!$since) {
                    foreach (['movies', 'series', 'episodes'] as $type) {
                        foreach ($items[$type] ?? [] as $item) {
                            $remoteId = trim(strval($item['remote_id'] ?? ''));
                            if ($remoteId != '') {
                                $seenIds[$remoteId] = true;
                                $this->librarySeenRemoteIds[$remoteId] = true;
                            }
                        }
                    }
                }
                $addedBefore = $added['movies'] + $added['series'] + $added['episodes'];
                $this->importLibraryItems($mediaApp, $platformName, [
                    'movies'   => $items['movies'] ?? [],
                    'series'   => $items['series'] ?? [],
                    'episodes' => $items['episodes'] ?? [],
                ], $added, $updated, $unchanged);
                if (($added['movies'] + $added['series'] + $added['episodes']) > $addedBefore && ($library['key'] ?? '') != '') {
                    $this->currentJob['history_libraries'][intval($mediaApp['id']) . ':' . $library['key']] = [
                        'media_app_id' => intval($mediaApp['id']),
                        'key'          => strval($library['key']),
                    ];
                }
            }

            if (!$since && $seenIds && $this->scannedAllAppLibraries($mediaApp, $libraries)) {
                $cleared = $this->database->clearUnseenMediaLibraryRemotes($mediaApp['platform'], array_keys($seenIds));
                if ($cleared) {
                    logger($this->logfile, 'media library ' . $mediaApp['name'] . ' cleared stale remote ids ' . $cleared);
                }
            }

            foreach (['movies', 'series', 'episodes'] as $type) {
                $updated[$type] += intval($this->pendingLibraryUpdates[$type] ?? 0);
            }
            $this->pendingLibraryUpdates = ['movies' => 0, 'series' => 0, 'episodes' => 0];

            $this->database->setMediaAppLastScan($mediaApp['id'], $scannedAt);
            $this->currentJob['stats']['added']     = intval($this->currentJob['stats']['added'] ?? 0) + $added['movies'] + $added['series'] + $added['episodes'];
            $this->currentJob['stats']['updated']   = intval($this->currentJob['stats']['updated'] ?? 0) + $updated['movies'] + $updated['series'] + $updated['episodes'];
            $this->currentJob['stats']['unchanged'] = intval($this->currentJob['stats']['unchanged'] ?? 0) + $unchanged['movies'] + $unchanged['series'] + $unchanged['episodes'];
            foreach (['movies', 'series', 'episodes'] as $type) {
                $this->addLibraryResult($mediaApp, $type, 'added', $added[$type]);
                $this->addLibraryResult($mediaApp, $type, 'updated', $updated[$type]);
                $this->addLibraryResult($mediaApp, $type, 'unchanged', $unchanged[$type]);
                $this->currentJob['stats']['library'][$type] = intval($this->currentJob['stats']['library'][$type] ?? 0) + $totals[$type];
            }
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' movies: added ' . $added['movies'] . ', updated: ' . $updated['movies'] . ', unchanged: ' . $unchanged['movies'] . ', total: ' . $totals['movies'] . ' | series: added ' . $added['series'] . ', updated: ' . $updated['series'] . ', unchanged: ' . $unchanged['series'] . ', total: ' . $totals['series'] . ' | episodes: added ' . $added['episodes'] . ', updated: ' . $updated['episodes'] . ', unchanged: ' . $unchanged['episodes'] . ', total: ' . $totals['episodes']);
            $this->libraryImportIndex = null;
        }

        $rehomed = $this->database->rehomeMisplacedEpisodes();
        if (intval($rehomed['moved'] ?? 0) > 0) {
            logger($this->logfile, 'media library rehomeMisplacedEpisodes checked=' . intval($rehomed['checked'] ?? 0) . ' moved=' . intval($rehomed['moved'] ?? 0));
        }
        $purged = $mediaApps->purgeMedia();
        if (($purged['movies'] + $purged['series'] + $purged['episodes']) > 0) {
            logger($this->logfile, 'media library purgeMedia movies=' . $purged['movies'] . ' series=' . $purged['series'] . ' episodes=' . $purged['episodes']);
        }
    }

    public function scannedAllAppLibraries($mediaApp, $libraries)
    {
        global $mediaApps;

        $selected = [];
        foreach ($libraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $selected[$key] = true;
            }
        }
        if (!$selected) {
            return false;
        }

        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '' && empty($selected[$key])) {
                return false;
            }
        }

        return true;
    }

    public function libraryFolderMoves($storedBefore, $apps)
    {
        global $mediaApps;

        $moves = [];
        foreach ($apps as $mediaApp) {
            $appId = intval($mediaApp['id'] ?? 0);
            if (!$appId || empty($storedBefore[$appId])) {
                continue;
            }
            $beforeByKey = [];
            foreach ($storedBefore[$appId] as $library) {
                $key = strval($library['key'] ?? '');
                if ($key != '') {
                    $beforeByKey[$key] = $library;
                }
            }
            foreach ($mediaApps->getLibraries($mediaApp, [], false) as $library) {
                $key = strval($library['key'] ?? '');
                if ($key == '' || empty($beforeByKey[$key])) {
                    continue;
                }
                $pair = $this->libraryFolderPair($beforeByKey[$key]['paths'] ?? [], $library['paths'] ?? []);
                if (!$pair) {
                    continue;
                }
                $old = $this->database->normalizeLibraryPath($pair['old']);
                $new = $this->database->normalizeLibraryPath($pair['new']);
                if ($old == '' || $new == '' || strcasecmp($old, $new) == 0) {
                    continue;
                }
                $moves[] = [
                    'app_id'  => $appId,
                    'old_raw' => $pair['old'],
                    'new_raw' => $pair['new'],
                    'old'     => $old,
                    'new'     => $new,
                    'library' => $library,
                ];
                logger($this->logfile, 'library folder moved ' . strval($mediaApp['name'] ?? '') . ' ' . strval($library['title'] ?? $key) . ' ' . $pair['old'] . ' -> ' . $pair['new']);
            }
        }

        return $moves;
    }

    public function libraryFolderPair($beforePaths, $afterPaths)
    {
        global $mediaApps;

        $before = [];
        foreach ($beforePaths as $path) {
            $key = $mediaApps->libraryPathKey($path);
            if ($key != '') {
                $before[$key] = $path;
            }
        }
        $after = [];
        foreach ($afterPaths as $path) {
            $key = $mediaApps->libraryPathKey($path);
            if ($key != '') {
                $after[$key] = $path;
            }
        }
        $removed = array_diff_key($before, $after);
        $added   = array_diff_key($after, $before);
        if (count($removed) != 1 || count($added) != 1) {
            return [];
        }

        return [
            'old' => array_values($removed)[0],
            'new' => array_values($added)[0],
        ];
    }

    public function pushLibraryPathMoves()
    {
        global $mediaApps;

        foreach ($this->libraryPathMoves as $move) {
            $source = $this->database->getMediaApp(intval($move['app_id'] ?? 0));
            if (!$source) {
                continue;
            }
            foreach ($this->database->getMediaApps() as $other) {
                $this->stopIfCancelled();
                if (!$other['active'] || intval($other['id'] ?? 0) == intval($source['id'] ?? 0)) {
                    continue;
                }
                if (!$this->mediaAppOnline($other)) {
                    logger($this->logfile, 'library path listener offline ' . strval($other['name'] ?? ''));
                    continue;
                }
                $linked = $mediaApps->findLinkedLibrary($source, $move['library'] ?? [], $other);
                if (!$linked || !$mediaApps->libraryHasFolder($linked, $move['old_raw'] ?? '')) {
                    continue;
                }
                logger($this->logfile, 'library path listener ' . strval($other['name'] ?? '') . ' ' . strval($linked['title'] ?? '') . ' ' . strval($move['old_raw'] ?? '') . ' -> ' . strval($move['new_raw'] ?? ''));
                $result = $mediaApps->updateLibraryFolder($other, $linked, $move['old_raw'] ?? '', $move['new_raw'] ?? '');
                if (!empty($result['error'])) {
                    logger($this->logfile, 'library path listener failed ' . strval($other['name'] ?? '') . ' ' . strval($result['message'] ?? ''));
                }
            }
        }
    }

    public function applyLibraryPathMoves()
    {
        if (!$this->libraryPathMoves) {
            return;
        }

        $moves    = $this->libraryPathMovesLongestFirst();
        $movies   = $this->relocateStoredLibraryPaths(MOVIE_TABLE, $this->database->getMovies(), $moves);
        $series   = $this->relocateStoredLibraryPaths(SERIES_TABLE, $this->database->getSeriesRows(), $moves);
        $episodes = $this->relocateStoredLibraryPaths(EPISODE_TABLE, $this->database->getEpisodes(), $moves);
        logger($this->logfile, 'library path moved movies=' . $movies . ' series=' . $series . ' episodes=' . $episodes);
    }

    public function libraryPathMovesLongestFirst()
    {
        $moves = $this->libraryPathMoves;
        usort($moves, function ($left, $right) {
            return strlen(strval($right['old'] ?? '')) - strlen(strval($left['old'] ?? ''));
        });

        return $moves;
    }

    public function relocateStoredLibraryPaths($table, $rows, $moves)
    {
        $moved = 0;
        foreach ($rows as $row) {
            $this->stopIfCancelled();
            $path = $this->database->normalizeLibraryPath($row['path'] ?? '');
            $move = $this->storedLibraryPathMove($path, $moves);
            if (!$move) {
                continue;
            }
            $next = $this->libraryPathAfterMove($path, $move);
            if ($next == '') {
                continue;
            }
            $current = $this->database->getMediaLibraryItemByPath($table, $path);
            if (!$current || intval($current['id'] ?? 0) != intval($row['id'] ?? 0)) {
                continue;
            }
            $result = $this->moveLibraryItemOntoPath($table, $current, $next, 0, '', [], true);
            if ($result == 'updated') {
                $moved++;
            }
        }

        return $moved;
    }

    public function storedLibraryPathMove($path, $moves)
    {
        $path = $this->database->normalizeLibraryPath($path);
        if ($path == '' || !$moves) {
            return [];
        }

        $lower = strtolower($path);
        foreach ($moves as $move) {
            $old = strval($move['old'] ?? '');
            $new = strval($move['new'] ?? '');
            if ($old == '' || $new == '') {
                continue;
            }
            $oldLower = strtolower($old);
            if ($lower == $oldLower || str_starts_with($lower, $oldLower . '/')) {
                return $move;
            }
        }

        return [];
    }

    public function libraryPathAfterMove($path, $move)
    {
        $path = $this->database->normalizeLibraryPath($path);
        $old  = strval($move['old'] ?? '');
        $new  = strval($move['new'] ?? '');
        if ($path == '' || $old == '' || $new == '') {
            return '';
        }

        $next = $this->database->normalizeLibraryPath($new . substr($path, strlen($old)));
        if ($next == '' || strcasecmp($next, $path) == 0) {
            return '';
        }

        return $next;
    }

    public function libraryPathBeforeMove($path, $appId = 0)
    {
        if (!$this->libraryPathMoves) {
            return '';
        }
        $path = $this->database->normalizeLibraryPath($path);
        if ($path == '') {
            return '';
        }

        $appId   = intval($appId);
        $lower   = strtolower($path);
        $best    = '';
        $bestLen = -1;
        foreach ($this->libraryPathMoves as $move) {
            if ($appId > 0 && intval($move['app_id'] ?? 0) != $appId) {
                continue;
            }
            $new = strval($move['new'] ?? '');
            $old = strval($move['old'] ?? '');
            if ($new == '' || $old == '') {
                continue;
            }
            $newLower = strtolower($new);
            $len      = strlen($newLower);
            $matched  = $lower == $newLower || str_starts_with($lower, $newLower . '/');
            if (!$matched || $len <= $bestLen) {
                continue;
            }
            $best    = $this->database->normalizeLibraryPath($old . substr($path, $len));
            $bestLen = $len;
        }
        if ($best == '' || strcasecmp($best, $path) == 0) {
            return '';
        }

        return $best;
    }

    public function adoptMovedLibraryItem($table, $path, $platform, $remoteId, $meta, $appId = 0)
    {
        if (!$this->libraryPathMoves) {
            return '';
        }
        $oldPath = $this->libraryPathBeforeMove($path, $appId);
        if ($oldPath == '') {
            return '';
        }
        $keeper = $this->findLibraryImportPathHit($table, $platform, $oldPath);
        if (!$keeper) {
            $keeper = $this->database->getMediaLibraryItemByPath($table, $oldPath);
        }
        if (!$keeper) {
            return '';
        }

        $result = $this->moveLibraryItemOntoPath($table, $keeper, $path, $platform, $remoteId, $meta);
        if ($result == 'updated') {
            return 'updating';
        }
        if ($result == 'conflict') {
            return 'skipping';
        }

        return '';
    }

    public function moveMatchedLibraryItem($table, $row, $path, $platform, $remoteId, $meta, $appId)
    {
        if (!$row || intval($row['id'] ?? 0) == 0) {
            return '';
        }

        $path   = $this->database->normalizeLibraryPath($path);
        $stored = $this->database->normalizeLibraryPath($row['path'] ?? '');
        if ($path == '' || $stored == '' || strcasecmp($path, $stored) == 0) {
            return '';
        }

        $previous = $this->libraryPathBeforeMove($path, $appId);
        if ($previous == '' || strcasecmp($previous, $stored) != 0) {
            return '';
        }

        $result = $this->moveLibraryItemOntoPath($table, $row, $path, $platform, $remoteId, $meta);
        if ($result == 'updated') {
            return 'updating';
        }
        if ($result == 'conflict') {
            return 'skipping';
        }

        return '';
    }

    public function countMovedLibraryItem($moved, $bucket, &$updated, &$unchanged)
    {
        if ($moved == 'updating') {
            $updated[$bucket]++;
            return 'updating';
        }
        if ($moved == 'skipping') {
            $this->database->setLastError('');
            $unchanged[$bucket]++;
            return 'skipping';
        }

        return '';
    }

    public function moveLibraryItemOntoPath($table, $keeper, $path, $platform, $remoteId, $meta, $unseenConflicts = false)
    {
        $keeperId = intval($keeper['id'] ?? 0);
        $path     = $this->database->normalizeLibraryPath($path);
        if (!$keeperId || $path == '') {
            return 'failed';
        }

        $oldPath = $this->database->normalizeLibraryPath($keeper['path'] ?? '');
        $other   = $this->database->getMediaLibraryItemByPath($table, $path);
        if ($other && intval($other['id'] ?? 0) == $keeperId) {
            $other = [];
        }
        if ($other && $this->libraryRemoteConflict($keeper, $other, $this->librarySeenRemoteIds, $unseenConflicts)) {
            logger($this->logfile, 'library path conflict table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id'] ?? 0));
            return 'conflict';
        }
        if ($table == SERIES_TABLE && $other && $this->seriesEpisodesConflict($keeperId, intval($other['id'] ?? 0), $unseenConflicts)) {
            logger($this->logfile, 'library path conflict table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id'] ?? 0));
            return 'conflict';
        }

        if ($other) {
            $merged = $this->libraryRowMerged($keeper, $other, $path, $platform, $remoteId, $meta);
            if ($table == MOVIE_TABLE) {
                $this->database->mergeUserMovieLinks(intval($other['id']), $keeperId);
                if (!$this->database->deleteMovieById(intval($other['id']))) {
                    logger($this->logfile, 'library path merge failed table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id']));
                    return 'failed';
                }
            } else if ($table == SERIES_TABLE) {
                if (!$this->mergeSeriesEpisodes($keeperId, intval($other['id']))) {
                    logger($this->logfile, 'library path merge failed table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id']));
                    return 'failed';
                }
                if (!$this->database->deleteSeriesById(intval($other['id']))) {
                    logger($this->logfile, 'library path merge failed table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id']));
                    return 'failed';
                }
            } else {
                $this->database->mergeUserEpisodeLinks(intval($other['id']), $keeperId);
                if (!$this->database->deleteEpisodeById(intval($other['id']))) {
                    logger($this->logfile, 'library path merge failed table=' . $table . ' keeper=' . $keeperId . ' other=' . intval($other['id']));
                    return 'failed';
                }
            }
            $this->dropLibraryImportItem($table, $other);
            $keeper = $merged;
        } else {
            $keeper = $this->libraryRowMerged($keeper, [], $path, $platform, $remoteId, $meta);
        }

        if (!$this->writeLibraryItemRow($table, $keeper)) {
            logger($this->logfile, 'library path merge failed table=' . $table . ' keeper=' . $keeperId);
            return 'failed';
        }
        $this->retargetLibraryImportItem($table, $keeper, $oldPath);

        return 'updated';
    }

    public function libraryRemoteConflict($keeper, $other, $seen, $unseenConflicts = false)
    {
        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
            $left  = trim(strval($keeper[$field] ?? ''));
            $right = trim(strval($other[$field] ?? ''));
            if ($left == '' || $right == '' || $left == $right) {
                continue;
            }
            $leftSeen  = !empty($seen[$left]);
            $rightSeen = !empty($seen[$right]);
            if ($leftSeen && $rightSeen) {
                return true;
            }
            if ($unseenConflicts && !$leftSeen && !$rightSeen) {
                return true;
            }
        }

        return false;
    }

    public function libraryRowMerged($keeper, $other, $path, $platform, $remoteId, $meta)
    {
        $row  = $keeper;
        $seen = $this->librarySeenRemoteIds;
        $row['path'] = $path;
        if (!is_array($meta)) {
            $meta = [];
        }
        if ($other) {
            foreach (['title', 'poster'] as $field) {
                if (trim(strval($row[$field] ?? '')) == '' && trim(strval($other[$field] ?? '')) != '') {
                    $row[$field] = $other[$field];
                }
            }
            if (intval($row['year'] ?? 0) <= 0 && intval($other['year'] ?? 0) > 0) {
                $row['year'] = intval($other['year']);
            }
        }
        if (trim(strval($row['title'] ?? '')) == '' && trim(strval($meta['title'] ?? '')) != '') {
            $row['title'] = $meta['title'];
        }
        if (intval($row['year'] ?? 0) <= 0 && intval($meta['year'] ?? 0) > 0) {
            $row['year'] = intval($meta['year']);
        }
        if (trim(strval($row['poster'] ?? '')) == '' && trim(strval($meta['poster'] ?? '')) != '') {
            $row['poster'] = $meta['poster'];
        }

        $incomingField = $this->database->mediaLibraryRemoteField($platform);
        foreach (['plex', 'emby', 'jellyfin'] as $name) {
            $field = $name . '_remote_id';
            $left  = trim(strval($row[$field] ?? ''));
            $right = $other ? trim(strval($other[$field] ?? '')) : '';
            if ($incomingField == $field && $left == '' && trim(strval($remoteId)) != '') {
                $left = trim(strval($remoteId));
            }
            if ($left == '' && $right != '') {
                $left = $right;
            } else if ($left != '' && $right != '' && $left != $right && !empty($seen[$right]) && empty($seen[$left])) {
                $left = $right;
            }
            $row[$field] = $left;
            if ($left != '' || !empty($row[$name]) || ($other && !empty($other[$name]))) {
                $row[$name] = $left != '' ? 1 : intval($row[$name] ?? 0);
            }
            if ($other && !empty($other[$name])) {
                $row[$name] = 1;
            }
        }

        return $row;
    }

    public function writeLibraryItemRow($table, $row)
    {
        $id = intval($row['id'] ?? 0);
        if (!$id) {
            return false;
        }

        $sets = [
            "`path` = '" . $this->database->prepare($this->database->normalizeLibraryPath($row['path'] ?? '')) . "'",
            "`plex` = " . intval($row['plex'] ?? 0),
            "`emby` = " . intval($row['emby'] ?? 0),
            "`jellyfin` = " . intval($row['jellyfin'] ?? 0),
            "`plex_remote_id` = " . $this->database->sqlStringOrNull($row['plex_remote_id'] ?? ''),
            "`emby_remote_id` = " . $this->database->sqlStringOrNull($row['emby_remote_id'] ?? ''),
            "`jellyfin_remote_id` = " . $this->database->sqlStringOrNull($row['jellyfin_remote_id'] ?? ''),
        ];
        if ($table != EPISODE_TABLE) {
            $sets[] = "`title` = '" . $this->database->prepare($row['title'] ?? '') . "'";
            $sets[] = "`year` = " . intval($row['year'] ?? 0);
            $sets[] = "`poster` = '" . $this->database->prepare($row['poster'] ?? '') . "'";
        } else {
            $sets[] = "`title` = '" . $this->database->prepare($row['title'] ?? '') . "'";
            $sets[] = "`poster` = '" . $this->database->prepare($row['poster'] ?? '') . "'";
        }
        $sql = "UPDATE " . $table . "
                SET " . implode(', ', $sets) . "
                WHERE id = " . $id;
        if (!$this->database->query($sql)) {
            return false;
        }

        return true;
    }

    public function seriesEpisodesConflict($keeperId, $otherId, $unseenConflicts = false)
    {
        $keeperEpisodes = $this->database->getEpisodesBySeriesId($keeperId);
        foreach ($this->database->getEpisodesBySeriesId($otherId) as $episode) {
            $collision = $this->episodeCollision($keeperEpisodes, $episode);
            if ($collision && $this->libraryRemoteConflict($collision, $episode, $this->librarySeenRemoteIds, $unseenConflicts)) {
                return true;
            }
        }

        return false;
    }

    public function episodeCollision($episodes, $episode)
    {
        $path = strtolower($this->database->normalizeLibraryPath($episode['path'] ?? ''));
        foreach ($episodes as $row) {
            $rowPath = strtolower($this->database->normalizeLibraryPath($row['path'] ?? ''));
            if ($path != '' && $rowPath == $path) {
                return $row;
            }
            foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
                $left  = trim(strval($row[$field] ?? ''));
                $right = trim(strval($episode[$field] ?? ''));
                if ($left != '' && $left == $right) {
                    return $row;
                }
            }
        }

        return [];
    }

    public function mergeSeriesEpisodes($keeperId, $otherId)
    {
        $keeperEpisodes = $this->database->getEpisodesBySeriesId($keeperId);
        foreach ($this->database->getEpisodesBySeriesId($otherId) as $episode) {
            $collision = $this->episodeCollision($keeperEpisodes, $episode);
            if ($collision) {
                $targetPath = trim(strval($episode['path'] ?? '')) != '' ? $episode['path'] : strval($collision['path'] ?? '');
                $merged     = $this->libraryRowMerged($collision, $episode, $targetPath, 0, '', []);
                $this->database->mergeUserEpisodeLinks(intval($episode['id']), intval($collision['id']));
                if (!$this->database->deleteEpisodeById(intval($episode['id']))) {
                    return false;
                }
                $this->dropLibraryImportItem(EPISODE_TABLE, $episode);
                if (!$this->writeLibraryItemRow(EPISODE_TABLE, $merged)) {
                    return false;
                }
                $this->retargetLibraryImportItem(EPISODE_TABLE, $merged, $collision['path'] ?? '');
                foreach ($keeperEpisodes as $index => $row) {
                    if (intval($row['id'] ?? 0) == intval($collision['id'] ?? 0)) {
                        $keeperEpisodes[$index] = $merged;
                        break;
                    }
                }
                continue;
            }
            if (!$this->database->setEpisodeSeriesId(intval($episode['id']), $keeperId)) {
                return false;
            }
            $episode['series_id'] = $keeperId;
            $this->rememberLibraryImportItem(EPISODE_TABLE, $episode);
        }

        return true;
    }

    public function dropLibraryImportItem($table, $row)
    {
        if (!$this->libraryImportIndex || !$row) {
            return;
        }
        $bucket = $this->libraryImportBucket($table);
        $id     = intval($row['id'] ?? 0);
        $path   = strtolower($this->database->normalizeLibraryPath($row['path'] ?? ''));
        if ($path != '' && intval($this->libraryImportIndex['path'][$bucket][$path]['id'] ?? 0) == $id) {
            unset($this->libraryImportIndex['path'][$bucket][$path]);
        }
        $field = $this->database->mediaLibraryRemoteField($this->libraryImportIndex['platform'] ?? 0);
        $remote = $field != '' ? trim(strval($row[$field] ?? '')) : '';
        if ($remote != '' && intval($this->libraryImportIndex['remote'][$bucket][$remote]['id'] ?? 0) == $id) {
            unset($this->libraryImportIndex['remote'][$bucket][$remote]);
        }
    }

    public function retargetLibraryImportItem($table, $row, $oldPath)
    {
        if (!$this->libraryImportIndex) {
            return;
        }
        $bucket = $this->libraryImportBucket($table);
        $id     = intval($row['id'] ?? 0);
        $old    = strtolower($this->database->normalizeLibraryPath($oldPath));
        if ($old != '' && intval($this->libraryImportIndex['path'][$bucket][$old]['id'] ?? 0) == $id) {
            unset($this->libraryImportIndex['path'][$bucket][$old]);
        }
        $this->rememberLibraryImportItem($table, $row);
    }

    public function libraryImportBucket($table)
    {
        if ($table == MOVIE_TABLE) {
            return 'movies';
        }
        if ($table == SERIES_TABLE) {
            return 'series';
        }

        return 'episodes';
    }

    public function warmLibraryImportIndex($platform)
    {
        $platform = intval($platform);
        if ($this->libraryImportIndex && intval($this->libraryImportIndex['platform'] ?? 0) == $platform) {
            return;
        }

        $field = $this->database->mediaLibraryRemoteField($platform);
        $index = [
            'platform' => $platform,
            'remote'   => ['movies' => [], 'series' => [], 'episodes' => []],
            'path'     => ['movies' => [], 'series' => [], 'episodes' => []],
        ];

        $tables = [
            'movies'   => $this->database->getMovies(),
            'series'   => $this->database->getSeriesRows(),
            'episodes' => $this->database->getEpisodes(),
        ];
        foreach ($tables as $bucket => $rows) {
            foreach ($rows as $row) {
                $this->indexLibraryImportRow($index, $bucket, $row, $field);
            }
        }

        $this->libraryImportIndex = $index;
        logger($this->logfile, 'media library import index movies=' . count($index['path']['movies']) . ' series=' . count($index['path']['series']) . ' episodes=' . count($index['path']['episodes']));
    }

    public function indexLibraryImportRow(&$index, $bucket, $row, $field = '')
    {
        if (!$row || !is_array($row)) {
            return;
        }
        if ($field == '' && !empty($index['platform'])) {
            $field = $this->database->mediaLibraryRemoteField($index['platform']);
        }
        if ($field) {
            $remoteId = trim(strval($row[$field] ?? ''));
            if ($remoteId != '') {
                $index['remote'][$bucket][$remoteId] = $row;
            }
        }
        $path = $this->database->normalizeLibraryPath($row['path'] ?? '');
        if ($path != '') {
            $index['path'][$bucket][strtolower($path)] = $row;
        }
    }

    public function rememberLibraryImportItem($table, $row)
    {
        if (!$this->libraryImportIndex || !$row) {
            return;
        }
        $this->indexLibraryImportRow($this->libraryImportIndex, $this->libraryImportBucket($table), $row);
    }

    public function libraryImportRemoteField($platform)
    {
        return $this->database->mediaLibraryRemoteField($platform);
    }

    public function libraryImportRowMatchesRemote($row, $platform, $remoteId)
    {
        $remoteId = trim(strval($remoteId));
        if ($remoteId == '' || !$row) {
            return true;
        }

        $field = $this->libraryImportRemoteField($platform);
        if ($field == '') {
            return true;
        }

        $rowRemote = trim(strval($row[$field] ?? ''));
        if ($rowRemote == '' || $rowRemote == $remoteId) {
            return true;
        }

        return $this->libraryImportRemoteIdRefreshes($platform);
    }

    /*
     * Emby, Jellyfin and Silo give a re-created item a new id at the same
     * path. For them a path match wins over a stored id, so the stale id is
     * replaced instead of kept for history pushes that 404.
     */
    public function libraryImportRemoteIdRefreshes($platform)
    {
        return in_array(intval($platform), [MediaPlatforms::EMBY, MediaPlatforms::JELLYFIN, MediaPlatforms::SILO]);
    }

    public function libraryImportStaleRemote($row, $platform, $remoteId, $path)
    {
        $field     = $this->database->mediaLibraryRemoteField($platform);
        $rowRemote = trim(strval($row[$field] ?? ''));
        $remoteId  = trim(strval($remoteId));
        if (!$field || $remoteId == '' || $rowRemote == '' || $rowRemote == $remoteId) {
            return false;
        }
        if (!$this->libraryImportRemoteIdRefreshes($platform)) {
            return false;
        }
        $path    = $this->database->normalizeLibraryPath($path);
        $rowPath = $this->database->normalizeLibraryPath($row['path'] ?? '');

        return $path != '' && strcasecmp($path, $rowPath) == 0;
    }

    public function findLibraryImportPathHit($table, $platform, $path)
    {
        $this->warmLibraryImportIndex($platform);
        $bucket = $this->libraryImportBucket($table);
        $path   = $this->database->normalizeLibraryPath($path);
        if ($path == '') {
            return [];
        }

        return $this->libraryImportIndex['path'][$bucket][strtolower($path)] ?? [];
    }

    public function findLibraryImportItem($table, $platform, $remoteId, $path)
    {
        $this->warmLibraryImportIndex($platform);
        $bucket   = $this->libraryImportBucket($table);
        $remoteId = trim(strval($remoteId));
        if ($this->libraryImportSharedRemoteIds($platform) && $this->database->normalizeLibraryPath($path) != '') {
            return $this->findLibraryImportPathHit($table, $platform, $path);
        }
        if ($remoteId != '') {
            $row = $this->libraryImportIndex['remote'][$bucket][$remoteId] ?? [];
            if ($row) {
                return $row;
            }
        }

        $row = $this->findLibraryImportPathHit($table, $platform, $path);
        if ($row && $this->libraryImportRowMatchesRemote($row, $platform, $remoteId)) {
            return $row;
        }

        return [];
    }

    /*
     * Silo gives every file of a title the same remote id, so its rows are
     * matched by path and one remote id may sit on several rows.
     */
    public function libraryImportSharedRemoteIds($platform)
    {
        return intval($platform) == MediaPlatforms::SILO;
    }

    /*
     * The add functions only take the Plex, Emby and Jellyfin columns, so the
     * flag and remote id of any other platform are set after the insert.
     */
    public function flagInsertedLibraryItem($table, $platform, $remoteId, $row)
    {
        if (!in_array(intval($platform), [MediaPlatforms::PLEX, MediaPlatforms::EMBY, MediaPlatforms::JELLYFIN])) {
            $this->applyLibraryImportRowUpdate($table, $platform, $remoteId, $row['path'] ?? '', $row);
        }
    }

    public function findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode)
    {
        $pathHit = $this->findLibraryImportPathHit(EPISODE_TABLE, $platform, $path);
        if (!$pathHit) {
            return [];
        }
        if (intval($pathHit['season'] ?? -1) != intval($season) || intval($pathHit['episode'] ?? -1) != intval($episode)) {
            return [];
        }

        return $pathHit;
    }

    public function importLibraryItems($mediaApp, $platformName, $items, &$added, &$updated, &$unchanged)
    {
        $movieTotal   = count($items['movies'] ?? []);
        $seriesTotal  = count($items['series'] ?? []);
        $episodeTotal = count($items['episodes'] ?? []);
        $movieIndex   = 0;
        $seriesIndex  = 0;
        $episodeIndex = 0;

        foreach ($items['series'] ?? [] as $item) {
            $this->stopIfCancelled();
            $seriesIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->upsertLibraryItem($mediaApp, SERIES_TABLE, 'series', $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'series upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('series', $seriesIndex, $seriesTotal, $platformName, $item, $success, $action, $error);
        }

        foreach ($items['movies'] ?? [] as $item) {
            $this->stopIfCancelled();
            $movieIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->upsertLibraryItem($mediaApp, MOVIE_TABLE, 'movie', $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'movie upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('movie', $movieIndex, $movieTotal, $platformName, $item, $success, $action, $error);
        }

        foreach ($items['episodes'] ?? [] as $item) {
            $this->stopIfCancelled();
            $episodeIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->saveLibraryEpisode($mediaApp, $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'episode upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('episode', $episodeIndex, $episodeTotal, $platformName, $item, $success, $action, $error);
        }
    }

    public function applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, $meta = [])
    {
        if (!$row || !intval($row['id'] ?? 0)) {
            return [];
        }

        $flag  = $this->database->mediaLibraryFlag($platform);
        $field = $this->database->mediaLibraryRemoteField($platform);
        $id    = intval($row['id']);
        $path  = $this->database->normalizeLibraryPath($path);
        $sets  = [];

        if ($flag) {
            if (intval($row[$flag] ?? 0) == 0) {
                $sets[]     = "`" . $flag . "` = 1";
                $row[$flag] = 1;
            }
        }
        if ($field && $remoteId != '' && $remoteId != null) {
            $rowRemote = trim(strval($row[$field] ?? ''));
            if ($rowRemote == '' || $this->libraryImportStaleRemote($row, $platform, $remoteId, $path)) {
                if ($rowRemote != '') {
                    logger($this->logfile, 'library remote refresh id=' . $id . ' ' . $field . ' from=' . $rowRemote . ' to=' . $remoteId);
                }
                $sets[]      = "`" . $field . "` = " . $this->database->sqlStringOrNull($remoteId);
                $row[$field] = $remoteId;
            }
        }

        $currentPath = $this->database->normalizeLibraryPath($row['path'] ?? '');
        if ($path != '' && $currentPath == '') {
            $sets[]      = "`path` = '" . $this->database->prepare($path) . "'";
            $row['path'] = $path;
        }

        if ($table != EPISODE_TABLE && is_array($meta)) {
            $title = trim(strval($meta['title'] ?? ''));
            if ($title != '' && trim(strval($row['title'] ?? '')) == '') {
                $sets[]       = "`title` = '" . $this->database->prepare($title) . "'";
                $row['title'] = $title;
            }
            $year = intval($meta['year'] ?? 0);
            if ($year > 0 && intval($row['year'] ?? 0) <= 0) {
                $sets[]      = "`year` = " . $year;
                $row['year'] = $year;
            }
            $poster = trim(strval($meta['poster'] ?? ''));
            if ($poster != '' && trim(strval($row['poster'] ?? '')) == '') {
                $sets[]        = "`poster` = '" . $this->database->prepare($poster) . "'";
                $row['poster'] = $poster;
            }
        }

        if ($sets) {
            $sql = "UPDATE " . $table . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . $id;
            $this->database->query($sql);
        }

        $this->rememberLibraryImportItem($table, $row);

        return $row;
    }

    public function resolveEpisodeSeriesId($mediaApp, $item, $episodePath)
    {
        $seriesPath = $this->database->normalizePath($item['path'] ?? $episodePath, 'series');
        $seriesYear = intval($item['series_year'] ?? ($item['year'] ?? 0));
        $platform   = $mediaApp['platform'];
        $remoteId   = $item['series_remote_id'] ?? '';

        $seriesMeta = [
            'title'  => $item['series'] ?? '',
            'year'   => $seriesYear,
            'poster' => $item['series_poster'] ?? '',
        ];
        $seriesRow = $this->findLibraryImportItem(SERIES_TABLE, $platform, $remoteId, $seriesPath);
        if ($seriesRow) {
            $moved = $this->moveMatchedLibraryItem(SERIES_TABLE, $seriesRow, $seriesPath, $platform, $remoteId, $seriesMeta, intval($mediaApp['id'] ?? 0));
            if ($moved == 'updating') {
                $this->pendingLibraryUpdates['series'] = intval($this->pendingLibraryUpdates['series'] ?? 0) + 1;
                return intval($seriesRow['id']);
            }
            if ($moved == 'skipping') {
                $this->database->setLastError('library path conflict');
                return 0;
            }
            $outcome = $this->libraryItemOutcomeFromRow($seriesRow, $platform, $remoteId, $seriesPath);
            if ($outcome == 'updated') {
                $this->applyLibraryImportRowUpdate(SERIES_TABLE, $platform, $remoteId, $seriesPath, $seriesRow, $seriesMeta);
            }
            return intval($seriesRow['id']);
        }

        if ($seriesPath == '') {
            $this->database->setLastError('empty series path for episode');
            return 0;
        }

        $adopted = $this->adoptMovedLibraryItem(SERIES_TABLE, $seriesPath, $platform, $remoteId, $seriesMeta, intval($mediaApp['id'] ?? 0));
        if ($adopted == 'updating') {
            $this->pendingLibraryUpdates['series'] = intval($this->pendingLibraryUpdates['series'] ?? 0) + 1;
            $seriesRow = $this->findLibraryImportPathHit(SERIES_TABLE, $platform, $seriesPath);
            if ($seriesRow) {
                return intval($seriesRow['id']);
            }
        }
        if ($adopted == 'skipping') {
            $this->database->setLastError('library path conflict');
            return 0;
        }

        $flags    = $this->platformFlags($platform, $remoteId);
        $seriesId = $this->database->addSeries($item['series'] ?? '', $seriesYear, $seriesPath, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $item['series_poster'] ?? '');
        if ($seriesId) {
            $this->rememberLibraryImportItem(SERIES_TABLE, [
                'id'                 => intval($seriesId),
                'title'              => strval($item['series'] ?? ''),
                'year'               => $seriesYear,
                'path'               => $seriesPath,
                'plex'               => intval($flags['plex']),
                'emby'               => intval($flags['emby']),
                'jellyfin'           => intval($flags['jellyfin']),
                'plex_remote_id'     => $flags['plex_remote_id'],
                'emby_remote_id'     => $flags['emby_remote_id'],
                'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
                'poster'             => strval($item['series_poster'] ?? ''),
            ]);
            $this->flagInsertedLibraryItem(SERIES_TABLE, $platform, $remoteId, $this->findLibraryImportPathHit(SERIES_TABLE, $platform, $seriesPath));
            return intval($seriesId);
        }

        $seriesRow = $this->findLibraryImportItem(SERIES_TABLE, $platform, $remoteId, $seriesPath);
        if ($seriesRow) {
            $moved = $this->moveMatchedLibraryItem(SERIES_TABLE, $seriesRow, $seriesPath, $platform, $remoteId, $seriesMeta, intval($mediaApp['id'] ?? 0));
            if ($moved == 'updating') {
                $this->pendingLibraryUpdates['series'] = intval($this->pendingLibraryUpdates['series'] ?? 0) + 1;
                $this->database->setLastError('');
                return intval($seriesRow['id']);
            }
            if ($moved == 'skipping') {
                $this->database->setLastError('library path conflict');
                return 0;
            }
            $this->applyLibraryImportRowUpdate(SERIES_TABLE, $platform, $remoteId, $seriesPath, $seriesRow, $seriesMeta);
            $this->database->setLastError('');
            return intval($seriesRow['id']);
        }

        if ($this->database->getLastError() == '') {
            $this->database->setLastError('could not resolve series for episode');
        }

        return 0;
    }

    public function rehomeEpisodeAfterMatch($mediaApp, $item, $episodePath)
    {
        $episodePath = $this->database->normalizeLibraryPath($episodePath);
        if ($episodePath == '') {
            return false;
        }

        $row = [];
        if (trim(strval($item['remote_id'] ?? '')) != '' && !$this->libraryImportSharedRemoteIds($mediaApp['platform'])) {
            $row = $this->database->getEpisodeByRemoteId($mediaApp['platform'], $item['remote_id']);
        }
        if (!$row) {
            $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . EPISODE_TABLE . "
                    WHERE `path` = '" . $this->database->prepare($episodePath) . "'
                    LIMIT 1";
            $row = $this->database->fetchAssoc($this->database->query($sql)) ?: [];
        }
        if (!$row) {
            return false;
        }

        $targetId = $this->database->findSeriesIdForEpisodePath($episodePath);
        if (!$targetId) {
            $targetId = $this->resolveEpisodeSeriesId($mediaApp, $item, $episodePath);
        }
        if (!$targetId || $targetId == intval($row['series_id'] ?? 0)) {
            return false;
        }

        $sql = "UPDATE " . EPISODE_TABLE . "
                SET series_id = " . intval($targetId) . "
                WHERE id = " . intval($row['id']);
        $this->database->query($sql);

        return $this->database->matchedRows() > 0;
    }

    public function libraryItemOutcome($table, $platform, $remoteId, $path, $title = '', $year = 0)
    {
        $row = $this->database->findMediaLibraryItem($table, $platform, $remoteId, $path);

        return $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
    }

    public function libraryItemOutcomeFromRow($row, $platform, $remoteId, $path)
    {
        if (!$row) {
            return 'added';
        }

        $flag  = $this->database->mediaLibraryFlag($platform);
        $field = $this->database->mediaLibraryRemoteField($platform);
        if (intval($row[$flag] ?? 0) == 0) {
            return 'updated';
        }

        $rowRemote = trim(strval($row[$field] ?? ''));
        $remoteId  = trim(strval($remoteId));
        if ($remoteId != '' && $rowRemote == '') {
            return 'updated';
        }
        if ($remoteId != '' && $rowRemote != '' && $rowRemote != $remoteId) {
            return $this->libraryImportStaleRemote($row, $platform, $remoteId, $path) ? 'updated' : 'unchanged';
        }

        $rowPath = $this->database->normalizeLibraryPath($row['path'] ?? '');
        $path    = $this->database->normalizeLibraryPath($path);
        if ($path != '' && $rowPath == '') {
            return 'updated';
        }

        return 'unchanged';
    }

    public function countLibraryOutcome($outcome, $bucket, &$added, &$updated, &$unchanged)
    {
        if ($outcome == 'unchanged') {
            $unchanged[$bucket]++;
            return 'skipping';
        }
        if ($outcome == 'updated') {
            $updated[$bucket]++;
            return 'updating';
        }

        return '';
    }

    public function resolvePlexCheckedPath($mediaApp, $remoteId, $currentPath, $pathType)
    {
        global $mediaApps;

        if (intval($mediaApp['platform'] ?? 0) != MediaPlatforms::PLEX) {
            return $currentPath;
        }
        $remoteId = trim(strval($remoteId));
        if ($remoteId == '') {
            return $currentPath;
        }

        $resolved = $mediaApps->plexResolvePathWithCheckFiles(
            strval($mediaApp['url'] ?? ''),
            strval($mediaApp['token'] ?? ''),
            $remoteId,
        );
        if ($resolved == '') {
            return $currentPath;
        }

        $normalized = $this->database->normalizePath($resolved, $pathType);
        if ($normalized == '' || strcasecmp($normalized, strval($currentPath)) == 0) {
            return $currentPath;
        }

        logger($this->logfile, 'plex checkFiles path remote=' . $remoteId . ' from=' . $currentPath . ' to=' . $normalized);

        return $normalized;
    }

    public function upsertLibraryItem($mediaApp, $table, $type, $item, &$added, &$updated, &$unchanged)
    {
        $platform = $mediaApp['platform'];
        $remoteId = $item['remote_id'] ?? '';
        $path     = $this->database->normalizePath($item['path'] ?? '', $type);
        $title    = $item['title'] ?? '';
        $year     = intval($item['year'] ?? 0);
        $poster   = trim(strval($item['poster'] ?? ''));
        $bucket   = $type == 'movie' ? 'movies' : 'series';
        $row = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
        if (!$row) {
            $checked = $this->resolvePlexCheckedPath($mediaApp, $remoteId, $path, $type);
            if ($checked != $path) {
                $path = $checked;
                $row  = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
            }
        }
        $itemMeta = [
            'title'  => $title,
            'year'   => $year,
            'poster' => $poster,
        ];
        $moved = $this->countMovedLibraryItem($this->moveMatchedLibraryItem($table, $row, $path, $platform, $remoteId, $itemMeta, intval($mediaApp['id'] ?? 0)), $bucket, $updated, $unchanged);
        if ($moved != '') {
            return $moved;
        }
        $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);

        if ($outcome == 'unchanged' && $row) {
            return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
        }

        if ($outcome == 'updated' && $row) {
            $this->applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, $itemMeta);
            return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
        }

        if ($path == '') {
            $this->database->setLastError('empty ' . $type . ' path');
            return '';
        }

        $adopted = $this->adoptMovedLibraryItem($table, $path, $platform, $remoteId, $itemMeta, intval($mediaApp['id'] ?? 0));
        if ($adopted == 'updating') {
            $updated[$bucket]++;
            return 'updating';
        }
        if ($adopted == 'skipping') {
            $this->database->setLastError('');
            $unchanged[$bucket]++;
            return 'skipping';
        }

        $flags = $this->platformFlags($platform, $remoteId);
        $id    = 0;
        if ($type == 'movie') {
            $id = $this->database->addMovie($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        } else {
            $id = $this->database->addSeries($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        }
        if (!$id) {
            $row = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
            if (!$row) {
                $pathHit = $this->findLibraryImportPathHit($table, $platform, $path);
                if ($pathHit && !$this->libraryImportRowMatchesRemote($pathHit, $platform, $remoteId)) {
                    $this->database->setLastError('');
                    $unchanged[$bucket]++;
                    return 'skipping';
                }
                $row = $pathHit;
            }
            $moved = $this->countMovedLibraryItem($this->moveMatchedLibraryItem($table, $row, $path, $platform, $remoteId, $itemMeta, intval($mediaApp['id'] ?? 0)), $bucket, $updated, $unchanged);
            if ($moved != '') {
                $this->database->setLastError('');
                return $moved;
            }
            $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
            if ($outcome != 'added' && $row) {
                $this->database->setLastError('');
                if ($outcome == 'unchanged') {
                    return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
                }
                $this->applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, $itemMeta);
                return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
            }
            if ($this->database->getLastError() == '') {
                $this->database->setLastError($type . ' insert failed');
            }
            return '';
        }

        $this->rememberLibraryImportItem($table, [
            'id'                 => intval($id),
            'title'              => strval($title),
            'year'               => $year,
            'path'               => $path,
            'plex'               => intval($flags['plex']),
            'emby'               => intval($flags['emby']),
            'jellyfin'           => intval($flags['jellyfin']),
            'plex_remote_id'     => $flags['plex_remote_id'],
            'emby_remote_id'     => $flags['emby_remote_id'],
            'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
            'poster'             => $poster,
        ]);
        $this->flagInsertedLibraryItem($table, $platform, $remoteId, $this->findLibraryImportPathHit($table, $platform, $path));
        $added[$bucket]++;
        return 'inserting';
    }

    /*
     * Rows stored as S00E00 with the series name as title label every history
     * line for that file as a series. A later pull that knows the episode code
     * writes it back.
     */
    public function backfillEpisodeCode($row, $item)
    {
        $season  = intval($item['season'] ?? 0);
        $episode = intval($item['episode'] ?? 0);
        if (!$row || !intval($row['id'] ?? 0) || intval($row['season'] ?? 0) || intval($row['episode'] ?? 0) || (!$season && !$episode)) {
            return $row;
        }

        $sets  = ['`season` = ' . $season, '`episode` = ' . $episode];
        $title = trim(strval($item['title'] ?? ''));
        if ($title != '') {
            $sets[]       = "`title` = '" . $this->database->prepare($title) . "'";
            $row['title'] = $title;
        }
        $this->database->query("UPDATE " . EPISODE_TABLE . " SET " . implode(', ', $sets) . " WHERE id = " . intval($row['id']));
        $row['season']  = $season;
        $row['episode'] = $episode;
        $this->rememberLibraryImportItem(EPISODE_TABLE, $row);

        return $row;
    }

    public function saveLibraryEpisode($mediaApp, $item, &$added, &$updated, &$unchanged)
    {
        $platform = $mediaApp['platform'];
        $remoteId = $item['remote_id'] ?? '';
        $path     = $this->database->normalizePath($item['path'] ?? '', 'episode');
        $season   = intval($item['season'] ?? 0);
        $episode  = intval($item['episode'] ?? 0);
        $row = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
        if (!$row) {
            $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
        }
        if (!$row) {
            $checked = $this->resolvePlexCheckedPath($mediaApp, $remoteId, $path, 'episode');
            if ($checked != $path) {
                $path = $checked;
                $row  = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
                if (!$row) {
                    $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
                }
            }
        }
        $row   = $this->backfillEpisodeCode($row, $item);
        $moved = $this->countMovedLibraryItem($this->moveMatchedLibraryItem(EPISODE_TABLE, $row, $path, $platform, $remoteId, [], intval($mediaApp['id'] ?? 0)), 'episodes', $updated, $unchanged);
        if ($moved != '') {
            return $moved;
        }
        $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);

        if ($outcome == 'unchanged' && $row) {
            $unchanged['episodes']++;
            return 'skipping';
        }

        if ($outcome == 'updated' && $row) {
            $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
            $updated['episodes']++;
            return 'updating';
        }

        if ($path == '') {
            $this->database->setLastError('empty episode path');
            return '';
        }

        $adopted = $this->adoptMovedLibraryItem(EPISODE_TABLE, $path, $platform, $remoteId, [], intval($mediaApp['id'] ?? 0));
        if ($adopted == 'updating') {
            $updated['episodes']++;
            return 'updating';
        }
        if ($adopted == 'skipping') {
            $this->database->setLastError('');
            $unchanged['episodes']++;
            return 'skipping';
        }

        $seriesId = $this->resolveEpisodeSeriesId($mediaApp, $item, $path);
        if (!$seriesId) {
            return '';
        }

        $flags = $this->platformFlags($platform, $remoteId);
        $id    = $this->database->addEpisode($seriesId, $item['season'], $item['episode'], $item['title'], $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], '', '');
        if (!$id) {
            $row = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
            if (!$row) {
                $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
            }
            if (!$row) {
                $pathHit = $this->findLibraryImportPathHit(EPISODE_TABLE, $platform, $path);
                if ($pathHit && intval($pathHit['season'] ?? -1) == $season && intval($pathHit['episode'] ?? -1) == $episode) {
                    $row = $pathHit;
                } else if ($pathHit && !$this->libraryImportRowMatchesRemote($pathHit, $platform, $remoteId)) {
                    $this->database->setLastError('');
                    $unchanged['episodes']++;
                    return 'skipping';
                }
            }
            $moved = $this->countMovedLibraryItem($this->moveMatchedLibraryItem(EPISODE_TABLE, $row, $path, $platform, $remoteId, [], intval($mediaApp['id'] ?? 0)), 'episodes', $updated, $unchanged);
            if ($moved != '') {
                $this->database->setLastError('');
                return $moved;
            }
            $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
            if ($outcome != 'added' && $row) {
                $this->database->setLastError('');
                if ($outcome == 'unchanged') {
                    $unchanged['episodes']++;
                    return 'skipping';
                }
                $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                $updated['episodes']++;
                return 'updating';
            }
            $recovered = $this->database->recoverEpisodeInsert($path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id']);
            if ($recovered) {
                $row = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
                if (!$row) {
                    $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
                }
                if ($row) {
                    $this->rememberLibraryImportItem(EPISODE_TABLE, $row);
                    $moved = $this->countMovedLibraryItem($this->moveMatchedLibraryItem(EPISODE_TABLE, $row, $path, $platform, $remoteId, [], intval($mediaApp['id'] ?? 0)), 'episodes', $updated, $unchanged);
                    if ($moved != '') {
                        $this->database->setLastError('');
                        return $moved;
                    }
                    $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
                    if ($outcome == 'unchanged') {
                        $unchanged['episodes']++;
                        return 'skipping';
                    }
                    if ($outcome == 'updated') {
                        $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                        $updated['episodes']++;
                        return 'updating';
                    }
                }
                $this->rememberLibraryImportItem(EPISODE_TABLE, [
                    'id'                 => intval($recovered),
                    'series_id'          => intval($seriesId),
                    'season'             => $season,
                    'episode'            => $episode,
                    'title'              => strval($item['title'] ?? ''),
                    'path'               => $path,
                    'plex'               => intval($flags['plex']),
                    'emby'               => intval($flags['emby']),
                    'jellyfin'           => intval($flags['jellyfin']),
                    'plex_remote_id'     => $flags['plex_remote_id'],
                    'emby_remote_id'     => $flags['emby_remote_id'],
                    'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
                    'poster'             => '',
                ]);
                $updated['episodes']++;
                return 'updating';
            }
            return '';
        }

        $this->rememberLibraryImportItem(EPISODE_TABLE, [
            'id'                 => intval($id),
            'series_id'          => intval($seriesId),
            'season'             => $season,
            'episode'            => $episode,
            'title'              => strval($item['title'] ?? ''),
            'path'               => $path,
            'plex'               => intval($flags['plex']),
            'emby'               => intval($flags['emby']),
            'jellyfin'           => intval($flags['jellyfin']),
            'plex_remote_id'     => $flags['plex_remote_id'],
            'emby_remote_id'     => $flags['emby_remote_id'],
            'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
            'poster'             => '',
        ]);
        $this->flagInsertedLibraryItem(EPISODE_TABLE, $platform, $remoteId, $this->findLibraryImportPathHit(EPISODE_TABLE, $platform, $path));
        $added['episodes']++;
        return 'inserting';
    }

    public function saveWebhookLibraryItem($mediaApp, $type, $item)
    {
        global $mediaApps;

        if (!$mediaApps->scanLibraryItemAllowed($mediaApp, $item)) {
            return '';
        }

        $platform = $mediaApp['platform'];
        $remoteId = trim(strval($item['remote_id'] ?? ''));
        $pathType = $type == 'episode' ? 'episode' : ($type == 'series' ? 'series' : 'movie');
        $path     = $this->database->normalizePath($item['path'] ?? '', $pathType);
        $title    = trim(strval($item['title'] ?? ''));
        $year     = intval($item['year'] ?? 0);
        $poster   = trim(strval($item['poster'] ?? ''));

        if ($type == 'episode') {
            $row = [];
            if ($remoteId != '') {
                $row = $this->database->getEpisodeByRemoteId($platform, $remoteId);
            }
            if (!$row && $path != '') {
                $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                        FROM " . EPISODE_TABLE . "
                        WHERE `path` = '" . $this->database->prepare($path) . "'
                        LIMIT 1";
                $row = $this->database->fetchAssoc($this->database->query($sql)) ?: [];
            }
            if (!$row) {
                return '';
            }

            $this->database->setEpisodePlatform($platform, $remoteId, $path != '' ? $path : strval($row['path'] ?? ''));
            if ($path != '') {
                $this->rehomeEpisodeAfterMatch($mediaApp, $item, $path);
            }

            return 'updating';
        }

        if ($type != 'movie' && $type != 'series') {
            return '';
        }

        $table = $type == 'movie' ? MOVIE_TABLE : SERIES_TABLE;
        $row   = $this->database->findMediaLibraryItem($table, $platform, $remoteId, $path);
        if (!$row) {
            return '';
        }

        $this->database->setMediaLibraryPlatformById($table, $platform, $remoteId, $path, $row['id']);
        $this->database->mergeLibraryItemOnto($table, $row['id'], [
            'title'              => $title,
            'year'               => $year,
            'path'               => $path,
            'poster'             => $poster,
            'plex'               => 0,
            'emby'               => 0,
            'jellyfin'           => 0,
            'plex_remote_id'     => '',
            'emby_remote_id'     => '',
            'jellyfin_remote_id' => '',
        ]);
        if ($poster != '' || $title != '' || $year > 0) {
            $this->database->fillBlankLibraryMetadata($table, $platform, $remoteId, $path != '' ? $path : ($row['path'] ?? ''), $title, $year, $poster);
        }

        return 'updating';
    }

    public function logLibraryItem($type, $index, $total, $platformName, $item, $success, $action = 'updating', $error = '')
    {
        $parts = [$platformName];
        if (($item['library'] ?? '') != '') {
            $parts[] = $item['library'];
        }
        if ($type == 'episode' && ($item['series'] ?? '') != '') {
            $parts[] = $item['series'];
        }
        if (($item['title'] ?? '') != '') {
            $parts[] = $item['title'];
        }
        $line = $type . ' ' . $index . '/' . $total . ' ' . $action . ': ' . implode(' -> ', $parts);
        if ($action == 'inserting' || $action == 'updating') {
            $line .= ' ' . ($success ? 'success' : 'failed');
        }
        logger($this->logfile, $line);
        if (!$success && $action != 'skipping') {
            $detail = trim(strval($error));
            if ($detail == '') {
                $detail = $this->database->getLastError();
            }
            if ($detail != '') {
                logger($this->logfile, 'error: ' . preg_replace('/\s+/', ' ', $detail));
            }
            $path = trim(strval($item['path'] ?? ''));
            if ($path != '') {
                logger($this->logfile, 'path: ' . $path);
            }
            $remoteId = trim(strval($item['remote_id'] ?? ''));
            if ($remoteId != '') {
                logger($this->logfile, 'remote_id: ' . $remoteId);
            }
        }
    }

    public function syncLibraries()
    {
        global $mediaApps;

        $master    = [];
        $listeners = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            } else {
                $listeners[] = $mediaApp;
            }
        }
        if (!$master) {
            logger($this->logfile, 'no master for library sync');
            return;
        }

        $online = $this->onlineMediaApps(array_merge([$master], $listeners), 2);
        if (!$online) {
            return;
        }

        $listeners    = [];
        $masterOnline = false;
        foreach ($online as $mediaApp) {
            if (intval($mediaApp['id']) == intval($master['id'])) {
                $master       = $mediaApp;
                $masterOnline = true;
                continue;
            }
            $listeners[] = $mediaApp;
        }
        if (!$masterOnline) {
            logger($this->logfile, 'no online master for library sync');
            return;
        }

        $masterLibraries = $mediaApps->getLibraries($master, [], true);
        $masterByKey     = [];
        foreach ($masterLibraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $masterByKey[$key] = $library;
            }
        }

        if ($this->isAutomaticJob() && $this->database->settingEnabled('syncParityAutoLibraries')) {
            $state = $mediaApps->paritySyncState('library');
            if ($state) {
                $changed = false;
                foreach ($masterLibraries as $library) {
                    $key = strval($library['key'] ?? '');
                    if ($key == '') {
                        continue;
                    }
                    $id = intval($master['id']) . ':' . $key;
                    if (array_key_exists($id, $state) || $this->database->getMediaAppLibraryLinks($master['id'], $key)) {
                        continue;
                    }
                    $state[$id] = 1;
                    $changed    = true;
                }
                if ($changed) {
                    $mediaApps->setParitySync('library', $state);
                    $this->currentJob['libraries'] = $mediaApps->selectedParityLibraries(false);
                }
            }
        }

        $keep     = [];
        $selected = [];
        foreach ($this->selectedLibraryRefs($master) as $library) {
            $key = strval($library['key'] ?? '');
            if ($key == '') {
                continue;
            }
            $keep[$this->libraryKeepId($master['id'], $key)] = true;
            if (!empty($masterByKey[$key])) {
                $selected[] = $masterByKey[$key];
            }
        }

        $automatic = $this->isAutomaticJob();
        logger($this->logfile, 'library sync master ' . $master['name'] . ' libraries=' . count($selected));

        foreach ($listeners as $listener) {
            $this->stopIfCancelled();
            logger($this->logfile, 'library sync ' . $listener['name']);
            $listenerLibraries = $mediaApps->getLibraries($listener);

            $created      = 0;
            $linked       = 0;
            $removed      = 0;
            $skipped      = 0;
            $removedKeys  = [];
            $createdNames = [];
            $linkedNames  = [];
            $removedNames = [];

            foreach ($masterLibraries as $masterLibrary) {
                $this->stopIfCancelled();
                $masterKey = strval($masterLibrary['key'] ?? '');
                if ($masterKey == '' || !empty($keep[$this->libraryKeepId($master['id'], $masterKey)])) {
                    continue;
                }

                $found       = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                $listenerKey = strval($found['key'] ?? '');
                if ($listenerKey == '') {
                    continue;
                }
                $label  = $masterLibrary['title'] ?? $masterKey;
                $before = $removed;
                $this->removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, $listenerLibraries, $removedKeys, $removed);
                if ($removed > $before) {
                    $removedNames[] = $label;
                }
            }

            foreach ($this->database->getMediaAppLibraryLinks($master['id']) as $link) {
                $this->stopIfCancelled();
                if (intval($link['linked_media_app_id']) != intval($listener['id'])) {
                    continue;
                }
                if (!empty($keep[$this->libraryKeepId($master['id'], $link['library_key'])])) {
                    continue;
                }

                $listenerKey = strval($link['linked_library_key'] ?? '');
                $masterKey   = strval($link['library_key'] ?? '');
                $label       = !empty($masterByKey[$masterKey]['title']) ? $masterByKey[$masterKey]['title'] : $masterKey;
                $before      = $removed;
                $this->removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, $listenerLibraries, $removedKeys, $removed);
                if ($removed > $before) {
                    $removedNames[] = $label;
                }
            }

            foreach ($selected as $masterLibrary) {
                $this->stopIfCancelled();
                $label = $masterLibrary['title'] ?? $masterLibrary['key'] ?? '';
                $found = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                if ($found && strval($found['key'] ?? '') != '') {
                    $hadLink = false;
                    foreach ($this->database->getMediaAppLibraryLinks($master['id']) as $link) {
                        if (intval($link['linked_media_app_id']) == intval($listener['id']) && strval($link['library_key']) == strval($masterLibrary['key'])) {
                            $hadLink = true;
                            break;
                        }
                    }
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $found['key']);
                    if (!$hadLink) {
                        $linked++;
                        if ($label != '') {
                            $linkedNames[] = $label;
                        }
                    }
                    continue;
                }
                if ($automatic && !$this->database->settingEnabled('syncParityAutoLibraries')) {
                    $skipped++;
                    logger($this->logfile, ($masterLibrary['title'] ?? $masterLibrary['key']) . ' no link, skip');
                    continue;
                }

                $result = $mediaApps->createLibrary($listener, $masterLibrary);
                if (!empty($result['error'])) {
                    logger($this->logfile, 'create ' . ($masterLibrary['title'] ?? '') . ' ' . ($result['message'] ?? ''));
                    continue;
                }
                $created++;
                if ($label != '') {
                    $createdNames[] = $label;
                }
                $listenerLibraries = $mediaApps->getLibraries($listener);
                $found             = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                if ($found && strval($found['key'] ?? '') != '') {
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $found['key']);
                    $linked++;
                }
            }

            $mediaApps->linkLibraries();
            $users = [];
            foreach ($mediaApps->selectedParityUserIds() as $userId) {
                $user = $this->database->getMediaAppUser($userId);
                if ($user) {
                    $users[] = $user;
                }
            }
            $access = $mediaApps->syncListenerSelectedAccess($master, $listener, $users, $selected);
            if ($created || $linked || $removed) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            $this->currentJob['stats']['created'] = intval($this->currentJob['stats']['created'] ?? 0) + $created;
            $this->currentJob['stats']['linked']  = intval($this->currentJob['stats']['linked'] ?? 0) + $linked;
            $this->currentJob['stats']['removed'] = intval($this->currentJob['stats']['removed'] ?? 0) + $removed;
            $this->currentJob['stats']['access']  = intval($this->currentJob['stats']['access'] ?? 0) + intval($access['updated'] ?? 0);
            foreach ($createdNames as $name) {
                $this->addParityResult($listener, 'libraries', 'created', $name);
            }
            foreach ($linkedNames as $name) {
                $this->addParityResult($listener, 'libraries', 'linked', $name);
            }
            foreach ($removedNames as $name) {
                $this->addParityResult($listener, 'libraries', 'removed', $name);
            }
            logger($this->logfile, 'library sync ' . $listener['name'] . ' created=' . $created . ' linked=' . $linked . ' removed=' . $removed . ' skipped=' . $skipped . ' access=' . intval($access['updated'] ?? 0));
        }
    }

    public function selectedLibraryRefs($master)
    {
        $refs = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (intval($library['media_app_id'] ?? 0) && intval($library['media_app_id']) != intval($master['id'])) {
                continue;
            }
            if (($library['key'] ?? '') != '') {
                $refs[] = $library;
            }
        }
        if ($refs) {
            return $refs;
        }

        global $mediaApps;

        return $mediaApps->selectedParityLibraries(false);
    }

    public function libraryKeepId($mediaAppId, $key)
    {
        return intval($mediaAppId) . ':' . strval($key);
    }

    public function removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, &$listenerLibraries, &$removedKeys, &$removed)
    {
        $listenerKey = strval($listenerKey);
        if ($listenerKey == '' || !empty($removedKeys[$listenerKey])) {
            return $listenerKey != '' && !empty($removedKeys[$listenerKey]);
        }

        $result = $mediaApps->deleteLibrary($listener, $listenerKey);
        if (!empty($result['error'])) {
            logger($this->logfile, 'remove ' . $label . ' ' . ($result['message'] ?? ''));
            return false;
        }
        $this->database->deleteMediaAppLibraryLink($listener['id'], $listenerKey);
        $removedKeys[$listenerKey] = true;
        $kept                      = [];
        foreach ($listenerLibraries as $library) {
            if (strval($library['key'] ?? '') != $listenerKey) {
                $kept[] = $library;
            }
        }
        $listenerLibraries = $kept;
        $removed++;
        logger($this->logfile, 'remove ' . $label . ' complete');

        return true;
    }
}
