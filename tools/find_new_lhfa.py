#!/usr/bin/env python3
"""
Compare Lithuanian hillforts on lt.wikipedia.org/wiki/Sąrašas:Lietuvos_piliakalniai
against the existing LHFA database (data/lhfa.json), fetch coordinates for each
new candidate from Wikipedia, and write results to new-lhfa.md.

Matching is done by the Vietovė(s) column from the Wikipedia table against
the LHFA 'name' field.
"""

import json
import re
import sys
import time
import urllib.parse
import urllib.request

WIKI_API = "https://lt.wikipedia.org/w/api.php"
LIST_PAGE = "Sąrašas:Lietuvos_piliakalniai"
WIKI_BASE = "https://lt.wikipedia.org/wiki/"
UA = "Mozilla/5.0 (X11; Linux x86_64; rv:151.0) Gecko/20100101 Firefox/151.0"

LHFA_JSON = "data/lhfa.json"
OUTPUT = "new-lhfa.md"

BATCH_SIZE = 50  # Wikipedia API allows up to 50 titles per coordinates query


def api_get(params):
    url = WIKI_API + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req) as resp:
        return json.load(resp)


def strip_wiki_links(text):
    """[[Target|Display]] → Display, [[Name]] → Name, plain text unchanged."""
    return re.sub(r"\[\[(?:[^\]|]+\|)?([^\]]+)\]\]", r"\1", text)


def fetch_wiki_entries():
    """
    Parse the list page wikitext and return tuples of:
      (region, link_target, display_name, location)
    where location is the first value from the Vietovė(s) column.
    Handles both single-line (| a || b || c) and multi-line (|a / |b / |c) table rows.
    """
    print("Fetching Wikipedia list page...", file=sys.stderr)
    data = api_get({"action": "parse", "page": LIST_PAGE, "prop": "wikitext", "format": "json"})
    text = data["parse"]["wikitext"]["*"]
    print(f"  Downloaded {len(text)} chars of wikitext", file=sys.stderr)

    entries = []
    current_region = ""
    current_row: list[str] = []
    in_table = False

    def process_row(cells):
        if not cells:
            return
        first = cells[0].strip()
        # Skip header rows and non-link rows
        if "'''" in first or not first.startswith("[["):
            return
        # Parse first cell: hillfort wiki link
        m = re.match(r"\[\[([^\]]+)\]\]", first)
        if not m:
            return
        link_content = m.group(1)
        if "|" in link_content:
            link_target, display_name = [p.strip() for p in link_content.split("|", 1)]
        else:
            link_target = display_name = link_content.strip()

        # Third cell is Vietovė(s)
        location = ""
        if len(cells) >= 3:
            loc_raw = strip_wiki_links(cells[2]).strip()
            # Take first location if multiple (separated by comma or <br>)
            location = re.split(r"\s*[,<]\s*", loc_raw)[0].strip()

        entries.append((current_region, link_target, display_name, location))

    for line in text.split("\n"):
        s = line.strip()

        # Section header == ... ==
        m = re.match(r"^==\s*(.+?)\s*==$", s)
        if m:
            if current_row:
                process_row(current_row)
                current_row = []
            current_region = strip_wiki_links(m.group(1)).strip()
            in_table = False
            continue

        if s.startswith("{|"):
            in_table = True
            current_row = []
            continue

        if s == "|}":
            if current_row:
                process_row(current_row)
                current_row = []
            in_table = False
            continue

        # Row separator
        if s == "|-":
            if current_row:
                process_row(current_row)
            current_row = []
            continue

        # Cell line(s): starts with | but isn't |-, |}
        if in_table and s.startswith("|") and not s.startswith("|-") and not s.startswith("|}"):
            content = s[1:]  # strip the leading |
            if "||" in content:
                # Single-line multi-cell row
                current_row.extend(c.strip() for c in content.split("||"))
            else:
                current_row.append(content.strip())

    if current_row:
        process_row(current_row)

    print(f"  Parsed {len(entries)} entries", file=sys.stderr)
    return entries


def load_lhfa_names(path):
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    names = set()
    for item in data:
        if item.get("type") == "table" and item.get("name") == "lhfa":
            for row in item["data"]:
                names.add(row["name"].strip())
    return names


def normalize(name):
    return re.sub(r"\s+", " ", name.strip().lower())


def strip_roman(name):
    """Strip trailing Roman numeral suffix, e.g. 'Antakščiai II' → 'Antakščiai'."""
    return re.sub(r"\s+[ivxlcdm]+$", "", name, flags=re.IGNORECASE).strip()


def strip_parens(name):
    """Remove parenthetical disambiguation, e.g. 'Dubiai (Alytus)' → 'Dubiai'."""
    return re.sub(r"\s*\([^)]*\)", "", name).strip()


