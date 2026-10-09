"use strict";

// Turns a set of possibly-overlapping circles into polygons that don't overlap.
//
// Where two circles intersect, the boundary between them is the straight line
// (the radical axis) through their two intersection points.  Each circle is
// approximated by a polygon and then clipped, in turn, by that line for every
// circle it intersects, keeping only the half on its own side.  Because the
// radical axes of three mutually-intersecting circles meet at a single point,
// the resulting polygons share edges with no gaps and no overlaps.
//
// circles: [{ latlng: {lat, lng}, radius: meters }]
// returns: an array parallel to `circles`; each entry is an array of
//          {lat, lng} points (a closed path), or null for a circle with no
//          radius.

const EARTH_RADIUS_METERS = 6371008.8;
const CIRCLE_SEGMENTS = 120;

function toRad(deg) {
    return deg * Math.PI / 180;
}

// Project to a flat plane (meters) centered on `origin`.  Accurate enough for
// circles a few miles wide.
function project(origin, point) {
    return {
        x: toRad(point.lng - origin.lng) * Math.cos(toRad(origin.lat)) * EARTH_RADIUS_METERS,
        y: toRad(point.lat - origin.lat) * EARTH_RADIUS_METERS
    };
}

function unproject(origin, p) {
    return {
        lat: origin.lat + (p.y / EARTH_RADIUS_METERS) * 180 / Math.PI,
        lng: origin.lng + (p.x / (EARTH_RADIUS_METERS * Math.cos(toRad(origin.lat)))) * 180 / Math.PI
    };
}

// True if the circle boundaries cross (as opposed to being apart or nested).
function circlesIntersect(r0, r1, d) {
    return d > 0 && d < r0 + r1 && d > Math.abs(r0 - r1);
}

// Sutherland-Hodgman clip of a polygon against the half-plane f(p) <= 0, where
// f(p) = 2 * (p . c) - k.
function clipHalfPlane(polygon, c, k) {
    const f = p => 2 * (p.x * c.x + p.y * c.y) - k;
    const result = [];
    for (let i = 0; i < polygon.length; i++) {
        const a = polygon[i];
        const b = polygon[(i + 1) % polygon.length];
        const fa = f(a);
        const fb = f(b);
        if (fa <= 0)
            result.push(a);
        if ((fa < 0 && fb > 0) || (fa > 0 && fb < 0)) {
            const t = fa / (fa - fb);
            result.push({ x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t });
        }
    }
    return result;
}

function calculateBorderPolygons(circles) {
    return circles.map((circle, i) => {
        if (!(circle.radius > 0))
            return null;
        const origin = circle.latlng;

        let polygon = [];
        for (let s = 0; s < CIRCLE_SEGMENTS; s++) {
            const angle = 2 * Math.PI * s / CIRCLE_SEGMENTS;
            polygon.push({ x: circle.radius * Math.cos(angle), y: circle.radius * Math.sin(angle) });
        }

        for (let j = 0; j < circles.length; j++) {
            if (j === i || !(circles[j].radius > 0))
                continue;
            const c = project(origin, circles[j].latlng);
            const d = Math.hypot(c.x, c.y);
            if (!circlesIntersect(circle.radius, circles[j].radius, d))
                continue;
            // Keep points closer (by power) to this circle than to circle j:
            // |p|^2 - ri^2 <= |p-c|^2 - rj^2  <=>  2 p.c <= |c|^2 + ri^2 - rj^2
            const k = d * d + circle.radius * circle.radius - circles[j].radius * circles[j].radius;
            polygon = clipHalfPlane(polygon, c, k);
            if (polygon.length < 3)
                break;
        }

        return polygon.length < 3 ? null : polygon.map(p => unproject(origin, p));
    });
}
