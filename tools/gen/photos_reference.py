#!/usr/bin/env python
"""Reference values for tests/PhotosServiceTest.php.

Replays the upstream VCR cassettes (tests/vcr_cassettes/*.yml of icloud_photos_downloader) through
the *Python* PyiCloudService / PhotosService and prints, as JSON, the HTTP requests sent and the
values computed from the responses (filenames, dates, item types, versions, download URLs...).

    cd ~/icloud_photos_downloader && TZ=Etc/UTC .venv/bin/python -I ~/Sites/icloudpd/tools/gen/photos_reference.py > expected_utc.json
    cd ~/icloud_photos_downloader && TZ=Europe/Paris .venv/bin/python -I ~/Sites/icloudpd/tools/gen/photos_reference.py > expected_paris.json

The scenarios mimic what icloudpd/base.py does with the photos service (list libraries, list albums,
`len(album)` then `islice(album, n)`), so that the requests are the same as in the upstream tests.
"""

import itertools
import json
import os
import shutil
import sys
import tempfile
from typing import Any, Callable, Dict, List
from urllib.parse import parse_qsl, urlsplit

import requests
from vcr import VCR

from icloudpd.base import lp_filename_concatinator, lp_filename_original
from icloudpd.filename_policies import build_filename_with_policies
from pyicloud_ipd.base import PyiCloudService
from pyicloud_ipd.file_match import FileMatchPolicy
from pyicloud_ipd.raw_policy import RawTreatmentPolicy
from pyicloud_ipd.services.photos import (
    PhotoAsset,
    PhotoLibrary,
    generate_fingerprint_filename,
)

UPSTREAM = os.path.expanduser("~/icloud_photos_downloader")
CASSETTES = os.path.join(UPSTREAM, "tests", "vcr_cassettes")
COOKIES = os.path.join(UPSTREAM, "tests", "cookie")
CLIENT_ID = "DE309E26-942E-11E8-92F5-14109FE0B321"

vcr = VCR(decode_compressed_response=True, record_mode="none")

LOG: List[Dict[str, Any]] = []
_orig_send = requests.sessions.Session.send


def _send(self: requests.Session, request: requests.PreparedRequest, **kwargs: Any) -> Any:
    body = request.body
    if isinstance(body, bytes):
        body = body.decode("utf-8")
    try:
        parsed = json.loads(body) if body else None
    except ValueError:
        parsed = body
    p = urlsplit(str(request.url))
    LOG.append(
        {
            "method": request.method,
            "url": f"{p.scheme}://{p.netloc}{p.path}",
            "query": [list(kv) for kv in parse_qsl(p.query, keep_blank_values=True)],
            "body": parsed,
            "range": request.headers.get("Range"),
            "content_type": request.headers.get("Content-type"),
        }
    )
    return _orig_send(self, request, **kwargs)


requests.sessions.Session.send = _send  # type: ignore[method-assign]


def safe(f: Callable[[], Any]) -> Any:
    try:
        return f()
    except Exception as e:
        return {"$error": type(e).__name__, "message": str(e)}


def dt(d: Any) -> Any:
    if isinstance(d, dict):
        return d
    out = {"ts": f"{d.timestamp():.6f}"}
    if d.tzinfo is not None:
        out["iso"] = d.isoformat(timespec="microseconds")
    return out


def enum_key(k: Any) -> str:
    return f"{type(k).__name__}.{k.name}"


