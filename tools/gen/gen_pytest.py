"""Generates tests/PyTest.php: inputs built in Python, expected values produced by Python itself."""
import datetime, json, locale, math, sys
from zoneinfo import ZoneInfo

D = datetime.datetime
UTC = datetime.timezone.utc
PAR = ZoneInfo('Europe/Paris')
OFF2 = datetime.timezone(datetime.timedelta(hours=2))
OFFM = datetime.timezone(datetime.timedelta(hours=-5, minutes=-30))


def php_str(s):
    out = []
    for ch in s:
        o = ord(ch)
        if ch == '\\': out.append('\\\\')
        elif ch == '"': out.append('\\"')
        elif ch == '$': out.append('\\$')
        elif ch == '\n': out.append('\\n')
        elif ch == '\r': out.append('\\r')
        elif ch == '\t': out.append('\\t')
        elif o < 0x20 or o == 0x7f: out.append('\\x%02x' % o)
        elif o < 0x7f: out.append(ch)
        else: out.append('\\u{%x}' % o)
    return '"' + ''.join(out) + '"'


def tzname(tz):
    if tz is UTC: return 'UTC'
    if tz is PAR: return 'Europe/Paris'
    if tz is OFF2: return '+02:00'
    if tz is OFFM: return '-05:30'
    raise ValueError(tz)


def php(v):
    if v is None: return 'null'
    if v is True: return 'true'
    if v is False: return 'false'
    if isinstance(v, int): return str(v)
    if isinstance(v, float):
        if math.isnan(v): return 'NAN'
        if math.isinf(v): return 'INF' if v > 0 else '-INF'
        r = repr(v)
        return r if ('.' in r or 'e' in r) else r + '.0'
    if isinstance(v, str): return php_str(v)
    if isinstance(v, D):
        return "new \\DateTimeImmutable(%s, new \\DateTimeZone(%s))" % (php_str(v.replace(tzinfo=None).isoformat(sep=' ', timespec='microseconds')), php_str(tzname(v.tzinfo)))
    if isinstance(v, tuple): return '\\SplFixedArray::fromArray([%s])' % ', '.join(php(x) for x in v)
    if isinstance(v, list): return '[%s]' % ', '.join(php(x) for x in v)
    if isinstance(v, dict):
        if not v: return 'new \\stdClass'
        return '[%s]' % ', '.join('%s => %s' % (php(k), php(x)) for k, x in v.items())
    raise TypeError(type(v))


def args_php(a):  # a Python tuple of arguments → PHP list expr
    return '[%s]' % ', '.join(php(x) for x in a)


