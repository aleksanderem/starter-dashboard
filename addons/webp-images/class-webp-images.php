<?php
/**
 * Pure logic for the WebP Images addon (no WordPress calls — testable with plain `php`).
 *
 * A WebP copy lives next to the original with ".webp" appended: 2025/07/foo.png → 2025/07/foo.png.webp.
 */

defined('ABSPATH') || exit;

class Starter_Webp_Images {

    const ATTRIBUTES = ['src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src'];
    const SRCSET_ATTRIBUTES = ['srcset', 'data-srcset'];
    const SUFFIX = '.webp';

    const QUEUE_MAX = 200;
    const BATCH_SIZE = 10;
    const MIN_BYTES = 30720; // 30 KB
    const MAX_WIDTH = 1920;
    const QUALITY = 82;

    /**
     * Protected blocks (left byte-identical) or an <img>/<source> tag.
     * Loops are written "unrolled" so 1 MB pages do not hit pcre.backtrack_limit.
     */
    const SCAN_PATTERN = '~
        <!--[^-]*+(?:-(?!->)[^-]*+)*+(?:-->|\z)
      | <(script|style|noscript)\b[^>]*+>[^<]*+(?:<(?!/\1\s*>)[^<]*+)*+(?:</\1\s*>|\z)
      | <(?:img|source)\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>
    ~ix';

    const ATTR_PATTERN = '~([^\s=/>"\']++)(?:\s*=\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>"\']++)))?~';