def info(p: PhotoAsset) -> Dict[str, Any]:
    d: Dict[str, Any] = {}
    d["id"] = p.id
    d["calculate_filename"] = safe(p.calculate_filename)
    d["filename"] = safe(lambda: p.filename)
    d["item_type"] = safe(lambda: None if p.item_type is None else p.item_type.name)
    d["item_type_extension"] = p.item_type_extension
    d["fingerprint_filename"] = generate_fingerprint_filename(p.id, p.item_type_extension)
    d["filename_original_policy"] = safe(
        lambda: build_filename_with_policies(FileMatchPolicy.NAME_SIZE_DEDUP_WITH_SUFFIX, lambda s: s, p)
    )
    d["filename_id7_policy"] = safe(
        lambda: build_filename_with_policies(FileMatchPolicy.NAME_ID7, lambda s: s, p)
    )
    d["size"] = safe(lambda: p.size)
    d["dimensions"] = safe(lambda: list(p.dimensions))
    d["asset_date"] = safe(lambda: dt(p.asset_date))
    d["added_date"] = safe(lambda: dt(p.added_date))
    d["created"] = safe(lambda: dt(p.created))

    def versions() -> List[Any]:
        return [
            {
                "key": enum_key(k),
                "size": v.size,
                "url": v.url,
                "type": v.type,
                "checksum": v.checksum,
                "filename_original": safe(
                    lambda: p.calculate_version_filename(v, k, lp_filename_original)
                ),
                "filename_concat": safe(
                    lambda: p.calculate_version_filename(v, k, lp_filename_concatinator)
                ),
            }
            for k, v in p.versions.items()
        ]

    d["versions"] = safe(versions)
    d["raw"] = {
        policy.name: safe(
            lambda policy=policy: [
                [enum_key(k), v.type, v.url, v.size]
                for k, v in p.versions_with_raw_policy(policy).items()
            ]
        )
        for policy in RawTreatmentPolicy
    }
    d["repr"] = repr(p)
    return d


def compact(p: PhotoAsset) -> List[Any]:
    return [
        p.id,
        safe(lambda: p.filename),
        safe(lambda: None if p.item_type is None else p.item_type.name),
        safe(lambda: dt(p.created)),
        safe(lambda: [enum_key(k) for k in p.versions.keys()]),
    ]


def service(tmp: str) -> PyiCloudService:
    cookie_dir = os.path.join(tmp, "cookie")
    shutil.copytree(COOKIES, cookie_dir)
    return PyiCloudService(
        "com", "jdoe@gmail.com", lambda: "password1", None, cookie_dir, True, CLIENT_ID
    )


def album_meta(a: Any) -> Dict[str, Any]:
    return {
        "name": a.name,
        "str": str(a),
        "repr": repr(a),
        "title": a.title,
        "list_type": a.list_type,
        "obj_type": a.obj_type,
        "query_filter": a.query_filter,
        "page_size": a.page_size,
        "zone_id": a._zone_id,
        "offset": a.offset,
    }


def run(cassette: str, f: Callable[[PyiCloudService], Any]) -> Dict[str, Any]:
    LOG.clear()
    with tempfile.TemporaryDirectory() as tmp:
        with vcr.use_cassette(os.path.join(CASSETTES, cassette + ".yml")):
            result = safe(lambda: f(service(tmp)))
    return {"result": result, "requests": list(LOG)}


def libraries(icloud: PyiCloudService) -> Any:
    # `--list-libraries`
    names = icloud.photos.private_libraries.keys() | icloud.photos.shared_libraries.keys()
    return {
        "private": list(icloud.photos.private_libraries.keys()),
        "shared": list(icloud.photos.shared_libraries.keys()),
        "union": sorted(names),
        "types": {
            k: [v.library_type, v.zone_id, v.service_endpoint]
            for k, v in {
                **icloud.photos.private_libraries,
                **icloud.photos.shared_libraries,
            }.items()
        },
    }


def library_of(icloud: PyiCloudService, name: str) -> PhotoLibrary:
    # `--library NAME` (base.py: the default is PrimarySync)
    if name in icloud.photos.private_libraries:
        return icloud.photos.private_libraries[name]
    return icloud.photos.shared_libraries[name]


def albums(icloud: PyiCloudService) -> Any:
    # `--list-albums`
    library: PhotoLibrary = library_of(icloud, "PrimarySync")
    all_albums = library.albums
    return {
        "titles": [str(a) for a in all_albums.values()],
        "albums": [album_meta(a) for a in all_albums.values()],
        "all": album_meta(library.all),
        "recently_deleted": album_meta(library.recently_deleted),
        "service_endpoint": library.service_endpoint,
        "params": library.params,
    }


