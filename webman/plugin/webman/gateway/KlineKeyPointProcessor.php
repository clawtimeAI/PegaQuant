<?php

namespace plugin\webman\gateway;

use support\Db;

class KlineKeyPointProcessor
{
    // Supported intervals (consistent with KlineSync)
    private static $intervals = ['1m','5m', '15m', '30m', '1h', '4h'];

    // Sleep time between cycles (seconds)
    private static $cycleSleep = 400;

    // Chunk size for processing candles
    private static $chunkSize = 1000;

    // Batch size for database updates (no longer needed but kept for reference)
    private static $updateBatchSize = 500;
    private static int $intervalCursor = 0;

    /**
     * Continuously processes key points for all symbols and intervals.
     * @param int $workid Worker ID for logging purposes
     * @param string|null $onlySymbol Only process this symbol when provided
     * @return void
     */
    public static function processKeyPoints(int $workid = 0, ?string $onlySymbol = null): void
    {
        $onlySymbol = $onlySymbol !== null ? strtoupper(trim($onlySymbol)) : null;
        $historyMode = $workid < 0;

        while(true){
            $startTime = time();
            $totalProcessed = 0;
            $totalUpdated = 0;

            echo "Starting key point processing cycle at " . date('Y-m-d H:i:s') . " (workid: $workid)\n";

            $symbolQuery = Db::table('ApairInfo')
                ->select('id', 'symbol')
                ->where('status', 'TRADING')
                ->orderBy('id', 'asc');

            if ($onlySymbol !== null && $onlySymbol !== '') {
                $symbolQuery->where('symbol', $onlySymbol);
            } else if ($workid >= 0) {
                $symbolQuery->whereRaw('MOD(id, 2) = ?', [$workid]);
            }

            $totalSymbols = (int)$symbolQuery->count();
            if ($totalSymbols <= 0) {
                echo "No symbols found for workid $workid.\n";
                sleep(self::$cycleSleep);
                continue;
            }

            echo "Found $totalSymbols symbols for workid $workid.\n";

            $symbolIndex = 0;
            foreach ($symbolQuery->cursor() as $symbolData) {
                $symbolIndex++;
                $symbol = $symbolData->symbol;
                $symbolProcessed = 0;
                foreach (self::$intervals as $interval) {
                    try {
                        echo "Processing key points for $symbol ($interval)\n";
                        // if ($interval != '4h') {
                        //      continue;
                        // }
                        $updated = self::detectKeyPoints($symbol, $interval, $historyMode ? null : 50.0);
                        $totalUpdated += $updated;
                        $symbolProcessed++;
                        $totalProcessed++;
                    } catch (\Exception $e) {
                        echo "Error processing key points for $symbol ($interval): {$e->getMessage()}\n";
                        continue;
                    }

                    // Print progress after each interval
                    $progress = ($symbolIndex / $totalSymbols) * 100;
                    echo "Progress: $symbolIndex/$totalSymbols symbols processed (" . number_format($progress, 2) . "%)\n";
                }
            }

            $totalTime = time() - $startTime;
            echo "Key point processing cycle completed in {$totalTime}s. Total processed: $totalProcessed intervals. Updated: $totalUpdated\n";

            if ($historyMode) {
                if ($totalUpdated <= 0) {
                    return;
                }
                continue;
            }

            echo "Sleeping for " . self::$cycleSleep . "s...\n";
            sleep(self::$cycleSleep);
        }
        
    }

