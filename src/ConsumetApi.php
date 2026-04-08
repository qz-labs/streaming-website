<?php
declare(strict_types=1);

/**
 * HiAnime API wrapper (JustAnimeCore/HiAnime-Api).
 *
 * Self-hosted Node.js scraper that does NOT depend on the DMCA'd consumet.ts.
 * Source: https://github.com/JustAnimeCore/HiAnime-Api
 * Setup:  cd services/hianime-api && npm install && npm start  (port 4444)
 *
 * Set CONSUMET_URL=http://localhost:4444 in .env
 *
 * Endpoint reference:
 *   Search   : GET /api/search?keyword={query}
 *   Episodes : GET /api/episodes/{animeId}
 *   Stream   : GET /api/stream?id={episodeId}&server=HD-1&type=sub|dub
 *
 * Response shapes:
 *   Search   : { data: [{ id, title, japanese_title, ... }], totalPage }
 *   Episodes : { totalEpisodes, episodes: [{ episode_no, id, title, filler }] }
 *   Stream   : { streamingLink: [{ link, type, server }], tracks: [...] }
 *
 * Metadata calls are file-cached. Stream calls are NEVER cached (expiring tokens).
 */
class ConsumetApi
{
    private string $base;

    public function __construct()
    {
        $this->base = CONSUMET_URL;  // http://localhost:4444
    }

    // ── Internal HTTP ─────────────────────────────────────────────────────────

    private function fetch(string $path, array $params = []): array
    {
        $url = $this->base . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: StreamFlix/1.0'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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

        if (file_exists($file) && (time() - filemtime($file)) < CACHE_TTL) {
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

    /**
     * Search HiAnime for an anime by title.
     * Returns: [['id' => 'one-piece-100', 'title' => '...', 'japanese_title' => '...'], ...]
     */
    public function search(string $query): array
    {
        // Response shape: { success, results: { data: [...], totalPage } }
        $data = $this->cachedFetch('/api/search', ['keyword' => $query]);
        return $data['results']['data'] ?? [];
    }

    /**
     * Get the full episode list for a HiAnime anime ID (e.g. "one-piece-100").
     * Returns: ['totalEpisodes' => N, 'episodes' => [['episode_no' => 1, 'id' => 'one-piece-100?ep=107149', ...]]]
     */
    public function animeEpisodes(string $hiAnimeId): array
    {
        // Response shape: { success, results: { totalEpisodes, episodes: [...] } }
        $data = $this->cachedFetch('/api/episodes/' . rawurlencode($hiAnimeId));
        return $data['results'] ?? [];
    }

    /**
     * Get streaming sources for a specific episode.
     * NOT cached — URLs contain expiring tokens.
     *
     * $episodeId : full episode ID, e.g. "one-piece-100?ep=107149"
     * $category  : 'sub' | 'dub'
     * $server    : 'HD-1' (Megacloud) | 'HD-2' (VidStreaming) | 'HD-3'
     *
     * Returns: { streamingLink: [{ link: '...m3u8', type: 'hls' }], tracks: [...] }
     */
    public function episodeSources(string $episodeId, string $category = 'sub', string $server = 'HD-1'): array
    {
        // Response shape: { success, results: { streamingLink, tracks, servers, ... } }
        $data = $this->fetch('/api/stream', [
            'id'     => $episodeId,
            'server' => $server,
            'type'   => $category,
        ]);
        return $data['results'] ?? [];
    }

    /**
     * Find the best-matching HiAnime anime ID for a given title.
     * Tries English title first, falls back to Japanese.
     */
    public function findAnimeId(string $titleEnglish, string $titleJapanese = ''): ?string
    {
        $results = $this->search($titleEnglish);

        // Prefer exact English title match
        $englishLower = strtolower($titleEnglish);
        foreach ($results as $result) {
            if (strtolower($result['title'] ?? '') === $englishLower) {
                return $result['id'] ?? null;
            }
        }

        // Fall back to first result from English search
        if (!empty($results)) {
            return $results[0]['id'] ?? null;
        }

        // Try Japanese title if English search returned nothing
        if ($titleJapanese && $titleJapanese !== $titleEnglish) {
            $results = $this->search($titleJapanese);
            if (!empty($results)) {
                return $results[0]['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * High-level: resolve a complete stream payload for an episode.
     *
     * Return shape on success:
     * [
     *   'm3u8'      => 'https://...master.m3u8',
     *   'headers'   => ['Referer' => '...'],
     *   'subtitles' => [['url' => '...vtt', 'lang' => 'English'], ...],
     * ]
     */
    public function resolveStream(
        string $titleEnglish,
        string $titleJapanese,
        int    $episodeNumber,
        string $category = 'sub'
    ): ?array {
        $hiAnimeId = $this->findAnimeId($titleEnglish, $titleJapanese);
        if (!$hiAnimeId) return null;

        $epData   = $this->animeEpisodes($hiAnimeId);
        $episodes = $epData['episodes'] ?? [];
        if (empty($episodes)) return null;

        // Find the episode matching the requested number
        $targetEpisode = null;
        foreach ($episodes as $ep) {
            if ((int)($ep['episode_no'] ?? 0) === $episodeNumber) {
                $targetEpisode = $ep;
                break;
            }
        }
        if (!$targetEpisode) return null;

        // Episode ID is the full "one-piece-100?ep=107149" string
        $episodeId = $targetEpisode['id'] ?? null;
        if (!$episodeId) return null;

        // Try HD-1 first, fall back to HD-2 if no stream is returned
        $sources = $this->episodeSources($episodeId, $category, 'HD-1');
        if (empty($sources['streamingLink'])) {
            $sources = $this->episodeSources($episodeId, $category, 'HD-2');
        }

        $streamingLinks = $sources['streamingLink'] ?? [];
        if (empty($streamingLinks)) return null;

        $m3u8Url = $streamingLinks[0]['link'] ?? null;
        if (!$m3u8Url) return null;

        // Normalize subtitle tracks — skip sprite thumbnail tracks
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
            // HiAnime streams go through Megacloud CDN — this referer is required
            'headers'   => ['Referer' => 'https://megacloud.club/'],
            'subtitles' => $subtitles,
        ];
    }
}
