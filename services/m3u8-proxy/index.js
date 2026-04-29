import https from "node:https";
import http from "node:http";
import express from 'express';

const httpUtils = {
    userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
};

// ── Allowed proxy domains (whitelist) ─────────────────────────────────────────
// Only domains in this list will be proxied. Add CDN/streaming hosts as needed.
const ALLOWED_DOMAINS = [
    'megacloud.club',
    'megacloud.tv',
    'www.megacloud.tv',
    'hianime.to',
    'aniwatch.to',
    'aniwatchtv.to',
    'vidstreamingcdn.com',
    'netmagcdn.com',
    'vod.netmagcdn.com',
    'mgstatics.xyz',
    's3.amazonaws.com',
    'akamai.net',
    'fastly.net',
    'cloudfront.net',
    'r2.cloudflarestorage.com',
    // vidsrc stream CDN hosts
    'whisperingauroras.com',
    'rcp.vidsrc.net',
    'vidplay.online',
    'vidplay.site',
    'vidsrc.stream',
    'vidcloud9.com',
    'filemoon.sx',
    'filemoon.to',
];

// Suffix-match helper — allows subdomains of known CDN hosts
const ALLOWED_SUFFIXES = [
    '.megacloud.club',
    '.megacloud.tv',
    '.hianime.to',
    '.aniwatch.to',
    '.aniwatchtv.to',
    '.netmagcdn.com',
    '.mgstatics.xyz',
    '.akamaized.net',
    '.akamaistream.net',
    '.cloudfront.net',
    '.fastly.net',
    '.r2.dev',
    // vidsrc CDN suffixes
    '.whisperingauroras.com',
    '.vidplay.online',
    '.vidplay.site',
    '.vidsrc.stream',
    '.vidcloud9.com',
    '.filemoon.sx',
    '.filemoon.to',
];

function isAllowedHost(hostname) {
    if (ALLOWED_DOMAINS.includes(hostname)) return true;
    return ALLOWED_SUFFIXES.some(suffix => hostname.endsWith(suffix));
}

// ── In-memory m3u8 manifest cache (5-minute TTL) ─────────────────────────────
const m3u8Cache = new Map();
const M3U8_CACHE_TTL = 5 * 60 * 1000; // 5 minutes

function getCachedM3u8(key) {
    const entry = m3u8Cache.get(key);
    if (!entry) return null;
    if (Date.now() - entry.ts > M3U8_CACHE_TTL) { m3u8Cache.delete(key); return null; }
    return entry.value;
}

function setCachedM3u8(key, value) {
    // Evict oldest entries if cache grows too large
    if (m3u8Cache.size > 200) {
        const oldest = m3u8Cache.keys().next().value;
        m3u8Cache.delete(oldest);
    }
    m3u8Cache.set(key, { value, ts: Date.now() });
}

// ── App ───────────────────────────────────────────────────────────────────────
const app = express();
const PORT = 3030;

app.listen(PORT, () => {
    console.log("Server Listening on PORT:", PORT);
});