def location_variants(name):
    """
    Generate morphological variants of a Lithuanian place name to account for
    common inflectional alternations between Wikipedia Vietovė(s) and LHFA names.

    Covered patterns:
      -ys  ↔ -is   masculine nominative sg  (Pagryžuvys ↔ Pagryžuvis)
      -iai ↔ -ės   plural paradigm shift    (Zaramciškiai ↔ Zaramciškės)
      -iai ↔ -ai   i-stem vs a-stem plural  (Joniškiai ↔ Joniškiai / Joniškiai ↔ Joniškai)
      -ės  ↔ -ai   cross-paradigm
      -as  ↔ -ai   masculine plural base    (Šilgalas ↔ Šilgalai)
      -a   ↔ -os   feminine sg alternation  (Margarava ↔ Margaravos — unlikely but cheap)
    """
    v = {name}

    if name.endswith("ys"):
        v.add(name[:-2] + "is")
    elif name.endswith("is"):
        v.add(name[:-2] + "ys")

    if name.endswith("iai"):
        v.add(name[:-3] + "ės")
        v.add(name[:-3] + "ai")
    elif name.endswith("ės"):
        v.add(name[:-2] + "iai")
        v.add(name[:-2] + "ai")
    elif name.endswith("ai"):
        v.add(name[:-2] + "iai")
        v.add(name[:-2] + "ės")

    if name.endswith("as"):
        v.add(name[:-2] + "ai")
    elif name.endswith("ai") and not name.endswith("iai"):
        v.add(name[:-2] + "as")

    if name.endswith("a") and not name.endswith("ia"):
        v.add(name[:-1] + "os")
    elif name.endswith("os"):
        v.add(name[:-2] + "a")

    return v


def fetch_coordinates(titles):
    """
    Batch-fetch coordinates for a list of Wikipedia article titles.
    Returns dict: original_title -> (lat, lon) or None.
    """
    coords = {}
    for i in range(0, len(titles), BATCH_SIZE):
        batch = titles[i : i + BATCH_SIZE]
        print(f"  Fetching coordinates {i+1}–{i+len(batch)} of {len(titles)}...", file=sys.stderr)
        data = api_get({
            "action": "query",
            "titles": "|".join(batch),
            "prop": "coordinates",
            "format": "json",
        })
        query = data.get("query", {})

        # Map normalized/redirected titles back to the originally requested title
        title_map = {t: t for t in batch}
        for norm in query.get("normalized", []):
            title_map[norm["to"]] = norm["from"]
        for redir in query.get("redirects", []):
            title_map[redir["to"]] = title_map.get(redir["from"], redir["from"])

        for page in query.get("pages", {}).values():
            original = title_map.get(page["title"], page["title"])
            if "coordinates" in page:
                c = page["coordinates"][0]
                coords[original] = (c["lat"], c["lon"])
            else:
                coords[original] = None

        if i + BATCH_SIZE < len(titles):
            time.sleep(0.2)

    return coords


def wiki_url(link_target):
    return WIKI_BASE + urllib.parse.quote(link_target.replace(" ", "_"), safe=":()/")


def main():
    wiki_entries = fetch_wiki_entries()
    lhfa_names = load_lhfa_names(LHFA_JSON)

    # Two normalized lookups: exact and Roman-numeral-stripped
    lhfa_norm = {normalize(n): n for n in lhfa_names}
    lhfa_norm_base = {normalize(strip_roman(n)): n for n in lhfa_names}

    candidates = []
    for region, link_target, display, location in wiki_entries:
        loc_clean = strip_parens(location)
        matched = None
        for variant in location_variants(loc_clean):
            v_norm = normalize(variant)
            matched = lhfa_norm.get(v_norm) or lhfa_norm_base.get(v_norm)
            if matched:
                break
        if not matched:
            candidates.append((region, link_target, display, location))

    print(f"\nFound {len(candidates)} candidates — fetching coordinates...", file=sys.stderr)

    link_targets = [lt for _, lt, _, _ in candidates]
    coords = fetch_coordinates(link_targets)

    # Split candidates into those with and without coordinates
    by_region: dict[str, list] = {}
    no_coords: list[tuple] = []
    for region, link_target, display, location in candidates:
        coord = coords.get(link_target)
        if coord:
            by_region.setdefault(region, []).append((display, link_target, location, coord))
        else:
            no_coords.append((region, display, link_target, location))

    lines = [
        "# Potential new LHFA candidates",
        "",
        f"Hillforts listed on [Sąrašas:Lietuvos piliakalniai]({wiki_url(LIST_PAGE)})"
        " whose Vietovė(s) location was not found in the LHFA database.",
        "",
        f"**Total: {len(candidates)} candidates across {len(set(r for r, *_ in candidates))} regions"
        f" ({len(candidates) - len(no_coords)} with coordinates, {len(no_coords)} without)**",
        "",
        "---",
        "",
    ]

    lines.append("| Hillfort | Location | Region | Coordinates |")
    lines.append("|---|---|---|---|")
    for region in sorted(by_region):
        for display, link_target, location, coord in sorted(by_region[region]):
            url = wiki_url(link_target)
            coord_str = f"{coord[0]:.6f}°N, {coord[1]:.6f}°E"
            lines.append(f"| [{display}]({url}) | {location} | {region} | {coord_str} |")
    lines.append("")

    if no_coords:
        lines += [
            "---",
            "",
            "## No coordinates available",
            "",
        ]
        for region, display, link_target, location in sorted(no_coords):
            url = wiki_url(link_target)
            lines.append(f"- [{display}]({url}) — {location} *({region})*")
        lines.append("")

    with open(OUTPUT, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))

    print(f"Written to {OUTPUT}", file=sys.stderr)


if __name__ == "__main__":
    main()
