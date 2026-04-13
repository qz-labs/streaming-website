<?php
declare(strict_types=1);

/**
 * HiAnime API wrapper (self-hosted, port 4444).
 *
 * Each MAL entry is already one season on HiAnime — no multi-season offset
 * arithmetic needed. stream.php always passes episode=N relative to the MAL
 * entry, and season is always effectively 1.
 *
 * Endpoint reference:
 *   Search   : GET /api/search?keyword={query}
 *   Episodes : GET /api/episodes/{animeId}
 *   Stream   : GET /api/stream?id={episodeId}&server=HD-1&type=sub|dub
 *   Fallback : GET /api/stream/fallback?id={episodeId}&server=HD-1&type=sub|dub
 */
class ConsumetApi
{
    private string $base;

    public function __construct()
    {
        $this->base = CONSUMET_URL;
    }

    // ── Internal HTTP ─────────────────────────────────────────────────────────

    private function fetch(string $path, array $params = []): array
    {
        $url = $this->base . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $isLocalhost = in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
        $caBundle    = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        $verifySsl   = !$isLocalhost && file_exists($caBundle);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: StreamFlix/1.0'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_CAINFO         => $verifySsl ? $caBundle : null,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno || !$body) return [];
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function cachedFetch(string $path, array $params = []): array
    {
        $file = CACHE_DIR . '/hianime_' . md5($path . serialize($params)) . '.json';

        if (file_exists($file) && time() - filemtime($file) < CACHE_TTL) {
            $cached = json_decode(file_get_contents($file), true);
            if (is_array($cached)) return $cached;
        }

        $data = $this->fetch($path, $params);
        if (!empty($data)) {
            file_put_contents($file, json_encode($data));
        }
        return $data;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function search(string $query): array
    {
        $data = $this->cachedFetch('/api/search', ['keyword' => $query]);
        return $data['results']['data'] ?? [];
    }

    public function animeEpisodes(string $hiAnimeId): array
    {
        $data = $this->cachedFetch('/api/episodes/' . rawurlencode($hiAnimeId));
        return $data['results'] ?? [];
    }

    public function episodeSources(string $episodeId, string $category = 'sub', string $server = 'HD-1', bool $fallback = false): array
    {
        $endpoint = $fallback ? '/api/stream/fallback' : '/api/stream';
        $data = $this->fetch($endpoint, [
            'id'     => $episodeId,
            'server' => $server,
            'type'   => $category,
        ]);
        return $data['results'] ?? [];
    }

    /**
     * Find the best-matching HiAnime anime ID for a given title.
     *
     * When $year is provided the function first searches with the year-tagged
     * MAL title (e.g. "Hunter x Hunter (2011)") — HiAnime's search engine ranks
     * the correct version first even though it doesn't put the year in its titles.
     *
     * Priority:
     *   1. Exact match on year-tagged secondary title
     *   2. Year present in result title
     *   3. First non-OVA/special result from year-tagged search (HiAnime ranking)
     *   4. Exact English title match (year-agnostic)
     *   5. First English search result
     *   6. First Japanese/secondary title result
     */
    public function findAnimeId(string $titleEnglish, string $titleJapanese = '', int $year = 0): ?string
    {
        $englishLower = strtolower($titleEnglish);
        $yearStr      = $year > 0 ? (string)$year : '';
        $skipWords    = ['ova', 'special', 'movie', 'film', 'greed island', 'original video'];

        // ── Secondary title exact-match fast path ─────────────────────────────
        // The MAL "title" field (Japanese/romaji) often matches HiAnime's entry
        // title directly even when the English title doesn't — e.g.:
        //   MAL title  = "One Punch Man 2nd Season"
        //   HiAnime    = "One Punch Man 2nd Season"   ← exact match
        //   MAL english= "One-Punch Man Season 2"     ← would miss
        // Try this before any English title search.
        if ($titleJapanese && $titleJapanese !== $titleEnglish) {
            $jpLower = strtolower($titleJapanese);
            $results = $this->search($titleJapanese);
            foreach ($results as $result) {
                if (strtolower($result['title'] ?? '') === $jpLower) {
                    return $result['id'] ?? null;
                }
            }
        }

        // ── Year-aware fast path ──────────────────────────────────────────────
        if ($year > 0 && $titleJapanese && str_contains($titleJapanese, $yearStr)) {
            $results = $this->search($titleJapanese);

            // Exact match on year-tagged title
            $jpLower = strtolower($titleJapanese);
            foreach ($results as $result) {
                if (strtolower($result['title'] ?? '') === $jpLower) {
                    return $result['id'] ?? null;
                }
            }
            // Year present in result title
            foreach ($results as $result) {
                if (str_contains(strtolower($result['title'] ?? ''), $yearStr)) {
                    return $result['id'] ?? null;
                }
            }
            // First result whose title matches the base English title, skipping OVAs
            foreach ($results as $result) {
                $t = strtolower($result['title'] ?? '');
                if ($t !== $englishLower) continue;
                $skip = false;
                foreach ($skipWords as $sw) {
                    if (str_contains($t, $sw)) { $skip = true; break; }
                }
                if (!$skip) return $result['id'] ?? null;
            }
        }

        // ── Bare English title search ─────────────────────────────────────────
        $results = $this->search($titleEnglish);

        if ($year > 0) {
            $withYear = $englishLower . ' (' . $yearStr . ')';
            foreach ($results as $result) {
                if (strtolower($result['title'] ?? '') === $withYear) {
                    return $result['id'] ?? null;
                }
            }
            foreach ($results as $result) {
                $t = strtolower($result['title'] ?? '');
                if (str_contains($t, $englishLower) && str_contains($t, $yearStr)) {
                    return $result['id'] ?? null;
                }
            }
            $yearResults = $this->search($titleEnglish . ' ' . $yearStr);
            foreach ($yearResults as $result) {
                if (str_contains(strtolower($result['title'] ?? ''), $yearStr)) {
                    return $result['id'] ?? null;
                }
            }
        }

        // Exact English title match (year-agnostic)
        foreach ($results as $result) {
            if (strtolower($result['title'] ?? '') === $englishLower) {
                return $result['id'] ?? null;
            }
        }
        if (!empty($results)) {
            return $results[0]['id'] ?? null;
        }
        if ($titleJapanese && $titleJapanese !== $titleEnglish) {
            $results = $this->search($titleJapanese);
            if (!empty($results)) return $results[0]['id'] ?? null;
        }

        return null;
    }

    /**
     * Given a HiAnime ID and episode number, return the resolved stream payload.
     *
     * Episode matching:
     *   1. By episode_no field (relative numbering, e.g. 1-12)
     *   2. By array position ($episodeNo-1) for shows with absolute/continuous numbering
     *
     * Source fallback: HD-1 → HD-2 → /api/stream/fallback
     */
    private function getStreamFromId(string $hiAnimeId, int $episodeNo, string $category): ?array
    {
        $epData   = $this->animeEpisodes($hiAnimeId);
        $episodes = $epData['episodes'] ?? [];
        if (empty($episodes)) return null;

        // Match by episode_no value
        $targetEpisode = null;
        foreach ($episodes as $ep) {
            if ((int)($ep['episode_no'] ?? 0) === $episodeNo) {
                $targetEpisode = $ep;
                break;
            }
        }

        // Fallback: match by array position (handles absolute/continuous numbering)
        if (!$targetEpisode && $episodeNo >= 1 && isset($episodes[$episodeNo - 1])) {
            $targetEpisode = $episodes[$episodeNo - 1];
        }

        if (!$targetEpisode) return null;

        $episodeId = $targetEpisode['id'] ?? null;
        if (!$episodeId) return null;

        // Try HD-1 → HD-2 → fallback server
        $sources = $this->episodeSources($episodeId, $category, 'HD-1');
        if (empty($sources['streamingLink'])) {
            $sources = $this->episodeSources($episodeId, $category, 'HD-2');
        }
        if (empty($sources['streamingLink'])) {
            $sources = $this->episodeSources($episodeId, $category, 'HD-1', true);
        }

        $m3u8Url = $sources['streamingLink'][0]['link'] ?? null;
        if (!$m3u8Url) return null;

        $subtitles = [];
        foreach ($sources['tracks'] ?? [] as $track) {
            $kind  = strtolower($track['kind']  ?? 'subtitles');
            $label = strtolower($track['label'] ?? '');
            if ($kind === 'thumbnails' || str_contains($label, 'thumbnail')) continue;
            $subtitles[] = [
                'url'  => $track['file']  ?? $track['url'] ?? '',
                'lang' => $track['label'] ?? 'Unknown',
            ];
        }

        return [
            'm3u8'      => $m3u8Url,
            'headers'   => ['Referer' => 'https://megacloud.club/'],
            'subtitles' => $subtitles,
        ];
    }

    /**
     * Resolve a complete stream payload for an episode.
     *
     * Since each MAL entry = one HiAnime show, this is straightforward:
     * find the HiAnime ID for the title, look up episode N.
     *
     * $year disambiguates remakes that share the same English title
     * (e.g. "Hunter x Hunter" 1999 vs 2011).
     */
    public function resolveStream(
        string $titleEnglish,
        string $titleJapanese,
        int    $episodeNumber,
        string $category = 'sub',
        int    $year = 0
    ): ?array {
        $hiAnimeId = $this->findAnimeId($titleEnglish, $titleJapanese, $year);
        if (!$hiAnimeId) return null;

        return $this->getStreamFromId($hiAnimeId, $episodeNumber, $category);
    }
}
