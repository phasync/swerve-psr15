<?php

use Psr\Http\Message\StreamInterface;

/**
 * A readable stream of $total bytes that exist only a chunk at a time: chunk i is the letter
 * a + i % 26 repeated. Nothing is held, so a body larger than memory can be answered with it.
 */
final class GeneratedStream implements StreamInterface
{
    public const CHUNK = 65536;

    private int $position = 0;

    public function __construct(private readonly int $total, private readonly bool $sized)
    {
    }

    /** The bytes of chunk $i of a stream of $total bytes. */
    public static function chunk(int $i, int $total): string
    {
        return \str_repeat(\chr(97 + $i % 26), \min(self::CHUNK, $total - $i * self::CHUNK));
    }

    public function __toString(): string
    {
        throw new LogicException('a generated stream is not meant to be held');
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->sized ? $this->total : null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->total;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        throw new RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $chunk = self::chunk(\intdiv($this->position, self::CHUNK), $this->total);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        throw new LogicException('a generated stream is not meant to be held');
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}

/** A stream that gives some bytes, then fails: the application breaking while the response is on its way. */
final class FailingStream implements StreamInterface
{
    private bool $started = false;

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        if ($this->started) {
            throw new RuntimeException('the stream failed halfway');
        }
        $this->started = true;

        return 'first part';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}
