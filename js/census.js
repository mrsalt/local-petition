// Get census areas (with boundaries) intersecting a lat/lon rectangle, plus population from ACS.
async function getCensusAreasWithPopulation(bbox, {
  geography = 'tract',          // 'county' | 'tract' | 'block group'
  year = 2023,                  // ACS vintage (e.g., 2023)
  dataset = 'acs/acs5',         // ACS 5-year
  variable = 'B01003_001E',     // Total population
  censusApiKey,                 // Your Census API key (string)
  includeGeoJSON = true,        // Convert ESRI geometry to simple GeoJSON (best-effort)
  tigerServiceUrl = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer'
} = {}) {
  if (!censusApiKey) throw new Error('censusApiKey is required');

  // 1) Resolve TIGER layer ID by geography name
  const layerId = await findLayerIdByGeography(tigerServiceUrl, geography);
  if (layerId == null) throw new Error(`Could not find TIGERweb layer for geography "${geography}"`);

  // 2) Query features intersecting the bounding box (with pagination)
  const fields = ['GEOID', 'NAME', 'STATE', 'COUNTY', 'TRACT', 'BLKGRP', 'ALAND', 'AWATER'];
  const features = await queryFeaturesByBBox(tigerServiceUrl, layerId, bbox, fields);

  // 3) Fetch population for these features from the Census Data API
  const popByGEOID = await fetchPopulationByFeatures(features, {
    geography, year, dataset, variable, censusApiKey
  });

  // 4) Assemble result
  return features.map(f => {
    const attrs = f.attributes || {};
    const geojson = includeGeoJSON ? esriPolygonToGeoJSON(f.geometry) : null;
    const geoid = attrs.GEOID;
    return {
      geoid,
      name: attrs.NAME,
      geography,
      population: popByGEOID.get(geoid) ?? null,
      areaLandMeters2: (attrs.ALAND != null ? Number(attrs.ALAND) : null),
      areaWaterMeters2: (attrs.AWATER != null ? Number(attrs.AWATER) : null),
      geometry: includeGeoJSON ? geojson : f.geometry // GeoJSON or ESRI polygon
    };
  });
}

// ---- TIGERweb helpers ----

async function findLayerIdByGeography(serviceUrl, geography) {
  const res = await fetch(`${serviceUrl}?f=pjson`);
  if (!res.ok) throw new Error(`Failed to load TIGER service metadata: ${res.status}`);
  const meta = await res.json();

  // Try to find the best matching layer name
  const patterns = {
    'county': /Counties/i,
    'tract': /Census Tracts/i,
    'block group': /Census Block Groups/i
  };
  const rx = patterns[geography.toLowerCase()];
  if (!rx) throw new Error(`Unsupported geography: ${geography}`);

  const layers = meta.layers || [];
  // Prefer non-group layers (those without subLayerIds) that match by name
  const candidates = layers.filter(l => rx.test(l.name) && (l.subLayerIds == null || l.subLayerIds.length === 0));
  if (candidates.length === 0) return null;

  // Some services include multiple vintages; pick the one marked as 'Current' or last (heuristic)
  // Here we just pick the first match.
  return candidates[0].id;
}

async function queryFeaturesByBBox(serviceUrl, layerId, bbox, outFieldNames) {
  const [minLon, minLat, maxLon, maxLat] = bbox;
  const geometry = {
    xmin: minLon, ymin: minLat, xmax: maxLon, ymax: maxLat,
    spatialReference: { wkid: 4326 }
  };
  // Get layer details for pagination support
  const layerInfo = await (await fetch(`${serviceUrl}/${layerId}?f=pjson`)).json();
  const pageSize = Math.min(1000, layerInfo.maxRecordCount || 1000);

  const where = '1=1';
  const outFields = outFieldNames.join(',');

  let resultOffset = 0;
  const all = [];

  while (true) {
    const params = new URLSearchParams({
      f: 'json',
      where,
      outFields,
      returnGeometry: 'true',
      outSR: '4326',
      geometry: JSON.stringify(geometry),
      geometryType: 'esriGeometryEnvelope',
      spatialRel: 'esriSpatialRelIntersects',
      resultOffset: String(resultOffset),
      resultRecordCount: String(pageSize)
    });

    const url = `${serviceUrl}/${layerId}/query?${params.toString()}`;
    const res = await fetch(url);
    if (!res.ok) throw new Error(`TIGER query failed: ${res.status}`);
    const json = await res.json();

    const features = json.features || [];
    all.push(...features);

    const exceeded = json.exceededTransferLimit === true;
    if (!exceeded || features.length === 0) break;
    resultOffset += features.length;
  }
  return all;
}

