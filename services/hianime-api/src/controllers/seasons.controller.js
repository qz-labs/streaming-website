import extractSeasons from "../extractors/seasons.extractor.js";

export const getSeasons = async (req) => {
  const { id } = req.params;
  try {
    const seasons = await extractSeasons(decodeURIComponent(id));
    return seasons || [];
  } catch (e) {
    console.error("Error fetching seasons:", e);
    return [];
  }
};
