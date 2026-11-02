<?php

namespace Z77\Module\Dms\Zip;

use Z77\Core\Http\Response\ByteSource;

/**
 * A ZIP archive that exists as arithmetic (DMS-ZIP-001): without compression every position
 * follows from the names, sizes and CRC-32 alone, so {@see emit()} answers any byte range from
 * the original files and nothing is stored twice. Pure: no session, no DMS, no database, and a
 * file is only opened when a range reaches its bytes.
 *
 * Format (PKWARE APPNOTE 6.3): method store; the CRC and the sizes stand in the local header,
 * so there is no data descriptor (the widest compatibility, and a reader can walk the file);
 * flag bit 11 for UTF-8 names; a flat archive.
 *
 * ZIP64 only where a value does not fit, so a small archive stays a plain one:
 * - a size >= 0xFFFFFFFF: an extra field in the local header (both sizes, the format wants
 *   them together there) and in the central entry;
 * - an offset >= 0xFFFFFFFF: an extra field in the central entry only;
 * - 0xFFFF entries or more, or the central directory beyond the 4 GB mark: the ZIP64 end
 *   record and its locator in front of the plain end record.
 *
 * **Names.** The constructor cleans and de-duplicates every name, whoever calls it: a path
 * in an archive is a write to a path on the machine that unpacks it (zip-slip). See
 * {@see cleanName()}. Both steps are idempotent, so a layout rebuilt from the names it
 * answered ({@see names()}) has the same bytes.
 */
final class ZipLayout implements ByteSource
{
    private const LIMIT_32 = 0xFFFFFFFF;
    private const LIMIT_16 = 0xFFFF;

    private const SIG_LOCAL     = 0x04034B50;
    private const SIG_CENTRAL   = 0x02014B50;
    private const SIG_END       = 0x06054B50;
    private const SIG_END64     = 0x06064B50;
    private const SIG_LOCATOR64 = 0x07064B50;

    private const LOCAL_FIXED   = 30;
    private const CENTRAL_FIXED = 46;
    private const END_FIXED     = 22;
    private const END64_FIXED   = 56;
    private const LOCATOR_FIXED = 20;

    /** Tag and size of an extra field, then its 8-byte values. */
    private const EXTRA_TAG_ZIP64 = 0x0001;
    private const EXTRA_HEAD      = 4;

    private const FLAG_UTF8 = 0x0800;

    /** The version a reader needs: 2.0 for store + the flag, 4.5 once ZIP64 is in play. */
    private const VERSION_PLAIN = 20;
    private const VERSION_ZIP64 = 45;

    /** Every final name stays below the 255 bytes of a file system, its `(n)` suffix included. */
    private const NAME_MAX_BYTES = 240;

    /** See {@see splitExtension()}. */
    private const EXTENSION_MAX_BYTES = 16;

    /** A name that cleans down to nothing. */
    private const NAME_FALLBACK = 'datei';

    private const READ_BYTES = 65536;

    /** @var list<array{name: string, path: string, size: int, crc: int, time: int, date: int, offset: int, head: int}> */
    private array $entries = [];

    /** @var list<int> local-header offset per entry, for the search by position */
    private array $offsets = [];

    private int $centralOffset = 0;
    private int $centralSize   = 0;
    private int $size          = 0;
    private bool $end64        = false;

    /** The central directory + end records, built when a range first reaches them. */
    private ?string $tail = null;

