/**
 * vidsrc-extractor — port 3031
 *
 * Attempts to extract a direct M3U8 stream URL for movies/TV via plain HTTP.
 * All major providers (vidsrc, cloudnestra, 2embed, etc.) now use Cloudflare
 * Turnstile or JS-obfuscated players, so this will usually fail and return
 * { success: false } — watch.php then falls back to the iframe embed player.
 *
 * Keeping this service running means any future provider that exposes a clean
 * HTTP API will be picked up automatically without changing watch.php.
 *
 * GET /extract?tmdb=ID&type=movie|tv[&season=S&episode=E]
 *   → { success: true,  stream: "https://...m3u8" }
 *   → { success: false, error: "No stream found" }
 *
 * GET /health → { ok: true }
 */

import express from 'express';
import axios   from 'axios';
import * as cheerio from 'cheerio';

const PORT = 3031;
const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function headers(referer = null) {
  const h = {
    'User-Agent':      UA,
    'Accept':          'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.5',
    'Sec-Fetch-Dest':  'iframe',
    'Sec-Fetch-Mode':  'navigate',
    'Sec-Fetch-Site':  'cross-site',
  };
  if (referer) h['Referer'] = referer;
  return h;
}

// ── Embed domains to try ──────────────────────────────────────────────────────
const EMBED_DOMAINS = [
  'vidsrc.net',
  'vidsrc.me',
  'vidsrc-embed.ru',
  'vidsrcme.ru',
];

// ── Parse server hashes from embed page HTML ─────────────────────────────────
function extractHashes(html) {
  const hashes = new Set();
  const $ = cheerio.load(html);
  $('[data-hash]').each((_, el) => {
    const h = $(el).attr('data-hash');
    if (h && h.length > 30) hashes.add(h);
  });
  for (const m of html.matchAll(/(?:cloudnestra\.com|whisperingauroras\.com)\/(?:p)?rcp\/([a-zA-Z0-9_-]{30,})/g)) {
    hashes.add(m[1]);
  }
  return [...hashes];
}

// ── Try to decode a hash directly (works only if not Cloudflare-locked) ───────
function tryDecode(hash) {
  try {
    const norm    = hash.replace(/-/g, '+').replace(/_/g, '/');
    const decoded = Buffer.from(norm, 'base64').toString('utf8');
    if (decoded.includes('\x00')) return null;
    const m3u8 = decoded.match(/https?:\/\/[^\s"'<>\\]+\.m3u8[^\s"'<>\\]*/);
    if (m3u8) return m3u8[0];
    const idx = decoded.indexOf(':');
    if (idx === -1) return null;
    const payload = decoded.substring(idx + 1).trim();
    // Multi-pass base64 decode
    let cur = payload;
    for (let i = 0; i < 4; i++) {
      try {
        const next = Buffer.from(cur.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
        if (!next || next.includes('\x00')) break;
        const u = next.match(/https?:\/\/[^\s"'<>\\]+\.m3u8/);
        if (u) return u[0];
        if (next.startsWith('http')) return next.split(/[\s"'<>]/)[0];
        cur = next;
      } catch { break; }
    }
    return null;
  } catch { return null; }
}

// ── Main extractor ────────────────────────────────────────────────────────────
async function extractStream(tmdbId, type, season, episode) {
  for (const domain of EMBED_DOMAINS) {
    const embedUrl = type === 'movie'
      ? `https://${domain}/embed/movie?tmdb=${tmdbId}`
      : `https://${domain}/embed/tv?tmdb=${tmdbId}&season=${season}&episode=${episode}`;

    console.log(`[extractor] Trying ${embedUrl}`);
    let html;
    try {
      const r = await axios.get(embedUrl, {
        headers: headers(),
        timeout: 8000,
        validateStatus: s => s === 200,
      });
      html = String(r.data);
    } catch (e) {
      console.warn(`[extractor] ${domain}: ${e.message}`);
      continue;
    }

    // Direct M3U8 in page (rare but check)
    const direct = html.match(/https?:\/\/[^\s"'<>\\]+\.m3u8[^\s"'<>\\]*/);
    if (direct) { console.log('[extractor] Direct M3U8 in page'); return direct[0]; }

    // Try hash decode (works before Cloudflare lock was added; kept for future)
    const hashes = extractHashes(html);
    console.log(`[extractor] ${domain}: ${hashes.length} hash(es)`);
    for (const h of hashes) {
      const url = tryDecode(h);
      if (url) { console.log('[extractor] Decoded from hash'); return url; }
    }

    // cloudnestra.com blocks all plain HTTP — no point trying RCP endpoints
    console.log(`[extractor] ${domain}: no stream (provider uses Cloudflare)`);
  }
  return null;
}

// ── Express ───────────────────────────────────────────────────────────────────
const app = express();

app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'vidsrc-extractor', port: PORT });
});

app.get('/extract', async (req, res) => {
  const { tmdb, type, season = '1', episode = '1' } = req.query;

  if (!tmdb || !['movie', 'tv'].includes(type)) {
    return res.status(400).json({ success: false, error: 'Required: tmdb (int), type (movie|tv)' });
  }

  console.log(`[extractor] tmdb=${tmdb} type=${type} s=${season} e=${episode}`);

  try {
    const stream = await extractStream(
      String(tmdb),
      String(type),
      parseInt(season,  10) || 1,
      parseInt(episode, 10) || 1,
    );
    if (stream) return res.json({ success: true, stream });
    return res.json({ success: false, error: 'No stream found — provider uses Cloudflare protection' });
  } catch (e) {
    console.error('[extractor] Error:', e.message);
    return res.status(500).json({ success: false, error: e.message });
  }
});

app.listen(PORT, () => {
  console.log(`[vidsrc-extractor] Listening on port ${PORT}`);
  console.log(`[vidsrc-extractor] Iframe fallback: moviesapi.to is primary, vidsrc is secondary`);
});