app.get("/m3u8-proxy", async (req, res) => {
    let responseSent = false;

    const safeSendResponse = (statusCode, data) => {
        try {
            if (!responseSent) {
                responseSent = true;
                res.status(statusCode).send(data);
            }
        } catch (_) {}
    };

    try {
        // ── Parse & validate URL ──────────────────────────────────────────────
        let url;
        try {
            url = new URL(req.query.url);
        } catch {
            return safeSendResponse(400, { message: "Invalid URL" });
        }

        if (!['http:', 'https:'].includes(url.protocol)) {
            return safeSendResponse(400, { message: "Only HTTP/HTTPS URLs are allowed" });
        }

        if (!isAllowedHost(url.hostname)) {
            console.warn(`[m3u8-proxy] Blocked disallowed host: ${url.hostname}`);
            return safeSendResponse(403, { message: "Host not in allowlist" });
        }

        // ── Parse headers safely ──────────────────────────────────────────────
        const headersParam = decodeURIComponent(req.query.headers || "");
        const headers = { "User-Agent": httpUtils.userAgent };

        if (headersParam) {
            try {
                const additionalHeaders = JSON.parse(headersParam);
                const blocked = ["Access-Control-Allow-Origin", "Access-Control-Allow-Methods", "Access-Control-Allow-Headers"];
                Object.entries(additionalHeaders).forEach(([key, value]) => {
                    if (!blocked.includes(key)) headers[key] = value;
                });
            } catch (e) {
                console.warn("[m3u8-proxy] Failed to parse headers param:", e.message);
                // Continue without extra headers rather than crashing
            }
        }

        // ── Fetch target ──────────────────────────────────────────────────────
        // SSL is always enforced (no NODE_TLS_REJECT_UNAUTHORIZED = "0")
        const targetResponse = await fetch(url, { headers });

        // ── m3u8 manifest ─────────────────────────────────────────────────────
        const contentType = targetResponse.headers.get('content-type') || '';
        if (url.pathname.endsWith(".m3u8") || contentType.includes("mpegURL")) {
            const cacheKey = url.toString();
            let modifiedM3u8 = getCachedM3u8(cacheKey);

            if (!modifiedM3u8) {
                const rawM3u8 = await targetResponse.text();
                const base    = `${url.origin}${url.pathname.replace(/[^/]+\.m3u8$/, "").trim()}`;

                modifiedM3u8 = rawM3u8.split("\n").map((line) => {
                    if (line.startsWith("#EXT-X-KEY")) {
                        const match = line.match(/(URI=")([^"]+)(")/);
                        if (match) {
                            const proxied = `/m3u8-proxy?url=${encodeURIComponent(match[2])}${headersParam ? `&headers=${encodeURIComponent(headersParam)}` : ""}`;
                            return line.replace(match[2], proxied);
                        }
                    }
                    if (line.startsWith("#") || line.trim() === '') return line;

                    let finalUrl;
                    if (line.startsWith("http://") || line.startsWith("https://")) {
                        finalUrl = line;
                    } else if (line.startsWith('/')) {
                        finalUrl = base.endsWith('/') ? `${base}${line.replace('/', '')}` : `${base}/${line.replace('/', '')}`;
                    } else {
                        finalUrl = base.endsWith('/') ? `${base}${line}` : `${base}/${line}`;
                    }
                    return `/m3u8-proxy?url=${encodeURIComponent(finalUrl)}${headersParam ? `&headers=${encodeURIComponent(headersParam)}` : ""}`;
                }).join("\n");

                setCachedM3u8(cacheKey, modifiedM3u8);
            }

            return res.status(200)
                .set('Access-Control-Allow-Origin', '*')
                .set('Content-Type', contentType || "application/vnd.apple.mpegurl")
                .send(modifiedM3u8);
        }

        // ── Encryption key ────────────────────────────────────────────────────
        if (url.pathname.endsWith(".key")) {
            const keyData = await targetResponse.arrayBuffer();
            res.setHeader("Content-Type", targetResponse.headers.get("Content-Type") || "application/octet-stream");
            res.setHeader("Content-Length", targetResponse.headers.get("Content-Length") || 0);
            res.setHeader("Access-Control-Allow-Origin", "*");
            return safeSendResponse(200, Buffer.from(keyData));
        }

        // ── Video segments / mp4 ──────────────────────────────────────────────
        if (url.pathname.includes('videos') || url.pathname.endsWith(".ts") || url.pathname.endsWith(".mp4") || contentType.includes("video")) {
            const useHttps = url.protocol === 'https:';
            const uri = new URL(url);

            const options = {
                hostname: uri.hostname,
                port: uri.port || (useHttps ? 443 : 80),
                path: uri.pathname + uri.search,
                method: req.method,
                headers,
                timeout: 10000,
            };

            try {
                const transport = useHttps ? https : http;
                const proxy = transport.request(options, (r) => {
                    if (url.pathname.endsWith(".mp4")) {
                        r.headers["content-type"] = "video/mp4";
                        r.headers["accept-ranges"] = "bytes";
                        const fileName = req.query.filename;
                        if (fileName) r.headers['content-disposition'] = `attachment; filename="${fileName}.mp4"`;
                    } else {
                        r.headers["content-type"] = "video/mp2t";
                    }
                    r.headers["Access-Control-Allow-Origin"] = "*";
                    res.writeHead(r.statusCode ?? 200, r.headers);
                    r.pipe(res, { end: true });
                });

                proxy.on('timeout', () => {
                    proxy.destroy();
                    safeSendResponse(504, { message: "Request timed out." });
                });

                proxy.on('error', (err) => {
                    console.error('Proxy request error:', err.message);
                    safeSendResponse(500, { message: "Proxy failed.", error: err.message });
                });

                req.pipe(proxy, { end: true });
            } catch (e) {
                safeSendResponse(500, { message: e.message });
            }
            return;
        }

        // ── VTT subtitles and other text responses ────────────────────────────
        res.setHeader("Content-Type", contentType || "text/plain");
        res.setHeader("Content-Length", targetResponse.headers.get("Content-Length") || 0);
        res.setHeader("Access-Control-Allow-Origin", "*");
        safeSendResponse(200, await targetResponse.text());

    } catch (e) {
        console.error("[m3u8-proxy] Unhandled error:", e.message);
        safeSendResponse(500, { message: e.message });
    }
});
