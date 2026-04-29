<?php
declare(strict_types=1);

/**
 * Jikan REST API v4 wrapper — MyAnimeList data.
 * No API key required. Rate limit: 3 req/sec, 60 req/min.
 * All calls are file-cached (CACHE_TTL) to stay well within limits.
 *
 * Base URL: https://api.jikan.moe/v4
 */
class JikanApi
{
    private const BASE = 'https://api.jikan.moe/v4';

    // ── Internal ──────────────────────────────────────────────────────────────

    private function fetch(string $endpoint, array $params = [], int $attempt = 1): array
    {
        // Adaptive throttle: only sleep the remaining time since the last real request.
        // Stays under Jikan's 3 req/sec limit without blocking when requests are
        // naturally spaced further apart (e.g. the first call, or after a cache miss).
        static $lastRequestAt = 0.0;
        $now     = microtime(true);
        $elapsed = $now - $lastRequestAt;
        if ($lastRequestAt > 0.0 && $elapsed < 0.35) {
            usleep((int)((0.35 - $elapsed) * 1_000_000));
        }
        $lastRequestAt = microtime(true);

        $url = self::BASE . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // On XAMPP/Windows, point cURL at the bundled CA cert (set CURL_CA_BUNDLE in .env).
        $caBundle = CURL_CA_BUNDLE;
        $verifySsl = $caBundle !== '' && file_exists($caBundle);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . SITE_NAME . '/1.0'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_CAINFO => $verifySsl ? $caBundle : null,
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        // curl_close() is deprecated since PHP 8.5
        unset($ch);

        if ($errno || !$raw)
            return [];

        $headerSize = $info['header_size'];
        $body = substr($raw, $headerSize);
        $httpCode = $info['http_code'];

        // Jikan rate-limit (429 or 503) — retry up to 3 times with backoff
        if (in_array($httpCode, [429, 503], true) && $attempt <= 3) {
            $headerSection = substr($raw, 0, $headerSize);
            if (preg_match('/Retry-After:\s*(\d+)/i', $headerSection, $m)) {
                $wait = max(1, (int) $m[1]);
            } else {
                $wait = $attempt * 2; // 2s, 4s, 6s
            }
            sleep($wait);
            return $this->fetch($endpoint, $params, $attempt + 1);
        }

        if ($httpCode < 200 || $httpCode >= 300)
            return [];

        return json_decode($body, true) ?? [];
    }

    private function cachedFetch(string $endpoint, array $params = []): array
    {
        $file = CACHE_DIR . '/jikan_' . md5($endpoint . serialize($params)) . '.json';

        if (file_exists($file) && (time() - filemtime($file)) < CACHE_TTL) {
            $cached = json_decode(file_get_contents($file), true);
            if (is_array($cached))
                return $cached;
        }

        $data = $this->fetch($endpoint, $params);

        if (!empty($data)) {
            file_put_contents($file, json_encode($data));
            return $data;
        }

        // API failed — serve stale cache (any age) rather than an empty row
        if (file_exists($file)) {
            $stale = json_decode(file_get_contents($file), true);
            if (is_array($stale)) return $stale;
        }

        return [];
    }

    /** Return the data[] array from a list endpoint. */
    private function results(string $endpoint, array $params = []): array
    {
        $data = $this->cachedFetch($endpoint, $params);
        return $data['data'] ?? [];
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Currently airing anime this season.
     */
    public function seasonalAnime(): array
    {
        return $this->results('/seasons/now', ['limit' => 25]);
    }

    /**
     * Top anime by type.
     * $type: 'tv' | 'movie' | 'ova' | 'special' | 'ona'
     * $filter: 'airing' | 'upcoming' | 'bypopularity' | 'favorite'
     */
    public function topAnime(string $type = 'tv', string $filter = 'bypopularity', int $page = 1): array
    {
        return $this->results('/top/anime', [
            'type' => $type,
            'filter' => $filter,
            'page' => $page,
            'limit' => 25,
        ]);
    }

    /**
     * Search anime by title.
     * $type: '' | 'tv' | 'movie' | 'ova' | 'special' | 'ona'
     */
    public function searchAnime(string $query, string $type = ''): array
    {
        $params = ['q' => $query, 'limit' => 20, 'sfw' => 'false'];
        if ($type !== '')
            $params['type'] = $type;
        return $this->results('/anime', $params);
    }

    /**
     * Full anime detail for a single MAL ID.
     */
    public function animeDetails(int $malId): array
    {
        $data = $this->cachedFetch('/anime/' . $malId . '/full');
        return $data['data'] ?? [];
    }

    /**
     * Fetch episode thumbnails from AniList using the MAL ID.
     *
     * AniList's streamingEpisodes field returns Crunchyroll/Funimation thumbnails.
     * Returns a map of episode_number => thumbnail_url, e.g.:
     *   [1 => 'https://img1.ak.crunchyroll.com/...', 2 => '...']
     *
     * Returns empty array if AniList has no thumbnails for this anime.
     * Results are cached for CACHE_TTL seconds.
     */
    public function episodeThumbnails(int $malId): array
    {
        $cacheFile = CACHE_DIR . '/anilist_thumbs_' . $malId . '.json';

        if (file_exists($cacheFile) && time() - filemtime($cacheFile) < CACHE_TTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached))
                return $cached;
        }

