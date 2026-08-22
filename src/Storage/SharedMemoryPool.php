<?php

namespace Orieg\JudyCache\Storage;

use Orieg\JudyCache\InvalidArgumentException;

/**
 * Shared memory payload pool using PHP's shmop and Unix shared memory.
 *
 * Enables multiple persistent worker processes (FrankenPHP, Octane, Swoole, CLI daemons)
 * to read and write large cached payloads from a shared OS memory segment with zero-copy
 * read access while local Judy arrays handle rapid O(range) key routing.
 */
class SharedMemoryPool
{
    private const MAGIC = "\x00JSHM\x01";
    private const HEADER_SIZE = 64;

    private \Shmop $shmop;
    private int $totalSize;
    private int $chunkSize;
    private int $totalChunks;
    private int $bitmapSize;
    private int $dataOffset;
    /** @var resource|\SysvSemaphore|null */
    private $semId = null;

    /**
     * @param int $key System V IPC key (e.g. ftok() result or integer constant like 0x53484D31).
     * @param int $size Total shared memory segment size in bytes (e.g. 10MB = 10485760).
     * @param int $chunkSize Size of each chunk in bytes (e.g. 4096).
     * @param string $mode shmop mode: 'c' (create or open read-write), 'w' (open read-write), 'a' (read-only), 'n' (create new).
     * @param int $permissions Octal Unix permissions (default 0644).
     */
    public function __construct(
        private readonly int $key = 0x53484D31,
        int $size = 10485760,
        int $chunkSize = 4096,
        string $mode = 'c',
        int $permissions = 0644,
    ) {
        if (!\extension_loaded('shmop')) {
            throw new InvalidArgumentException('SharedMemoryPool requires ext-shmop');
        }
        if ($size <= self::HEADER_SIZE) {
            throw new InvalidArgumentException('size must be greater than header size (' . self::HEADER_SIZE . ' bytes)');
        }
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('chunkSize must be a positive integer');
        }

        $shmop = @\shmop_open($this->key, $mode, $permissions, $size);
        if ($shmop === false) {
            $err = \error_get_last();
            throw new \RuntimeException('Failed to open shmop segment (key: 0x' . \dechex($this->key) . '): ' . ($err['message'] ?? 'unknown error'));
        }
        $this->shmop = $shmop;
        $this->totalSize = \shmop_size($this->shmop);

        if (\function_exists('sem_get')) {
            $this->semId = @\sem_get($this->key, 1, $permissions, 1);
        }

