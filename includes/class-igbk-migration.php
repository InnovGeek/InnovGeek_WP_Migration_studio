<?php
if (!defined('ABSPATH')) { exit; }

/**
 * URL rewriting for a site-to-site restore.
 * Parses PHP serialization as bytes, so the s:N lengths are updated without
 * instantiating arbitrary objects from imported backups.
 */
final class IGBK_Migration {
    public static function rewrite($raw, $source, $target) {
        if (!is_string($raw) || $raw === '' || $source === $target) { return $raw; }
        $escaped = str_replace('/', '\\/', $source);
        if (strpos($raw, $source) === false && strpos($raw, $escaped) === false) { return $raw; }
        if (preg_match('/\A(?:a|s|O|C|E):[0-9]+:/', $raw)) {
            $at = 0;
            try {
                $result = self::node($raw, $at, $source, $target, 0);
                if ($at !== strlen($raw)) { throw new RuntimeException('Données en fin de chaîne'); }
                return $result;
            } catch (Throwable $exception) {
                // NEVER raw-replace inside an unrecognized serialized value:
                // it would silently invalidate its byte-length fields.
                throw new RuntimeException('Valeur PHP sérialisée incompatible avec la migration des URL : ' . $exception->getMessage());
            }
        }
        return self::plain($raw, $source, $target);
    }
    private static function plain($value, $source, $target) {
        $from = str_replace('/', '\\/', $source);
        $to = str_replace('/', '\\/', $target);
        // Prevent rewriting source domain prefixes inside longer hostnames.
        $pattern = '~' . preg_quote($source, '~') . '(?![A-Za-z0-9_.:-])~';
        $value = preg_replace_callback($pattern, static function () use ($target) { return $target; }, $value);
        $pattern = '~' . preg_quote($from, '~') . '(?![A-Za-z0-9_.:-])~';
        return preg_replace_callback($pattern, static function () use ($to) { return $to; }, $value);
    }
    private static function fail($reason) { throw new RuntimeException('Format de sérialisation invalide : ' . $reason); }
    private static function number($raw, &$at, $separator) {
        $end = strpos($raw, $separator, $at);
        if ($end === false || $end - $at > 15 || $end === $at) { self::fail('longueur'); }
        $digits = substr($raw, $at, $end - $at);
        if (!ctype_digit($digits)) { self::fail('nombre'); }
        $at = $end + 1;
        return (int) $digits;
    }
    private static function expect($raw, &$at, $token) {
        if (substr($raw, $at, strlen($token)) !== $token) { self::fail('séparateur'); }
        $at += strlen($token);
    }
    private static function stringBytes($raw, &$at) {
        self::expect($raw, $at, ':');
        $n = self::number($raw, $at, ':');
        self::expect($raw, $at, '"');
        if ($n > strlen($raw) - $at) { self::fail('chaîne tronquée'); }
        $content = substr($raw, $at, $n);
        $at += $n;
        self::expect($raw, $at, '"');
        return $content;
    }
    private static function node($raw, &$at, $source, $target, $depth) {
        if ($depth > 96 || $at >= strlen($raw)) { self::fail('profondeur ou données'); }
        $start = $at;
        $type = $raw[$at++];
        if ($type === 'N') { self::expect($raw, $at, ';'); return 'N;'; }
        if (strpos('ibdRr', $type) !== false) {
            self::expect($raw, $at, ':');
            $end = strpos($raw, ';', $at);
            if ($end === false || $end - $at > 120) { self::fail('valeur scalaire'); }
            $at = $end + 1;
            return substr($raw, $start, $at - $start);
        }
        if ($type === 's') {
            $content = self::stringBytes($raw, $at);
            self::expect($raw, $at, ';');
            // WordPress may double-serialize serialized strings. Recalculate
            // lengths at each nesting level rather than corrupting the inner data.
            if (preg_match('/\A(?:a|s|O|C|E):[0-9]+:/', $content) &&
                (strpos($content, $source) !== false || strpos($content, str_replace('/', '\\/', $source)) !== false)) {
                $innerAt = 0;
                $updated = self::node($content, $innerAt, $source, $target, $depth + 1);
                if ($innerAt !== strlen($content)) { self::fail('sérialisation imbriquée'); }
            } else {
                $updated = self::plain($content, $source, $target);
            }
            return 's:' . strlen($updated) . ':"' . $updated . '";';
        }
        if ($type === 'a' || $type === 'O') {
            self::expect($raw, $at, ':');
            if ($type === 'O') {
                $classLength = self::number($raw, $at, ':');
                self::expect($raw, $at, '"');
                $at += $classLength;
                self::expect($raw, $at, '"');
                self::expect($raw, $at, ':');
            }
            $count = self::number($raw, $at, ':');
            if ($count > 1000000) { self::fail('trop de propriétés'); }
            self::expect($raw, $at, '{');
            $head = substr($raw, $start, $at - $start);
            $body = '';
            for ($i = 0; $i < $count; $i++) {
                $body .= self::node($raw, $at, $source, $target, $depth + 1);
                $body .= self::node($raw, $at, $source, $target, $depth + 1);
            }
            self::expect($raw, $at, '}');
            return $head . $body . '}';
        }
        if ($type === 'C') {
            // Custom object serialization can contain arbitrary opaque bytes.
            // Reject if a URL must be changed; never corrupt custom payloads.
            self::expect($raw, $at, ':');
            $classLength = self::number($raw, $at, ':');
            self::expect($raw, $at, '"');
            $at += $classLength;
            self::expect($raw, $at, '"');
            self::expect($raw, $at, ':');
            $dataLength = self::number($raw, $at, ':');
            self::expect($raw, $at, '{');
            $at += $dataLength;
            self::expect($raw, $at, '}');
            $opaque = substr($raw, $start, $at - $start);
            if (strpos($opaque, $source) !== false || strpos($opaque, str_replace('/', '\\/', $source)) !== false) {
                self::fail('objet avec sérialisation personnalisée');
            }
            return $opaque;
        }
        if ($type === 'E') {
            // Enum labels cannot be URL strings; keep their original bytes.
            self::stringBytes($raw, $at);
            self::expect($raw, $at, ';');
            return substr($raw, $start, $at - $start);
        }
        self::fail('type ' . $type);
    }
}
