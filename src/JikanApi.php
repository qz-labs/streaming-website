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

    private function fetch(string $endpoint, array $params = []): array
    {
        $url = self::BASE . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // XAMPP Windows SSL fix (same as TmdbApi)
        $caBundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: StreamFlix/1.0'],
            CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
            CURLOPT_SSL_VERIFYHOST => file_exists($caBundle) ? 2 : 0,
            CURLOPT_CAINFO         => file_exists($caBundle) ? $caBundle : null,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || !$body) return [];
        return json_decode($body, true) ?? [];
    }

    private function cachedFetch(string $endpoint, array $params = []): array
    {
        $file = CACHE_DIR . '/jikan_' . md5($endpoint . serialize($params)) . '.json';

        if (file_exists($file) && (time() - filemtime($file)) < CACHE_TTL) {
            $cached = json_decode(file_get_contents($file), true);
            if (is_array($cached)) return $cached;
        }

        $data = $this->fetch($endpoint, $params);
        if (!empty($data)) {
            file_put_contents($file, json_encode($data));
        }
        return $data;
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
            'type'   => $type,
            'filter' => $filter,
            'page'   => $page,
            'limit'  => 25,
        ]);
    }

    /**
     * All-time popular anime (any type).
     */
    public function popularAnime(int $page = 1): array
    {
        return $this->results('/top/anime', [
            'filter' => 'bypopularity',
            'page'   => $page,
            'limit'  => 25,
        ]);
    }

    /**
     * Search anime by title.
     * $type: '' | 'tv' | 'movie' | 'ova' | 'special' | 'ona'
     */
    public function searchAnime(string $query, string $type = ''): array
    {
        $params = ['q' => $query, 'limit' => 20, 'sfw' => 'false'];
        if ($type !== '') $params['type'] = $type;
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
     * Episode list for an anime (100 per page).
     * Returns ['episodes' => [...], 'has_next' => bool]
     */
    public function animeEpisodes(int $malId, int $page = 1): array
    {
        $data = $this->cachedFetch('/anime/' . $malId . '/episodes', ['page' => $page]);
        return [
            'episodes' => $data['data'] ?? [],
            'has_next' => ($data['pagination']['has_next_page'] ?? false),
            'last_page' => ($data['pagination']['last_visible_page'] ?? 1),
        ];
    }

    /**
     * Characters & voice actors for an anime (used for cast section).
     */
    public function animeCharacters(int $malId): array
    {
        return $this->results('/anime/' . $malId . '/characters');
    }

    /**
     * External links for an anime — includes streaming sites, AniDB, etc.
     * Used to try to find IMDB/TMDB equivalents.
     */
    public function animeExternal(int $malId): array
    {
        return $this->results('/anime/' . $malId . '/external');
    }
}
