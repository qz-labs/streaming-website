<?php
declare(strict_types=1);

class TmdbApi
{
    private string $key;
    private string $cacheDir;
    private int    $ttl;

    public function __construct()
    {
        $this->key      = TMDB_KEY;
        $this->cacheDir = CACHE_DIR;
        $this->ttl      = CACHE_TTL;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Build full URL and perform the HTTP request via cURL.
     * Returns decoded array or [] on failure.
     */
    private function fetch(string $endpoint, array $params = []): array
    {
        $params['api_key']  = $this->key;
        $params['language'] = 'en-US';
        $url = TMDB_BASE . $endpoint . '?' . http_build_query($params);

        // On XAMPP/Windows, point cURL at the bundled CA cert.
        // Falls back to disabling peer verification if the bundle is missing.
        $caBundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
            CURLOPT_SSL_VERIFYHOST => file_exists($caBundle) ? 2 : 0,
            CURLOPT_CAINFO         => file_exists($caBundle) ? $caBundle : null,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || $body === false || $body === '') {
            return [];
        }
        return json_decode($body, true) ?? [];
    }

    /**
     * Return a deterministic cache filename for an endpoint + params combo.
     */
    private function cacheFile(string $endpoint, array $params): string
    {
        return $this->cacheDir . '/' . md5($endpoint . serialize($params)) . '.json';
    }

    /**
     * Fetch from cache when fresh, otherwise hit TMDB and write to cache.
     */
    private function cachedFetch(string $endpoint, array $params = []): array
    {
        $file = $this->cacheFile($endpoint, $params);

        if (file_exists($file) && (time() - filemtime($file)) < $this->ttl) {
            $cached = json_decode(file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $this->fetch($endpoint, $params);
        if (!empty($data)) {
            file_put_contents($file, json_encode($data));
        }
        return $data;
    }

    /**
     * Shorthand: fetch a list endpoint and return just the results array.
     */
    private function results(string $endpoint, array $params = []): array
    {
        $data = $this->cachedFetch($endpoint, $params);
        return $data['results'] ?? [];
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function trendingMovies(): array
    {
        return $this->results('/trending/movie/week');
    }

    public function trendingTv(): array
    {
        return $this->results('/trending/tv/week');
    }

    public function popularMovies(): array
    {
        return $this->results('/movie/popular');
    }

    public function popularTv(): array
    {
        return $this->results('/tv/popular');
    }

    public function topRatedMovies(): array
    {
        return $this->results('/movie/top_rated');
    }

    /**
     * Full movie detail object including cast credits.
     */
    public function movieDetails(int $id): array
    {
        return $this->cachedFetch('/movie/' . $id, ['append_to_response' => 'credits']);
    }

    /**
     * Full TV show detail object including cast credits.
     */
    public function tvDetails(int $id): array
    {
        return $this->cachedFetch('/tv/' . $id, ['append_to_response' => 'credits']);
    }

    /**
     * All episodes for a specific season of a TV show.
     */
    public function tvSeason(int $id, int $season): array
    {
        return $this->cachedFetch('/tv/' . $id . '/season/' . $season);
    }

    /**
     * Multi-search (movies + TV shows). Returns raw results array with media_type field.
     */
    public function search(string $query): array
    {
        return $this->results('/search/multi', ['query' => $query]);
    }

    /**
     * Find the TMDB ID for an anime given its title (English preferred).
     * Searches TMDB TV shows + movies filtered to animation genre.
     * Returns ['tmdb_id' => int, 'type' => 'tv'|'movie'] or null if not found.
     *
     * Result is cached in a separate MAL→TMDB map file (very long TTL: 7 days).
     */
    public function findAnimeTmdbId(int $malId, string $title, string $titleEnglish = '', string $animeType = 'tv'): ?array
    {
        // Persistent mapping cache (7-day TTL — titles don't change)
        $mapFile = CACHE_DIR . '/mal_tmdb_map.json';
        $map     = [];
        if (file_exists($mapFile)) {
            $map = json_decode(file_get_contents($mapFile), true) ?? [];
        }

        $key = (string)$malId;
        if (isset($map[$key])) {
            return $map[$key];   // already resolved
        }

        // Determine which TMDB media type to search
        // Jikan type: TV/OVA/ONA/Special → tmdb tv; Movie → tmdb movie
        $searchType = ($animeType === 'Movie') ? 'movie' : 'tv';

        // Prefer English title for TMDB search; fall back to original
        $query = $titleEnglish !== '' ? $titleEnglish : $title;

        $endpoint = '/search/' . $searchType;
        $results  = $this->results($endpoint, ['query' => $query, 'with_genres' => '16']);

        if (empty($results)) {
            // Try the original Japanese title as a fallback
            if ($titleEnglish !== '' && $title !== $titleEnglish) {
                $results = $this->results($endpoint, ['query' => $title]);
            }
        }

        if (empty($results)) {
            $map[$key] = null;
            file_put_contents($mapFile, json_encode($map));
            return null;
        }

        $found = ['tmdb_id' => (int)$results[0]['id'], 'type' => $searchType];
        $map[$key] = $found;
        file_put_contents($mapFile, json_encode($map));
        return $found;
    }
}
