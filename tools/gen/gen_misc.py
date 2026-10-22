"""Generates the Python-derived provider data for tests/FoundationOsTest.php, FoundationLoggingTest.php, FoundationTqdmTest.php"""
import logging, os, posixpath, sys, math, io
sys.path.insert(0, os.path.expanduser('~/Sites/icloudpd'))
sys.path.insert(0, '/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad')
from helpers import *
from tqdm import tqdm

def pstr(s): return php_str(s)

# ---------------------------------------------------------------- os.path
paths = ['', '.', '..', '/', '//', '///', 'a', 'a/b', 'a/b/', '/a/b', '//a/b', '///a/b', 'a//b', 'a/./b', 'a/../b', '../a', '../../a/b', 'a/b/../..', 'a/b/../../..', '/..', '/../a', '/a/../..',
         './a', 'a/.', 'a/..', '/a/b/c.txt', 'IMG_0001.JPG', '.hidden', '..hidden', '.a.b', 'a.b/c', 'a.b/c.d', 'a/.b', 'a/..b.c', 'x.', 'a..', '..', '...', 'dir.d/', '/tmp/x.tar.gz', 'é/日本.JPG', 'a//', '~', '~/a', '~/', '~x', 'a~']
join_cases = [('a', 'b'), ('a/', 'b'), ('a', '/b'), ('', 'b'), ('a', ''), ('a', 'b', 'c'), ('/a', 'b', '/c', 'd'), ('a/b', '../c'), ('a', '', 'b'), ('', ''), ('/', 'a'), ('a/', '/'), ('a', 'b/'), ('~', 'x')]
out = []
def emit(s=''): out.append(s)
emit('    public static function join_cases(): array\n    {\n        return [')
for c in join_cases:
    emit('            [[%s], %s],' % (', '.join(pstr(x) for x in c), pstr(posixpath.join(*c))))
emit('        ];\n    }\n')
for fn, name in [(posixpath.normpath, 'normpath'), (posixpath.basename, 'basename'), (posixpath.dirname, 'dirname'), (posixpath.splitext, 'splitext')]:
    emit('    public static function %s_cases(): array\n    {\n        return [' % name)
    for p in paths:
        r = fn(p)
        emit('            [%s, %s],' % (pstr(p), php(list(r)) if isinstance(r, tuple) else pstr(r)))
    emit('        ];\n    }\n')
emit('    public static function expanduser_cases(): array\n    {\n        return [')
for home in ['/home/jmoati', '/home/jmoati/', '/', '//', '/root']:
    os.environ['HOME'] = home
    for p in ['~', '~/a', '~/', '~/a/b', 'a~', '/a', '', '~nosuchuserzz', '~nosuchuserzz/x']:
        emit('            [%s, %s, %s],' % (pstr(home), pstr(p), pstr(posixpath.expanduser(p))))
emit('        ];\n    }\n')
open('/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad/parts/_os_cases.txt', 'w').write('\n'.join(out) + '\n')

# ---------------------------------------------------------------- logging.Filter
out = []
names = ['', 'a', 'a.b', 'a.b.c', 'a.bb', 'b.a.b', 'ab', 'a.', 'a.b.c.d']
emit('    public static function filter_cases(): array\n    {\n        return [')
for fname in ['', 'a.b', 'a', 'a.b.c', 'x']:
    f = logging.Filter(fname)
    for rname in names:
        rec = logging.LogRecord(rname, 20, 'p', 1, 'm', (), None)
        emit('            [%s, %s, %s],' % (pstr(fname), pstr(rname), 'true' if f.filter(rec) else 'false'))
emit('        ];\n    }\n')

# Formatter: record created at a fixed instant, TZ UTC
class R(logging.LogRecord):
    pass