HEADER = '<?php\n\nnamespace Tests;\n\nuse Foundation\\Py;\nuse PHPUnit\\Framework\\Attributes\\DataProvider;\n\n/**\n * `Foundation\\Py` against Python 3.13: the expected values below were produced by Python itself\n * (`str`, `repr`, `datetime.strftime`, `str.format`, `%`, `json.dumps`) from the same inputs, with TZ=Etc/UTC.\n * `[\'!\' => \'ValueError\']` is an expected Python exception. Generated: do not edit the data by hand.\n */\nclass PyTest extends TestCase\n{\n    private const EXCEPTIONS = [\n        \'ValueError\' => \\ValueError::class,\n        \'TypeError\' => \\TypeError::class,\n        \'IndexError\' => \\OutOfRangeException::class,\n        \'KeyError\' => \\OutOfBoundsException::class,\n        \'AttributeError\' => \\Error::class,\n    ];\n\n    private function check(callable $f, mixed $expected): void\n    {\n        if (is_array($expected) && isset($expected[\'!\'])) {\n            $this->expectException(self::EXCEPTIONS[$expected[\'!\']]);\n            $f();\n            return;\n        }\n        $this->assertSame($expected, $f());\n    }\n\n    #[DataProvider(\'str_cases\')]\n    public function test_str(mixed $value, string $expected): void\n    {\n        $this->assertSame($expected, Py::str($value));\n    }\n\n    #[DataProvider(\'repr_cases\')]\n    public function test_repr(mixed $value, string $expected): void\n    {\n        $this->assertSame($expected, Py::repr($value));\n    }\n\n    #[DataProvider(\'strftime_cases\')]\n    public function test_strftime(\\DateTimeInterface $dt, string $fmt, string $expected): void\n    {\n        $this->assertSame($expected, Py::strftime($dt, $fmt));\n    }\n\n    #[DataProvider(\'strftime_de_cases\')]\n    public function test_strftime_locale(\\DateTimeInterface $dt, string $fmt, string $expected): void\n    {\n        // what `--use-os-locale` does: setlocale(LC_ALL, "")\n        if (setlocale(LC_ALL, \'de_DE.UTF-8\') === false) {\n            $this->markTestSkipped(\'de_DE.UTF-8 is not available\');\n        }\n        try {\n            $this->assertSame($expected, Py::strftime($dt, $fmt));\n        } finally {\n            setlocale(LC_ALL, \'C\');\n        }\n    }\n\n    #[DataProvider(\'format_cases\')]\n    public function test_format(string $fmt, array $args, array $kwargs, mixed $expected): void\n    {\n        $this->check(fn () => Py::format($fmt, ...array_merge($args, $kwargs)), $expected);\n    }\n\n    #[DataProvider(\'mod_format_cases\')]\n    public function test_mod_format(string $fmt, array $args, mixed $expected): void\n    {\n        $this->check(fn () => Py::mod_format($fmt, $args), $expected);\n    }\n\n    #[DataProvider(\'json_dumps_cases\')]\n    public function test_json_dumps(mixed $data, bool $ensure_ascii, ?int $indent, string $expected, string $name): void\n    {\n        $this->assertSame($expected, Py::json_dumps($data, $ensure_ascii, $indent), $name);\n    }\n\n'
out = []
def emit(s=''): out.append(s)

# ---------------------------------------------------------------- str / repr
floats = [0.0, -0.0, 1.0, -1.0, 1.5, 0.1, 0.1 + 0.2, 1 / 3, 2 / 3, 100.0, 12345.678, 123456789.123456789, 1e15, 1e16, 1e17, 1e22,
          123456789012345678.0, 1.5e300, 1e-4, 1e-5, 2.5e-5, 0.000123, 5e-324, 1.7976931348623157e308, 9007199254740993.0,
          float('inf'), float('-inf'), float('nan'), 1e21, 3.14159, 2.5e-10, 1e100, 123456.789e3]
ints = [0, -5, 42, 2 ** 40, -2 ** 40]
strings = ['', 'abc', "it's", 'say "hi"', "both ' and \"", 'tab\t nl\n cr\r bs\\', 'é 日本語 😀', '\x00\x1f\x7f', '​', '\xa0', ' ',
           '\x80\x9f', 'é', '\U000e0001', 'á', '100%', "'", '"', '\\']
nested = [[], [1, 2, 3], ['a', None, True, False, 1.5], {'a': 1, 'b': [1, 'x', None, True, 2.5]}, {'k': {'n': {'d': []}}},
          {1: 'x', 2: 'y'}, [[1, [2, [3]]]], (1, 2), (1,), (), ('a', (1, 2)), [{'a': {}}]]

emit('    public static function str_cases(): array\n    {\n        return [')
for v in floats + ints + [None, True, False] + strings + nested:
    emit('            [%s, %s],' % (php(v), php_str(str(v))))
emit('        ];\n    }\n')
emit('    public static function repr_cases(): array\n    {\n        return [')
for v in floats + ints + [None, True, False] + strings + nested:
    emit('            [%s, %s],' % (php(v), php_str(repr(v))))
emit('        ];\n    }\n')

# ---------------------------------------------------------------- strftime
dts = [D(2018, 7, 1, 3, 4, 5, 678, UTC), D(2018, 1, 1, 0, 0, 0, 0, UTC), D(2021, 12, 31, 23, 59, 59, 999999, UTC),
       D(2020, 2, 29, 12, 30, 0, 0, UTC), D(1999, 12, 31, 12, 0, 1, 0, UTC), D(2024, 12, 30, 8, 5, 9, 0, UTC),
       D(2023, 1, 1, 13, 45, 30, 5, UTC), D(2026, 10, 22, 10, 0, 0, 500000, UTC), D(2018, 7, 1, 3, 4, 5, 678, PAR),
       D(2018, 12, 25, 17, 0, 0, 0, PAR), D(2018, 7, 1, 3, 4, 5, 678, OFF2), D(2018, 7, 1, 3, 4, 5, 0, OFFM),
       D(2025, 1, 5, 0, 0, 0, 0, UTC), D(2016, 1, 3, 9, 9, 9, 0, UTC)]