    /**
     * @param list<array{name: string, path: string, size: int, crc: int, mtime: int}> $entries
     *        `path` may be '' for a file no range will reach, or for an empty one
     */
    public function __construct(array $entries)
    {
        $seen   = [];
        $offset = 0;
        foreach ($entries as $entry) {
            $size = (int) $entry['size'];
            $crc  = (int) $entry['crc'];
            if ($size < 0 || $crc < 0 || $crc > self::LIMIT_32) {
                throw new \InvalidArgumentException('ZipLayout: size or CRC out of range.');
            }

            $name = self::uniqueName(self::cleanName((string) $entry['name']), $seen);
            $head = self::LOCAL_FIXED + strlen($name) + ($size >= self::LIMIT_32 ? self::EXTRA_HEAD + 16 : 0);
            [$time, $date] = self::dosTime((int) $entry['mtime']);

            $this->entries[] = [
                'name'   => $name,
                'path'   => (string) $entry['path'],
                'size'   => $size,
                'crc'    => $crc,
                'time'   => $time,
                'date'   => $date,
                'offset' => $offset,
                'head'   => $head,
            ];
            $this->offsets[] = $offset;

            $this->centralSize += self::CENTRAL_FIXED + strlen($name) + self::centralExtraBytes($size, $offset);
            $offset += $head + $size;
        }

        $this->centralOffset = $offset;
        $this->end64 = count($this->entries) >= self::LIMIT_16
            || $this->centralOffset >= self::LIMIT_32
            || $this->centralSize >= self::LIMIT_32;
        $this->size = $this->centralOffset + $this->centralSize + self::END_FIXED
            + ($this->end64 ? self::END64_FIXED + self::LOCATOR_FIXED : 0);
    }

    /**
     * A name as it may stand in an archive: the last path segment, no control or format
     * characters, none of the characters Windows refuses, no `..` anywhere, no trailing dot
     * or space (Windows would drop them and two names could meet), no device name, at most
     * {@see NAME_MAX_BYTES} with the extension kept, valid UTF-8 and composed (NFC; a file system
     * that normalizes would otherwise see one name in two). `''` when nothing is left.
     */
    public static function cleanName(string $name): string
    {
        $name = rtrim(str_replace('\\', '/', $name), '/');
        $pos  = strrpos($name, '/');
        if ($pos !== false) {
            $name = substr($name, $pos + 1);
        }

        $name = mb_scrub($name, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $name = (string) (\Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name);
        }
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
        $name = str_replace(['<', '>', ':', '"', '|', '?', '*'], '_', $name);
        $name = (string) preg_replace('/\.{2,}/', '.', $name);
        $name = rtrim(trim($name), '. ');

        if ($name === '') {
            return '';
        }
        // Microsoft's list of reserved names, also with an extension, and with spaces before it:
        // Windows drops those when it looks for a device.
        if (preg_match('/^(con|prn|aux|nul|conin\$|conout\$|com[0-9¹²³]|lpt[0-9¹²³])\s*(\.|$)/iu', $name) === 1) {
            $name = '_' . $name;
        }

        return self::capName($name);
    }

    /**
     * The final name of each entry, in archive order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->entries, 'name');
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Which entries a byte range shows something of, by index. The local header carries the
     * name and the CRC, so a range that only clips a header touches that entry. The central
     * directory carries EVERY name, so a range that reaches it touches all of them.
     *
     * @return list<int>
     */
    public function touched(int $start, int $end): array
    {
        $count = count($this->entries);
        if ($count === 0 || $end < $start) {
            return [];
        }
        if ($end >= $this->centralOffset) {
            return range(0, $count - 1);
        }

        return range($this->entryAt($start), $this->entryAt($end));
    }

    public function emit(int $start, int $end, $stream): void
    {
        if ($start < 0 || $end < $start || $end >= $this->size) {
            throw new \InvalidArgumentException("ZipLayout: range {$start}-{$end} is outside {$this->size} bytes.");
        }

        $pos = $start;
        if ($pos < $this->centralOffset) {
            for ($i = $this->entryAt($pos), $count = count($this->entries); $i < $count && $pos <= $end; $i++) {
                $entry = $this->entries[$i];
                $data  = $entry['offset'] + $entry['head'];
                if ($pos < $data) {
                    $part = substr($this->localHeader($entry), $pos - $entry['offset'], min($end + 1, $data) - $pos);
                    self::write($stream, $part);
                    $pos += strlen($part);
                }
                $stop = min($end + 1, $data + $entry['size']);
                if ($pos < $stop) {
                    $this->copyData($entry, $pos - $data, $stop - $pos, $stream);
                    $pos = $stop;
                }
            }
        }
        if ($pos <= $end) {
            $this->tail ??= $this->buildTail();
            self::write($stream, substr($this->tail, $pos - $this->centralOffset, $end + 1 - $pos));
        }
    }

