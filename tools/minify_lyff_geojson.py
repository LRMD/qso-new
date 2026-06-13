#!/usr/bin/env python3
"""Create a simplified LYFF GeoJSON copy for faster client-side loading.

Rules:
- very small rings (≤15 pts) stay unchanged
- typical rings keep roughly 1/15 of their points
- large rings (>500 pts) are reduced to at most 60 points
- tiny interior holes (<6 pts after simplify) are dropped — invisible at map scale
- all coordinates are rounded to 5 decimal places (~1 m precision at these latitudes)

The output keeps the same FeatureCollection structure and properties, only the
polygon coordinate lists are simplified.
"""

from __future__ import annotations

import json
import math
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "data" / "lyff.geojson"
DST = ROOT / "data" / "lyff.min.geojson"

COORD_PRECISION = 5      # 0.00001° ≈ 0.6–1.1 m at 55 °N; sub-metre is wasted on a web map
SMALL_RING_KEEP = 15     # rings at/below this size pass through unchanged
LARGE_RING_THRESHOLD = 500
MAX_LARGE_RING_POINTS = 60   # was 100; parks at overview scale need far fewer
RATIO_DIVISOR = 15           # keep 1/15 of medium rings (was 1/10)
MIN_HOLE_POINTS = 6          # drop interior rings smaller than this after simplify

# Features that need gentler simplification — target 300 points per ring.
KEEP_300 = {'LYFF-0186', 'LYFF-0188', 'LYFF-0193'}


def dist_point_to_segment(point, a, b) -> float:
    px, py = point
    ax, ay = a
    bx, by = b
    dx = bx - ax
    dy = by - ay
    if dx == 0 and dy == 0:
        return math.hypot(px - ax, py - ay)
    t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)
    t = max(0.0, min(1.0, t))
    return math.hypot(px - ax - t * dx, py - ay - t * dy)


def rdp(points, epsilon):
    if len(points) < 3:
        return points[:]

    first = points[0]
    last = points[-1]
    index = 1
    max_dist = -1.0
    for i in range(1, len(points) - 1):
        d = dist_point_to_segment(points[i], first, last)
        if d > max_dist:
            index = i
            max_dist = d

    if max_dist > epsilon:
        left = rdp(points[:index + 1], epsilon)
        right = rdp(points[index:], epsilon)
        return left[:-1] + right
    return [first, last]


def bbox_diagonal(coords):
    xs = [p[0] for p in coords]
    ys = [p[1] for p in coords]
    return math.hypot(max(xs) - min(xs), max(ys) - min(ys))


def simplify_open_ring(coords, target_points):
    """Reduce coords to at most target_points using RDP with binary-search epsilon."""
    if len(coords) <= target_points:
        return coords[:]

    # Seed the upper bound from the bbox diagonal — converges in far fewer iterations
    # than starting at 1.0 and doubling, especially for large rings.
    diag = bbox_diagonal(coords)
    high = diag / max(target_points, 1)
    while len(rdp(coords, high)) > target_points:
        high *= 2.0

    low = 0.0
    best = rdp(coords, high)
    for _ in range(20):
        mid = (low + high) / 2.0
        candidate = rdp(coords, mid)
        if len(candidate) > target_points:
            low = mid
        else:
            high = mid
            best = candidate

    return best


def simplify_ring(ring, target_override=None):
    if len(ring) < 6:
        return ring

    closed = ring[0] == ring[-1]
    coords = ring[:-1] if closed else ring[:]
    n = len(coords)

    if target_override is None and n <= SMALL_RING_KEEP:
        return ring

    if target_override is not None:
        target = target_override
    elif n > LARGE_RING_THRESHOLD:
        target = MAX_LARGE_RING_POINTS
    else:
        target = max(10, int(math.ceil(n / RATIO_DIVISOR)))
    if target >= n:
        return ring

    simplified = simplify_open_ring(coords, target)
    if len(simplified) < 4:
        return ring

    if simplified[0] != simplified[-1]:
        simplified = simplified + [simplified[0]]

    if len(simplified) < 5:
        return ring

    return simplified


def round_ring(ring):
    return [[round(c, COORD_PRECISION) for c in pt] for pt in ring]


def simplify_geometry(geom, target_override=None):
    if not isinstance(geom, dict):
        return geom
    t = geom.get("type")
    c = geom.get("coordinates")
    if t == "Polygon" and isinstance(c, list):
        rings = []
        for i, r in enumerate(c):
            s = simplify_ring(r, target_override)
            if i > 0 and len(s) < MIN_HOLE_POINTS:
                continue  # drop hole invisible at map scale
            rings.append(round_ring(s))
        return {"type": "Polygon", "coordinates": rings}
    if t == "MultiPolygon" and isinstance(c, list):
        polys = []
        for poly in c:
            rings = []
            for i, r in enumerate(poly):
                s = simplify_ring(r, target_override)
                if i > 0 and len(s) < MIN_HOLE_POINTS:
                    continue
                rings.append(round_ring(s))
            if rings:
                polys.append(rings)
        return {"type": "MultiPolygon", "coordinates": polys}
    return geom


def point_count(geom):
    if not isinstance(geom, dict):
        return 0
    coords = geom.get("coordinates")
    if geom.get("type") == "Polygon" and isinstance(coords, list):
        return sum(len(r) for r in coords if isinstance(r, list))
    if geom.get("type") == "MultiPolygon" and isinstance(coords, list):
        return sum(len(r) for poly in coords if isinstance(poly, list) for r in poly if isinstance(r, list))
    return 0


def main():
    data = json.loads(SRC.read_text(encoding="utf-8"))
    feats = data.get("features", [])
    out = {
        "type": data.get("type", "FeatureCollection"),
        "attribution": data.get("attribution"),
        "features": [],
    }

    before = []
    after = []
    for feat in feats:
        geom = feat.get("geometry")
        before.append(point_count(geom))
        new_feat = dict(feat)
        code = (feat.get("properties") or {}).get("code", "")
        target = 300 if code in KEEP_300 else None
        new_feat["geometry"] = simplify_geometry(geom, target)
        out["features"].append(new_feat)
        after.append(point_count(new_feat["geometry"]))

    DST.write_text(json.dumps(out, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")

    print(f"wrote {DST}")
    print(f"features: {len(out['features'])}")
    if before:
        print(f"total points before: {sum(before)}")
        print(f"total points after:  {sum(after)}")
        print(f"avg points before: {sum(before) / len(before):.1f}")
        print(f"avg points after:  {sum(after) / len(after):.1f}")
        print(f"point reduction:   {100 * (1 - sum(after) / max(sum(before), 1)):.1f}%")


if __name__ == "__main__":
    main()