fmts_all = ['%Y-%m-%d %H:%M:%S', '%Y/%m/%d', '%y%m%d', '%a %A %b %B %h', '%p %I %l %k', '%j', '%U %W %V %G %g %u %w', '%e', '%C', '%D %F',
            '%R %T %r', '%c', '%x', '%X', '%f', '%z|%Z', '%%', '%Y%%%m', 'plain', '', '%-d/%-m/%-y %-H %-j', '%Ey %Od', '%Q', '%',
            'abc%', '%%%', '%Y-%m-%dT%H:%M:%S%z', '%d/%m/%Y %H:%M']
emit('    public static function strftime_cases(): array\n    {\n        return [')
for dt in dts:
    for f in fmts_all:
        emit('            [%s, %s, %s],' % (php(dt), php_str(f), php_str(dt.strftime(f))))
    if dt.tzinfo is UTC:
        emit('            [%s, %s, %s],' % (php(dt), php_str('%s'), php_str(dt.strftime('%s'))))
emit('        ];\n    }\n')

# locale (de_DE.UTF-8), if available here
try:
    locale.setlocale(locale.LC_ALL, 'de_DE.UTF-8')
    have_de = True
except locale.Error:
    have_de = False
emit('    public static function strftime_de_cases(): array\n    {\n        return [')
if have_de:
    for dt in dts[:4] + dts[7:8]:
        # %p is left out: macOS strftime prints '' in de_DE where nl_langinfo(AM_STR) says AM
        for f in ['%B %b %a %A', '%c', '%x', '%X', '%Y/%B']:
            emit('            [%s, %s, %s],' % (php(dt), php_str(f), php_str(dt.strftime(f))))
emit('        ];\n    }\n')
locale.setlocale(locale.LC_ALL, 'C')

