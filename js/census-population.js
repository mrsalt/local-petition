"use strict";

// Estimates the population inside map shapes (circles or polygons) from US
// Census block groups.  Block group outlines come from the Census TIGERweb
// service; the numbers come from our server (lp_census_population), which
// holds the Census API key.  A block group that is only partly inside a shape
// contributes its value multiplied by the fraction of its area inside.
//
// Can also count only people inside one city, using the city's TIGERweb
// outline ("place") and the polygon-clipping library.
//
// Requires boundary-calculator.js (project/unproject) and, for city filtering,
// polygon-clipping (global polygonClipping).

const CENSUS_DATASETS = [
    { id: 'dec2020_population', label: 'Total population (2020 Census)' },
    { id: 'acs2023_population', label: 'Total population (ACS 2019-2023 estimate)' },
    { id: 'acs2023_under18', label: 'Population under 18 (ACS 2019-2023 estimate)' },
    { id: 'acs2023_households', label: 'Households (ACS 2019-2023 estimate)' }
];

const TIGERWEB_BLOCK_GROUPS_URL = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/Tracts_Blocks/MapServer/11/query';
const TIGERWEB_PLACES_URL = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/Places_CouSub_ConCity_SubMCD/MapServer/25/query';
// Block groups are fetched one small tile at a time, and only for tiles that a
// library's shape touches, so libraries in distant cities don't make us
// download everything in between.
// State abbreviation -> FIPS code, for looking up a city's boundary.
const STATE_FIPS = {
    AL: '01',
    AK: '02',
    AZ: '04',
    AR: '05',
    CA: '06',
    CO: '08',
    CT: '09',
    DE: '10',
    DC: '11',
    FL: '12',
    GA: '13',
    HI: '15',
    ID: '16',
    IL: '17',
    IN: '18',
    IA: '19',
    KS: '20',
    KY: '21',
    LA: '22',
    ME: '23',
    MD: '24',
    MA: '25',
    MI: '26',
    MN: '27',
    MS: '28',
    MO: '29',
    MT: '30',
    NE: '31',
    NV: '32',
    NH: '33',
    NJ: '34',
    NM: '35',
    NY: '36',
    NC: '37',
    ND: '38',
    OH: '39',
    OK: '40',
    OR: '41',
    PA: '42',
    RI: '44',
    SC: '45',
    SD: '46',
    TN: '47',
    TX: '48',
    UT: '49',
    VT: '50',
    VA: '51',
    WA: '53',
    WV: '54',
    WI: '55',
    WY: '56',
    PR: '72'
};

const CENSUS_TILE_DEGREES = 0.1;   // roughly 8 x 11 km
const CENSUS_MAX_CONCURRENT_REQUESTS = 6;
const CENSUS_MAX_PAGES_PER_TILE = 20;

const censusCache = {
    features: new Map(),   // GEOID -> GeoJSON feature
    tiles: new Set(),      // "x,y" of tiles whose block groups have been fetched
    values: new Map(),     // "dataset|state|county" -> {GEOID: value}
    places: new Map()      // "state|name" -> MultiPolygon coordinates
};

function bboxOfPaths(paths) {
    const bbox = { west: Infinity, south: Infinity, east: -Infinity, north: -Infinity };
    for (const path of paths) {
        for (const p of path) {
            bbox.west = Math.min(bbox.west, p.lng);
            bbox.east = Math.max(bbox.east, p.lng);
            bbox.south = Math.min(bbox.south, p.lat);
            bbox.north = Math.max(bbox.north, p.lat);
        }
    }
    return bbox;
}

function bboxesIntersect(a, b) {
    return a.west <= b.east && a.east >= b.west && a.south <= b.north && a.north >= b.south;
}

// All rings of a GeoJSON Polygon/MultiPolygon as a flat list.
function geometryRings(geometry) {
    if (geometry.type === 'Polygon')
        return geometry.coordinates;
    if (geometry.type === 'MultiPolygon')
        return geometry.coordinates.flat();
    return [];
}

async function fetchBlockGroups(bbox) {
    const pageSize = 500;
    const features = [];
    for (let page = 0, offset = 0; ; page++, offset += pageSize) {
        if (page >= CENSUS_MAX_PAGES_PER_TILE)
            throw new Error('Census boundary request returned too many results');
        const params = new URLSearchParams({
            where: '1=1',
            geometry: [bbox.west, bbox.south, bbox.east, bbox.north].join(','),
            geometryType: 'esriGeometryEnvelope',
            inSR: '4326',
            outSR: '4326',
            spatialRel: 'esriSpatialRelIntersects',
            outFields: 'GEOID,STATE,COUNTY',
            maxAllowableOffset: '0.0001', // simplify outlines to about 10 meters
            orderByFields: 'GEOID',
            resultOffset: String(offset),
            resultRecordCount: String(pageSize),
            f: 'geojson'
        });
        const response = await fetch(TIGERWEB_BLOCK_GROUPS_URL + '?' + params);
        if (!response.ok)
            throw new Error('Census boundary request failed (' + response.status + ')');
        const json = await response.json();
        if (json.error)
            throw new Error('Census boundary request failed: ' + (json.error.message || 'unknown error'));
        features.push(...json.features);
        if (json.features.length < pageSize)
            return features;
    }
}

