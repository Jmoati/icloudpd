<?php

namespace Foundation;

/**
 * Python's `os` and `os.path` for POSIX paths. `utime` and `makedirs` are patchable (`Foundation\Patch`),
 * the upstream tests mock them. Failures raise `Foundation\OSError` (or `FileNotFoundError`,
 * `FileExistsError`), never a PHP warning.
 */
final class Os
{
    /** `os.path.join(a, *p)`: an absolute component discards what precedes it */
    public static function path_join(string $a, string ...$p): string
    {
        $path = $a;
        foreach ($p as $b) {
            if (str_starts_with($b, '/')) {
                $path = $b;
            } elseif ($path === '' || str_ends_with($path, '/')) {
                $path .= $b;
            } else {
                $path .= '/'.$b;
            }
        }
        return $path;
    }

    /** `os.path.normpath(path)` */
    public static function path_normpath(string $path): string
    {
        if ($path === '') {
            return '.';
        }
        $initial_slashes = str_starts_with($path, '/');
        // POSIX allows one or two initial slashes, but treats three or more as a single slash
        if ($initial_slashes && str_starts_with($path, '//') && !str_starts_with($path, '///')) {
            $initial_slashes = 2;
        }
        $new_comps = [];
        foreach (explode('/', $path) as $comp) {
            if ($comp === '' || $comp === '.') {
                continue;
            }
            if ($comp !== '..' || (!$initial_slashes && !$new_comps) || ($new_comps && end($new_comps) === '..')) {
                $new_comps[] = $comp;
            } elseif ($new_comps) {
                array_pop($new_comps);
            }
        }
        $path = implode('/', $new_comps);
        if ($initial_slashes) {
            $path = str_repeat('/', (int) $initial_slashes).$path;
        }
        return $path !== '' ? $path : '.';
    }

    /** `os.path.expanduser(path)`: `~` and `~user` */
    public static function path_expanduser(string $path): string
    {
        if (!str_starts_with($path, '~')) {
            return $path;
        }
        $i = strpos($path, '/', 1);
        if ($i === false) {
            $i = strlen($path);
        }
        if (!function_exists('posix_getpwuid')) {
            // ponytail: without ext-posix only `~` with $HOME is expanded
            if ($i !== 1 || getenv('HOME') === false) {
                return $path;
            }
        }
        if ($i === 1) {
            $userhome = getenv('HOME');
            if ($userhome === false) {
                $userhome = posix_getpwuid(posix_getuid())['dir'] ?? null;
                if ($userhome === null) {
                    return $path;
                }
            }
        } else {
            $pw = posix_getpwnam(substr($path, 1, $i - 1));
            if ($pw === false) {
                return $path;
            }
            $userhome = $pw['dir'];
        }
        $userhome = rtrim($userhome, '/');
        $r = $userhome.substr($path, $i);
        return $r !== '' ? $r : '/';
    }

    /** `os.path.exists(path)` */
    public static function path_exists(string $path): bool
    {
        clearstatcache(true, $path);
        return file_exists($path);
    }

    /** `os.path.isfile(path)` */
    public static function path_isfile(string $path): bool
    {
        clearstatcache(true, $path);
        return is_file($path);
    }

    /** `os.path.getsize(path)` */
    public static function path_getsize(string $path): int
    {
        return self::stat($path)['st_size'];
    }

    /** `os.path.basename(p)` */
    public static function path_basename(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? $p : substr($p, $i + 1);
    }

    /** `os.path.dirname(p)` */
    public static function path_dirname(string $p): string
    {
        $i = strrpos($p, '/');
        $head = $i === false ? '' : substr($p, 0, $i + 1);
        if ($head !== '' && $head !== str_repeat('/', strlen($head))) {
            $head = rtrim($head, '/');
        }
        return $head;
    }

    /** `os.path.splitext(p)`: `("name", ".ext")`, leading dots of the basename do not start an extension */
    public static function path_splitext(string $p): array
    {
        $sep_index = strrpos($p, '/');
        $dot_index = strrpos($p, '.');
        if ($dot_index !== false && ($sep_index === false || $dot_index > $sep_index)) {
            // skip all leading dots
            $filename_index = ($sep_index === false ? -1 : $sep_index) + 1;
            while ($filename_index < $dot_index) {
                if ($p[$filename_index] !== '.') {
                    return [substr($p, 0, $dot_index), substr($p, $dot_index)];
                }
                $filename_index++;
            }
        }
        return [$p, ''];
    }

    /**
     * `os.stat(path)`: the keys of PHP's `stat()` prefixed with `st_` (`st_size`, `st_mtime`, `st_mode`…)
     */
    public static function stat(string $path): array
    {
        clearstatcache(true, $path);
        $s = @stat($path);
        if ($s === false) {
            throw new FileNotFoundError("[Errno 2] No such file or directory: ".Py::repr($path));
        }
        $r = [];
        foreach ($s as $k => $v) {
            if (is_string($k)) {
                $r['st_'.$k] = $v;
            }
        }
        return $r;
    }

    /** `os.makedirs(path, mode, exist_ok)` */
    public static function makedirs(string $path, bool $exist_ok = false, int $mode = 0777): void
    {
        if ($p = Patch::get(__METHOD__)) {
            $p($path, $exist_ok, $mode);
            return;
        }
        if (is_dir($path)) {
            if ($exist_ok) {
                return;
            }
            throw new FileExistsError('[Errno 17] File exists: '.Py::repr($path), 17);
        }
        if (file_exists($path)) {
            throw new FileExistsError('[Errno 17] File exists: '.Py::repr($path), 17);
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new OSError('[Errno 13] '.(error_get_last()['message'] ?? 'Cannot create directory').': '.Py::repr($path), 13);
        }
    }

    /** `os.remove(path)` */
    public static function remove(string $path): void
    {
        if (!@unlink($path)) {
            if (!file_exists($path) && !is_link($path)) {
                throw new FileNotFoundError('[Errno 2] No such file or directory: '.Py::repr($path), 2);
            }
            throw new OSError('[Errno 1] Operation not permitted: '.Py::repr($path), 1);
        }
    }

    /** `os.rename(src, dst)`: replaces `dst` if it is a file */
    public static function rename(string $src, string $dst): void
    {
        if (!@rename($src, $dst)) {
            if (!file_exists($src) && !is_link($src)) {
                throw new FileNotFoundError('[Errno 2] No such file or directory: '.Py::repr($src).' -> '.Py::repr($dst), 2);
            }
            throw new OSError('[Errno 1] Operation not permitted: '.Py::repr($src).' -> '.Py::repr($dst), 1);
        }
    }

    /**
     * `os.utime(path, times)`: `$times` is `[atime, mtime]` in seconds since the epoch (as `os.utime`'s
     * tuple), `null` for now
     *
     * @param array{0: int|float, 1: int|float}|null $times
     */
    public static function utime(string $path, ?array $times = null): void
    {
        if ($p = Patch::get(__METHOD__)) {
            $p($path, $times);
            return;
        }
        // touch() would create the file
        if (!file_exists($path)) {
            throw new FileNotFoundError('[Errno 2] No such file or directory: '.Py::repr($path), 2);
        }
        $ok = $times === null
            ? @touch($path)
            : @touch($path, (int) $times[1], (int) $times[0]);
        if (!$ok) {
            throw new OSError('[Errno 1] Operation not permitted: '.Py::repr($path), 1);
        }
    }

    /** `os.getcwd()` */
    public static function getcwd(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new FileNotFoundError('[Errno 2] No such file or directory', 2);
        }
        return $cwd;
    }

    /** `os.environ.get(key, default)` */
    public static function environ_get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }
}
