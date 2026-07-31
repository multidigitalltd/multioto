<?php

namespace App\Services\Backup;

use php_user_filter;

/**
 * A stream filter that reports progress while somebody else does the reading.
 *
 * Writing one file to a disk is a single call — `put($path, $handle)` — and the
 * work inside it can take longer than the window in which this system believes
 * an operation is still running. Nothing on our side of that call runs while it
 * is happening, except this: the uploader pulls the source stream through the
 * filter, so every chunk it reads is a chance to say "still here".
 *
 * The callback is passed as the filter's params and is expected to throttle
 * itself — it is invoked per chunk, which can be very often.
 */
class HeartbeatFilter extends php_user_filter
{
    public const NAME = 'multioto.heartbeat';

    private static bool $registered = false;

    /** Attach the filter to a read stream, with the callback to fire per chunk. */
    public static function attach(mixed $stream, callable $beat): void
    {
        if (! self::$registered) {
            self::$registered = stream_filter_register(self::NAME, self::class);
        }

        if (self::$registered && is_resource($stream)) {
            stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $beat);
        }
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            if (is_callable($this->params)) {
                ($this->params)();
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
