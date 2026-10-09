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
const CENSUS_BBOX_PADDING_DEGREES = 0.1;

const censusCache = {
    features: new Map(),   // GEOID -> GeoJSON feature
    bbox: null,            // area for which features have been fetched
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

function bboxContains(outer, inner) {
    return outer && outer.west <= inner.west && outer.east >= inner.east &&
        outer.south <= inner.south && outer.north >= inner.north;
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
    for (let offset = 0; ; offset += pageSize) {
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

// Makes sure block groups covering `bbox` are in censusCache.
async function ensureCensusGeometry(bbox) {
    if (bboxContains(censusCache.bbox, bbox))
        return;
    // Fetch a generous area so small changes (like dragging a marker) don't refetch.
    const padded = {
        west: bbox.west - CENSUS_BBOX_PADDING_DEGREES,
        east: bbox.east + CENSUS_BBOX_PADDING_DEGREES,
        south: bbox.south - CENSUS_BBOX_PADDING_DEGREES,
        north: bbox.north + CENSUS_BBOX_PADDING_DEGREES
    };
    const features = await fetchBlockGroups(padded);
    for (const feature of features) {
        feature.bbox = bboxOfPaths([geometryRings(feature.geometry).flat().map(c => ({ lng: c[0], lat: c[1] }))]);
        censusCache.features.set(feature.properties.GEOID, feature);
    }
    censusCache.bbox = padded;
}

// The outline of an incorporated place (city) as MultiPolygon coordinates,
// e.g. fetchPlaceGeometry('16', 'Boise City').
async function fetchPlaceGeometry(state, baseName) {
    const key = state + '|' + baseName;
    if (censusCache.places.has(key))
        return censusCache.places.get(key);
    const params = new URLSearchParams({
        where: "STATE='" + state + "' AND BASENAME='" + baseName.replace(/'/g, "''") + "'",
        outFields: 'GEOID,NAME',
        outSR: '4326',
        maxAllowableOffset: '0.0001',
        f: 'geojson'
    });
    const response = await fetch(TIGERWEB_PLACES_URL + '?' + params);
    if (!response.ok)
        throw new Error('Census city boundary request failed (' + response.status + ')');
    const json = await response.json();
    if (json.error || !json.features || !json.features.length)
        throw new Error('Census has no city boundary named ' + baseName);
    const multiPolygon = json.features.flatMap(f => toMultiPolygon(f.geometry));
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
        const response = await fetch('/wp-admin/admin-ajax.php?action=lp_census_population' +
            '&dataset=' + encodeURIComponent(datasetId) +
            '&state=' + encodeURIComponent(state) +
            '&county=' + encodeURIComponent(countyCode));
        const json = await response.json();
        if (!response.ok)
            throw new Error(json.error || 'Census data request failed');
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
    const bbox = bboxOfPaths(paths);
    await ensureCensusGeometry(bbox);
    const place = options.place ? await fetchPlaceGeometry(options.place.state, options.place.name) : null;

    const nearby = [...censusCache.features.values()].filter(f => bboxesIntersect(f.bbox, bbox));
    await ensureCensusValues(datasetId, nearby);

    const origin = { lat: (bbox.north + bbox.south) / 2, lng: (bbox.east + bbox.west) / 2 };
    return paths.map(path => {
        const shapeBox = bboxOfPaths([path]);
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
