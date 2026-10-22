# Porting rules (Python icloudpd → PHP)

This repository is a **faithful, file-for-file port** of icloud_photos_downloader 1.32.3
(`~/icloud_photos_downloader`, git clone of github.com/icloud-photos-downloader/icloud_photos_downloader,
tag `v1.32.3`). It is not a rewrite: keep the upstream structure, function names, argument order,
variable names, docstrings, comments and control flow, so that upstream releases can be diffed and
re-applied. Same rules as the ExifTool port in `~/Sites/image-exiftool/PORTING.md`.

The upstream tests (261 cases, replayed against recorded HTTP cassettes) are the parity bench: the
port must issue **the same HTTP requests in the same order** and print the same output.

## Layout

A Python dotted path becomes a PHP namespace path, PascalCase per segment (`icloudpd` → `Icloudpd`,
`pyicloud_ipd` → `PyicloudIpd`, `foundation` → `Foundation`). PSR-4, one class per file.

| Python | PHP |
|---|---|
| module `icloudpd/download.py` (functions `download_media`…) | `src/Icloudpd/Download.php`, `final class Download` with `public static function download_media(...)` (same snake_case names) |
| class `pyicloud_ipd.services.photos.PhotoAsset` | `src/PyicloudIpd/Services/Photos/PhotoAsset.php`, `PyicloudIpd\Services\Photos\PhotoAsset` (the module is a namespace segment, the class keeps its name) |
| module-level functions and constants of a module that also has classes (`photos.py`) | `src/PyicloudIpd/Services/Photos.php`, class `Photos` |
| `pyicloud_ipd/exceptions.py` | one file per exception under `src/PyicloudIpd/Exceptions/`, class `PyicloudIpd\Exceptions\PyiCloudAPIResponseException` |
| package `foundation/core/__init__.py` | `src/Foundation/Core.php`, class `Core` (a package `__init__` is a module named after the package) |
| `foundation/core/optional/__init__.py` | `src/Foundation/Core/Optional.php` |
| `src/starters/icloudpd.py`, `src/starters/icloud.py` (entry points) | `bin/icloudpd`, `bin/icloud` |
| `icloudpd/server/` (Flask WebUI for MFA/password) | **not ported yet** (`--mfa-provider webui`, `--password-provider webui` give a clear error) |
| `tests/test_download_photos.py` (`class DownloadPhotoTestCase(TestCase)`) | `tests/DownloadPhotosTest.php`, `Tests\DownloadPhotosTest extends TestCase` (PHPUnit), same test method names |
| `tests/helpers/__init__.py` | `tests/Helpers.php`, class `Tests\Helpers` (static functions, same names) |
| `tests/vcr_cassettes/*.yml` | `tests/vcr_cassettes/*.json.gz`, converted once by `tools/convert-cassettes.py` (never edit) |
| `tests/cookie/`, `tests/data/` | copied as is |

Enums (`class ItemType(Enum)`) become PHP backed enums with the same case names and values.
Dataclasses become `final class` with public typed properties and a constructor in the same field order.

## Python → PHP

| Python | PHP |
|---|---|
| `None` / `Optional[T]` | `null` / `?T` |
| `str` | `string` (UTF-8 text, use `mb_*` only where Python uses character semantics) |
| `bytes` | `string` (binary) |
| `dict` / `Mapping` | `array` (string keys); `TypedDict` → `array` with the same keys |
| `list` / `Sequence` / `tuple` | `array` (list); a tuple used as a record → `array` with the same order |
| `Iterable` / generator (`yield`) | `iterable` / `Generator` (`yield`) |
| `Callable[[A], B]` | `Closure` / `callable`; curried helpers of `foundation.core` return `Closure` |
| `datetime` (aware/naive), `timedelta`, `tzinfo` | `DateTimeImmutable` (naive = `DateTimeImmutable` in UTC with a `naive` flag carried by `Foundation\Py\Datetime` helpers where the distinction matters), `DateInterval`, `DateTimeZone` |
| `Enum` | backed `enum` |
| `@dataclass` | `final class` with public properties |
| `raise`/`except` | `throw`/`catch`; Python exception classes → PHP exception classes with the same names; `except Exception` → `catch (\Throwable)` only where upstream catches everything |
| `with open(...) as f` | `fopen`/`fwrite`/`fclose` in `try/finally` |
| `f"..."`, `"...".format()`, `%` | string interpolation, `sprintf`; a format string that comes **from the user** (`--folder-structure "{:%Y/%m/%d}"`) is interpreted by `Foundation\Py::format()` with Python semantics |
| `strftime(fmt)` | `Foundation\Py::strftime($dt, $fmt)` (Python `%Y %m %d %H %M %S %j %Z %z %%`…) |
| `str(float)`, `repr()` | `Foundation\Py::str()`, `Foundation\Py::repr()` (only where the result is printed or compared) |
| `json.dump(data, f)` / `json.dumps` | `Foundation\Py::json_dumps()` (Python separators `", "` / `": "`, `ensure_ascii`) so that files are byte-identical with the Python tool (session files are shared with it) |
| `os.path.*` | `Foundation\Os::path_join()`… thin wrappers in `Foundation\Os` (Python semantics: `join` restarts at an absolute component, `normpath`, `expanduser`) |
| `logging` | `Foundation\Logging` (`getLogger`, levels, `StreamHandler`, `Formatter` with `%(asctime)s %(levelname)-8s %(message)s`, `Filter`, propagation) — the subset icloudpd uses |
| `tqdm` | `Foundation\Tqdm` (the subset used: `total`, `desc`, `disable`, `update`, `set_description`, `write`, `n`, `close`) |
| `argparse` | `Foundation\Argparse` (the subset used by `cli.py`; `--help` output must match upstream) |
| `requests.Session` / `Response` | `Foundation\Http\Session` / `Foundation\Http\Response` on `ext-curl`; cookies in `Foundation\Http\LWPCookieJar` (the `#LWP-Cookies-2.0` file format, so that cookie directories of the Python tool are reusable) |
| `srp` (Apple SRP-6a, `s2k` / `s2k_fo`) | `PyicloudIpd\Srp` on `ext-gmp` |
| `piexif` | `jmoati/image-exiftool` (`Image\ExifTool\ExifTool`): read `ExifIFD:DateTimeOriginal`, write `IFD1:ModifyDate`, `ExifIFD:DateTimeOriginal`, `ExifIFD:CreateDate` |
| `plistlib.loads` (binary plist) | `Foundation\Plist::loads()` (bplist00 reader) |
| `zlib.decompress(data, -zlib.MAX_WBITS)` | `gzinflate()` |
| `xml.etree.ElementTree` | `XMLWriter` / `DOMDocument` producing the same XML text |
| `smtplib` | `Foundation\Smtp` (minimal client: `ehlo`, `starttls`, `login`, `sendmail`, `quit`) |
| `keyring` | `PyicloudIpd\Keyring`: `security` on macOS, `secret-tool` on Linux, otherwise "not available" (`ponytail:` shortcut) |
| `threading.Lock`, `multiprocessing` | nothing (single thread): `Counter` is a plain int, `StatusExchange` has no lock |
| `time.sleep`, `os.utime`, `datetime.now()` | `Foundation\Time::sleep()`, `Foundation\Os::utime()`, `Foundation\Clock::now()` — patchable (see below) |