emit('    public static function formatter_cases(): array\n    {\n        return [')
import time
fcases = [('%(asctime)s %(levelname)-8s %(message)s', '%Y-%m-%d %H:%M:%S', 20, 'hello %s', ('world',), 1514764800.123, 'icloudpd'),
          ('%(asctime)s %(levelname)-8s %(message)s', None, 10, 'plain', (), 1514764800.123, 'icloudpd'),
          ('%(asctime)s', None, 40, 'x', (), 1514764800.9996, 'n'),
          ('%(message)s', None, 30, '%d items, %s', (3, [1, 2]), 1.0, 'n'),
          ('%(name)s|%(levelno)d|%(levelname)s|%(msecs)d|%(message)s', None, 50, 'a %(k)s b', ({'k': 'v'},), 1514764800.5, 'icloudpd.http'),
          ('%(levelname)s:%(name)s:%(message)s', None, 25, '100%', (), 5.0, 'root'),
          ('%(created)f|%(asctime)s|%(message)s', '%d/%m/%Y %H:%M', 20, 'm', (), 1700000000.25, 'n'),
          ('%(message)s', None, 20, {'a': 1}, (), 5.0, 'n'),
          ('%(message)s', None, 20, 'only %s', ('arg',), 5.0, 'n')]
for fmt, datefmt, level, msg, args, created, name in fcases:
    rec = logging.LogRecord(name, level, 'p', 1, msg, args, None)
    rec.created = created
    ns = round(created * 1e6) * 1000  # what time_ns() would have been
    rec.msecs = (ns % 1_000_000_000) // 1_000_000 + 0.0
    if rec.msecs == 999.0 and int(created) != ns // 1_000_000_000: rec.msecs = 0.0
    f = logging.Formatter(fmt, datefmt)
    emit('            [%s, %s, %s, %s, %s, %s, %s, %s],' % (pstr(fmt), php(datefmt), level, php(msg), php(list(args)), repr(created), pstr(name), pstr(f.format(rec))))
emit('        ];\n    }\n')
emit('    public static function level_name_cases(): array\n    {\n        return [')
for lv in [0, 10, 20, 30, 40, 50, 25, 7, 'INFO', 'DEBUG', 'WARNING', 'nope', 'NOTSET']:
    emit('            [%s, %s],' % (php(lv), php(logging.getLevelName(lv))))
emit('        ];\n    }\n')
open('/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad/parts/_logging_cases.txt', 'w').write('\n'.join(out) + '\n')

# ---------------------------------------------------------------- tqdm.format_meter
out = []
emit('    public static function format_meter_cases(): array\n    {\n        return [')
cases = [(0, 10, 0.0, None, '', False), (3, 10, 5.0, None, 'abc', True), (10, 10, 10.0, 80, 'Waiting...', True), (5, None, 12.5, None, '', False), (50, 100, 3661.0, 100, 'x: ', True),
         (1, 3, 0.5, 0, '', False), (7, 7, 0.0, None, '', False), (2, 4, 90.0, None, '', False), (3, 10, 5.0, 60, 'desc', False), (33, 100, 7.0, 70, '', False), (3.5, 10.0, 2.0, None, '', True),
         (12, 10, 1.0, None, '', False), (0, 0, 1.0, None, '', False), (1, 1000, 0.001, 80, 'Photos', True), (999, 1000, 100000.0, 50, 'big', False), (6, 8, 60.0, 30, '', True), (4, 5, 3.0, 40, 'é日', False),
         (2, 100, 0.0, 80, '', True), (25, 100, 1.0, 20, 'x', True)]
for n, total, el, ncols, prefix, ascii_ in cases:
    exp = tqdm.format_meter(n, total, el, ncols=ncols, prefix=prefix, ascii=ascii_)
    emit('            [%s, %s, %s, %s, %s, %s, %s],' % (php(n), php(total), php(float(el)), php(ncols), pstr(prefix), 'true' if ascii_ else 'false', pstr(exp)))
emit('        ];\n    }\n')
for t in [0, 5, 59, 60, 61, 3599, 3600, 3661, 86399, 100000, 5.9]:
    pass
emit('    public static function interval_cases(): array\n    {\n        return [')
for t in [0, 5, 59, 60, 61, 3599, 3600, 3661, 86399, 100000, 5.9, 0.4]:
    emit('            [%s, %s],' % (php(t), pstr(tqdm.format_interval(t))))
emit('        ];\n    }\n')
open('/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad/parts/_tqdm_cases.txt', 'w').write('\n'.join(out) + '\n')
