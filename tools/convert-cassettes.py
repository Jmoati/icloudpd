#!/usr/bin/env python3
"""Convert the vcrpy YAML cassettes of upstream icloudpd into gzipped JSON for the PHP tests.

Usage: tools/convert-cassettes.py [~/icloud_photos_downloader/tests/vcr_cassettes] [tests/vcr_cassettes]

Run it with the Python of the upstream venv (it needs PyYAML, which vcrpy pulls in).
The YAML files use python-specific tags (!!python/unicode, !!binary) that PHP YAML
parsers do not understand, so the conversion is done once here, in Python.
Binary bodies are stored base64-encoded under "base64" instead of "string".
"""
import base64
import gzip
import json
import os
import sys

import yaml

src = os.path.expanduser(sys.argv[1] if len(sys.argv) > 1 else "~/icloud_photos_downloader/tests/vcr_cassettes")
dst = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(__file__), "..", "tests", "vcr_cassettes")


def body(value):
    if value is None:
        return None
    if isinstance(value, dict):  # response body: {string: ...}
        value = value.get("string")
    if isinstance(value, bytes):
        try:
            return {"string": value.decode("utf-8")}
        except UnicodeDecodeError:
            return {"base64": base64.b64encode(value).decode("ascii")}
    return {"string": value}


for name in sorted(os.listdir(src)):
    if not name.endswith(".yml"):
        continue
    with open(os.path.join(src, name), "rb") as f:
        data = yaml.load(f, Loader=yaml.UnsafeLoader)
    out = {"version": data.get("version", 1), "interactions": []}
    for it in data["interactions"]:
        req, res = it["request"], it["response"]
        out["interactions"].append(
            {
                "request": {
                    "method": req["method"],
                    "uri": req["uri"],
                    "headers": req.get("headers", {}),
                    "body": body(req.get("body")),
                },
                "response": {
                    "status": res["status"],
                    "headers": res.get("headers", {}),
                    "body": body(res.get("body")),
                },
            }
        )
    target = os.path.join(dst, name[:-4] + ".json.gz")
    with gzip.open(target, "wt", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False)
    print(f"{name}: {len(out['interactions'])} interactions -> {os.path.getsize(target) // 1024} KiB")
