import { readFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";
const _dir = dirname(fileURLToPath(import.meta.url));
let _cfg = {};
try { _cfg = JSON.parse(readFileSync(join(_dir, "../../../config.json"), "utf8")); } catch {}
export const fallback_1 = _cfg.fallback_1 || "megaplay.buzz";
export const fallback_2 = _cfg.fallback_2 || "vidwish.live";
