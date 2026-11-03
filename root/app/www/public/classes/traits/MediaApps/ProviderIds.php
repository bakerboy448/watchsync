<?php

/*
----------------------------------
------  Created: 110226   ------
------  bakerboy448       ------
----------------------------------
*/

/*
 * Cross server item matching by imdb, tmdb, and tvdb id. Episode ids differ
 * between metadata agents, so an episode also matches on its series id plus
 * season and episode number.
 */
trait ProviderIds
{
    public function normalizeProviderIds($ids)
    {
        $normalized = [];
        foreach (is_array($ids) ? $ids : [] as $provider => $value) {
            $provider = strtolower(trim(strval($provider)));
            $value    = strtolower(trim(strval($value)));
            if (in_array($provider, ['imdb', 'tmdb', 'tvdb']) && $value != '' && $value != '0') {
                $normalized[$provider] = $value;
            }
        }

        return $normalized;
    }

    public function providerIdsFromPlexGuids($guids)
    {
        $ids = [];
        $guids = is_array($guids) ? $guids : [];
        if (isset($guids['id']) || isset($guids['@attributes'])) {
            $guids = [$guids];
        }
        foreach ($guids as $guid) {
            $value = is_array($guid) ? strval($guid['id'] ?? ($guid['@attributes']['id'] ?? '')) : strval($guid);
            if (str_contains($value, '://')) {
                [$provider, $id] = explode('://', $value, 2);
                $ids[$provider]  = $id;
            }
        }

        return $this->normalizeProviderIds($ids);
    }

    public function providerMatchKeys($type, $item, $seriesIds = [])
    {
        $keys = [];
        foreach ($this->normalizeProviderIds($item['provider_ids'] ?? []) as $provider => $id) {
            $keys[] = $type . ':' . $provider . ':' . $id;
        }
        if ($type == 'episode') {
            $season  = intval($item['season'] ?? -1);
            $episode = intval($item['episode'] ?? -1);
            if ($season >= 0 && $episode >= 0) {
                foreach ($this->normalizeProviderIds($seriesIds) as $provider => $id) {
                    $keys[] = 'series:' . $provider . ':' . $id . ':s' . $season . 'e' . $episode;
                }
            }
        }

        return $keys;
    }

    /*
     * Returns source remote id => target remote id. A key that points at two
     * target items is ambiguous and is not used.
     */
    public function matchItemsByProviderIds($type, $sourceItems, $targetItems, $sourceSeriesIds = [], $targetSeriesIds = [])
    {
        $index = [];
        foreach ($targetItems as $item) {
            $remote = strval($item['remote_id'] ?? '');
            $series = $targetSeriesIds[strval($item['series_remote_id'] ?? '')] ?? [];
            foreach ($this->providerMatchKeys($type, $item, $series) as $key) {
                $index[$key][$remote] = true;
            }
        }

        $matches = [];
        foreach ($sourceItems as $item) {
            $series = $sourceSeriesIds[strval($item['series_remote_id'] ?? '')] ?? [];
            foreach ($this->providerMatchKeys($type, $item, $series) as $key) {
                if (count($index[$key] ?? []) == 1) {
                    $matches[strval($item['remote_id'] ?? '')] = array_key_first($index[$key]);
                    break;
                }
            }
        }

        return $matches;
    }
}