    public static function processKeyPointsOnce(int $workid = 0, ?string $onlySymbol = null, ?array $onlyIntervals = null): int
    {
        $onlySymbol = $onlySymbol !== null ? strtoupper(trim($onlySymbol)) : null;
        $onlyIntervals = $onlyIntervals ? array_values(array_filter(array_map('strval', $onlyIntervals))) : null;

        $processed = 0;
        $deadlineAt = microtime(true) + 55.0;
        $timeBudgetPerIntervalSeconds = 8.0;

        $symbolQuery = Db::table('ApairInfo')
            ->select('id', 'symbol')
            ->where('status', 'TRADING')
            ->orderBy('id', 'asc');

        if ($onlySymbol !== null && $onlySymbol !== '') {
            $symbolQuery->where('symbol', $onlySymbol);
        } else if ($workid >= 0) {
            $symbolQuery->whereRaw('MOD(id, 2) = ?', [$workid]);
        }

        foreach ($symbolQuery->cursor() as $symbolData) {
            if (microtime(true) >= $deadlineAt) {
                break;
            }
            $symbol = $symbolData->symbol;
            $intervals = self::$intervals;
            $intervalCount = count($intervals);
            if ($intervalCount > 1) {
                $start = self::$intervalCursor % $intervalCount;
                if ($start !== 0) {
                    $intervals = array_merge(array_slice($intervals, $start), array_slice($intervals, 0, $start));
                }
            }
            self::$intervalCursor++;

            foreach ($intervals as $interval) {
                if (microtime(true) >= $deadlineAt) {
                    break 2;
                }
                if ($onlyIntervals !== null && !in_array($interval, $onlyIntervals, true)) {
                    continue;
                }
                self::detectKeyPoints($symbol, $interval, $timeBudgetPerIntervalSeconds);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Detects key points (is_key) for a symbol and interval, and updates is_check.
     * @param string $symbol Trading pair (e.g., BTCUSDT)
     * @param string $interval Interval (5m, 15m, 30m, 1h, 4h)
     * @return void
     * @throws \Exception
     */
    public static function detectKeyPoints(string $symbol, string $interval, ?float $timeBudgetSeconds = 50.0): int
    {
        $table = "kline_$interval";
        $totalUpdated = 0;

        // Step 1: Ensure the first candle has is_check=1
        $firstCandle = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'asc')
            ->first(['open_time', 'is_check']);

        if ($firstCandle) {
            if ($firstCandle->is_check != 1) {
                Db::table($table)
                    ->where('symbol', $symbol)
                    ->where('open_time', $firstCandle->open_time)
                    ->update(['is_check' => 1]);
                echo "Set first candle is_check=1 for $symbol ($interval) at open_time: {$firstCandle->open_time}\n";
            } else {
                echo "First candle for $symbol ($interval) already has is_check=1 at open_time: {$firstCandle->open_time}\n";
            }
        } else {
            echo "No candles found for $symbol ($interval).\n";
            return 0;
        }

        $firstUnchecked = Db::table($table)
            ->where('symbol', $symbol)
            ->where('is_check', 0)
            ->orderBy('open_time', 'asc')
            ->first(['open_time']);

        if (!$firstUnchecked) {
            echo "No unchecked candles found for $symbol ($interval).\n";
            return 0;
        }

        $firstUncheckedOpenTime = $firstUnchecked->open_time;
        $prevCandle = Db::table($table)
            ->where('symbol', $symbol)
            ->where('open_time', '<', $firstUncheckedOpenTime)
            ->orderBy('open_time', 'desc')
            ->first(['open_time']);

        $lastOpenTime = $prevCandle ? $prevCandle->open_time : $firstUncheckedOpenTime;
        echo "Start processing for $symbol ($interval) from open_time: $lastOpenTime\n";

        $deadlineAt = $timeBudgetSeconds === null ? null : (microtime(true) + max(1.0, $timeBudgetSeconds));

        while (true) {
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
                break;
            }
            // Fetch candles after the last checked open_time
            $candles = Db::table($table)
                ->where('symbol', $symbol)
                ->where('open_time', '>=', $lastOpenTime)
                ->orderBy('open_time', 'asc')
                ->take(self::$chunkSize) // Fetch extra candles for overlap
                ->get(['id', 'high', 'low', 'close', 'boll_up', 'boll_dn', 'is_check', 'open_time']);

            if ($candles->isEmpty()) {
                echo "No more candles to process for $symbol ($interval).\n";
                break;
            }

            $chunkCount = $candles->count();
            if ($chunkCount < 3) {
                echo "Not enough candles (< 3) to process for $symbol ($interval).\n";
                break;
            }
            echo "Processing chunk of $chunkCount candles for $symbol ($interval) starting after open_time: $lastOpenTime\n";

            // Prepare updates for this chunk
            $updates = [];
            for ($i = 1; $i < $chunkCount - 1; $i++) {
                // Skip if already processed
                if ($candles[$i]->is_check == 1) {
                    continue;
                }

                $prevCandle = $candles[$i - 1];
                $currentCandle = $candles[$i];
                $nextCandle = $candles[$i + 1];

                $isKey = 0; // Default: not a key point

                $isHighKey = $currentCandle->high > $prevCandle->high && $currentCandle->high > $nextCandle->high;
                $isLowKey = $currentCandle->low < $prevCandle->low && $currentCandle->low < $nextCandle->low;

                if ($isHighKey && $isLowKey) {
                    $close = (float)$currentCandle->close;
                    $bollUp = $currentCandle->boll_up !== null ? (float)$currentCandle->boll_up : null;
                    $bollDn = $currentCandle->boll_dn !== null ? (float)$currentCandle->boll_dn : null;

                    if ($bollUp !== null && $close > $bollUp) {
                        $isKey = 1;
                    } elseif ($bollDn !== null && $close < $bollDn) {
                        $isKey = 2;
                    } else {
                        $isKey = 1;
                    }
                } elseif ($isHighKey) {
                    $isKey = 1;
                } elseif ($isLowKey) {
                    $isKey = 2;
                }

                $updates[] = [
                    'open_time' => $currentCandle->open_time,
                    'is_key' => $isKey,
                    'is_check' => 1
                ];
            }

            // Perform row-by-row updates
            if (!empty($updates)) {
                self::batchUpdateCandles($table, $symbol, $updates);
                $totalUpdated += count($updates);
            }

            // Update lastOpenTime to the last candle's open_time in this chunk
            $newLastOpenTime = $candles[$chunkCount - 2]->open_time;
            if ($newLastOpenTime === $lastOpenTime) {
                echo "No progress for $symbol ($interval) at open_time: $lastOpenTime\n";
                break;
            }
            $lastOpenTime = $newLastOpenTime;
        }

        echo "Completed key point detection for $symbol ($interval)\n";
        return $totalUpdated;
    }

    private static function batchUpdateCandles(string $table, string $symbol, array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $batchSize = 200;
        $total = count($updates);
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $batch = array_slice($updates, $offset, $batchSize);
            if (empty($batch)) {
                continue;
            }

            $minOpenTime = $batch[0]['open_time'];
            $maxOpenTime = $batch[0]['open_time'];
            foreach ($batch as $u) {
                if ($u['open_time'] < $minOpenTime) {
                    $minOpenTime = $u['open_time'];
                }
                if ($u['open_time'] > $maxOpenTime) {
                    $maxOpenTime = $u['open_time'];
                }
            }

            $valueSqlParts = [];
            $bindings = [];
            foreach ($batch as $u) {
                $valueSqlParts[] = '(CAST(? AS timestamp), CAST(? AS smallint), CAST(? AS smallint))';
                $bindings[] = (string)$u['open_time'];
                $bindings[] = (int)$u['is_key'];
                $bindings[] = (int)$u['is_check'];
            }
            $bindings[] = $symbol;
            $bindings[] = (string)$minOpenTime;
            $bindings[] = (string)$maxOpenTime;

            $sql = "UPDATE {$table} AS t
SET is_key = v.is_key,
    is_check = v.is_check
FROM (VALUES " . implode(',', $valueSqlParts) . ") AS v(open_time, is_key, is_check)
WHERE t.symbol = ?
  AND t.open_time >= CAST(? AS timestamp)
  AND t.open_time <= CAST(? AS timestamp)
  AND t.open_time = v.open_time";

            Db::statement($sql, $bindings);
        }
    }
}