// ---- Census Data API population fetch ----

async function fetchPopulationByFeatures(features, { geography, year, dataset, variable, censusApiKey }) {
  // Extract minimal keys for batching
  const each = f => f.attributes || {};
  const uniq = (arr) => Array.from(new Set(arr));

  const attrs = features.map(each);
  const states = uniq(attrs.map(a => a.STATE).filter(Boolean));
  const countiesByState = new Map();

  if (geography === 'county') {
    // Group counties by state for batch fetch
    for (const a of attrs) {
      if (!a.STATE || !a.COUNTY) continue;
      const key = a.STATE;
      if (!countiesByState.has(key)) countiesByState.set(key, new Set());
      countiesByState.get(key).add(a.COUNTY);
    }
  } else if (geography === 'tract' || geography === 'block group') {
    // Group tracts by (state, county)
    for (const a of attrs) {
      if (!a.STATE || !a.COUNTY) continue;
      const key = `${a.STATE}-${a.COUNTY}`;
      if (!countiesByState.has(key)) countiesByState.set(key, new Set());
      countiesByState.get(key).add(a.TRACT);
    }
  }

  const popMap = new Map();

  if (geography === 'county') {
    // For each state, request all counties, then filter
    for (const state of states) {
      const url = `https://api.census.gov/data/${year}/${dataset}?get=NAME,${variable}&for=county:*&in=state:${state}&key=${encodeURIComponent(censusApiKey)}`;
      const rows = await fetchJSON(url);
      const [header, ...data] = rows;
      const idx = indexMap(header);
      const wanted = countiesByState.get(state) || new Set();
      for (const r of data) {
        if (!wanted.has(r[idx.county])) continue;
        const geoid = `US${state}${r[idx.county]}`; // We'll normalize to match TIGER GEOID below
        popMap.set(geoidNormalize(geoid, 'county'), Number(r[idx[variable]]));
      }
    }
  } else if (geography === 'tract') {
    // For each (state, county): all tracts, then filter to those we have
    for (const key of countiesByState.keys()) {
      const [state, county] = key.split('-');
      const url = `https://api.census.gov/data/${year}/${dataset}?get=NAME,${variable}&for=tract:*&in=state:${state}+county:${county}&key=${encodeURIComponent(censusApiKey)}`;
      const rows = await fetchJSON(url);
      const [header, ...data] = rows;
      const idx = indexMap(header);
      const tractsWanted = countiesByState.get(key) || new Set();
      for (const r of data) {
        if (!tractsWanted.has(r[idx.tract])) continue;
        const geoid = `US${state}${county}${r[idx.tract]}`;
        popMap.set(geoidNormalize(geoid, 'tract'), Number(r[idx[variable]]));
      }
    }
  } else if (geography === 'block group') {
    // For each (state, county), we must query block groups by tract
    for (const key of countiesByState.keys()) {
      const [state, county] = key.split('-');
      const tractsWanted = Array.from(countiesByState.get(key) || []);
      // Build a set of GEOIDs we actually need to fill
      const neededByTract = new Map();
      for (const a of attrs) {
        if (a.STATE === state && a.COUNTY === county && a.TRACT) {
          const tract = a.TRACT;
          if (!neededByTract.has(tract)) neededByTract.set(tract, new Set());
          if (a.BLKGRP) neededByTract.get(tract).add(a.BLKGRP);
        }
      }
      for (const tract of tractsWanted) {
        const url = `https://api.census.gov/data/${year}/${dataset}?get=NAME,${variable}&for=block%20group:*&in=state:${state}+county:${county}+tract:${tract}&key=${encodeURIComponent(censusApiKey)}`;
        const rows = await fetchJSON(url);
        const [header, ...data] = rows;
        const idx = indexMap(header);
        const groupsWanted = neededByTract.get(tract) || new Set();
        for (const r of data) {
          if (!groupsWanted.has(r[idx['block group']])) continue;
          const geoid = `US${state}${county}${tract}${r[idx['block group']]}`;
          popMap.set(geoidNormalize(geoid, 'block group'), Number(r[idx[variable]]));
        }
      }
    }
  } else {
    throw new Error(`Unsupported geography for population fetch: ${geography}`);
  }

  // Normalize keys from TIGER ESA GEOID forms to the same form used above
  const result = new Map();
  for (const f of features) {
    const a = f.attributes || {};
    const geoid = a.GEOID;
    result.set(geoid, popMap.get(geoidNormalize(geoid, geography)) ?? null);
  }
  return result;
}