    /**
     * Point PNG/JPEG upload URLs in <img>/<source> tags to their WebP copy when it exists.
     *
     * @param string   $html            Page HTML.
     * @param string   $uploads_baseurl e.g. https://example.com/wp-content/uploads
     * @param callable $webp_exists     fn(string $original_url): bool — called once per URL.
     * @return array{html:string, missing:string[]} missing = qualifying URLs without a WebP copy yet.
     */
    public static function rewrite_html($html, $uploads_baseurl, callable $webp_exists) {
        $base = rtrim((string) $uploads_baseurl, '/');
        $result = ['html' => $html, 'missing' => []];
        if ($base === '' || !is_string($html) || strpos($html, $base . '/') === false) {
            return $result;
        }
        if (!preg_match_all(self::SCAN_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $result; // no match or PCRE error: never break the page
        }

        $known = [];
        $missing = [];
        $check = function ($url) use ($base, $webp_exists, &$known, &$missing) {
            if (!self::qualifies($url, $base)) {
                return false;
            }
            if (!isset($known[$url])) {
                $known[$url] = (bool) $webp_exists($url);
                if (!$known[$url]) {
                    $missing[$url] = true;
                }
            }
            return $known[$url];
        };

        $out = '';
        $cursor = 0;
        foreach ($matches[0] as [$tag, $offset]) {
            $name_length = self::tag_name_length($tag);
            if (!$name_length) {
                continue;
            }
            $edits = [];
            foreach (self::value_positions($tag, $name_length) as $name => [$value, $value_offset]) {
                if (!in_array($name, self::ATTRIBUTES, true)) {
                    continue;
                }
                $new = in_array($name, self::SRCSET_ATTRIBUTES, true)
                    ? self::rewrite_srcset($value, $check)
                    : self::rewrite_single($value, $check);
                if ($new !== $value) {
                    $edits[] = [$value_offset, strlen($value), $new];
                }
            }
            if (!$edits) {
                continue;
            }
            $out .= substr($html, $cursor, $offset - $cursor) . self::apply_edits($tag, $edits);
            $cursor = $offset + strlen($tag);
        }

        $result['missing'] = array_keys($missing);
        if ($cursor > 0) {
            $result['html'] = $out . substr($html, $cursor);
        }
        return $result;
    }

    /**
     * Path inside the uploads directory for an upload URL (URL-decoded), or null.
     */
    public static function url_to_relative($url, $uploads_baseurl) {
        $base = rtrim((string) $uploads_baseurl, '/');
        if (!self::qualifies((string) $url, $base)) {
            return null;
        }
        $relative = rawurldecode(substr($url, strlen($base) + 1));
        return self::safe_relative($relative) ? $relative : null;
    }

    /**
     * Add paths to the generation queue: no duplicates, nothing from the skip list, at most $max entries.
     * Returns a new array; the input is not modified.
     */
    public static function queue_add($queue, array $items, array $skip = [], $max = self::QUEUE_MAX) {
        $out = [];
        foreach (is_array($queue) ? $queue : [] as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[$entry] = true;
            }
        }
        $skipped = array_fill_keys(array_map('strval', $skip), true);
        foreach ($items as $item) {
            $item = (string) $item;
            if (count($out) >= $max) {
                break;
            }
            if ($item !== '' && !isset($out[$item]) && !isset($skipped[$item])) {
                $out[$item] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Absolute path of a queued file, only when it is a real PNG/JPEG file inside the uploads directory.
     */
    public static function resolve_source($basedir, $relative) {
        $relative = (string) $relative;
        if ($relative === '' || !self::safe_relative($relative) || !preg_match('~\.(?:png|jpe?g)$~i', $relative)) {
            return null;
        }
        $base_real = realpath((string) $basedir);
        $real = realpath(rtrim((string) $basedir, '/\\') . '/' . $relative);
        if (!$base_real || !$real || !is_file($real) || strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Create "<source>.webp" with an image editor (WP_Image_Editor or compatible).
     *
     * @param string   $source         Absolute path (see resolve_source()).
     * @param callable $editor_factory fn(string $path): object editor, or WP_Error/anything else on error.
     * @return string converted | exists | small | larger | error
     */
    public static function convert_file($source, callable $editor_factory) {
        return self::convert_file_detailed($source, $editor_factory)[0];
    }

    /**
     * Same as convert_file(), with a readable message: sizes on success, the editor's own
     * error text (WP_Error::get_error_message(), exception message) on failure.
     *
     * @return array{0:string, 1:string} [status, message]
     */
    public static function convert_file_detailed($source, callable $editor_factory) {
        $target = $source . self::SUFFIX;
        clearstatcache(true, $source);
        clearstatcache(true, $target);
        if (file_exists($target)) {
            return ['exists', 'WebP already exists'];
        }
        $bytes = @filesize($source);
        if ($bytes === false) {
            return ['error', 'Cannot read the original file'];
        }
        if ($bytes < self::MIN_BYTES) {
            return ['small', 'Smaller than 30 KB (' . self::format_bytes($bytes) . ')'];
        }

        try {
            $editor = $editor_factory($source);
            if (!is_object($editor) || !method_exists($editor, 'save')) {
                return ['error', 'Image editor: ' . self::error_message($editor)];
            }
            $size = $editor->get_size();
            if (is_array($size) && (int) ($size['width'] ?? 0) > self::MAX_WIDTH) {
                $resized = $editor->resize(self::MAX_WIDTH, null, false);
                if ($resized !== true) {
                    return ['error', 'Resize: ' . self::error_message($resized)];
                }
            }
            $editor->set_quality(self::QUALITY); // a failure here only keeps the editor's default quality
            $saved = $editor->save($target, 'image/webp');
        } catch (\Throwable $e) {
            self::remove($target);
            return ['error', self::error_message($e)];
        }

        if (!is_array($saved) || empty($saved['path'])) {
            self::remove($target);
            return ['error', 'Save: ' . self::error_message($saved)];
        }
        $path = (string) $saved['path'];
        if (!is_file($path) || realpath($path) !== realpath($target)) {
            self::remove($path);
            self::remove($target);
            return ['error', 'Save: editor wrote ' . basename($path) . ' instead of ' . basename($target)];
        }
        clearstatcache(true, $target);
        $webp_bytes = (int) filesize($target);
        if ($webp_bytes === 0) {
            self::remove($target);
            return ['error', 'Save: WebP file is empty (0 bytes)'];
        }
        if ($webp_bytes >= $bytes) {
            self::remove($target);
            return ['larger', 'WebP ' . self::format_bytes($webp_bytes) . ' is not smaller than the original ' . self::format_bytes($bytes)];
        }
        return ['converted', self::format_bytes($bytes) . ' → ' . self::format_bytes($webp_bytes)];
    }

    /** Readable text of a WP_Error-like object, a Throwable or a string; max 300 characters. */
    public static function error_message($error) {
        $text = '';
        if ($error instanceof \Throwable) {
            $text = get_class($error) . ': ' . $error->getMessage();
        } elseif (is_object($error) && method_exists($error, 'get_error_message')) {
            $text = (string) $error->get_error_message();
        } elseif (is_string($error)) {
            $text = $error;
        }
        if (trim($text) === '') {
            $text = 'unknown error (' . (is_object($error) ? get_class($error) : gettype($error)) . ')';
        }
        return self::cut($text);
    }

    /** The generator should be (re)scheduled: files are waiting and no event is planned. */
    public static function should_schedule($queue_length, $next_scheduled) {
        return (int) $queue_length > 0 && !$next_scheduled;
    }

    /**
     * none | scheduled | overdue. Overdue (more than 2 minutes late) means WP-Cron does not run:
     * DISABLE_WP_CRON without a server cron, no traffic, or blocked loopback requests.
     */
    public static function cron_state($next_scheduled, $now) {
        if (!$next_scheduled) {
            return 'none';
        }
        return (int) $next_scheduled < (int) $now - 120 ? 'overdue' : 'scheduled';
    }

    /** Last $limit skipped paths, newest first. */
    public static function recent_skips($skip, $limit = 10) {
        if (!is_array($skip)) {
            return [];
        }
        return array_slice(array_reverse(array_values(array_map('strval', $skip))), 0, $limit);
    }

    /**
     * State of one generator run, stored in the option mwts_webp_last.
     *
     * @param array $files [['path' => string, 'status' => string, 'message' => string], ...]
     */
    public static function last_run_record($time, $trigger, array $files, $queue_before, $supports_webp, $editor_class, $cron_disabled, $message) {
        $clean = [];
        $counts = [];
        foreach ($files as $file) {
            $status = (string) ($file['status'] ?? 'error');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $clean[] = [
                'path'    => (string) ($file['path'] ?? ''),
                'status'  => $status,
                'message' => self::cut((string) ($file['message'] ?? '')),
            ];
        }
        return [
            'time'            => (int) $time,
            'trigger'         => (string) $trigger,
            'processed'       => count($clean),
            'queue_before'    => (int) $queue_before,
            'supports_webp'   => (bool) $supports_webp,
            'editor'          => is_string($editor_class) ? $editor_class : '',
            'disable_wp_cron' => (bool) $cron_disabled,
            'message'         => self::cut((string) $message),
            'counts'          => $counts,
            'files'           => $clean,
        ];
    }

    /** 493568 → "482 KB", 50000 → "48.8 KB". */
    public static function format_bytes($bytes) {
        $kb = number_format($bytes / 1024, 1, '.', '');
        if (substr($kb, -2) === '.0') {
            $kb = substr($kb, 0, -2);
        }
        return $kb . ' KB';
    }

    private static function cut($text) {
        return function_exists('mb_substr') ? mb_substr($text, 0, 300, 'UTF-8') : substr($text, 0, 300);
    }

    /** Upload URL of a PNG/JPEG without query string, fragment, entities or parent-directory segments. */
    private static function qualifies($url, $base) {
        $prefix = $base . '/';
        if ($base === '' || strncmp($url, $prefix, strlen($prefix)) !== 0 || strpbrk($url, "?#&\\ \t\r\n") !== false) {
            return false;
        }
        return (bool) preg_match('~\.(?:png|jpe?g)$~i', $url) && self::safe_relative(substr($url, strlen($prefix)));
    }

    private static function safe_relative($relative) {
        return strpos($relative, "\0") === false && strpos($relative, '\\') === false
            && !preg_match('~(?:^|/)\.\.(?:/|$)~', $relative);
    }

    private static function rewrite_single($value, callable $check) {
        if (preg_match('~^(\s*)(\S+)(\s*)$~', $value, $m) && $check($m[2])) {
            return $m[1] . $m[2] . self::SUFFIX . $m[3];
        }
        return $value;
    }

    /** Candidate by candidate: only the URL part changes, descriptors (300w, 2x) stay. */
    private static function rewrite_srcset($value, callable $check) {
        return preg_replace_callback('~(^|,)(\s*)([^\s,]++)~', function ($m) use ($check) {
            return $m[1] . $m[2] . $m[3] . ($check($m[3]) ? self::SUFFIX : '');
        }, $value);
    }

    /** Length of "<img" / "<source", or 0 for protected blocks. */
    private static function tag_name_length($tag) {
        if (strncasecmp($tag, '<img', 4) === 0) {
            return 4;
        }
        if (strncasecmp($tag, '<source', 7) === 0) {
            return 7;
        }
        return 0;
    }

    /**
     * Raw (undecoded) attribute values with their offset in the tag; the first occurrence wins.
     *
     * @return array<string, array{0:string, 1:int}>
     */
    private static function value_positions($tag, $name_length) {
        $out = [];
        if (!preg_match_all(self::ATTR_PATTERN, substr($tag, $name_length), $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m as $match) {
            $name = strtolower($match[1][0]);
            if (isset($out[$name])) {
                continue;
            }
            foreach ([2, 3, 4] as $g) {
                if (isset($match[$g]) && $match[$g][1] !== -1) {
                    $out[$name] = [$match[$g][0], $name_length + $match[$g][1]];
                    break;
                }
            }
            if (!isset($out[$name])) {
                $out[$name] = ['', -1]; // valueless attribute still blocks later duplicates
            }
        }
        return array_filter($out, function ($v) {
            return $v[1] >= 0;
        });
    }

    /** Apply [offset, length, replacement] edits, last offset first so earlier offsets stay valid. */
    private static function apply_edits($tag, array $edits) {
        usort($edits, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        foreach ($edits as [$offset, $length, $replacement]) {
            $tag = substr_replace($tag, $replacement, $offset, $length);
        }
        return $tag;
    }

    private static function remove($path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