        $this->initOrLoadSegment($chunkSize);
    }

    private function initOrLoadSegment(int $requestedChunkSize): void
    {
        $this->withLock(function () use ($requestedChunkSize) {
            $magic = \shmop_read($this->shmop, 0, 6);
            if ($magic !== self::MAGIC) {
                // Initialize new shared memory layout
                $this->chunkSize = $requestedChunkSize;
                $usableBytes = $this->totalSize - self::HEADER_SIZE;
                // Solve for totalChunks: totalChunks * chunkSize + ceil(totalChunks / 8) <= usableBytes
                $this->totalChunks = (int) \floor($usableBytes / ($this->chunkSize + (1 / 8)));
                $this->bitmapSize = (int) \ceil($this->totalChunks / 8);
                $this->dataOffset = self::HEADER_SIZE + $this->bitmapSize;

                // Write fixed header (64 bytes)
                $header = self::MAGIC                                // 0..5 (6 bytes)
                    . \pack('v', 1)                                  // 6..7 (2 bytes version)
                    . \pack('V', $this->totalSize)                   // 8..11 (4 bytes size)
                    . \pack('V', $this->chunkSize)                   // 12..15 (4 bytes chunk size)
                    . \pack('V', $this->totalChunks)                 // 16..19 (4 bytes total chunks)
                    . \pack('V', 0)                                  // 20..23 (4 bytes allocated chunks)
                    . \pack('V', self::HEADER_SIZE)                  // 24..27 (4 bytes header size)
                    . \pack('V', $this->bitmapSize)                  // 28..31 (4 bytes bitmap size)
                    . \str_repeat("\0", 32);                         // 32..63 (32 bytes reserved)

                \shmop_write($this->shmop, $header, 0);

                // Zero out bitmap
                if ($this->bitmapSize > 0) {
                    \shmop_write($this->shmop, \str_repeat("\0", $this->bitmapSize), self::HEADER_SIZE);
                }
            } else {
                // Load existing layout metadata from header
                $header = \shmop_read($this->shmop, 8, 24);
                $meta = \unpack('VtotalSize/VchunkSize/VtotalChunks/VallocatedChunks/VheaderSize/VbitmapSize', $header);
                $this->chunkSize = $meta['chunkSize'];
                $this->totalChunks = $meta['totalChunks'];
                $this->bitmapSize = $meta['bitmapSize'];
                $this->dataOffset = self::HEADER_SIZE + $this->bitmapSize;
            }
        });
    }

    /**
     * Allocate storage for a payload in shared memory and return its chunk offset ID.
     *
     * @param string $data Byte payload to store.
     * @return int Chunk offset ID.
     * @throws \OverflowException If shared memory pool is full.
     */
    public function allocate(string $data): int
    {
        $dataLen = \strlen($data);
        $totalBytes = 4 + $dataLen; // 4-byte uint32 length prefix + data
        $numChunks = (int) \ceil($totalBytes / $this->chunkSize);

        return $this->withLock(function () use ($data, $dataLen, $numChunks) {
            $bitmap = $this->bitmapSize > 0 ? \shmop_read($this->shmop, self::HEADER_SIZE, $this->bitmapSize) : '';
            $startIndex = $this->findFreeChunksInBitmap($bitmap, $numChunks);
            if ($startIndex === -1) {
                throw new \OverflowException("SharedMemoryPool: out of space (cannot allocate {$numChunks} chunks of {$this->chunkSize} bytes)");
            }

            // Mark chunks allocated in bitmap
            $updatedBitmap = $this->setBitmapBits($bitmap, $startIndex, $numChunks, true);
            \shmop_write($this->shmop, $updatedBitmap, self::HEADER_SIZE);

            // Update allocated chunks count
            $allocatedChunks = $this->getAllocatedChunks() + $numChunks;
            \shmop_write($this->shmop, \pack('V', $allocatedChunks), 20);

            // Write payload with 4-byte length prefix
            $byteOffset = $this->dataOffset + ($startIndex * $this->chunkSize);
            $payload = \pack('V', $dataLen) . $data;
            \shmop_write($this->shmop, $payload, $byteOffset);

            return $startIndex;
        });
    }

    /**
     * Read payload from the given chunk offset ID.
     * Zero-copy lock-free read for high concurrency across workers.
     *
     * @param int $offsetId Offset chunk ID returned by allocate().
     * @return string Payload data.
     * @throws InvalidArgumentException If offset ID is out of range.
     */
    public function read(int $offsetId): string
    {
        if ($offsetId < 0 || $offsetId >= $this->totalChunks) {
            throw new InvalidArgumentException("Invalid shared memory offset ID: {$offsetId}");
        }

        $byteOffset = $this->dataOffset + ($offsetId * $this->chunkSize);
        $header = \shmop_read($this->shmop, $byteOffset, 4);
        if (\strlen($header) < 4) {
            throw new InvalidArgumentException("Corrupt shared memory chunk header at offset: {$offsetId}");
        }

        $dataLen = \unpack('V', $header)[1];
        return $dataLen > 0 ? \shmop_read($this->shmop, $byteOffset + 4, $dataLen) : '';
    }

    /**
     * Overwrite payload at the given chunk offset ID.
     *
     * @param int $offsetId Offset chunk ID.
     * @param string $data New payload data.
     * @throws InvalidArgumentException If offset ID is invalid or payload exceeds allocated space.
     */
    public function write(int $offsetId, string $data): void
    {
        if ($offsetId < 0 || $offsetId >= $this->totalChunks) {
            throw new InvalidArgumentException("Invalid shared memory offset ID: {$offsetId}");
        }

        $dataLen = \strlen($data);
        $totalBytes = 4 + $dataLen;

        $this->withLock(function () use ($offsetId, $data, $dataLen, $totalBytes) {
            $byteOffset = $this->dataOffset + ($offsetId * $this->chunkSize);
            $oldHeader = \shmop_read($this->shmop, $byteOffset, 4);
            $oldLen = \unpack('V', $oldHeader)[1];
            $allocatedChunks = (int) \ceil((4 + $oldLen) / $this->chunkSize);
            $maxBytes = $allocatedChunks * $this->chunkSize;

            if ($totalBytes > $maxBytes) {
                throw new InvalidArgumentException("Updated payload ({$totalBytes} bytes) exceeds previously allocated chunk space ({$maxBytes} bytes)");
            }

            $payload = \pack('V', $dataLen) . $data;
            \shmop_write($this->shmop, $payload, $byteOffset);
        });
    }

    /**
     * Free allocation at the given chunk offset ID.
     *
     * @param int $offsetId Offset chunk ID.
     * @throws InvalidArgumentException If offset ID is out of range.
     */
    public function free(int $offsetId): void
    {
        if ($offsetId < 0 || $offsetId >= $this->totalChunks) {
            throw new InvalidArgumentException("Invalid shared memory offset ID: {$offsetId}");
        }

        $this->withLock(function () use ($offsetId) {
            $byteOffset = $this->dataOffset + ($offsetId * $this->chunkSize);
            $header = \shmop_read($this->shmop, $byteOffset, 4);
            $dataLen = \unpack('V', $header)[1];
            $numChunks = (int) \ceil((4 + $dataLen) / $this->chunkSize);

            $bitmap = \shmop_read($this->shmop, self::HEADER_SIZE, $this->bitmapSize);
            $updatedBitmap = $this->setBitmapBits($bitmap, $offsetId, $numChunks, false);
            \shmop_write($this->shmop, $updatedBitmap, self::HEADER_SIZE);

            $allocatedChunks = \max(0, $this->getAllocatedChunks() - $numChunks);
            \shmop_write($this->shmop, \pack('V', $allocatedChunks), 20);
        });
    }

    /** Total shared memory segment size in bytes. */
    public function getSize(): int
    {
        return $this->totalSize;
    }

    /** Size of each chunk in bytes. */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /** Total chunk capacity. */
    public function getTotalChunks(): int
    {
        return $this->totalChunks;
    }

    /** Number of currently allocated chunks. */
    public function getAllocatedChunks(): int
    {
        $raw = \shmop_read($this->shmop, 20, 4);
        return \unpack('V', $raw)[1];
    }

    /** Number of free chunks. */
    public function getFreeChunks(): int
    {
        return \max(0, $this->totalChunks - $this->getAllocatedChunks());
    }

    /** Reset all allocations in shared memory. */
    public function clear(): void
    {
        $this->withLock(function () {
            if ($this->bitmapSize > 0) {
                \shmop_write($this->shmop, \str_repeat("\0", $this->bitmapSize), self::HEADER_SIZE);
            }
            \shmop_write($this->shmop, \pack('V', 0), 20);
        });
    }

    /** Mark shared memory segment for deletion from the OS. */
    public function delete(): bool
    {
        return \shmop_delete($this->shmop);
    }

    private function findFreeChunksInBitmap(string $bitmap, int $count): int
    {
        $consecutive = 0;
        $start = -1;

        for ($i = 0; $i < $this->totalChunks; $i++) {
            $byteIdx = $i >> 3;
            $bitIdx = $i & 7;
            $byte = \ord($bitmap[$byteIdx] ?? "\0");

            if (($byte & (1 << $bitIdx)) === 0) {
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

    private function setBitmapBits(string $bitmap, int $startIndex, int $count, bool $allocated): string
    {
        for ($i = 0; $i < $count; $i++) {
            $idx = $startIndex + $i;
            $byteIdx = $idx >> 3;
            $bitIdx = $idx & 7;
            $byte = \ord($bitmap[$byteIdx] ?? "\0");
            if ($allocated) {
                $byte |= (1 << $bitIdx);
            } else {
                $byte &= ~(1 << $bitIdx);
            }
            $bitmap[$byteIdx] = \chr($byte);
        }
        return $bitmap;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(callable $fn): mixed
    {
        if ($this->semId !== null && \is_resource($this->semId)) {
            \sem_acquire($this->semId);
            try {
                return $fn();
            } finally {
                \sem_release($this->semId);
            }
        }

        return $fn();
    }
}