function geoidNormalize(geoid, geography) {
  // Normalize to 2+3+6+1 codes where applicable and prefix 'US' not strictly needed.
  // We’ll simply strip any non-digits and keep the canonical lengths.
  const digits = (geoid || '').replace(/\D/g, '');
  if (geography === 'county') {
    // state(2) + county(3)
    return digits.slice(0, 5);
  } else if (geography === 'tract') {
    // state(2) + county(3) + tract(6)
    return digits.slice(0, 11);
  } else if (geography === 'block group') {
    // state(2) + county(3) + tract(6) + blkgrp(1)
    return digits.slice(0, 12);
  }
  return digits;
}

// ---- Utilities ----

async function fetchJSON(url) {
  const res = await fetch(url);
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Request failed ${res.status}: ${url}\n${text}`);
  }
  return res.json();
}

function indexMap(headerRow) {
  const map = {};
  headerRow.forEach((h, i) => map[h] = i);
  return new Proxy(map, {
    get: (obj, key) => obj[key] ?? obj[String(key)]
  });
}

// Best-effort ESRI polygon -> GeoJSON converter (handles most single-part polygons)
function esriPolygonToGeoJSON(esriGeom) {
  if (!esriGeom || !Array.isArray(esriGeom.rings)) return null;
  // Naive grouping: treat all outer rings and holes together as a MultiPolygon with each ring as a linear ring set.
  // For many TIGER polygons, rings already come grouped per part.
  const rings = esriGeom.rings.map(ring =>
    ring.map(([x, y]) => [x, y])
  );
  // Heuristic: if multiple rings likely form parts, wrap each outer ring separately.
  // We’ll return a MultiPolygon where each ring is its own polygon unless it’s a hole (orientation CW vs CCW).
  // Orientation check:
  const polys = [];
  const outers = [];
  const holes = [];
  for (const r of rings) {
    const area2 = signedRingArea(r);
    if (area2 < 0) outers.push(r); else holes.push(r); // ESRI/GeoJSON outer typically CCW; adjust if needed
  }
  if (outers.length === 0) {
    // Fallback: treat every ring as its own polygon
    return { type: 'MultiPolygon', coordinates: rings.map(r => [r]) };
  }
  // Assign holes to nearest outer (very naive)
  const groups = outers.map(o => ({ outer: o, holes: [] }));
  for (const h of holes) {
    // put all holes into the first outer (approx)
    groups[0].holes.push(h);
  }
  // Build MultiPolygon
  const coords = groups.map(g => [g.outer, ...g.holes]);
  return groups.length === 1
    ? { type: 'Polygon', coordinates: coords }
    : { type: 'MultiPolygon', coordinates: coords.map(rings => [rings[0], ...rings.slice(1)]) };
}

function signedRingArea(ring) {
  let sum = 0;
  for (let i = 0; i < ring.length - 1; i++) {
    const [x1, y1] = ring[i];
    const [x2, y2] = ring[i + 1];
    sum += (x2 - x1) * (y2 + y1);
  }
  return sum;
}

// ---- Example usage ----

async function example() {
  // A rectangle around Boise, ID
  const bbox = [-116.35, 43.50, -116.05, 43.70]; // [minLon, minLat, maxLon, maxLat]

  const results = await getCensusAreasWithPopulation(bbox, {
    geography: 'tract',         // try 'county' or 'block group' as well
    year: 2023,
    dataset: 'acs/acs5',
    variable: 'B01003_001E',
    censusApiKey: process.env.CENSUS_API_KEY,
    includeGeoJSON: true
  });

  console.log(`Found ${results.length} tracts:`);
  for (const r of results) {
    console.log(`${r.geoid} | ${r.name} | population=${r.population}`);
  }
}

// Only run the example if this file is executed directly (Node)
if (typeof module !== 'undefined' && require.main === module) {
  example().catch(err => {
    console.error(err);
    process.exit(1);
  });
}