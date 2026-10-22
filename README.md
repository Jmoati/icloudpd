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

Tests: `vendor/bin/phpunit` (the upstream test suite ported to PHPUnit, replaying the upstream HTTP cassettes).
