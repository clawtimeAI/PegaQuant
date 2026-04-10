<?php

namespace plugin\webman\gateway;

use support\Db;

class KlineSync
{
    // Supported intervals
    private static $intervals = ['1m', '5m', '15m', '30m', '1h', '4h'];

    // API endpoint
    private static $apiUrl = 'https://fapi.binance.com/fapi/v1/klines';

    // Max candles per API request
    private static $limit = 1500;

    // Bollinger Bands parameters
    private static $bollPeriod = 400;
    private static $bollStdDev = 2;

    // Batch size for processing candles
    private static $batchSize = 1500; // Align with API limit

    // Sleep time between cycles (seconds)
    private static $cycleSleep = 20;

    private static ?\CurlHandle $curlHandle = null;
    private static int $minRequestIntervalUs = 55000;
    private static int $lastRequestAtUs = 0;
    private static string $bollSource = 'close';

    public static function setBollSource(string $src): void
    {
        $src = strtolower($src);
        if (in_array($src, ['close', 'hl2', 'hlc3', 'ohlc4'], true)) {
            self::$bollSource = $src;
        }
    }

    public static function syncKlinesOnce(int $workid = 0, ?string $onlySymbol = null, ?array $onlyIntervals = null): array
    {
        $onlySymbol = $onlySymbol !== null ? strtoupper(trim($onlySymbol)) : null;
        $onlyIntervals = $onlyIntervals ? array_values(array_filter(array_map('strval', $onlyIntervals))) : null;

        $cycleStartTs = time();
        $cycleStartStr = date('Y-m-d H:i:s');
        $totalInserted = 0;
        $lastSymbol = null;
        $processedSymbols = 0;

        $logId = Db::table('AgetKlineLog')->insertGetId([
            'workid' => $workid,
            'total_time' => 0,
            'last_symbol' => null,
            'total_count' => 0,
            'status' => 0,
            'createtime' => $cycleStartStr,
            'updatetime' => $cycleStartStr,
        ]);

        $symbolQuery = Db::table('ApairInfo')
            ->select('id', 'symbol', 'onboardDate')
            ->where('status', 'TRADING')
            ->orderBy('id', 'asc');

        if ($onlySymbol !== null && $onlySymbol !== '') {
            $symbolQuery->where('symbol', $onlySymbol);
        } else {
            $symbolQuery->whereRaw('MOD(id, 2) = ?', [$workid]);
        }

        try {
            foreach ($symbolQuery->cursor() as $symbolData) {
                $processedSymbols++;
                $symbol = $symbolData->symbol;
                $lastSymbol = $symbol;

                $onboardDateStr = $symbolData->onboardDate;
                $onboardDate = strtotime($onboardDateStr);
                if ($onboardDate === false) {
                    continue;
                }
                $onboardDate = (int)($onboardDate * 1000);

                $symbolInserted = 0;
                foreach (self::$intervals as $interval) {
                    if ($onlyIntervals !== null && !in_array($interval, $onlyIntervals, true)) {
                        continue;
                    }
                    $inserted = self::syncSymbolInterval($symbol, $interval, $onboardDate);
                    $totalInserted += $inserted;
                    $symbolInserted += $inserted;
                }

                if ($symbolInserted > 0 || $processedSymbols % 20 === 0) {
                    Db::table('AgetKlineLog')
                        ->where('id', $logId)
                        ->update([
                            'last_symbol' => $lastSymbol,
                            'total_count' => $totalInserted,
                            'updatetime' => date('Y-m-d H:i:s'),
                        ]);
                }
            }
        } catch (\Throwable $e) {
            $totalTime = time() - $cycleStartTs;
            Db::table('AgetKlineLog')
                ->where('id', $logId)
                ->update([
                    'total_time' => $totalTime,
                    'last_symbol' => $lastSymbol,
                    'total_count' => $totalInserted,
                    'status' => 2,
                    'updatetime' => date('Y-m-d H:i:s'),
                ]);
            throw $e;
        }

        $totalTime = time() - $cycleStartTs;
        Db::table('AgetKlineLog')
            ->where('id', $logId)
            ->update([
                'total_time' => $totalTime,
                'last_symbol' => $lastSymbol,
                'total_count' => $totalInserted,
                'status' => 1,
                'updatetime' => date('Y-m-d H:i:s'),
            ]);

        return [
            'log_id' => $logId,
            'total_time' => $totalTime,
            'total_count' => $totalInserted,
            'last_symbol' => $lastSymbol,
        ];
    }

