# icloudpd (PHP)

Pure PHP port of [icloud_photos_downloader](https://github.com/icloud-photos-downloader/icloud_photos_downloader)
1.32.3: download photos and videos from iCloud without Python.

Same command line, same options, same output and same cookie/session files as the Python tool, so an
existing `--cookie-directory` can be reused. Porting rules and the status of each module are in
[PORTING.md](PORTING.md).

```bash
bin/icloudpd --directory ./Photos --username jacques@example.com --folder-structure "{:%Y/%m/%d}" --size original --until-found 100
```

Requires PHP 8.5 with `curl`, `gmp`, `mbstring`, `openssl` and `zlib`; EXIF dates are read and written with
[jmoati/image-exiftool](https://github.com/Jmoati/image-exiftool).

## Status (2026-10-22)

Every module of upstream 1.32.3 is ported except the Flask WebUI (`--mfa-provider webui`,
`--password-provider webui` give a clear error). Upstream PR #1392 is applied on top (`--adp-web-access`, for
accounts with Advanced Data Protection; not merged upstream as of 2026-10-22). The 223 upstream test functions (and the 7 of the PR) are ported to PHPUnit
under their names and replay the upstream HTTP cassettes (`tests/vcr_cassettes/`, converted once by
`tools/convert-cassettes.py`); the suite also checks the port against Python itself (string formatting,
`json.dumps`, `strftime`, argparse help, SRP proofs, cookie files, XMP sidecars, plists, SMTP transcripts):
`vendor/bin/phpunit` runs 1 794 tests, 6 skipped like upstream.

Tests: `vendor/bin/phpunit` (the upstream test suite ported to PHPUnit, replaying the upstream HTTP cassettes).