function tileBounds(x, y) {
    return {
        west: x * CENSUS_TILE_DEGREES,
        east: (x + 1) * CENSUS_TILE_DEGREES,
        south: y * CENSUS_TILE_DEGREES,
        north: (y + 1) * CENSUS_TILE_DEGREES
    };
}

// Runs async `task(item)` for every item, `limit` at a time.
async function forEachConcurrent(items, limit, task) {
    let next = 0;
    const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
        while (next < items.length)
            await task(items[next++]);
    });
    await Promise.all(workers);
}

// Makes sure the block groups covering each box in `bboxes` are in censusCache.
async function ensureCensusGeometry(bboxes) {
    const missing = new Map();
    for (const bbox of bboxes) {
        for (let x = Math.floor(bbox.west / CENSUS_TILE_DEGREES); x <= Math.floor(bbox.east / CENSUS_TILE_DEGREES); x++) {
            for (let y = Math.floor(bbox.south / CENSUS_TILE_DEGREES); y <= Math.floor(bbox.north / CENSUS_TILE_DEGREES); y++) {
                const key = x + ',' + y;
                if (!censusCache.tiles.has(key))
                    missing.set(key, [x, y]);
            }
        }
    }
    await forEachConcurrent([...missing], CENSUS_MAX_CONCURRENT_REQUESTS, async ([key, [x, y]]) => {
        const features = await fetchBlockGroups(tileBounds(x, y));
        for (const feature of features) {
            if (censusCache.features.has(feature.properties.GEOID))
                continue; // already fetched with a neighboring tile
            feature.bbox = bboxOfPaths([geometryRings(feature.geometry).flat().map(c => ({ lng: c[0], lat: c[1] }))]);
            censusCache.features.set(feature.properties.GEOID, feature);
        }
        censusCache.tiles.add(key);
    });
}