    /**
     * Syncs K-line data for all symbols and intervals with logging.
     * @param int $workid Worker ID (0 for even IDs, 1 for odd IDs)
     * @param string|null $onlySymbol Only sync this symbol when provided
     * @return void
     */
    public static function syncKlines(int $workid = 0, ?string $onlySymbol = null)
    {
        $onlySymbol = $onlySymbol !== null ? strtoupper(trim($onlySymbol)) : null;

        while (true) {
            $cycleStartTs = time();
            $cycleStartStr = date('Y-m-d H:i:s');
            $totalInserted = 0;
            $lastSymbol = null;
            $processedSymbols = 0;

            echo "Starting K-line sync cycle at {$cycleStartStr} (workid: $workid)\n";

            $logId = Db::table('AgetKlineLog')->insertGetId([
                'workid' => $workid,
                'total_time' => 0,
                'last_symbol' => null,
                'total_count' => 0,
                'status' => 0,
                'createtime' => $cycleStartStr,
                'updatetime' => $cycleStartStr,
            ]);
            echo "Created log id=$logId for workid=$workid\n";

            $symbolQuery = Db::table('ApairInfo')
                ->select('id', 'symbol', 'onboardDate')
                ->where('status', 'TRADING')
                ->orderBy('id', 'asc');

            if ($onlySymbol !== null && $onlySymbol !== '') {
                $symbolQuery->where('symbol', $onlySymbol);
            } else {
                $symbolQuery->whereRaw('MOD(id, 2) = ?', [$workid]);
            }

            $totalSymbols = (int)$symbolQuery->count();
            if ($totalSymbols <= 0) {
                echo "No symbols found for workid $workid.\n";
                $totalTime = time() - $cycleStartTs;
                Db::table('AgetKlineLog')
                    ->where('id', $logId)
                    ->update([
                        'total_time' => $totalTime,
                        'status' => 1,
                        'updatetime' => date('Y-m-d H:i:s'),
                    ]);
                echo "Logged cycle: workid=$workid, total_time=$totalTime, total_count=0, status=1\n";
                sleep(self::$cycleSleep);
                continue;
            }

            echo "Found $totalSymbols symbols for workid $workid.\n";

            try {
                foreach ($symbolQuery->cursor() as $symbolData) {
                    $processedSymbols++;
                    $symbol = $symbolData->symbol;
                    $lastSymbol = $symbol;
                    $onboardDateStr = $symbolData->onboardDate;
                    $onboardDate = strtotime($onboardDateStr);
                    if ($onboardDate === false) {
                        echo "Invalid onboardDate for $symbol: $onboardDateStr, skipping symbol\n";
                        if ($processedSymbols % 20 === 0) {
                            Db::table('AgetKlineLog')->where('id', $logId)->update([
                                'last_symbol' => $lastSymbol,
                                'total_count' => $totalInserted,
                                'updatetime' => date('Y-m-d H:i:s'),
                            ]);
                        }
                        continue;
                    }
                    $onboardDate = (int)($onboardDate * 1000);

                    $symbolInserted = 0;
                    foreach (self::$intervals as $interval) {
                        try {
                            echo "Processing $symbol ($interval)\n";
                            if ($interval != '1m') {
                                continue;
                            }
                            $inserted = self::syncSymbolInterval($symbol, $interval, $onboardDate);
                            $totalInserted += $inserted;
                            $symbolInserted += $inserted;
                        } catch (\Throwable $e) {
                            echo "Error processing $symbol ($interval): {$e->getMessage()}\n";
                        }
                    }

                    if ($symbolInserted > 0 || $processedSymbols % 20 === 0) {
                        Db::table('AgetKlineLog')
                            ->where('id', $logId)
                            ->update([
                                'last_symbol' => $lastSymbol,
                                'total_count' => $totalInserted,
                                'updatetime' => date('Y-m-d H:i:s'),
                            ]);
                        echo "Updated log id=$logId: last_symbol=$lastSymbol, total_count=$totalInserted\n";
                    }
                }
            } catch (\Throwable $e) {
                $totalTime = time() - $cycleStartTs;
                Db::table('AgetKlineLog')
                    ->where('id', $logId)
                    ->update([
                        'total_time' => $totalTime,
                        'last_symbol' => $lastSymbol,
                        'total_count' => $totalInserted,
                        'status' => 2,
                        'updatetime' => date('Y-m-d H:i:s'),
                    ]);
                echo "K-line sync cycle failed: {$e->getMessage()}\n";
                sleep(self::$cycleSleep);
                continue;
            }

            $totalTime = time() - $cycleStartTs;
            Db::table('AgetKlineLog')
                ->where('id', $logId)
                ->update([
                    'total_time' => $totalTime,
                    'last_symbol' => $lastSymbol,
                    'total_count' => $totalInserted,
                    'status' => 1,
                    'updatetime' => date('Y-m-d H:i:s'),
                ]);
            echo "Logged cycle: workid=$workid, total_time=$totalTime, last_symbol=$lastSymbol, total_count=$totalInserted, status=1\n";

            echo "K-line sync cycle completed in {$totalTime}s. Sleeping for " . self::$cycleSleep . "s...\n";
            sleep(self::$cycleSleep);
        }
    }

