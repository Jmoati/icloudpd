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


