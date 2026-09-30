<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * The mock's queue: one JSON file, read and written under an exclusive lock.
 *
 * The real gateway keeps each item encrypted, in a store that expires it with its TTL. The mock is a local development tool and keeps plain JSON on the
 * developer's disk instead — which is exactly why real comments must never be sent to it.
 *
 * An entry: `{entry, item_id, text, profile, meta, received_at, next_at, attempts,
 * deliveries}` — times in unix seconds.
 */
final class Queue
{
    /** @var string */
    private $path;

    /**
     * @param string $path The queue file; its directory is created when missing.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * The number of entries.
     *
     * @return int The count.
     */
    public function length(): int
    {
        return count($this->all());
    }

    /**
     * Every entry, oldest first.
     *
     * @return array<int,array<string,mixed>> The entries.
     */
    public function all(): array
    {
        return $this->mutate(static function (array $entries): array {
            return $entries;
        }, false);
    }

    /**
     * Appends items.
     *
     * @param array<int,array<string,mixed>> $items Items with `item_id`, `text`, `profile`,
     *     `meta`, `received_at` and `next_at`.
     * @return void
     */
    public function append(array $items): void
    {
        $this->mutate(static function (array $entries) use ($items): array {
            foreach ($items as $item) {
                $item['entry'] = bin2hex(random_bytes(8));
                $item['attempts'] = 0;
                $item['deliveries'] = 0;
                $entries[] = $item;
            }
            return $entries;
        });
    }

    /**
     * Replaces one entry's fields.
     *
     * @param string               $entry   The entry id.
     * @param array<string,mixed>  $changes Fields to set.
     * @return void
     */
    public function update(string $entry, array $changes): void
    {
        $this->mutate(static function (array $entries) use ($entry, $changes): array {
            foreach ($entries as $i => $current) {
                if ($current['entry'] === $entry) {
                    $entries[$i] = array_merge($current, $changes);
                }
            }
            return $entries;
        });
    }

    /**
     * Removes one entry.
     *
     * @param string $entry The entry id.
     * @return void
     */
    public function remove(string $entry): void
    {
        $this->mutate(static function (array $entries) use ($entry): array {
            return array_values(array_filter($entries, static function (array $e) use ($entry): bool {
                return $e['entry'] !== $entry;
            }));
        });
    }

    /**
     * Reads the file, applies a change and (optionally) writes the result, all under one lock.
     *
     * @param callable $change  `fn(array $entries): array`.
     * @param bool     $persist Whether to write the result back.
     * @return array<int,array<string,mixed>> The entries after the change.
     * @throws \RuntimeException When the queue file cannot be opened or locked.
     */
    private function mutate(callable $change, bool $persist = true): array
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the queue directory: ' . $dir);
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the queue file: ' . $this->path);
        }
        try {
            $raw = stream_get_contents($handle);
            $entries = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $entries = $change(is_array($entries) ? $entries : []);
            if ($persist) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string)json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($handle);
            }
            return $entries;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
