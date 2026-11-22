<?php

/*
----------------------------------
------  Created: 112226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Silo reports no file path, so its items attach to rows that Plex or
 * Jellyfin already created. The match uses imdb, tmdb, and tvdb ids stored in
 * the provider_id table by the other platforms' imports. Nothing is created.
 */
trait SiloLibrary
{
    public function recordProviderIds($table, $rowId, $ids)
    {
        global $mediaApps;

        $rowId = intval($rowId);
        if (!$rowId || !in_array($table, [MOVIE_TABLE, SERIES_TABLE])) {
            return;
        }

        foreach ($mediaApps->normalizeProviderIds($ids) as $provider => $value) {
            $this->database->query("INSERT INTO " . PROVIDER_ID_TABLE . "
                                    (`item_table`, `item_id`, `provider`, `value`)
                                    VALUES
                                    ('" . $this->database->prepare($table) . "', " . $rowId . ", '" . $this->database->prepare($provider) . "', '" . $this->database->prepare($value) . "')
                                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        }
    }

    public function recordImportedProviderIds($mediaApp, $table, $item)
    {
        if (intval($mediaApp['platform']) == MediaPlatforms::SILO || empty($item['provider_ids'])) {
            return;
        }

        $type = $table == MOVIE_TABLE ? 'movie' : 'series';
        $row  = $this->findLibraryImportItem($table, $mediaApp['platform'], $item['remote_id'] ?? '', $this->database->normalizePath($item['path'] ?? '', $type));
        if ($row) {
            $this->recordProviderIds($table, $row['id'], $item['provider_ids']);
        }
    }

    public function findRowIdByProviderIds($table, $ids)
    {
        global $mediaApps;

        $where = [];
        foreach ($mediaApps->normalizeProviderIds($ids) as $provider => $value) {
            $where[] = "(`provider` = '" . $this->database->prepare($provider) . "' AND `value` = '" . $this->database->prepare($value) . "')";
        }
        if (!$where) {
            return 0;
        }

        $res = $this->database->query("SELECT DISTINCT item_id
                                       FROM " . PROVIDER_ID_TABLE . "
                                       WHERE item_table = '" . $this->database->prepare($table) . "'
                                       AND (" . implode(' OR ', $where) . ")");
        $found = [];
        while ($row = $this->database->fetchAssoc($res)) {
            $found[] = intval($row['item_id']);
        }

        return count($found) == 1 ? $found[0] : 0;
    }

    public function siloSeriesProviderIds($seriesId, $seriesIds = [])
    {
        if (!empty($seriesIds[$seriesId])) {
            return $seriesIds[$seriesId];
        }
        if (preg_match('/^series-(imdb|tmdb|tvdb)-(.+)$/', strval($seriesId), $match)) {
            return [$match[1] => $match[2]];
        }

        return [];
    }

    public function setSiloRemote($table, $row, $remoteId, $dry = false)
    {
        if (intval($row['silo'] ?? 0) == 1 && strval($row['silo_remote_id'] ?? '') == strval($remoteId)) {
            return 'unchanged';
        }

        $taken = $this->database->fetchAssoc($this->database->query("SELECT id
                                                                     FROM " . $table . "
                                                                     WHERE silo_remote_id = '" . $this->database->prepare($remoteId) . "'
                                                                     AND id != " . intval($row['id']) . "
                                                                     LIMIT 1"));
        if ($taken) {
            return 'conflict';
        }

        if (!$dry) {
            $this->database->query("UPDATE " . $table . "
                                    SET silo = 1, silo_remote_id = '" . $this->database->prepare($remoteId) . "'
                                    WHERE id = " . intval($row['id']));
        }

        return 'updated';
    }

    public function importSiloItems($mediaApp, $items, &$stats, $dry = false)
    {
        foreach (['movies', 'series', 'episodes'] as $type) {
            $stats[$type] = $stats[$type] ?? ['matched' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'conflict' => 0];
        }

        foreach ($items['movies'] ?? [] as $item) {
            $id = $this->findRowIdByProviderIds(MOVIE_TABLE, $item['provider_ids'] ?? []);
            $this->applySiloMatch(MOVIE_TABLE, 'movies', $id, $item['remote_id'] ?? '', $stats, $dry);
        }

        $seriesIds = [];
        foreach ($items['series'] ?? [] as $item) {
            $seriesIds[strval($item['remote_id'] ?? '')] = $item['provider_ids'] ?? [];
        }

        $series = [];
        foreach ($items['episodes'] ?? [] as $item) {
            $seriesKey = strval($item['series_remote_id'] ?? '');
            if (!array_key_exists($seriesKey, $series)) {
                $series[$seriesKey] = $this->findRowIdByProviderIds(SERIES_TABLE, $this->siloSeriesProviderIds($seriesKey, $seriesIds));
                if ($series[$seriesKey]) {
                    $this->applySiloMatch(SERIES_TABLE, 'series', $series[$seriesKey], $seriesKey, $stats, $dry);
                } else {
                    $stats['series']['unmatched']++;
                }
            }
            $id = 0;
            if ($series[$seriesKey]) {
                $row = $this->database->fetchAssoc($this->database->query("SELECT id
                                                                           FROM " . EPISODE_TABLE . "
                                                                           WHERE series_id = " . intval($series[$seriesKey]) . "
                                                                           AND season = " . intval($item['season']) . "
                                                                           AND episode = " . intval($item['episode']) . "
                                                                           LIMIT 2"));
                $id  = intval($row['id'] ?? 0);
            }
            $this->applySiloMatch(EPISODE_TABLE, 'episodes', $id, $item['remote_id'] ?? '', $stats, $dry);
        }
    }

    public function applySiloMatch($table, $bucket, $rowId, $remoteId, &$stats, $dry)
    {
        $remoteId = strval($remoteId);
        if (!$rowId || $remoteId == '') {
            $stats[$bucket]['unmatched']++;
            return;
        }

        $row = $this->database->fetchAssoc($this->database->query("SELECT id, silo, silo_remote_id FROM " . $table . " WHERE id = " . intval($rowId)));
        if (!$row) {
            $stats[$bucket]['unmatched']++;
            return;
        }

        $stats[$bucket]['matched']++;
        $stats[$bucket][$this->setSiloRemote($table, $row, $remoteId, $dry)]++;
    }
}
