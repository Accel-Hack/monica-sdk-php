<?php

declare(strict_types=1);

namespace Monica\Transport;

use RuntimeException;

/**
 * The byte side of `1 request = 1 envelope`.
 *
 * ingest.md caps an envelope at 1 MiB gzipped and 8 MiB decompressed, and says
 * the SDK splits what does not fit, on item boundaries. Batching by item count
 * alone does not do it: a hundred items is well under the cap most days, and
 * over it the day someone attaches a large `contexts` or a 200-frame trace to
 * every event.
 *
 * So an envelope is measured before it is posted, and halved until each piece
 * fits. If MONICA answers 413 anyway -- its limit is its own to change, and
 * limits.json is only a copy -- the piece is halved and posted again, which is
 * what `split_and_retry` asks for.
 *
 * A single item that still does not fit cannot be split any further. It is
 * dropped and counted, because the alternative is posting it forever: no
 * amount of retrying makes one item smaller. The count reaches MONICA in the
 * next envelope's `discarded`, and is reported through Diagnostics so the
 * application can see which of its events are too big to send.
 */
final class EnvelopeSplitter
{
    /**
     * The byte caps from limits.json (`envelope_gzip_bytes`,
     * `envelope_decompressed_bytes`).
     *
     * They are constants because `spec/` is a development-time copy of the
     * contract and is not shipped in the package (see `.gitattributes`), so
     * there is nothing to read at runtime. `tests/spec-contract.php` compares
     * them with limits.json, which is what keeps them from drifting.
     */
    public const MAX_GZIP_BYTES = 1048576;
    public const MAX_DECOMPRESSED_BYTES = 8388608;

    private Diagnostics $diagnostics;

    public function __construct(?Diagnostics $diagnostics = null)
    {
        $this->diagnostics = $diagnostics ?? new Diagnostics();
    }

    /**
     * Post an envelope, splitting it as far as it takes.
     *
     * `$post` receives one gzipped body and returns what MONICA answered. It is
     * called once per piece, in order, and stops at the first piece that fails
     * for a reason that would apply to the rest as well.
     *
     * The result is the answer to the last piece that was posted, carrying the
     * number of items dropped for being unsplittable. Pieces that were dropped
     * count as handled: nothing can be done with them, so leaving the caller to
     * retry the envelope would only lose the pieces that did get through.
     *
     * @param array<string, mixed> $envelope
     * @param callable(string): Response $post
     */
    public function send(array $envelope, callable $post): Response
    {
        // Each piece remembers where its items sat in the envelope it came
        // from, so a dropped item can be named to the caller and not merely
        // counted: the caller has a queue to take it out of.
        $pending = [['envelope' => $envelope, 'offset' => 0]];
        $droppedIndexes = [];
        $last = null;

        while ($pending !== []) {
            $work = array_shift($pending);
            $piece = $work['envelope'];
            $encoded = self::encode($piece);
            $tooBig = strlen($encoded['gzip']) > self::MAX_GZIP_BYTES
                || strlen($encoded['json']) > self::MAX_DECOMPRESSED_BYTES;

            if (!$tooBig) {
                $response = $post($encoded['gzip']);
                if ($response->status() !== 413) {
                    if ($response->outcome() !== Outcome::ACCEPTED) {
                        // Whatever this was -- refused key, rate limit, network
                        // -- applies to the pieces behind it too. What was
                        // already dropped still goes back with it: the caller
                        // is about to retry the rest, and must not retry these.
                        return self::withDropped($response, $droppedIndexes);
                    }
                    $last = $response;
                    continue;
                }
                // MONICA refuses a body this SDK measured as fitting. Its cap
                // is the real one, so the piece is split on its word.
            }

            $halves = self::halve($piece);
            if ($halves === null) {
                // Whatever is in this piece cannot be sent. Usually that is one
                // item; an envelope whose own fields do not fit has none, and
                // then nothing is lost and nothing is counted.
                $count = count(self::itemsOf($piece));
                for ($index = 0; $index < $count; $index++) {
                    $droppedIndexes[] = $work['offset'] + $index;
                }
                $this->diagnostics->warn(self::describeDrop($piece, $encoded['gzip'], $tooBig));
                continue;
            }
            array_unshift(
                $pending,
                ['envelope' => $halves[0], 'offset' => $work['offset']],
                [
                    'envelope' => $halves[1],
                    'offset' => $work['offset'] + count(self::itemsOf($halves[0])),
                ]
            );
        }

        return self::withDropped($last ?? Response::forOutcome(Outcome::ACCEPTED), $droppedIndexes);
    }

    /**
     * @param list<int> $droppedIndexes
     */
    private static function withDropped(Response $response, array $droppedIndexes): Response
    {
        return $droppedIndexes === []
            ? $response
            : $response->withDroppedItems(count($droppedIndexes), $droppedIndexes);
    }

    /**
     * Split an envelope in two on the item boundary, or null when there is only
     * one item left to carry.
     *
     * `discarded` stays with the first half. It counts events lost before the
     * envelope was built, so copying it into both halves would report the same
     * losses twice.
     *
     * @param array<string, mixed> $envelope
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function halve(array $envelope): ?array
    {
        $items = self::itemsOf($envelope);
        if (count($items) < 2) {
            return null;
        }
        $middle = intdiv(count($items), 2);
        $first = $envelope;
        $first['items'] = array_slice($items, 0, $middle);
        $second = $envelope;
        $second['items'] = array_slice($items, $middle);
        $second['discarded'] = 0;

        return [$first, $second];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{json: string, gzip: string}
     */
    private static function encode(array $envelope): array
    {
        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $gzip = gzencode($json, 6);
        if ($gzip === false) {
            throw new RuntimeException('Unable to gzip the MONICA envelope');
        }

        return ['json' => $json, 'gzip' => $gzip];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<mixed>
     */
    private static function itemsOf(array $envelope): array
    {
        return isset($envelope['items']) && is_array($envelope['items'])
            ? array_values($envelope['items'])
            : [];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function describeDrop(array $envelope, string $gzip, bool $measured): string
    {
        $items = self::itemsOf($envelope);

        return 'monica: dropped ' . count($items) . ' item(s) that cannot fit one envelope ('
            . strlen($gzip) . ' gzip bytes, limit ' . self::MAX_GZIP_BYTES . ', '
            . ($measured ? 'measured by the SDK' : 'ingest answered 413')
            . '); the event(s) are lost';
    }
}