Type hints: use PHP types on parameters and return values (`string`, `int`, `?array`, `callable`,
class names) as the Python annotations say; `Any` → `mixed`. No `declare(strict_types=1)`.

## Patching (the `mock.patch` of the upstream tests)

Upstream tests patch functions and methods (`mock.patch("icloudpd.download.download_media")`,
`mock.patch.object(PhotoAsset, "download")`, `time.sleep`, `os.utime`, `piexif.insert`,
`smtplib.SMTP`, `datetime.datetime`…). PHP cannot monkeypatch, so every function or method that an
upstream test patches starts with the one-line guard of `Foundation\Patch`:

```php
public static function download_media(Logger $logger, bool $dry_run, PyiCloudService $icloud, ...): bool
{
    if ($p = Patch::get(__METHOD__)) return $p($logger, $dry_run, $icloud, ...);
    ...
```

For an instance method, the closure receives `$this` first, then the arguments. Tests install a patch
with `Patch::set('Icloudpd\Download::download_media', fn(...) => ...)`; `Tests\TestCase::tearDown()`
clears them. Builtins that tests patch get a thin static wrapper (`Foundation\Time::sleep`,
`Foundation\Os::utime`, `Foundation\Clock::now`, `Icloudpd\ExifDatetime::...`) with the same guard.
Nothing else is made patchable.

## HTTP replay (the `vcr` of the upstream tests)

`Foundation\Http\Session` sends every request through `Session::$transport` (a
`callable(Request): Response`, default: curl). `Tests\Vcr::use_cassette($name, fn() => ...)` replaces
it with a replayer that pops interactions from `tests/vcr_cassettes/<name>.json.gz` in order,
matching like vcrpy's default (`method`, `scheme`, `host`, `port`, `path`, `query`) and failing the
test on an unexpected request (vcrpy `record_mode="none"`). Cassette bodies are `{"string": ...}` or
`{"base64": ...}` for binary content; responses are already decompressed (vcrpy
`decode_compressed_response=True`), so the replayer drops `Content-Encoding`.

## Tests

PHPUnit (`vendor/bin/phpunit`), one PHP test class per upstream test file, same test names, same
assertions (`assertEqual` → `assertSame`, `assertIn(x, s)` → `assertStringContainsString`,
`assertIsNone` → `assertNull`…). `Tests\Helpers::run_icloudpd_test()` mirrors the upstream helper
(prepares `data/` and `cookie/` in a temp dir, runs the CLI in-process with captured stdout/stderr,
returns `[data_dir, TestResult]`). Tests run with `TZ=Etc/UTC` (`phpunit.xml` sets it): upstream's
own suite only passes in UTC. Upstream tests must be run with their venv for reference:
`cd ~/icloud_photos_downloader && TZ=Etc/UTC .venv/bin/python -m pytest -q -n 8`.

## Style

- Keep upstream docstrings as PHPDoc and upstream comments verbatim (English).
- Mark deliberate simplifications with a `ponytail:` comment naming the ceiling and the upgrade path.
- Composer: `composer dump-autoload` is not needed (PSR-4), but new extensions go in `composer.json`.