# ---------------------------------------------------------------- format
dt1 = dts[0]; dt2 = D(2018, 7, 31, 7, 8, 38, 0, PAR)
fcases = [
    ('{:%Y/%m/%d}', (dt1,), {}), ('{:%Y/%m/%d}', (dt2,), {}), ('{:%Y/%B}', (dt2,), {}), ('{:%Y-%m-%d %H:%M:%S%z}', (dt2,), {}),
    ('{0:%Y}/{0:%m}', (dt1,), {}), ('{:%Y}', (dt1,), {}), ('{}', (dt1,), {}), ('{}', (dt2,), {}), ('{:}', (dt1,), {}),
    ('{0.year}-{0.month}-{0.day} {0.hour}:{0.minute}:{0.second}.{0.microsecond}', (dt1,), {}),
    ('{}', ('abc',), {}), ('{}-{}', ('a', 'b'), {}), ('{1}{0}{1}', ('a', 'b'), {}), ('{name}!', (), {'name': 'x'}), ('{0}{name}', ('a',), {'name': 'x'}),
    ('{!r}', ("it's",), {}), ('{!s}', ("it's",), {}), ('{!a}', ('é',), {}), ('{0!r:>8}', ('a',), {}), ('{:>8}|{:<8}|{:^8}|', ('r', 'l', 'c'), {}),
    ('{:*^9}', ('mid',), {}), ('{:.2}', ('abcdef',), {}), ('{:10.3}|', ('abcdef',), {}),
    ('{:d}', (42,), {}), ('{:05d}', (-42,), {}), ('{:+d} {: d}', (5, 5), {}), ('{:,}', (1234567,), {}), ('{:_}', (1234567,), {}), ('{:x} {:X} {:#x} {:o} {:#o} {:b} {:#b}', (255, 255, 255, 8, 8, 5, 5), {}),
    ('{:c}', (65,), {}), ('{:>5}', (7,), {}), ('{:<5}|', (7,), {}), ('{:^5}|', (7,), {}), ('{:=+6}', (42,), {}), ('{:08}', (-3,), {}),
    ('{:.2f}', (3.14159,), {}), ('{:10.3f}|', (3.14159,), {}), ('{:<10.1f}|', (-2.55,), {}), ('{:e}', (12345.6789,), {}), ('{:.2E}', (0.000123,), {}),
    ('{:g}', (1234567.0,), {}), ('{:g}', (0.00001234,), {}), ('{:g}', (100.0,), {}), ('{:.3g}', (3.14159,), {}), ('{:%}', (0.256,), {}), ('{:.1%}', (0.256,), {}),
    ('{:,.2f}', (1234567.891,), {}), ('{}', (1.0,), {}), ('{}', (1e16,), {}), ('{}', (None,), {}), ('{}', (True,), {}), ('{:d}', (True,), {}),
    ('{:.0f}', (2.5,), {}), ('{:.0f}', (3.5,), {}), ('{:f}', (1.5,), {}), ('{:f}', (3,), {}), ('{:5}', ('ab',), {}), ('{:5}', (1.5,), {}), ('{:+.1f}', (0.0,), {}), ('{:z.1f}', (-0.0001,), {}), ('{:.1f}', (-0.0001,), {}), ('{:z}', (-0.0,), {}), ('{:+.1f}', (-0.0,), {}),
    ('{{}}', (), {}), ('a{{b}}c{}', (1,), {}), ('{{{}}}', (1,), {}), ('{0[1]}', ([10, 20],), {}), ('{0[a]}', ({'a': 5},), {}), ('{:{}}|', ('x', 5), {}), ('{:{w}}|', ('x',), {'w': 4}),
    ('no fields', (), {}), ('', (), {}),
    ('{%Y}{%Y}', (dt1,), {}), ('{:%Y}{:%m}', (dt1,), {}), ('{', (), {}), ('}', (), {}), ('{0', (1,), {}), ('{}{1}', (1, 2), {}), ('{1}{}', (1, 2), {}),
    ('{:z}', (1,), {}), ('{:d}', ('s',), {}), ('{:s}', (1,), {}), ('{.foo}', (1,), {}), ('{!x}', (1,), {}), ('{0!r', (1,), {}), ('{:%Y}', (5,), {}), ('{:ab}', (1,), {}),
]
emit('    public static function format_cases(): array\n    {\n        return [')
for fmt, args, kw in fcases:
    try:
        res = fmt.format(*args, **kw)
        exp = php_str(res)
    except Exception as e:
        exp = "['!' => %s]" % php_str(type(e).__name__)
    kwphp = ', '.join('%s: %s' % (k, php(v)) for k, v in kw.items())
    emit('            [%s, %s, %s, %s],' % (php_str(fmt), args_php(args), '[%s]' % ', '.join('%s => %s' % (php_str(k), php(v)) for k, v in kw.items()), exp))
emit('        ];\n    }\n')