    /**
     * Syncs K-line data for a specific symbol and interval, ensuring only closed candles are synced.
     * @param string $symbol Trading pair (e.g., BTCUSDT)
     * @param string $interval Interval (5m, 15m, 30m, 1h, 4h)
     * @param int $onboardDate Onboard timestamp in ms
     * @return int Number of inserted records
     * @throws \Exception
     */
    private static function syncSymbolInterval(string $symbol, string $interval, int $onboardDate): int
    {
        $table = "kline_$interval";
        $intervalMs = self::intervalToMilliseconds($interval);
        $insertedCount = 0;

        // Get the latest open_time for this symbol and interval
        $latest = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'desc')
            ->first(['open_time']);

        // Ensure startTime is an integer (milliseconds)
        if ($latest) {
            $latestOpenTimeMs = strtotime($latest->open_time);
            $startTime = $latestOpenTimeMs === false
                ? $onboardDate
                : (int)($latestOpenTimeMs * 1000 + $intervalMs);
        } else {
            if ($interval === '10m') {
                $threeDaysAgoMs = (int)((time() - 30 * 86400) * 1000);
                $startTime = max($onboardDate, $threeDaysAgoMs);
                $startTime = $startTime - ($startTime % $intervalMs);
            } else {
                $startTime = $onboardDate;
            }
        }

        // Calculate the most recent closed candle's close_time
        $currentTimeMs = (int)(time() * 1000); // Current time in milliseconds
        // Floor the current time to the nearest completed candle
        $lastClosedCandleCloseTime = $currentTimeMs - ($currentTimeMs % $intervalMs);

        // If the last closed candle's close_time is earlier than startTime, no new data to sync
        if ($startTime >= $lastClosedCandleCloseTime) {
            echo "No new closed candles for $symbol ($interval) at " . date('Y-m-d H:i:s', $startTime / 1000) . "\n";
            return 0;
        }

