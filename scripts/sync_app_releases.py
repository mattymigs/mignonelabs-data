"""Archive public Apple releases; standard library only, no credentials.

A failed lookup never replaces an archive. Dates come from Apple, not the clock.
The same script supports future app JSON files with an app.appleId field.
"""
from __future__ import annotations

import copy
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class InvalidRelease(ValueError):
    """Remote or existing data failed a validation check."""


def version_key(value: Any) -> tuple[int, ...]:
    if not isinstance(value, str) or not re.fullmatch(r"\d+(?:\.\d+){0,3}", value):
        raise InvalidRelease("Invalid numeric release version")
    parts = tuple(int(part) for part in value.split("."))
    return parts + (0,) * (4 - len(parts))


def merge_release(data: dict[str, Any], payload: dict[str, Any]) -> dict[str, Any]:
    config = data.get("app", {})
    apple_id = str(config.get("appleId", ""))
    if not apple_id.isdigit():
        raise InvalidRelease("Missing or invalid configured appleId")
    results = payload.get("results")
    if payload.get("resultCount") != 1 or not isinstance(results, list) or len(results) != 1:
        raise InvalidRelease("Apple did not return exactly one app")
    app = results[0]
    if not isinstance(app, dict) or str(app.get("trackId")) != apple_id:
        raise InvalidRelease("Apple returned the wrong app ID")
    version = app.get("version")
    current_key = version_key(version)
    raw_date = app.get("currentVersionReleaseDate")
    if not isinstance(raw_date, str):
        raise InvalidRelease("Apple did not supply a release timestamp")
    try:
        released = datetime.fromisoformat(raw_date.replace("Z", "+00:00"))
    except ValueError as error:
        raise InvalidRelease("Invalid Apple release timestamp") from error
    if released.tzinfo is None:
        raise InvalidRelease("Apple release timestamp has no timezone")
    released = released.astimezone(timezone.utc)
    notes = app.get("releaseNotes")
    if not isinstance(notes, str) or not notes.strip() or len(notes) > 30000:
        raise InvalidRelease("Missing or oversized release notes")
    existing = data.get("releases", [])
    if not isinstance(existing, list) or len(existing) > 200:
        raise InvalidRelease("Invalid existing archive")
    seen: set[str] = set()
    for item in existing:
        if not isinstance(item, dict) or not isinstance(item.get("releaseNotes"), str):
            raise InvalidRelease("Invalid existing release")
        key = version_key(item.get("version"))
        if item["version"] in seen:
            raise InvalidRelease("Duplicate existing release version")
        seen.add(item["version"])
        if key > current_key:
            raise InvalidRelease("Apple lookup is older than the archive; preserving existing data")
    country = str(config.get("country", "us")).lower()
    if not re.fullmatch(r"[a-z]{2}", country):
        raise InvalidRelease("Invalid storefront country")
    url = app.get("trackViewUrl", f"https://apps.apple.com/{country}/app/id{apple_id}")
    if not isinstance(url, str):
        raise InvalidRelease("Invalid App Store URL")
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https" or parsed.netloc != "apps.apple.com":
        raise InvalidRelease("Unexpected App Store URL host")
    previous = next((item for item in existing if item["version"] == version), {})
    latest = {
        **previous,
        "version": version,
        "releaseDate": released.date().isoformat(),
        "releasedAt": released.isoformat(timespec="seconds").replace("+00:00", "Z"),
        "releaseNotes": notes,
        "appStoreUrl": url,
        "source": "Apple public lookup API",
    }
    merged = copy.deepcopy(data)
    merged["releases"] = [latest] + [item for item in existing if item["version"] != version]
    merged["releases"].sort(key=lambda item: version_key(item["version"]), reverse=True)
    return merged


def fetch_release(apple_id: str, country: str) -> dict[str, Any]:
    query = urllib.parse.urlencode({"id": apple_id, "country": country})
    request = urllib.request.Request(
        "https://itunes.apple.com/lookup?" + query,
        headers={"User-Agent": "MignoneLabsReleaseBot/2.0", "Accept": "application/json"},
    )
    for attempt in range(3):
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                raw = response.read(1048577)
            if len(raw) > 1048576:
                raise InvalidRelease("Apple response is too large")
            payload = json.loads(raw)
            if not isinstance(payload, dict):
                raise InvalidRelease("Apple response is not an object")
            return payload
        except (urllib.error.URLError, TimeoutError):
            if attempt == 2:
                raise
            time.sleep(2 ** attempt)
    raise RuntimeError("Unreachable retry state")


def sync_file(path: Path) -> bool:
    before = path.read_text(encoding="utf-8")
    data = json.loads(before)
    if not isinstance(data, dict):
        raise InvalidRelease("App file is not an object")
    config = data.get("app", {})
    apple_id = str(config.get("appleId", ""))
    country = str(config.get("country", "us")).lower()
    if not apple_id.isdigit() or not re.fullmatch(r"[a-z]{2}", country):
        raise InvalidRelease("Invalid app configuration")
    merged = merge_release(data, fetch_release(apple_id, country))
    after = json.dumps(merged, indent=2, ensure_ascii=False) + "\n"
    if before == after:
        print(f"{path.name}: unchanged ({merged['releases'][0]['version']})")
        return False
    temporary = path.with_suffix(".json.tmp")
    try:
        temporary.write_text(after, encoding="utf-8")
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)
    print(f"{path.name}: archived {merged['releases'][0]['version']}")
    return True


def main() -> int:
    failed = False
    for path in sorted(Path("apps").glob("*.json")):
        try:
            sync_file(path)
        except (ValueError, OSError, urllib.error.URLError) as error:
            print(f"ERROR {path.name}: {error}", file=sys.stderr)
            failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
