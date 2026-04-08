import axios from "axios";
import * as cheerio from "cheerio";
import { v1_base_url } from "../utils/base_v1.js";
// import decryptMegacloud from "../parsers/decryptors/megacloud.decryptor.js";
// import AniplayExtractor from "../parsers/aniplay.parser.js";
import { decryptSources_v1 } from "../parsers/decryptors/decrypt_v1.decryptor.js";

export async function extractServers(id) {
  try {
    const resp = await axios.get(
      `https://${v1_base_url}/ajax/v2/episode/servers?episodeId=${id}`
    );
    const $ = cheerio.load(resp.data.html);
    const serverData = [];
    $(".server-item").each((index, element) => {
      const data_id = $(element).attr("data-id");
      const server_id = $(element).attr("data-server-id");
      const type = $(element).attr("data-type");

      let serverName = $(element).find("a").text().trim();

      // Backward compatibility mapping
      switch (serverName.toLowerCase()) {
        case "megacloud":
        case "rapidcloud":
          serverName = "HD-1";
          break;
        case "vidsrc":
        case "vidstreaming":
          serverName = "HD-2";
          break;
        case "t-cloud":
          serverName = "HD-3";
          break;
      }

      serverData.push({
        type,
        data_id,
        server_id,
        serverName,
      });
    });
    return serverData;
  } catch (error) {
    console.log(error);
    return [];
  }
}

async function extractStreamingInfo(id, name, type, fallback) {
  try {
    const servers = await extractServers(id.split("?ep=").pop());

    // 1. Try exact match
    let requestedServer = servers.filter(
      (server) =>
        server.serverName.toLowerCase() === name.toLowerCase() &&
        server.type.toLowerCase() === type.toLowerCase()
    );

    // 2. Try 'raw' type if 'sub'/'dub' fails but name matches
    if (requestedServer.length === 0) {
      requestedServer = servers.filter(
        (server) =>
          server.serverName.toLowerCase() === name.toLowerCase() &&
          server.type.toLowerCase() === "raw"
      );
    }

    // 3. Try to find any server of the same type (Fallback for name changes like 'hd-1' -> 'VidSrc')
    if (requestedServer.length === 0) {
        requestedServer = servers.filter(
          (server) => server.type.toLowerCase() === type.toLowerCase()
        );
    }

    // 4. If still nothing, take the first available server
    if (requestedServer.length === 0 && servers.length > 0) {
        requestedServer = [servers[0]];
    }

    if (requestedServer.length === 0) {
      throw new Error(
        `No matching server found for name: ${name}, type: ${type}`
      );
    }

    const streamingLink = await decryptSources_v1(
      id,
      requestedServer[0].data_id,
      requestedServer[0].serverName, // Use the actual server name found
      requestedServer[0].type,       // Use the actual type found
      fallback
    );

    if (!streamingLink) {
      return { streamingLink: [], servers };
    }

    return {
      streamingLink: [
        {
          link: streamingLink.link.file,
          type: streamingLink.link.type,
          server: streamingLink.server,
          iframe: streamingLink.iframe,
        },
      ],
      tracks: streamingLink.tracks,
      intro: streamingLink.intro,
      outro: streamingLink.outro,
      server: streamingLink.server,
      servers,
    };
  } catch (error) {
    console.error("An error occurred:", error);
    return { streamingLink: [], servers: [] };
  }
}
export { extractStreamingInfo };