def photos(
    n: int, full: int = 5, library: str = "PrimarySync"
) -> Callable[[PyiCloudService], Any]:
    def _photos(icloud: PyiCloudService) -> Any:
        # `photos_count = len(album)` then `islice(album, n)`
        album = library_of(icloud, library).all
        count = len(album)
        items = list(itertools.islice(album, n))
        return {
            "count": count,
            "offset": album.offset,
            "n": len(items),
            "full": [info(p) for p in items[:full]],
            "compact": [compact(p) for p in items],
        }

    return _photos


def deleted(n: int, full: int = 5) -> Callable[[PyiCloudService], Any]:
    def _deleted(icloud: PyiCloudService) -> Any:
        # autodelete: `for media in library_object.recently_deleted`
        items = list(itertools.islice(library_of(icloud, "PrimarySync").recently_deleted, n))
        return {
            "n": len(items),
            "full": [info(p) for p in items[:full]],
            "compact": [compact(p) for p in items],
        }

    return _deleted


SCENARIOS: Dict[str, Callable[[], Dict[str, Any]]] = {
    "listing_albums/libraries": lambda: run("listing_albums", libraries),
    "listing_albums/albums": lambda: run("listing_albums", albums),
    "listing_photos/photos": lambda: run("listing_photos", photos(100)),
    # the shared library has one photo
    "listing_photos/shared": lambda: run(
        "listing_photos", photos(1, library="SharedSync-00000000-1111-2222-3333-444444444444")
    ),
    "listing_videos/photos": lambda: run("listing_videos", photos(100)),
    "listing_photos_resume/photos": lambda: run("listing_photos_resume", photos(100)),
    "listing_photos_raw/photos": lambda: run("listing_photos_raw", photos(100)),
    "listing_photos_raw_alt/photos": lambda: run("listing_photos_raw_alt", photos(100)),
    "listing_photos_raw_alt_adj/photos": lambda: run("listing_photos_raw_alt_adj", photos(100)),
    "listing_photos_bad_filename/photos": lambda: run("listing_photos_bad_filename", photos(100)),
    "listing_photos_bad_filename_base64_encoding/photos": lambda: run(
        "listing_photos_bad_filename_base64_encoding", photos(100)
    ),
    "listing_photos_bad_filename_utf8_encoding/photos": lambda: run(
        "listing_photos_bad_filename_utf8_encoding", photos(100)
    ),
    "listing_photos_filename_string_encoding/photos": lambda: run(
        "listing_photos_filename_string_encoding", photos(100)
    ),
    "listing_photos_chinese/photos": lambda: run("listing_photos_chinese", photos(100)),
    "listing_photos_missing_filenameEnc/photos": lambda: run(
        "listing_photos_missing_filenameEnc", photos(100)
    ),
    "listing_photos_missing_downloadUrl/photos": lambda: run(
        "listing_photos_missing_downloadUrl", photos(100)
    ),
    "listing_photos_missing_item_type/photos": lambda: run(
        "listing_photos_missing_item_type", photos(100)
    ),
    "listing_photos_missing_item_type_value/photos": lambda: run(
        "listing_photos_missing_item_type_value", photos(100)
    ),
    "listing_photos_two_sizes/photos": lambda: run("listing_photos_two_sizes", photos(100)),
    "download_live_photos/photos": lambda: run("download_live_photos", photos(100)),
    "autodelete_photos/deleted": lambda: run("autodelete_photos", deleted(16)),
    "autodelete_photos_heic/deleted": lambda: run("autodelete_photos_heic", deleted(16)),
}


def main() -> None:
    only = sys.argv[1:]
    out = {k: f() for k, f in SCENARIOS.items() if not only or k in only}
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1, sort_keys=False)


if __name__ == "__main__":
    main()
