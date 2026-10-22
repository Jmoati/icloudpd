import sys, json, binascii, base64
sys.path.insert(0, '/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad')
from helpers import *
from foundation.core import constant
from foundation.json import apply_rules, compile_patterns, extract_context, first_matching_rule
from foundation.string import obfuscate
import foundation
from foundation import *
import json as pyjson

out = []
def emit(s=''): out.append(s)

emit('    public static function obfuscate_cases(): array\n    {\n        return [')
for s in ['', 'abc', 'é日', 'x' * 100, 'X-Apple-Session-Token', 'password with spaces', '😀']:
    emit('            [%s, %s],' % (php_str(s), php_str(obfuscate(s))))
emit('        ];\n    }\n')

emit('    public static function extract_context_cases(): array\n    {\n        return [')
for ctx, k in [('', 'a'), ('x', 'a'), ('x.', 'a'), ('x.y', 'z'), ('', ''), ('a', ''), ('.', 'b'), ('a.', '')]:
    r = extract_context(ctx, (k, 1))
    emit('            [%s, %s, %s],' % (php_str(ctx), php_str(k), php_str(r[0])))
emit('        ];\n    }\n')

docs = {
 'headers': {"X-Apple-ID-Session-Id": "abc", "Content-Type": "application/json", "scnt": "zzz", "x-apple-i-rscd": "200"},
 'body': {"dsInfo": {"dsid": "123", "fullName": "John Doe", "appleId": "jdoe@gmail.com"}, "webservices": {"ckdatabasews": {"url": "https://p1-ckdatabasews.icloud.com:443", "status": "active"}}, "hsaTrustedBrowser": True, "tokens": ["a", "b"]},
 'list_of_dicts': {"records": [{"recordName": "A1", "fields": {"filenameEnc": {"value": "SU1H", "type": "ENCRYPTED_BYTES"}}}, {"recordName": "A2", "fields": {"filenameEnc": {"value": "SU1I", "type": "ENCRYPTED_BYTES"}}}], "syncToken": "tok"},
 'cookies': [["a", "b"], ["c", "d"]],
 'scalar': 'hello',
 'numbers': {"a": 1, "b": 2.5, "c": None, "d": False},
}
rulesets = {
 'obf_apple': (['X-APPLE-.*', 'scnt'], 'obf'),
 'obf_dsinfo': (['dsInfo\\.(fullName|appleId)'], 'obf'),
 'obf_leaf': (['filenameEnc.*value'], 'obf'),
 'drop_tokens': (['^tokens\\.'], 'none'),
 'drop_records_fields': (['fields'], 'none'),
 'const_all': (['^records\\.recordName$'], 'const'),
 'whole_list': (['^cookies$'], 'obf'),
 'case': (['content-type'], 'const'),
 'tilde': (['a~b|^zzz'], 'const'),
}
emit('    public static function apply_rules_cases(): array\n    {\n        return [')
for dn, doc in docs.items():
    for rn, (pats, kind) in rulesets.items():
        fn = {'obf': obfuscate, 'none': constant(None), 'const': constant('REDACTED')}[kind]
        rules = [(p, fn) for p in compile_patterns(pats)]
        res = apply_rules('', rules, doc)
        emit('            [%s, %s, %s, %s, %s],' % (php_str(dn + '/' + rn), php(doc), php(pats), php_str(kind), php_str(pyjson.dumps(res))))
emit('        ];\n    }\n')

# version_info_formatted under TZ=UTC
foundation.version_info = foundation.VersionInfo(version='1.2.3', commit_sha='abc1234', commit_timestamp=1514764800)
emit('    public static function version_cases(): array\n    {\n        return [')
for ts in [1514764800, 1234567890, 1700000000, 1530403200]:
    foundation.version_info = foundation.VersionInfo(version='1.2.3', commit_sha='abc1234', commit_timestamp=ts)
    emit('            [%d, %s],' % (ts, php_str(foundation.version_info_formatted())))
emit('        ];\n    }\n')

emit('    public static function strip_cases(): array\n    {\n        return [')
for s in ['  hello  ', '\t\n x \r\v\f', '\xa0 x  ', '\x1c\x1d\x1e\x1f x \x85', '​x​', '　日本　', '', '   ', 'a b', 'é ', '﻿x']:
    emit('            [%s, %s],' % (php_str(s), php_str(s.strip())))
emit('        ];\n    }\n')
emit('    public static function case_cases(): array\n    {\n        return [')
for s in ['HELLO', 'Straße', 'İstanbul', 'ΑΣ ΣΑ Σ', 'ǅ', 'ﬁ', 'ÉCOLE', 'IMG_0001.HEIC', 'ǆ', 'ẞ', 'ŉ', 'ΐ']:
    emit('            [%s, %s, %s],' % (php_str(s), php_str(s.lower()), php_str(s.upper())))
emit('        ];\n    }\n')
open('/private/tmp/claude-501/-Users-jmoati/6dfe57af-c68a-4c5e-8ef0-ee59be17542e/scratchpad/parts/_funcs_cases.txt', 'w').write('\n'.join(out) + '\n')