        // Fetch until the last closed candle
        while ($startTime < $lastClosedCandleCloseTime) {
            $candles = self::fetchKlines($symbol, $interval, $startTime);
            if (empty($candles)) {
                echo "No more data for $symbol ($interval) at " . date('Y-m-d H:i:s', $startTime / 1000) . "\n";
                break;
            }

            // Filter out any candles that are not yet closed
            $filteredCandles = array_filter($candles, function ($candle) use ($lastClosedCandleCloseTime, $symbol, $interval) {
                // 确保 $candle 是数组且包含 close_time
                if (!is_array($candle) || !isset($candle[6])) {
                    echo "Invalid candle data for $symbol ($interval): " . json_encode($candle) . "\n";
                    return false;
                }
                return $candle[6] <= $lastClosedCandleCloseTime; // close_time <= last closed candle's close_time
            });

            if (empty($filteredCandles)) {
                echo "No closed candles to insert for $symbol ($interval) at " . date('Y-m-d H:i:s', $startTime / 1000) . "\n";
                break;
            }

            $insertedCount += self::batchInsertKlines($table, $symbol, $filteredCandles);

            // Update startTime to the next millisecond after the last close_time
            $lastCandle = end($filteredCandles);
            if (!is_array($lastCandle) || !isset($lastCandle[6])) {
                echo "Invalid last candle for $symbol ($interval): " . json_encode($lastCandle) . "\n";
                break;
            }
            $startTime = (int)($lastCandle[6] + 1); // close_time + 1ms
        }
        return $insertedCount;
    }

    /**
     * Fetches K-line data from Binance API.
     * @param string $symbol Trading pair
     * @param string $interval Interval
     * @param int $startTime Start time in ms
     * @return array K-line data
     * @throws \Exception
     */
    private static function fetchKlines(string $symbol, string $interval, int $startTime, ?int $endTime = null): array
    {
        $query = [
            'symbol' => $symbol,
            'interval' => $interval,
            'startTime' => $startTime,
            'limit' => self::$limit
        ];
        if ($endTime !== null && $endTime > 0) {
            $query['endTime'] = $endTime;
        }

        $url = self::$apiUrl . '?' . http_build_query($query);
        $response = self::getData($url);
        $candles = json_decode($response, true);

        // 检查是否为数组
        if (!is_array($candles)) {
            throw new \Exception("无效的 API 响应 for $symbol ($interval): 响应不是数组。原始响应: $response");
        }

        // 检查是否为错误响应（Binance API 可能返回 { "code": -1100, "msg": "Illegal characters found in parameter" }）
        if (isset($candles['code']) && isset($candles['msg'])) {
            throw new \Exception("Binance API 错误 for $symbol ($interval): 代码 {$candles['code']}, 消息: {$candles['msg']}");
        }

        // 检查是否为空数组
        if (empty($candles)) {
            echo "没有数据返回 for $symbol ($interval) at " . date('Y-m-d H:i:s', $startTime / 1000) . "\n";
            return [];
        }

        // 验证第一条数据格式
        if (!is_array($candles[0]) || count($candles[0]) < 12) {
            throw new \Exception("无效的 K 线数据格式 for $symbol ($interval): 第一条 K 线数据不是有效数组。原始响应: $response");
        }

        return $candles;
    }

    private static function buildRowsWithBoll(string $symbol, array $candles, array &$prevSources): array
    {
        $rows = [];
        foreach ($candles as $candle) {
            if (!is_array($candle) || count($candle) < 12) {
                continue;
            }

            $srcVal = (function ($c) {
                $o = (float)$c[1];
                $h = (float)$c[2];
                $l = (float)$c[3];
                $cl = (float)$c[4];
                switch (self::$bollSource) {
                    case 'hl2':
                        return ($h + $l) / 2.0;
                    case 'hlc3':
                        return ($h + $l + $cl) / 3.0;
                    case 'ohlc4':
                        return ($o + $h + $l + $cl) / 4.0;
                    default:
                        return $cl;
                }
            })($candle);

            $bollUp = null;
            $bollMb = null;
            $bollDn = null;
            if (count($prevSources) >= self::$bollPeriod - 1) {
                if (count($prevSources) > self::$bollPeriod - 1) {
                    $prevSources = array_slice($prevSources, - (self::$bollPeriod - 1));
                }
                $window = $prevSources;
                $window[] = $srcVal;
                $sum = 0.0;
                foreach ($window as $v) {
                    $sum += $v;
                }
                $bollMb = $sum / self::$bollPeriod;
                $varSum = 0.0;
                foreach ($window as $v) {
                    $d = $v - $bollMb;
                    $varSum += $d * $d;
                }
                $stdDev = sqrt($varSum / self::$bollPeriod);
                $bollUp = $bollMb + self::$bollStdDev * $stdDev;
                $bollDn = $bollMb - self::$bollStdDev * $stdDev;
            }

            $rows[] = [
                'symbol' => $symbol,
                'open' => $candle[1],
                'high' => $candle[2],
                'low' => $candle[3],
                'close' => $candle[4],
                'volume' => $candle[5],
                'amount' => $candle[7],
                'num_trades' => $candle[8],
                'buy_volume' => $candle[9],
                'buy_amount' => $candle[10],
                'open_time' => date('Y-m-d H:i:s', (int)($candle[0] / 1000)),
                'close_time' => date('Y-m-d H:i:s', (int)($candle[6] / 1000)),
                'boll_up' => $bollUp,
                'boll_mb' => $bollMb,
                'boll_dn' => $bollDn,
            ];

            $prevSources[] = $srcVal;
        }
        if (count($prevSources) > self::$bollPeriod - 1) {
            $prevSources = array_slice($prevSources, - (self::$bollPeriod - 1));
        }
        return $rows;
    }

    private static function throttle(): void
    {
        $nowUs = (int)(microtime(true) * 1000000);
        $nextAllowedUs = self::$lastRequestAtUs + self::$minRequestIntervalUs;
        if ($nowUs < $nextAllowedUs) {
            usleep($nextAllowedUs - $nowUs);
            $nowUs = (int)(microtime(true) * 1000000);
        }
        self::$lastRequestAtUs = $nowUs;
    }

    /**
     * Batch inserts K-line data into the specified table with Bollinger Bands.
     * @param string $table Table name (e.g., kline_5m)
     * @param string $symbol Trading pair
     * @param array $candles K-line data
     * @return int Number of inserted records
     */
    private static function batchInsertKlines(string $table, string $symbol, array $candles): int
    {
        if (empty($candles)) {
            return 0;
        }

        $rows = [];

        $prevRows = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'desc')
            ->take(self::$bollPeriod - 1)
            ->get(['open','high','low','close'])
            ->toArray();
        $prevSources = array_reverse(array_map(function($r){
            $o = (float)$r->open; $h = (float)$r->high; $l = (float)$r->low; $c = (float)$r->close;
            switch (self::$bollSource) {
                case 'hl2': return ($h + $l) / 2.0;
                case 'hlc3': return ($h + $l + $c) / 3.0;
                case 'ohlc4': return ($o + $h + $l + $c) / 4.0;
                default: return $c;
            }
        }, $prevRows));
        if (count($prevSources) > self::$bollPeriod - 1) {
            $prevSources = array_slice($prevSources, - (self::$bollPeriod - 1));
        }

        foreach ($candles as $index => $candle) {
            // 验证 K 线数据格式
            if (!is_array($candle) || count($candle) < 12) {
                echo "Invalid candle data for $symbol at index $index: " . json_encode($candle) . "\n";
                continue;
            }

            $bollUp = null;
            $bollMb = null;
            $bollDn = null;

            $srcVal = (function($c){
                $o = (float)$c[1]; $h = (float)$c[2]; $l = (float)$c[3]; $cl = (float)$c[4];
                switch (self::$bollSource) {
                    case 'hl2': return ($h + $l) / 2.0;
                    case 'hlc3': return ($h + $l + $cl) / 3.0;
                    case 'ohlc4': return ($o + $h + $l + $cl) / 4.0;
                    default: return $cl;
                }
            })($candle);

            if (count($prevSources) === self::$bollPeriod - 1) {
                $window = $prevSources;
                $window[] = $srcVal;
                $sum = 0.0;
                foreach ($window as $v) { $sum += $v; }
                $bollMb = $sum / self::$bollPeriod;
                $varSum = 0.0;
                foreach ($window as $v) {
                    $d = $v - $bollMb;
                    $varSum += $d * $d;
                }
                $stdDev = sqrt($varSum / self::$bollPeriod);
                $bollUp = $bollMb + self::$bollStdDev * $stdDev;
                $bollDn = $bollMb - self::$bollStdDev * $stdDev;
            }

            $rows[] = [
                'symbol' => $symbol,
                'open' => $candle[1],
                'high' => $candle[2],
                'low' => $candle[3],
                'close' => $candle[4],
                'volume' => $candle[5],
                'amount' => $candle[7],
                'num_trades' => $candle[8],
                'buy_volume' => $candle[9],
                'buy_amount' => $candle[10],
                'open_time' => date('Y-m-d H:i:s', (int)($candle[0] / 1000)),
                'close_time' => date('Y-m-d H:i:s', (int)($candle[6] / 1000)),
                'boll_up' => $bollUp,
                'boll_mb' => $bollMb,
                'boll_dn' => $bollDn,
            ];

            $prevSources[] = $srcVal;
            if (count($prevSources) > self::$bollPeriod - 1) {
                $prevSources = array_slice($prevSources, - (self::$bollPeriod - 1));
            }
        }

        if (empty($rows)) {
            echo "No valid candles to insert for $symbol\n";
            return 0;
        }

        $inserted = Db::table($table)->insertOrIgnore($rows);
        echo "Inserted " . $inserted . " $table records for $symbol with Bollinger Bands\n";

        return $inserted;
    }

    public static function insertClosedKlineFromStream(string $symbol, string $interval, array $kline): int
    {
        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '') {
            return 0;
        }
        if (!isset($kline['x']) || $kline['x'] !== true) {
            return 0;
        }

        $table = "kline_$interval";

        $candle = [
            (int)($kline['t'] ?? 0),
            (string)($kline['o'] ?? '0'),
            (string)($kline['h'] ?? '0'),
            (string)($kline['l'] ?? '0'),
            (string)($kline['c'] ?? '0'),
            (string)($kline['v'] ?? '0'),
            (int)($kline['T'] ?? 0),
            (string)($kline['q'] ?? '0'),
            (int)($kline['n'] ?? 0),
            (string)($kline['V'] ?? '0'),
            (string)($kline['Q'] ?? '0'),
            '0',
        ];

        return self::batchInsertKlines($table, $symbol, [$candle]);
    }

    public static function backfill(string $symbol, string $interval, int $startTimeMs, int $endTimeMs): array
    {
        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || !in_array($interval, self::$intervals, true)) {
            throw new \InvalidArgumentException('invalid symbol or interval');
        }
        if ($startTimeMs <= 0 || $endTimeMs <= 0 || $endTimeMs <= $startTimeMs) {
            throw new \InvalidArgumentException('invalid time window');
        }

        $table = "kline_$interval";
        $intervalMs = self::intervalToMilliseconds($interval);
        $startTimeMs = $startTimeMs - ($startTimeMs % $intervalMs);

        $currentTimeMs = (int)(time() * 1000);
        $lastClosedCandleCloseTime = $currentTimeMs - ($currentTimeMs % $intervalMs);
        $endTimeMs = min($endTimeMs, $lastClosedCandleCloseTime);

        $insertedCount = 0;
        $prevSources = [];
        while ($startTimeMs < $endTimeMs) {
            $candles = self::fetchKlines($symbol, $interval, $startTimeMs, $endTimeMs);
            if (empty($candles)) {
                break;
            }

            $filtered = array_values(array_filter($candles, function ($c) use ($endTimeMs, $lastClosedCandleCloseTime) {
                if (!is_array($c) || !isset($c[0], $c[6])) {
                    return false;
                }
                return $c[0] < $endTimeMs && $c[6] <= $lastClosedCandleCloseTime;
            }));
            if (empty($filtered)) {
                break;
            }

            $rows = self::buildRowsWithBoll($symbol, $filtered, $prevSources);
            if (!empty($rows)) {
                $updateColumns = ['open', 'high', 'low', 'close', 'volume', 'amount', 'num_trades', 'buy_volume', 'buy_amount', 'close_time', 'boll_up', 'boll_mb', 'boll_dn'];
                Db::table($table)->upsert($rows, ['symbol', 'open_time'], $updateColumns);
                $insertedCount += count($rows);
            }

            $last = end($filtered);
            if (!is_array($last) || !isset($last[6])) {
                break;
            }
            $startTimeMs = (int)($last[6] + 1);
        }

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'inserted' => $insertedCount,
            'start_ms' => $startTimeMs,
            'end_ms' => $endTimeMs,
        ];
    }

    /**
     * Converts interval to milliseconds.
     * @param string $interval Interval (1m, 5m, 15m, 30m, 1h, 4h)
     * @return int Milliseconds
     */
    private static function intervalToMilliseconds(string $interval): int
    {
        switch ($interval) {
            case '1m': return 1 * 60 * 1000;
            case '5m': return 5 * 60 * 1000;
            case '15m': return 15 * 60 * 1000;
            case '30m': return 30 * 60 * 1000;
            case '1h': return 60 * 60 * 1000;
            case '4h': return 4 * 60 * 60 * 1000;
            default: throw new \InvalidArgumentException("Invalid interval: $interval");
        }
    }

    /**
     * Fetches data from the given URL using cURL.
     * @param string $url API endpoint
     * @return string Raw API response
     * @throws \Exception If cURL request fails
     */
    private static function getData(string $url): string
    {
        self::throttle();

        if (!self::$curlHandle) {
            self::$curlHandle = curl_init();
            curl_setopt(self::$curlHandle, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt(self::$curlHandle, CURLOPT_HEADER, 0);
            curl_setopt(self::$curlHandle, CURLOPT_TIMEOUT, 60);
            curl_setopt(self::$curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt(self::$curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        }

        curl_setopt(self::$curlHandle, CURLOPT_URL, $url);
        $output = curl_exec(self::$curlHandle);

        if ($output === false) {
            $error = curl_error(self::$curlHandle);
            throw new \Exception("cURL error: $error");
        }

        return $output;
    }
}