# ---------------------------------------------------------------- % formatting
mcases = [
    ('%s', ('abc',)), ('%s', (1,)), ('%s', (1.0,)), ('%s', (None,)), ('%s', (True,)), ('%s', ([1, 'a'],)), ('%s', ({'a': 1},)), ('%s', ((1, 2),)), ('%s', (dt1,)),
    ('%r', ('abc',)), ('%r', ("it's",)), ('%r', (1.5,)), ('%a', ('é',)), ('%d', (3.9,)), ('%d', (-3.9,)), ('%d', (42,)), ('%d', (True,)), ('%i', (7,)), ('%u', (7,)),
    ('%5d|%-5d|%05d', (42, 42, 42)), ('%+d % d', (5, 5)), ('%05d', (-42,)), ('%.3d', (5,)), ('%x %X %o %#x %#X %#o', (255, 255, 8, 255, 255, 8)), ('%x', (-255,)),
    ('%f', (3.14159,)), ('%.2f', (3.14159,)), ('%10.4f|', (3.14159,)), ('%-10.2f|', (3.14159,)), ('%+.1f', (2.0,)), ('%08.3f', (-3.14159,)), ('%.0f %.0f', (2.5, 3.5)),
    ('%e', (12345.6789,)), ('%.2E', (0.000123,)), ('%g', (1234567.0,)), ('%g', (0.00001234,)), ('%g', (100.0,)), ('%G', (1e-10,)), ('%.3g', (3.14159,)), ('%g', (1e16,)), ('%g', (123456789.0,)),
    ('%f', (5,)), ('%f', (float('inf'),)), ('%f', (float('nan'),)), ('%5.1f', (float('-inf'),)), ('%c', (65,)), ('%c', ('z',)), ('%%', ()), ('100%%', ()), ('%5s|%-5s|', ('ab', 'cd')),
    ('%.3s', ('abcdef',)), ('%10.3s|', ('abcdef',)), ('%*d', (5, 3)), ('%-*d|', (5, 3)), ('%.*f', (2, 3.14159)), ('%s %s', ('a', 'b')), ('%s%%', (5,)), ('%-8s|', ('é日',)), ('%5s', ('日本',)),
    ('%(a)s %(b)d', {'a': 'x', 'b': 2}), ('%(a)s-%(a)s', {'a': 1}), ('%(a)05d', {'a': 42}), ('%(levelname)-8s %(message)s', {'levelname': 'INFO', 'message': 'hi'}), ('%(x)s', {'x': [1, 2]}),
    ('%s', {'a': 1}), ('%s %s', ('a',)), ('%s', ('a', 'b')), ('%d', ('s',)), ('%(a)s', {'b': 1}), ('%(a)s', ('x',)), ('%y', (1,)), ('%', ()), ('%s %', ('a',)), ('%d', (float('nan'),)), ('%c', ([],)),
    ('abc', ()), ('abc', ('x',)), ('', ()),
]
emit('    public static function mod_format_cases(): array\n    {\n        return [')
for fmt, args in mcases:
    try:
        res = fmt % args
        exp = php_str(res)
    except Exception as e:
        exp = "['!' => %s]" % php_str(type(e).__name__)
    emit('            [%s, %s, %s],' % (php_str(fmt), php(args if isinstance(args, dict) else list(args)), exp))
emit('        ];\n    }\n')

# ---------------------------------------------------------------- json.dumps
big = {"records": [{"recordName": "ABC-123", "fields": {"filenameEnc": {"value": "SU1HXzAwMDEuSlBH", "type": "ENCRYPTED_BYTES"}, "resOriginalWidth": {"value": 4032}, "ratio": {"value": 0.75}}, "deleted": False, "zone": None}],
       "syncToken": "AQ/AAA==", "unicode": "été 日本語 😀    ", "ctrl": "a\x00b\x1fc\x7fd\ne\tf\"g\\h/i<j>k&l'm", "emptyl": [], "emptyd": {}, "nums": [0, -1, 1.0, 1e22, 1e-7, 0.1, -0.0, 1e16, 100.0, 2 ** 40, 12345.678], "nested": [[1, [2, []]], {"a": {"b": {}}}], 10: "intkey", "tup": (1, 'a')}
jcases = [('null', None), ('true', True), ('int', 5), ('float', 2.5), ('str', 'é'), ('emptylist', []), ('emptydict', {}), ('simple', {'a': 1, 'b': [1, 2], 'c': None}),
          ('big', big), ('nan', float('nan')), ('inf', [float('inf'), float('-inf')]), ('tuple', (1, 2)), ('session', {'client_id': 'auth-abc', 'scnt': 'x==', 'session_token': 'T/+=', 'account_country': 'FRA'}),
          ('query', {"query": {"recordType": "CheckIndexingState"}, "zoneID": {"zoneName": "PrimarySync", "ownerRecordName": "_abc", "zoneType": "REGULAR_CUSTOM_ZONE"}}),
          ('emptyobj', {})]
emit('    public static function json_dumps_cases(): array\n    {\n        return [')
for name, v in jcases:
    for ea in (True, False):
        for ind in (None, 2, 0):
            if isinstance(v, float) and math.isnan(v) and ind: continue
            exp = json.dumps(v, ensure_ascii=ea, indent=ind)
            emit('            [%s, %s, %s, %s, %s],' % (php(v), 'true' if ea else 'false', 'null' if ind is None else ind, php_str(exp), php_str(name)))
emit('        ];\n    }\n')

open(sys.argv[1], 'w').write(HEADER + '\n'.join(out) + '}\n')