// The outline of an incorporated place (city) as MultiPolygon coordinates,
// e.g. fetchPlaceGeometry('16', 'Boise').  The Census sometimes calls a city
// "<name> City" (Boise is "Boise City"), so both spellings are accepted.
async function fetchPlaceGeometry(state, name) {
    const key = state + '|' + name;
    if (censusCache.places.has(key))
        return censusCache.places.get(key);
    const quoted = name.replace(/'/g, "''");
    const params = new URLSearchParams({
        where: "STATE='" + state + "' AND BASENAME IN ('" + quoted + "', '" + quoted + " City')",
        outFields: 'GEOID,NAME,BASENAME',
        outSR: '4326',
        maxAllowableOffset: '0.0001',
        f: 'geojson'
    });
    const response = await fetch(TIGERWEB_PLACES_URL + '?' + params);
    if (!response.ok)
        throw new Error('Census city boundary request failed (' + response.status + ')');
    const json = await response.json();
    if (json.error || !json.features || !json.features.length)
        throw new Error('Census has no city boundary named ' + name);
    // prefer an exact name match over the "<name> City" spelling
    const exact = json.features.filter(f => f.properties.BASENAME.toLowerCase() === name.toLowerCase());
    const multiPolygon = (exact.length ? exact : json.features).flatMap(f => toMultiPolygon(f.geometry));
    censusCache.places.set(key, multiPolygon);
    return multiPolygon;
}

function toMultiPolygon(geometry) {
    return geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
}

// Area in square meters of MultiPolygon coordinates (holes subtracted).
function multiPolygonArea(multiPolygon, origin) {
    let area = 0;
    for (const rings of multiPolygon) {
        rings.forEach((ring, index) => {
            const a = Math.abs(ringArea(ring.map(c => project(origin, { lat: c[1], lng: c[0] }))));
            area += index === 0 ? a : -a;
        });
    }
    return area;
}

async function ensureCensusValues(datasetId, features) {
    const counties = new Set(features.map(f => f.properties.STATE + '|' + f.properties.COUNTY));
    await Promise.all([...counties].map(async (county) => {
        const cacheKey = datasetId + '|' + county;
        if (censusCache.values.has(cacheKey))
            return;
        const [state, countyCode] = county.split('|');
        // Try the static file first (served by Apache, cached by the browser).
        // It doesn't exist until the handler has fetched it once, so fall back to that.
        let json = null;
        if (typeof lpCensus !== 'undefined' && lpCensus.cacheUrl) {
            const staticResponse = await fetch(lpCensus.cacheUrl + datasetId + '-' + state + '-' + countyCode + '.json');
            if (staticResponse.ok)
                json = await staticResponse.json();
        }
        if (json === null) {
            const response = await fetch('/wp-admin/admin-ajax.php?action=lp_census_population' +
                '&dataset=' + encodeURIComponent(datasetId) +
                '&state=' + encodeURIComponent(state) +
                '&county=' + encodeURIComponent(countyCode));
            json = await response.json();
            if (!response.ok)
                throw new Error(json.error || 'Census data request failed');
        }
        censusCache.values.set(cacheKey, json);
    }));
}

function ringArea(points) {
    let sum = 0;
    for (let i = 0; i < points.length; i++) {
        const a = points[i];
        const b = points[(i + 1) % points.length];
        sum += a.x * b.y - b.x * a.y;
    }
    return sum / 2; // signed: positive if counter-clockwise
}

// Sutherland-Hodgman clip of `subject` by a convex, counter-clockwise `clip`.
function clipToConvex(subject, clip) {
    let output = subject;
    for (let i = 0; i < clip.length && output.length; i++) {
        const a = clip[i];
        const b = clip[(i + 1) % clip.length];
        const side = p => (b.x - a.x) * (p.y - a.y) - (b.y - a.y) * (p.x - a.x);
        const input = output;
        output = [];
        for (let j = 0; j < input.length; j++) {
            const p = input[j];
            const q = input[(j + 1) % input.length];
            const sp = side(p);
            const sq = side(q);
            if (sp >= 0)
                output.push(p);
            if ((sp > 0 && sq < 0) || (sp < 0 && sq > 0)) {
                const t = sp / (sp - sq);
                output.push({ x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t });
            }
        }
    }
    return output;
}

// Fraction (0..1) of a GeoJSON polygon's area that lies inside `clip`, a
// convex counter-clockwise polygon already projected to meters around `origin`.
function fractionInside(geometry, clip, origin) {
    let total = 0;
    let inside = 0;
    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
    for (const rings of polygons) {
        rings.forEach((ring, index) => {
            const projected = ring.map(c => project(origin, { lat: c[1], lng: c[0] }));
            // The first ring is the outline; the rest are holes.
            const sign = index === 0 ? 1 : -1;
            total += sign * Math.abs(ringArea(projected));
            inside += sign * Math.abs(ringArea(clipToConvex(projected, clip)));
        });
    }
    return total > 0 ? Math.max(0, Math.min(1, inside / total)) : 0;
}

// Returns a counter-clockwise projected copy of a {lat,lng} path.
function projectShape(path, origin) {
    const points = path.map(p => project(origin, p));
    return ringArea(points) < 0 ? points.reverse() : points;
}

// paths: array of shapes, each an array of {lat, lng} (convex polygons).
// options.place: {state, name} to count only people inside that city.
// Returns an array of estimated values parallel to `paths`.
async function estimateCensusValues(datasetId, paths, options = {}) {
    if (!paths.length)
        return [];
    const shapeBoxes = paths.map(path => bboxOfPaths([path]));
    await ensureCensusGeometry(shapeBoxes);
    const place = options.place ? await fetchPlaceGeometry(options.place.state, options.place.name) : null;

    // only block groups near a shape matter (and only their counties need values)
    const nearby = [...censusCache.features.values()].filter(f => shapeBoxes.some(box => bboxesIntersect(f.bbox, box)));
    await ensureCensusValues(datasetId, nearby);

    return paths.map((path, index) => {
        const shapeBox = shapeBoxes[index];
        // project around this shape so libraries far apart aren't distorted
        const origin = { lat: (shapeBox.north + shapeBox.south) / 2, lng: (shapeBox.east + shapeBox.west) / 2 };
        const shape = projectShape(path, origin);
        let region = null;
        if (place) {
            const ring = path.map(p => [p.lng, p.lat]);
            ring.push(ring[0]);
            region = polygonClipping.intersection([ring], place);
            if (!region.length)
                return 0;
        }
        let total = 0;
        for (const feature of nearby) {
            if (!bboxesIntersect(feature.bbox, shapeBox))
                continue;
            const values = censusCache.values.get(datasetId + '|' + feature.properties.STATE + '|' + feature.properties.COUNTY);
            const value = values && values[feature.properties.GEOID];
            if (!value)
                continue;
            if (region) {
                // part of the block group that is inside both the shape and the city
                const part = polygonClipping.intersection(toMultiPolygon(feature.geometry), region);
                if (part.length)
                    total += value * multiPolygonArea(part, origin) / multiPolygonArea(toMultiPolygon(feature.geometry), origin);
            } else {
                total += value * fractionInside(feature.geometry, shape, origin);
            }
        }
        return Math.round(total);
    });
}
