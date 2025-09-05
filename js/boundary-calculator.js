const {spherical} = await google.maps.importLibrary("geometry");

function overlapExists(circle1, circle2) {
    const dist = spherical.computeDistanceBetween(circle1.latlng, circle2.latlng);

    // Optional: check for non-overlapping or contained circles
    if (dist > circle1.radius + circle2.radius || dist < Math.abs(circle1.radius - circle2.radius) || dist === 0)
        return null; // no intersection or infinite intersections
    return dist;
}

// This returns the angle from the center of the first circle to the
// intersecting point followed by the angle from the center of the
// second circle to the intersecting point.
function circleIntersections(p0, r0, p1, r1) {
    // Step 1: distance between centers
    const dx = p1.x - p0.x;
    const dy = p1.y - p0.y;
    const d = Math.sqrt(dx * dx + dy * dy);

    // Step 2: distance from first center to midpoint of chord
    const a = (r0 * r0 - r1 * r1 + d * d) / (2 * d);

    // Step 3: height from base point to intersections
    const h = Math.sqrt(r0 * r0 - a * a);

    /*
    // Step 4: coordinates of midpoint P2
    const xm = p0.x + (a * dx) / d;
    const ym = p0.y + (a * dy) / d;

    // Step 5: offsets for the intersection points
    const rx = -(dy * (h / d));
    const ry = dx * (h / d);

    // Step 6: intersection points
    const p3 = { x: xm + rx, y: ym + ry };
    const p4 = { x: xm - rx, y: ym - ry };

    return [p3, p4];*/

    // opposite / adjacent
    return [Math.acos(h / a), Math.acos(h / (d - a))];
}

function normalize(angle) {
    if (angle > 180)
        return angle - 360;
    if (angle < -180)
        return angle + 360;
    return angle;
}

function addAngles(angle1, angle2) {
    return normalize(angle1 + angle2);
}

function addArcs(arcs, index, start, end) {
    if (!arcs[index])
        arcs[index] = [];
    let segmentList = arcs[index];
    let inserted = false;
    const newSegment = { start: start, end: end };
    for (let i = 0; i < segmentList.length; i++) {
        if (start < segmentList[i].start) {
            segmentList.splice(i, 0, newSegment);
            inserted = true;
            break;
        }
    }
    if (!inserted) {
        segmentList.push(newSegment);
    }
}

function addPoint(list, from, heading, distance) {
    let point = spherical.computeOffset(from, distance, heading);
    list.push(point);
}

function fillPoints(points, index, center, start, end, increment, radius) {
    if (!points[index])
        points[index] = [];
    for (let angle = start; angle < end; angle += increment) {
        addPoint(points[index], center, normalize(angle), radius);
    }
}

function convertSegmentToCoordinates(segment, from, distance) {
    return [spherical.computeOffset(from, distance, segment.start), spherical.computeOffset(from, distance, segment.end)];
}

function calculateBorderPolygons(circles) {

    // Given a series of circles, find the distance between all circles.
    // For each circle, keep a record of the line segments reducing the
    // size of that circle, including the start and stop of the segment
    // in degrees.
    let arcs = {};

    for (let index1 = 0; index1 < circles.length; index1++) {
        for (let index2 = index1 + 1; index2 < circles.length; index2++) {
            const dist = overlapExists(circles[index1], circles[index2]);
            if (dist) {
                const heading = spherical.computeHeading(circles[index1].latlng, circles[index2].latlng);
                const oppHeading = addAngles(heading, 180);
                const anglesOfIntersection = circleIntersections({ x: 0, y: 0 }, circles[index1].radius, { x: dist, y: 0 }, circles[index2].radius);
                addArcs(arcs, index1, addAngles(heading, - anglesOfIntersection[0]), addAngles(heading, anglesOfIntersection[0]));
                addArcs(arcs, index2, addAngles(oppHeading, - anglesOfIntersection[1]), addAngles(oppHeading, anglesOfIntersection[1]));
            }
        }
    }

    let points = {};
    // Finally, for each circle, iterate over these segments in order of
    // degrees, finding intersections and reducing the line segments and
    // arcs so there are no overlapping args and line segments.
    for (let index = 0; index < circles.length; index++) {
        const segments = arcs[index];
        if (!segments) {
            fillPoints(points, index, circles[index].latlng, -180, 180, 0.5);
        }
        else {
            let segmentPoints = [];
            for (let i = 0; i < segments.length; i++) {
                segmentPoints.push(convertSegmentToCoordinates(segments[i], circles[index].latlng, circles[index].radius));
            }
            for (let i = 0; i < segments.length; i++) {
                let nextSegment = i == segments.length - 1 ? 0 : i + 1;
                s1 = segmentPoints[i];
                s2 = segmentPoints[nextSegment];
                if (segments.length > 1 && segmentsOverlap(segments[i], segments[nextSegment])) {
                    //let point = spherical.computeOffset(from, distance, heading);
                    console.log(`need to determine overlapping point.  s1=${s1}, s2=${s2}`);
                }
                else {
                    console.log(`no overlap.  s1=${s1}, s2=${s2}`);
                    points[index].push(s1[0]);
                    points[index].push(s1[1]);
                    fillPoints(points, index, circles[index].latlng, segments[i].end, segments[nextSegment].start);
                }
            }
        }
    }
}