        $query = 'query($id:Int){Media(idMal:$id,type:ANIME){streamingEpisodes{title thumbnail}}}';
        $payload = json_encode(['query' => $query, 'variables' => ['id' => $malId]]);

        $caBundle = CURL_CA_BUNDLE;
        $verifySsl = $caBundle !== '' && file_exists($caBundle);

        $ch = curl_init('https://graphql.anilist.co');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_CAINFO => $verifySsl ? $caBundle : null,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        unset($ch);

        if ($errno || !$body)
            return [];

        $data = json_decode($body, true);
        $streamingEps = $data['data']['Media']['streamingEpisodes'] ?? [];
        if (empty($streamingEps))
            return [];

        // Build map: episode_number => thumbnail_url
        // AniList titles are like "Episode 1 - Title" or "Episode 1"
        $map = [];
        foreach ($streamingEps as $ep) {
            $thumb = $ep['thumbnail'] ?? '';
            $title = $ep['title'] ?? '';
            if (!$thumb)
                continue;
            if (preg_match('/^Episode\s+(\d+)/i', $title, $m)) {
                $map[(int) $m[1]] = $thumb;
            }
        }

        if (!empty($map)) {
            file_put_contents($cacheFile, json_encode($map));
        }
        return $map;
    }

    /**
     * Episode list for one page (100 per page max on Jikan).
     * Returns: [['mal_id'=>1, 'title'=>'...', 'aired'=>'2011-10-02T...', 'filler'=>false], ...]
     */
    public function animeEpisodes(int $malId, int $page = 1): array
    {
        $data = $this->cachedFetch('/anime/' . $malId . '/episodes', ['page' => $page]);
        return [
            'episodes' => $data['data'] ?? [],
            'has_next' => $data['pagination']['has_next_page'] ?? false,
            'last_page' => $data['pagination']['last_visible_page'] ?? 1,
        ];
    }

    /**
     * Fetch ALL episode pages and return a flat array of episodes.
     * Cached per-page so repeat calls are free.
     */
    public function allAnimeEpisodes(int $malId): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->animeEpisodes($malId, $page);
            array_push($all, ...$result['episodes']);
            $hasNext = $result['has_next'];
            $page++;
        } while ($hasNext && $page <= 20); // safety cap at 2000 episodes
        return $all;
    }

    /**
     * Relations for a MAL anime entry.
     * Returns raw relation array: [['relation'=>'Sequel', 'entry'=>[...]], ...]
     */
    public function animeRelations(int $malId): array
    {
        $data = $this->cachedFetch('/anime/' . $malId . '/relations');
        return $data['data'] ?? [];
    }

    /**
     * Build an ordered sequel chain starting from any entry in the chain.
     *
     * Walks Sequel relations recursively to build the full season list.
     * Each element: ['mal_id'=>int, 'title'=>string, 'episode_count'=>int, 'season_num'=>int]
     *
     * Example for OPM:
     *   [0] => ['mal_id'=>30276, 'title'=>'One Punch Man',            'season_num'=>1]
     *   [1] => ['mal_id'=>34134, 'title'=>'One Punch Man 2nd Season', 'season_num'=>2]
     */
    public function sequelChain(int $startMalId): array
    {
        // Walk backwards to find root (in case user is on S2/S3)
        $root = $this->findChainRoot($startMalId, []);
        // Walk forward from root
        return $this->walkSequels($root, [], 1);
    }

    private function findChainRoot(int $malId, array $visited): int
    {
        if (in_array($malId, $visited, true))
            return $malId; // cycle guard
        $visited[] = $malId;

        $relations = $this->animeRelations($malId);
        foreach ($relations as $rel) {
            if (strtolower($rel['relation'] ?? '') !== 'prequel')
                continue;
            foreach ($rel['entry'] as $entry) {
                if ($entry['type'] === 'anime') {
                    return $this->findChainRoot((int) $entry['mal_id'], $visited);
                }
            }
        }
        return $malId; // no prequel found → this is root
    }

    private function walkSequels(int $malId, array $visited, int $seasonNum): array
    {
        if (in_array($malId, $visited, true))
            return []; // cycle guard
        $visited[] = $malId;

        $details = $this->animeDetails($malId);
        $chain = [
            [
                'mal_id' => $malId,
                'title' => $details['title_english'] ?: ($details['title'] ?? 'Season ' . $seasonNum),
                'episode_count' => (int) ($details['episodes'] ?? 0),
                'season_num' => $seasonNum,
            ]
        ];

        $relations = $this->animeRelations($malId);
        foreach ($relations as $rel) {
            if (strtolower($rel['relation'] ?? '') !== 'sequel')
                continue;
            foreach ($rel['entry'] as $entry) {
                if ($entry['type'] === 'anime') {
                    $sequelDetails = $this->animeDetails((int) $entry['mal_id']);
                    $sequelType = strtolower($sequelDetails['type'] ?? 'tv');
                    // Skip OVAs, movies, specials — only follow main TV sequels
                    if (!in_array($sequelType, ['tv', 'ona'], true))
                        continue;
                    $chain = array_merge(
                        $chain,
                        $this->walkSequels((int) $entry['mal_id'], $visited, $seasonNum + 1)
                    );
                    break 2; // only one main sequel per entry
                }
            }
        }
        return $chain;
    }

}
