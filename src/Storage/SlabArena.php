<?php

namespace Orieg\JudyCache\Storage;

use Orieg\JudyCache\InvalidArgumentException;

/**
 * Dedicated contiguous buffer slab allocator for large byte payloads
 * (JSON documents, HTML fragments, binary blobs).
 *
 * Manages pre-allocated chunk blocks with bitmap tracking to prevent
 * Zend Memory Manager (ZMM) heap fragmentation in long-running PHP workers.
 */
class SlabArena
{
    /** @var resource Stream handle to contiguous memory buffer */
    private $stream;
    private int $totalChunks;
    private int $allocatedChunks = 0;
    /** @var array<int, int> Map of chunkIndex => chunkCount */
    private array $allocations = [];
    /** @var array<int, int> Bitmap 32-bit integer words */
    private array $bitmap = [];
    private int $bitmapWords;

    /**
     * @param int $chunkSize Size in bytes of each chunk (e.g. 4096 for 4KB chunks).
     * @param int $initialChunks Initial number of chunk slots to pre-allocate.
     * @param int $maxChunks Maximum number of chunk slots the arena is allowed to grow to.
     */
    public function __construct(
        private readonly int $chunkSize = 4096,
        private readonly int $initialChunks = 256,
        private readonly int $maxChunks = 65536,
    ) {
        if ($this->chunkSize <= 0) {
            throw new InvalidArgumentException('chunkSize must be a positive integer');
        }
        if ($this->initialChunks <= 0) {
            throw new InvalidArgumentException('initialChunks must be a positive integer');
        }
        if ($this->maxChunks < $this->initialChunks) {
            throw new InvalidArgumentException('maxChunks must be greater than or equal to initialChunks');
        }

        $this->totalChunks = $this->initialChunks;
        $this->bitmapWords = (int) \ceil($this->totalChunks / 32);
        $this->bitmap = \array_fill(0, $this->bitmapWords, 0);

        $stream = \fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open memory buffer for SlabArena');
        }
        $this->stream = $stream;
        \ftruncate($this->stream, $this->totalChunks * $this->chunkSize);
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    /**
     * Allocate storage for a string payload and return its chunk offset ID.
     *
     * @param string $data Byte payload to store.
     * @return int Offset chunk ID.
     * @throws \OverflowException If arena is exhausted and cannot grow further.
     */
    public function allocate(string $data): int
    {
        $dataLen = \strlen($data);
        $totalBytes = 4 + $dataLen; // 4-byte uint32 length prefix + data
        $numChunks = (int) \ceil($totalBytes / $this->chunkSize);

        $startIndex = $this->findFreeChunks($numChunks);
        if ($startIndex === -1) {
            if (!$this->grow($numChunks)) {
                throw new \OverflowException(
                    "SlabArena: out of memory (cannot allocate {$numChunks} chunks of {$this->chunkSize} bytes, maxChunks={$this->maxChunks})"
                );
            }
            $startIndex = $this->findFreeChunks($numChunks);
            if ($startIndex === -1) {
                throw new \OverflowException('SlabArena: out of memory');
            }
        }

        $this->markChunks($startIndex, $numChunks, true);
        $this->allocations[$startIndex] = $numChunks;
        $this->allocatedChunks += $numChunks;

        $byteOffset = $startIndex * $this->chunkSize;
        \fseek($this->stream, $byteOffset, \SEEK_SET);
        \fwrite($this->stream, \pack('V', $dataLen) . $data);

        return $startIndex;
    }

    /**
     * Read payload stored at the given chunk offset ID.
     *
     * @param int $offsetId Offset ID returned by allocate().
     * @return string Payload.
     * @throws InvalidArgumentException If offset ID is invalid or freed.
     */
    public function read(int $offsetId): string
    {
        if (!isset($this->allocations[$offsetId])) {
            throw new InvalidArgumentException("Invalid or freed slab offset: {$offsetId}");
        }

        $byteOffset = $offsetId * $this->chunkSize;
        \fseek($this->stream, $byteOffset, \SEEK_SET);
        $header = \fread($this->stream, 4);
        if (\strlen($header) < 4) {
            throw new InvalidArgumentException("Corrupt slab header at offset: {$offsetId}");
        }

        $dataLen = \unpack('V', $header)[1];
        return $dataLen > 0 ? (string) \fread($this->stream, $dataLen) : '';
    }

    /**
     * Free the allocation at the given chunk offset ID.
     *
     * @param int $offsetId Offset ID returned by allocate().
     * @throws InvalidArgumentException If offset ID is invalid or already freed.
     */
    public function free(int $offsetId): void
    {
        if (!isset($this->allocations[$offsetId])) {
            throw new InvalidArgumentException("Cannot free invalid or already-freed slab offset: {$offsetId}");
        }

        $numChunks = $this->allocations[$offsetId];
        $this->markChunks($offsetId, $numChunks, false);
        $this->allocatedChunks -= $numChunks;
        unset($this->allocations[$offsetId]);
    }

    /** Size of each chunk in bytes. */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /** Total chunk capacity currently provisioned. */
    public function getTotalChunks(): int
    {
        return $this->totalChunks;
    }

    /** Number of chunks currently allocated. */
    public function getAllocatedChunks(): int
    {
        return $this->allocatedChunks;
    }

    /** Number of chunks currently free. */
    public function getFreeChunks(): int
    {
        return $this->totalChunks - $this->allocatedChunks;
    }

    /** Memory usage in bytes of the allocated buffer. */
    public function getMemoryUsage(): int
    {
        return $this->totalChunks * $this->chunkSize;
    }

    /** Reset all allocations in the arena. */
    public function clear(): void
    {
        $this->allocatedChunks = 0;
        $this->allocations = [];
        $this->bitmap = \array_fill(0, $this->bitmapWords, 0);
    }

    private function findFreeChunks(int $count): int
    {
        $consecutive = 0;
        $start = -1;

        for ($i = 0; $i < $this->totalChunks; $i++) {
            $word = $i >> 5;
            $bit = $i & 31;

            if (($this->bitmap[$word] & (1 << $bit)) === 0) {
                if ($consecutive === 0) {
                    $start = $i;
                }
                $consecutive++;
                if ($consecutive === $count) {
                    return $start;
                }
            } else {
                $consecutive = 0;
                $start = -1;
            }
        }

        return -1;
    }

    private function markChunks(int $startIndex, int $count, bool $allocated): void
    {
        for ($i = 0; $i < $count; $i++) {
            $idx = $startIndex + $i;
            $word = $idx >> 5;
            $bit = $idx & 31;
            if ($allocated) {
                $this->bitmap[$word] |= (1 << $bit);
            } else {
                $this->bitmap[$word] &= ~(1 << $bit);
            }
        }
    }

    private function grow(int $neededChunks): bool
    {
        if ($this->totalChunks >= $this->maxChunks) {
            return false;
        }

        $newTotal = \min($this->maxChunks, \max($this->totalChunks * 2, $this->totalChunks + $neededChunks));
        if ($newTotal <= $this->totalChunks) {
            return false;
        }

        $newWords = (int) \ceil($newTotal / 32);
        for ($w = $this->bitmapWords; $w < $newWords; $w++) {
            $this->bitmap[$w] = 0;
        }

        $this->totalChunks = $newTotal;
        $this->bitmapWords = $newWords;
        \ftruncate($this->stream, $this->totalChunks * $this->chunkSize);

        return true;
    }
}