    /** The entry whose header or bytes hold `$pos` (below the central directory). */
    private function entryAt(int $pos): int
    {
        $low  = 0;
        $high = count($this->offsets) - 1;
        while ($low < $high) {
            $mid = ($low + $high + 1) >> 1;
            if ($this->offsets[$mid] <= $pos) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /** @param array{name: string, size: int, crc: int, time: int, date: int, offset: int} $entry */
    private function localHeader(array $entry): string
    {
        $big   = $entry['size'] >= self::LIMIT_32;
        $size  = min($entry['size'], self::LIMIT_32);
        $extra = $big ? pack('vvPP', self::EXTRA_TAG_ZIP64, 16, $entry['size'], $entry['size']) : '';

        return pack(
            'VvvvvvVVVvv',
            self::SIG_LOCAL,
            self::versionNeeded($entry),
            self::FLAG_UTF8,
            0, // store
            $entry['time'],
            $entry['date'],
            $entry['crc'],
            $size,
            $size,
            strlen($entry['name']),
            strlen($extra)
        ) . $entry['name'] . $extra;
    }

    /** @param array{name: string, size: int, crc: int, time: int, date: int, offset: int} $entry */
    private function centralEntry(array $entry): string
    {
        $values = [];
        if ($entry['size'] >= self::LIMIT_32) {
            $values[] = $entry['size'];
            $values[] = $entry['size'];
        }
        if ($entry['offset'] >= self::LIMIT_32) {
            $values[] = $entry['offset'];
        }
        $extra = $values === [] ? '' : pack('vv', self::EXTRA_TAG_ZIP64, 8 * count($values)) . pack('P*', ...$values);
        $size  = min($entry['size'], self::LIMIT_32);

        return pack(
            'VvvvvvvVVVvvvvvVV',
            self::SIG_CENTRAL,
            self::VERSION_ZIP64, // version made by, host 0 (no Unix mode to interpret)
            self::versionNeeded($entry),
            self::FLAG_UTF8,
            0, // store
            $entry['time'],
            $entry['date'],
            $entry['crc'],
            $size,
            $size,
            strlen($entry['name']),
            strlen($extra),
            0, // comment
            0, // disk
            0, // internal attributes
            0, // external attributes
            min($entry['offset'], self::LIMIT_32)
        ) . $entry['name'] . $extra;
    }

    private function buildTail(): string
    {
        $tail = '';
        foreach ($this->entries as $entry) {
            $tail .= $this->centralEntry($entry);
        }

        $count = count($this->entries);
        if ($this->end64) {
            $tail .= pack(
                'VPvvVVPPPP',
                self::SIG_END64,
                self::END64_FIXED - 12, // the record's size after this field, by the format
                self::VERSION_ZIP64,
                self::VERSION_ZIP64,
                0,
                0,
                $count,
                $count,
                $this->centralSize,
                $this->centralOffset
            );
            $tail .= pack('VVPV', self::SIG_LOCATOR64, 0, $this->centralOffset + $this->centralSize, 1);
        }

        return $tail . pack(
            'VvvvvVVv',
            self::SIG_END,
            0,
            0,
            min($count, self::LIMIT_16),
            min($count, self::LIMIT_16),
            min($this->centralSize, self::LIMIT_32),
            min($this->centralOffset, self::LIMIT_32),
            0
        );
    }

    /**
     * Copy a part of one file. A file that ends early throws: `Content-Length` promises these
     * bytes, and silently fewer would shift everything behind them.
     *
     * @param array{name: string, path: string} $entry
     * @param resource $stream
     */
    private function copyData(array $entry, int $offset, int $length, $stream): void
    {
        $in = $entry['path'] === '' ? false : @fopen($entry['path'], 'rb');
        if ($in === false) {
            throw new \RuntimeException("ZipLayout: cannot open the file behind '{$entry['name']}'.");
        }

        try {
            if (fseek($in, $offset) !== 0) {
                throw new \RuntimeException("ZipLayout: cannot seek in the file behind '{$entry['name']}'.");
            }
            while ($length > 0) {
                $bytes = fread($in, min(self::READ_BYTES, $length));
                if ($bytes === false || $bytes === '') {
                    throw new \RuntimeException("ZipLayout: the file behind '{$entry['name']}' ended early.");
                }
                self::write($stream, $bytes);
                $length -= strlen($bytes);
            }
        } finally {
            fclose($in);
        }
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('ZipLayout: the target took fewer bytes than offered.');
        }
    }

    /**
     * Local header and central entry must name the same version (libzip compares them), so
     * this asks whether the entry needs ZIP64 in EITHER of the two.
     *
     * @param array{size: int, offset: int} $entry
     */
    private static function versionNeeded(array $entry): int
    {
        return $entry['size'] >= self::LIMIT_32 || $entry['offset'] >= self::LIMIT_32
            ? self::VERSION_ZIP64
            : self::VERSION_PLAIN;
    }

    private static function centralExtraBytes(int $size, int $offset): int
    {
        $values = ($size >= self::LIMIT_32 ? 2 : 0) + ($offset >= self::LIMIT_32 ? 1 : 0);

        return $values === 0 ? 0 : self::EXTRA_HEAD + 8 * $values;
    }

    /**
     * `name.ext`, then `name (2).ext`. Compared without case: the machine that unpacks it may
     * not tell `A.pdf` from `a.pdf`, and the second would overwrite the first.
     *
     * @param array<string, int> $seen
     */
    private static function uniqueName(string $name, array &$seen): string
    {
        if ($name === '') {
            $name = self::NAME_FALLBACK;
        }
        $key = mb_strtolower($name, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = 1;
            return $name;
        }

        [$stem, $ext] = self::splitExtension($name);
        for ($n = $seen[$key] + 1; ; $n++) {
            $suffix       = " ({$n}){$ext}";
            $candidate    = mb_strcut($stem, 0, self::NAME_MAX_BYTES - strlen($suffix), 'UTF-8') . $suffix;
            $candidateKey = mb_strtolower($candidate, 'UTF-8');
            if (!isset($seen[$candidateKey])) {
                $seen[$key] = $n;
                $seen[$candidateKey] = 1;
                return $candidate;
            }
        }
    }

    /** Cut a long name on a character boundary, the extension stays when it is a sensible one. */
    private static function capName(string $name): string
    {
        if (strlen($name) <= self::NAME_MAX_BYTES) {
            return $name;
        }

        [$stem, $ext] = self::splitExtension($name);
        $cut = mb_strcut($stem, 0, self::NAME_MAX_BYTES - strlen($ext), 'UTF-8');

        return rtrim($cut, '. ') . $ext;
    }

    /**
     * Stem and extension with its dot. What follows the last dot only counts as an extension up to
     * {@see EXTENSION_MAX_BYTES}: the rest of the name is a stem then, so a cut or a suffix always
     * has room, and a name that was cut or got a suffix reads the same the next time.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitExtension(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0 || strlen($name) - $dot - 1 > self::EXTENSION_MAX_BYTES) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), substr($name, $dot)];
    }

    /**
     * MS-DOS time and date in the installation's zone, the format's 2-second steps; outside
     * its years (1980–2107) the nearest it can say.
     *
     * @return array{0: int, 1: int} time, date
     */
    private static function dosTime(int $mtime): array
    {
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', explode(' ', date('Y n j G i s', $mtime)));
        if ($year < 1980) {
            [$year, $month, $day, $hour, $minute, $second] = [1980, 1, 1, 0, 0, 0];
        } elseif ($year > 2107) {
            [$year, $month, $day, $hour, $minute, $second] = [2107, 12, 31, 23, 59, 58];
        }

        return [
            ($hour << 11) | ($minute << 5) | ($second >> 1),
            (($year - 1980) << 9) | ($month << 5) | $day,
        ];
    }
}
