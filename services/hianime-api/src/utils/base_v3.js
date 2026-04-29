import { readFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";
const _dir = dirname(fileURLToPath(import.meta.url));
let _cfg = {};
try { _cfg = JSON.parse(readFileSync(join(_dir, "../../../config.json"), "utf8")); } catch {}
export const v3_base_url = _cfg.v3_base_url || "aniplay.lol";
