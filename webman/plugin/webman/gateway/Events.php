<?php

namespace plugin\webman\gateway;

use GatewayWorker\Lib\Gateway;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use support\Db;

class Events
{
    private static ?bool $pgsqlDriverAvailable = null;
    private static ?bool $timescaleAvailable = null;
    private static ?AsyncTcpConnection $binanceMarketConn = null;
    private static int $binanceMarketReconnectAttempts = 0;
    private static int $binanceMarketHealthTimerId = 0;
    private static int $binanceMarketReconnectTimerId = 0;
    private static int $binanceMarketStartedAt = 0;
    private static int $binanceMarketLastMessageAt = 0;
    private static array $binanceMarketSymbols = [];
    private static array $binanceMarketIntervals = [];
    private static bool $checkKlinesRunning = false;
    private static ?AsyncTcpConnection $binanceTradeConn = null;
    private static int $binanceTradeReconnectAttempts = 0;
    private static int $binanceTradeHealthTimerId = 0;
    private static int $binanceTradeReconnectTimerId = 0;
    private static int $binanceTradeStartedAt = 0;
    private static int $binanceTradeLastMessageAt = 0;
    private static array $binanceTradeSymbols = [];

    static function postData($url, $postData = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
        ]);
        $output = curl_exec($ch);
        $ch = null;
        return $output;
    }

    static function getData($url)
    {
      //  echo $url."\n";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        $output = curl_exec($ch);
        $ch = null;
        return $output;
    }

    public static function getPairInfo()
    {
        PairInfoSync::syncPairInfo();
    }

    public static function getKlines()
    {
        self::klinePipeline('BTCUSDT');
    }
    
    public static function checkKlines()
    {
        if (self::$checkKlinesRunning) {
            return;
        }
        self::$checkKlinesRunning = true;
        try {
            KlineKeyPointProcessor::processKeyPoints(-1, 'BTCUSDT');
            $symbols = !empty(self::$binanceMarketSymbols) ? self::$binanceMarketSymbols : ['BTCUSDT'];
            $intervals = !empty(self::$binanceMarketIntervals) ? self::$binanceMarketIntervals : ['1m', '5m', '15m', '30m', '1h', '4h'];
            foreach ($symbols as $symbol) {
                foreach ($intervals as $interval) {
                    try {
                     //   OscillationEngine::run($symbol, $interval);
                      //  NewOscillationEngine::run($symbol, $interval);
                    //    AbcEngine::run($symbol, $interval);
                        // if($interval === '1m')
                        // {
                        //     continue;
                        // }
                        NewOscillationEngineV3::run($symbol, $interval);
                    } catch (\Throwable $e) {
                    }
                }
            }
        } finally {
            self::$checkKlinesRunning = false;
        }
    }

    public static function startBinanceMarketKlineStreams(array $symbols = ['BTCUSDT'], array $intervals = ['1m']): void
    {
        $symbols = array_values(array_filter(array_map(function ($s) {
            $s = strtoupper(trim((string)$s));
            return $s === '' ? null : $s;
        }, $symbols)));

        $intervals = array_values(array_filter(array_map(function ($i) {
            $i = trim((string)$i);
            return $i === '' ? null : $i;
        }, $intervals)));

        if (empty($symbols) || empty($intervals)) {
            return;
        }

        self::$binanceMarketSymbols = $symbols;
        self::$binanceMarketIntervals = $intervals;

        if (!self::ensurePgsqlDriver()) {
            return;
        }

        self::initTimescaleSchema();

        echo "Starting Binance market streams. symbols=" . json_encode(self::$binanceMarketSymbols) . " intervals=" . json_encode(self::$binanceMarketIntervals) . "\n";

        self::connectBinanceMarketStreams();
        self::startBinanceTradeStreams($symbols);

        if (self::$binanceMarketHealthTimerId === 0) {
            self::$binanceMarketHealthTimerId = Timer::add(60, function () {
                $now = time();
                if (self::$binanceMarketConn === null) {
                    self::scheduleBinanceMarketReconnect();
                    return;
                }
                if (self::$binanceMarketLastMessageAt > 0 && ($now - self::$binanceMarketLastMessageAt) > 240) {
                    self::scheduleBinanceMarketReconnect(true);
                    return;
                }
                if (self::$binanceMarketStartedAt > 0 && ($now - self::$binanceMarketStartedAt) > 23 * 3600) {
                    self::scheduleBinanceMarketReconnect(true);
                    return;
                }
            }, null, true);
        }
    }

    public static function startBinanceTradeStreams(array $symbols = ['BTCUSDT']): void
    {
        $symbols = array_values(array_filter(array_map(function ($s) {
            $s = strtoupper(trim((string)$s));
            return $s === '' ? null : $s;
        }, $symbols)));
        if (empty($symbols)) {
            return;
        }
        self::$binanceTradeSymbols = $symbols;
        self::connectBinanceTradeStreams();
        if (self::$binanceTradeHealthTimerId === 0) {
            self::$binanceTradeHealthTimerId = Timer::add(60, function () {
                $now = time();
                if (self::$binanceTradeConn === null) {
                    self::scheduleBinanceTradeReconnect();
                    return;
                }
                if (self::$binanceTradeLastMessageAt > 0 && ($now - self::$binanceTradeLastMessageAt) > 240) {
                    self::scheduleBinanceTradeReconnect(true);
                    return;
                }
                if (self::$binanceTradeStartedAt > 0 && ($now - self::$binanceTradeStartedAt) > 23 * 3600) {
                    self::scheduleBinanceTradeReconnect(true);
                    return;
                }
            }, null, true);
        }
    }

    private static function connectBinanceTradeStreams(): void
    {
        if (empty(self::$binanceTradeSymbols)) {
            return;
        }
        if (self::$binanceTradeConn !== null) {
            try {
                self::$binanceTradeConn->close();
            } catch (\Throwable $e) {
            }
            self::$binanceTradeConn = null;
        }

        $streams = [];
        foreach (self::$binanceTradeSymbols as $symbol) {
            $sym = strtolower($symbol);
            $streams[] = $sym . '@aggTrade';
        }
        $streamPath = implode('/', $streams);
        $remoteAddress = 'ws://fstream.binance.com:443/stream?streams=' . $streamPath;

        $conn = new AsyncTcpConnection($remoteAddress);
        $conn->transport = 'ssl';

        $conn->onWebSocketConnect = function () {
            self::$binanceTradeReconnectAttempts = 0;
            self::$binanceTradeStartedAt = time();
            self::$binanceTradeLastMessageAt = time();
            echo "Binance trade stream connected.\n";
        };

        $conn->onMessage = function ($connection, $message) {
            self::$binanceTradeLastMessageAt = time();
            $json = json_decode($message, true);
            if (!is_array($json)) {
                return;
            }
            $data = $json['data'] ?? $json;
            if (!is_array($data)) {
                return;
            }
            $event = $data['e'] ?? null;
            if ($event !== 'aggTrade' && $event !== 'trade') {
                return;
            }
            $symbol = $data['s'] ?? null;
            if (!is_string($symbol) || $symbol === '') {
                return;
            }
            $symbol = strtoupper($symbol);
            $price = isset($data['p']) ? (float)$data['p'] : (isset($data['price']) ? (float)$data['price'] : null);
            $ts = isset($data['T']) ? (int)$data['T'] : (isset($data['E']) ? (int)$data['E'] : null);
            if ($price === null || $ts === null) {
                return;
            }
            Gateway::sendToGroup(self::tickGroupName($symbol), json_encode([
                'type' => 'tick',
                'symbol' => $symbol,
                'time_ms' => $ts,
                'price' => $price,
            ]));
        };

        $conn->onClose = function () {
            self::$binanceTradeConn = null;
            echo "Binance trade stream closed.\n";
            self::scheduleBinanceTradeReconnect();
        };

        $conn->onError = function ($connection, $code = null, $msg = null) {
            self::$binanceTradeConn = null;
            $codeStr = $code !== null ? (string)$code : '';
            $msgStr = $msg !== null ? (string)$msg : '';
            echo "Binance trade stream error: {$codeStr} {$msgStr}\n";
            self::scheduleBinanceTradeReconnect();
        };

        self::$binanceTradeConn = $conn;
        $conn->connect();
    }

    private static function scheduleBinanceTradeReconnect(bool $forceClose = false): void
    {
        if ($forceClose && self::$binanceTradeConn !== null) {
            try {
                self::$binanceTradeConn->close();
            } catch (\Throwable $e) {
            }
            self::$binanceTradeConn = null;
        }
        if (self::$binanceTradeReconnectTimerId !== 0) {
            Timer::del(self::$binanceTradeReconnectTimerId);
            self::$binanceTradeReconnectTimerId = 0;
        }
        self::$binanceTradeReconnectAttempts++;
        $delay = min(60, 2 ** min(6, self::$binanceTradeReconnectAttempts));
        $delay = max(2, (int)$delay);
        self::$binanceTradeReconnectTimerId = Timer::add($delay, function () {
            self::$binanceTradeReconnectTimerId = 0;
            self::connectBinanceTradeStreams();
        }, null, false);
    }

    private static function connectBinanceMarketStreams(): void
    {
        if (empty(self::$binanceMarketSymbols) || empty(self::$binanceMarketIntervals)) {
            return;
        }

        if (self::$binanceMarketConn !== null) {
            try {
                self::$binanceMarketConn->close();
            } catch (\Throwable $e) {
            }
            self::$binanceMarketConn = null;
        }

        $streams = [];
        foreach (self::$binanceMarketSymbols as $symbol) {
            $sym = strtolower($symbol);
            foreach (self::$binanceMarketIntervals as $interval) {
                $streams[] = $sym . '@kline_' . $interval;
            }
        }
        $streamPath = implode('/', $streams);
        $remoteAddress = 'ws://fstream.binance.com:443/stream?streams=' . $streamPath;
        echo "Connecting Binance market stream: {$remoteAddress}\n";

        $conn = new AsyncTcpConnection($remoteAddress);
        $conn->transport = 'ssl';

        $conn->onWebSocketConnect = function () {
            self::$binanceMarketReconnectAttempts = 0;
            self::$binanceMarketStartedAt = time();
            self::$binanceMarketLastMessageAt = time();
            echo "Binance market stream connected.\n";
            foreach (self::$binanceMarketSymbols as $symbol) {
                try {
                    KlineSync::syncKlinesOnce(0, $symbol, self::$binanceMarketIntervals);
                } catch (\Throwable $e) {
                    echo "KlineSync::syncKlinesOnce failed: {$e->getMessage()}\n";
                }
            }
        };

        $conn->onMessage = function ($connection, $message) {
            self::$binanceMarketLastMessageAt = time();
            $json = json_decode($message, true);
            if (!is_array($json)) {
                return;
            }

            $data = $json['data'] ?? $json;
            if (!is_array($data)) {
                return;
            }

            if (($data['e'] ?? null) !== 'kline' || !isset($data['k']) || !is_array($data['k'])) {
                return;
            }

            $k = $data['k'];
            $symbol = $data['s'] ?? $k['s'] ?? null;
            $interval = $k['i'] ?? self::extractIntervalFromStream($json['stream'] ?? '');
            if (!is_string($symbol) || $symbol === '' || !is_string($interval) || $interval === '') {
                return;
            }
            $symbol = strtoupper($symbol);

            self::broadcastStreamKline($symbol, $interval, $k);

            if (($k['x'] ?? null) === true) {
                try {
                    KlineSync::insertClosedKlineFromStream($symbol, $interval, $k);
                    if (isset($k['t'])) {
                        $openTime = date('Y-m-d H:i:s', (int)(((int)$k['t']) / 1000));
                        self::broadcastFinalKlineFromDb($symbol, $interval, $openTime);
                    }
                } catch (\Throwable $e) {
                    echo "KlineSync::insertClosedKlineFromStream failed: {$e->getMessage()}\n";
                }
            }
        };

        $conn->onClose = function () {
            self::$binanceMarketConn = null;
            echo "Binance market stream closed.\n";
            self::scheduleBinanceMarketReconnect();
        };

        $conn->onError = function ($connection, $code = null, $msg = null) {
            self::$binanceMarketConn = null;
            $codeStr = $code !== null ? (string)$code : '';
            $msgStr = $msg !== null ? (string)$msg : '';
            echo "Binance market stream error: {$codeStr} {$msgStr}\n";
            self::scheduleBinanceMarketReconnect();
        };

        self::$binanceMarketConn = $conn;
        $conn->connect();
    }

    private static function scheduleBinanceMarketReconnect(bool $forceClose = false): void
    {
        if ($forceClose && self::$binanceMarketConn !== null) {
            try {
                self::$binanceMarketConn->close();
            } catch (\Throwable $e) {
            }
            self::$binanceMarketConn = null;
        }

        if (self::$binanceMarketReconnectTimerId !== 0) {
            Timer::del(self::$binanceMarketReconnectTimerId);
            self::$binanceMarketReconnectTimerId = 0;
        }

        self::$binanceMarketReconnectAttempts++;
        $delay = min(60, 2 ** min(6, self::$binanceMarketReconnectAttempts));
        $delay = max(2, (int)$delay);

        self::$binanceMarketReconnectTimerId = Timer::add($delay, function () {
            self::$binanceMarketReconnectTimerId = 0;
            self::connectBinanceMarketStreams();
        }, null, false);
    }

    private static function extractIntervalFromStream(string $stream): string
    {
        $stream = (string)$stream;
        $pos = strrpos($stream, '@kline_');
        if ($pos === false) {
            return '';
        }
        return substr($stream, $pos + 7);
    }

    private static function klineGroupName(string $symbol, string $interval): string
    {
        return 'kline:' . strtoupper($symbol) . ':' . $interval;
    }

    private static function tickGroupName(string $symbol): string
    {
        return 'tick:' . strtoupper($symbol);
    }

    private static function loadKlineSnapshotRows(string $symbol, string $interval, int $limit): array
    {
        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        $allowedIntervals = ['1m', '5m', '15m', '30m', '1h', '4h'];
        if ($symbol === '' || $interval === '') {
            return [];
        }
        if (!in_array($interval, $allowedIntervals, true)) {
            return [];
        }
        if ($limit <= 0) {
            $limit = 1500;
        }
        if ($limit > 1500) {
            $limit = 1500;
        }

        $table = "kline_$interval";
        $rows = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'desc')
            ->take($limit)
            ->get(['open_time', 'open', 'high', 'low', 'close', 'boll_up', 'boll_dn'])
            ->toArray();

        $rows = array_reverse($rows);

        $out = [];
        foreach ($rows as $r) {
            $openTime = (string)$r->open_time;
            $ms = strtotime($openTime);
            if ($ms === false) {
                continue;
            }
            $out[] = [
                'open_time' => $openTime,
                'open_time_ms' => (int)($ms * 1000),
                'open' => (float)$r->open,
                'high' => (float)$r->high,
                'low' => (float)$r->low,
                'close' => (float)$r->close,
                'boll_up' => $r->boll_up !== null ? (float)$r->boll_up : null,
                'boll_dn' => $r->boll_dn !== null ? (float)$r->boll_dn : null,
            ];
        }
        return $out;
    }

    private static function sendKlineSnapshot(int|string $clientId, string $symbol, string $interval, int $limit = 1500): void
    {
        try {
            $data = self::loadKlineSnapshotRows($symbol, $interval, $limit);
            Gateway::sendToClient((string)$clientId, json_encode([
                'type' => 'kline_snapshot',
                'symbol' => strtoupper($symbol),
                'interval' => $interval,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
        }
    }

    private static function broadcastFinalKlineFromDb(string $symbol, string $interval, string $openTime): void
    {
        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '' || $openTime === '') {
            return;
        }

        try {
            $table = "kline_$interval";
            $row = Db::table($table)
                ->where('symbol', $symbol)
                ->where('open_time', $openTime)
                ->orderBy('open_time', 'desc')
                ->first([
                    'symbol',
                    'open_time',
                    'close_time',
                    'open',
                    'high',
                    'low',
                    'close',
                    'volume',
                    'amount',
                    'num_trades',
                    'buy_volume',
                    'buy_amount',
                    'boll_up',
                    'boll_mb',
                    'boll_dn',
                ]);
            if (!$row) {
                return;
            }

            $ot = (string)$row->open_time;
            $ct = (string)$row->close_time;
            $otMs = strtotime($ot);
            $ctMs = strtotime($ct);
            if ($otMs === false) {
                return;
            }
            $otMs = (int)($otMs * 1000);
            $ctMs = $ctMs === false ? $otMs : (int)($ctMs * 1000);

            Gateway::sendToGroup(self::klineGroupName($symbol, $interval), json_encode([
                'type' => 'kline_stream',
                'symbol' => $symbol,
                'interval' => $interval,
                'data' => [
                    'symbol' => $symbol,
                    'is_final' => true,
                    'open_time_ms' => $otMs,
                    'close_time_ms' => $ctMs,
                    'open_time' => $ot,
                    'close_time' => $ct,
                    'open' => (string)$row->open,
                    'high' => (string)$row->high,
                    'low' => (string)$row->low,
                    'close' => (string)$row->close,
                    'volume' => (string)$row->volume,
                    'amount' => (string)$row->amount,
                    'num_trades' => (int)$row->num_trades,
                    'buy_volume' => (string)$row->buy_volume,
                    'buy_amount' => (string)$row->buy_amount,
                    'boll_up' => $row->boll_up !== null ? (float)$row->boll_up : null,
                    'boll_mb' => $row->boll_mb !== null ? (float)$row->boll_mb : null,
                    'boll_dn' => $row->boll_dn !== null ? (float)$row->boll_dn : null,
                ],
            ]));
        } catch (\Throwable $e) {
        }
    }

    private static function broadcastStreamKline(string $symbol, string $interval, array $k): void
    {
        $openTime = isset($k['t']) ? date('Y-m-d H:i:s', (int)($k['t'] / 1000)) : null;
        $closeTime = isset($k['T']) ? date('Y-m-d H:i:s', (int)($k['T'] / 1000)) : null;

        Gateway::sendToGroup(self::klineGroupName($symbol, $interval), json_encode([
            'type' => 'kline_stream',
            'symbol' => $symbol,
            'interval' => $interval,
            'data' => [
                'symbol' => $symbol,
                'is_final' => (bool)($k['x'] ?? false),
                'open_time_ms' => isset($k['t']) ? (int)$k['t'] : null,
                'close_time_ms' => isset($k['T']) ? (int)$k['T'] : null,
                'open_time' => $openTime,
                'close_time' => $closeTime,
                'open' => $k['o'] ?? null,
                'high' => $k['h'] ?? null,
                'low' => $k['l'] ?? null,
                'close' => $k['c'] ?? null,
                'volume' => $k['v'] ?? null,
                'amount' => $k['q'] ?? null,
                'num_trades' => $k['n'] ?? null,
                'buy_volume' => $k['V'] ?? null,
                'buy_amount' => $k['Q'] ?? null,
            ],
        ]));
    }

    public static function klinePipeline(?string $onlySymbol = null, ?array $onlyIntervals = null): void
    {
        $onlySymbol = $onlySymbol !== null ? strtoupper(trim($onlySymbol)) : null;
        $onlyIntervals = $onlyIntervals ? array_values(array_filter(array_map('strval', $onlyIntervals))) : null;

        $syncResult = KlineSync::syncKlinesOnce(0, $onlySymbol, $onlyIntervals);

        if ($onlySymbol) {
            self::pushLatestKlinesToClients($onlySymbol, $onlyIntervals);
        }

        KlineKeyPointProcessor::processKeyPointsOnce(0, $onlySymbol, $onlyIntervals);
    }

    private static function pushLatestKlinesToClients(string $symbol, ?array $onlyIntervals = null): void
    {
        $intervals = $onlyIntervals ?: ['1m', '5m', '15m', '30m', '1h', '4h'];

        foreach ($intervals as $interval) {
            $table = "kline_$interval";
            try {
                $row = Db::table($table)
                    ->where('symbol', $symbol)
                    ->orderBy('open_time', 'desc')
                    ->first([
                        'symbol',
                        'open_time',
                        'close_time',
                        'open',
                        'high',
                        'low',
                        'close',
                        'volume',
                        'amount',
                        'num_trades',
                        'buy_volume',
                        'buy_amount',
                        'boll_up',
                        'boll_mb',
                        'boll_dn',
                    ]);
                if (!$row) {
                    continue;
                }

                Gateway::sendToGroup(self::klineGroupName($symbol, $interval), json_encode([
                    'type' => 'kline_sync',
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'data' => $row,
                ]));
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    public static function initTimescaleSchema(): void
    {
        if (!self::ensurePgsqlDriver()) {
            return;
        }

        $statements = [
            'CREATE EXTENSION IF NOT EXISTS timescaledb',
            'CREATE TABLE IF NOT EXISTS "ApairInfo" (
                id bigserial PRIMARY KEY,
                symbol text NOT NULL UNIQUE,
                "baseAsset" text,
                "quoteAsset" text,
                "pricePrecision" int,
                "quantityPrecision" int,
                "baseAssetPrecision" int,
                "quotePrecision" int,
                "onboardDate" timestamp,
                "updateDate" timestamp,
                workid int,
                status text,
                "isCreate" boolean NOT NULL DEFAULT false,
                "isOpen" boolean NOT NULL DEFAULT false
            )',
            'CREATE INDEX IF NOT EXISTS "ApairInfo_status_idx" ON "ApairInfo"(status)',
            'CREATE INDEX IF NOT EXISTS "ApairInfo_updateDate_idx" ON "ApairInfo"("updateDate")',
            'CREATE TABLE IF NOT EXISTS "AgetKlineLog" (
                id bigserial PRIMARY KEY,
                workid int NOT NULL,
                total_time int NOT NULL,
                last_symbol text,
                total_count bigint NOT NULL,
                status smallint NOT NULL,
                createtime timestamp NOT NULL,
                updatetime timestamp NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS "AgetKlineLog_createtime_idx" ON "AgetKlineLog"(createtime DESC)',
            'CREATE INDEX IF NOT EXISTS "AgetKlineLog_status_idx" ON "AgetKlineLog"(status)',
            'CREATE TABLE IF NOT EXISTS oscillation_structures (
                id bigserial PRIMARY KEY,
                symbol text NOT NULL,
                interval text NOT NULL,
                status text NOT NULL DEFAULT \'ACTIVE\',
                x_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                y_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                close_reason text,
                close_condition jsonb,
                engine_state jsonb,
                start_time timestamp,
                end_time timestamp,
                created_at timestamp NOT NULL DEFAULT now(),
                updated_at timestamp NOT NULL DEFAULT now()
            )',
            'CREATE INDEX IF NOT EXISTS oscillation_structures_symbol_interval_status_idx ON oscillation_structures(symbol, interval, status)',
            'CREATE INDEX IF NOT EXISTS oscillation_structures_symbol_interval_id_idx ON oscillation_structures(symbol, interval, id DESC)',
        ];

        foreach ($statements as $sql) {
            self::execInitSql($sql);
        }

        $timescaleAvailable = self::ensureTimescaleExtension();

        $intervalConfigs = [
            '1m' => ['chunk' => "INTERVAL '1 day'", 'compress_after' => "INTERVAL '3 days'"],
            '5m' => ['chunk' => "INTERVAL '7 days'", 'compress_after' => "INTERVAL '7 days'"],
            '15m' => ['chunk' => "INTERVAL '14 days'", 'compress_after' => "INTERVAL '14 days'"],
            '30m' => ['chunk' => "INTERVAL '14 days'", 'compress_after' => "INTERVAL '14 days'"],
            '1h' => ['chunk' => "INTERVAL '30 days'", 'compress_after' => "INTERVAL '30 days'"],
            '4h' => ['chunk' => "INTERVAL '90 days'", 'compress_after' => "INTERVAL '90 days'"],
        ];

        foreach ($intervalConfigs as $interval => $cfg) {
            $table = "kline_$interval";

            self::execInitSql("CREATE TABLE IF NOT EXISTS $table (
                id bigserial NOT NULL,
                symbol text NOT NULL,
                open numeric(20,10) NOT NULL,
                high numeric(20,10) NOT NULL,
                low numeric(20,10) NOT NULL,
                close numeric(20,10) NOT NULL,
                volume numeric(28,12) NOT NULL,
                amount numeric(28,12) NOT NULL,
                num_trades bigint NOT NULL,
                buy_volume numeric(28,12) NOT NULL,
                buy_amount numeric(28,12) NOT NULL,
                open_time timestamp NOT NULL,
                close_time timestamp NOT NULL,
                boll_up numeric(20,10),
                boll_mb numeric(20,10),
                boll_dn numeric(20,10),
                bw numeric(20,10),
                mouth_state smallint NOT NULL DEFAULT 0,
                is_check smallint NOT NULL DEFAULT 0,
                is_key smallint NOT NULL DEFAULT 0,
                PRIMARY KEY (id, open_time, symbol),
                UNIQUE(symbol, open_time)
            )");
            self::execInitSql("ALTER TABLE $table ADD COLUMN IF NOT EXISTS mouth_state smallint NOT NULL DEFAULT 0");
            self::execInitSql("ALTER TABLE $table ADD COLUMN IF NOT EXISTS bw numeric(20,10)");

            self::execInitSql("CREATE INDEX IF NOT EXISTS {$table}_symbol_time_idx ON $table(symbol, open_time ASC)");
            self::execInitSql("CREATE INDEX IF NOT EXISTS {$table}_symbol_check_time_idx ON $table(symbol, is_check, open_time ASC)");

            if ($timescaleAvailable) {
                if (!self::isHypertable($table)) {
                    self::execInitSql("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_pkey");
                    self::execInitSql("ALTER TABLE $table ADD CONSTRAINT {$table}_pkey PRIMARY KEY (id, open_time, symbol)");
                    self::execInitSql("SELECT create_hypertable('$table', 'open_time', 'symbol', 4, chunk_time_interval => {$cfg['chunk']}, if_not_exists => TRUE)");
                }

                if (self::isHypertable($table)) {
                    self::execInitSql("ALTER TABLE $table SET (timescaledb.compress, timescaledb.compress_orderby = 'open_time DESC', timescaledb.compress_segmentby = 'symbol')");
                    self::execInitSql("SELECT add_compression_policy('$table', {$cfg['compress_after']}, if_not_exists => TRUE)");
                }
            }
        }
    }

    private static function ensureTimescaleExtension(): bool
    {
        if (self::$timescaleAvailable !== null) {
            return self::$timescaleAvailable;
        }

        try {
            $rows = Db::select("SELECT 1 FROM pg_extension WHERE extname = 'timescaledb' LIMIT 1");
            self::$timescaleAvailable = !empty($rows);
        } catch (\Throwable $e) {
            self::$timescaleAvailable = false;
        }

        if (!self::$timescaleAvailable) {
            echo "TimescaleDB extension not available. Tables will be created as regular PostgreSQL tables.\n";
        }

        return self::$timescaleAvailable;
    }

    private static function isHypertable(string $table): bool
    {
        if (!self::ensureTimescaleExtension()) {
            return false;
        }

        try {
            $rows = Db::select('SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_name = ? LIMIT 1', [$table]);
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function ensurePgsqlDriver(): bool
    {
        if (self::$pgsqlDriverAvailable !== null) {
            return self::$pgsqlDriverAvailable;
        }

        $availableDrivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        self::$pgsqlDriverAvailable = in_array('pgsql', $availableDrivers, true);

        if (!self::$pgsqlDriverAvailable) {
            echo "PostgreSQL PDO driver not found. Enable/install pdo_pgsql for your PHP runtime.\n";
        }

        return self::$pgsqlDriverAvailable;
    }

    private static function execInitSql(string $sql): void
    {
        try {
            Db::statement($sql);
        } catch (\Throwable $e) {
            echo "Init SQL failed: {$e->getMessage()}\n";
        }
    }

    public static function onWorkerStart($worker)
    {
        // if($worker->id == 0){
        //     Timer::add(1, array('\plugin\webman\gateway\Events', 'initTimescaleSchema'), null, false);
        // }

        // if($worker->id == 0){
        //     Timer::add(1, array('\plugin\webman\gateway\Events', 'getKlines'), null, true);
        // }

        // if($worker->id == 0){
        //     Timer::add(1, array('\plugin\webman\gateway\Events', 'getKlines'), null, false);
        // }

        // if($worker->id == 0){
        //     Timer::add(1, array('\plugin\webman\gateway\Events', 'getPairInfo'), null, false);
        // }

        if ($worker->id == 0) {
            Timer::add(1, array('\plugin\webman\gateway\Events', 'startBinanceMarketKlineStreams'), [['BTCUSDT'], ['1m','5m', '15m', '30m', '1h', '4h']], false);
            Timer::add(1, array('\plugin\webman\gateway\Events', 'checkKlines'), null, false);
        }
 
    }

    public static function onConnect($client_id)
    {
        // 向当前client_id发送数据 
        $data = array(
            'type'=>1,
            'message'=>'connected'
        );
        Gateway::sendToClient($client_id, json_encode($data));
    }

    public static function onWebSocketConnect($client_id, $data)
    {
        // print_r($data);
    }

    public static function onMessage($client_id, $message)
    {
        $jsonData = json_decode($message,true);
        if(isset($jsonData['type'])){
            echo $jsonData['type']."\n";
            if($jsonData['type'] == 'ping'){
                $data = array(
                    'type'=>'pong',
                );
                Gateway::sendToClient($client_id, json_encode($data));
            } elseif ($jsonData['type'] === 'subscribe_kline' || $jsonData['type'] === 'subscribe') {
                $symbols = $jsonData['symbols'] ?? null;
                if (!is_array($symbols)) {
                    $symbols = [($jsonData['symbol'] ?? null)];
                }
                $intervals = $jsonData['intervals'] ?? null;
                if (!is_array($intervals)) {
                    $intervals = [($jsonData['interval'] ?? null)];
                }

                $symbols = array_values(array_filter(array_map(function ($s) {
                    $s = strtoupper(trim((string)$s));
                    return $s === '' ? null : $s;
                }, $symbols)));

                $intervals = array_values(array_filter(array_map(function ($i) {
                    $i = trim((string)$i);
                    return $i === '' ? null : $i;
                }, $intervals)));

                $groups = [];
                foreach ($symbols as $symbol) {
                    foreach ($intervals as $interval) {
                        $group = self::klineGroupName($symbol, $interval);
                        Gateway::joinGroup($client_id, $group);
                        $groups[] = $group;
                    }
                }

                Gateway::sendToClient($client_id, json_encode([
                    'type' => 'subscribed',
                    'groups' => $groups,
                ]));

                foreach ($symbols as $symbol) {
                    foreach ($intervals as $interval) {
                        self::sendKlineSnapshot($client_id, $symbol, $interval, 1500);
                    }
                }
                foreach ($symbols as $symbol) {
                    $tickGroup = self::tickGroupName($symbol);
                    Gateway::joinGroup($client_id, $tickGroup);
                }
            } elseif ($jsonData['type'] === 'unsubscribe_kline' || $jsonData['type'] === 'unsubscribe') {
                $symbols = $jsonData['symbols'] ?? null;
                if (!is_array($symbols)) {
                    $symbols = [($jsonData['symbol'] ?? null)];
                }
                $intervals = $jsonData['intervals'] ?? null;
                if (!is_array($intervals)) {
                    $intervals = [($jsonData['interval'] ?? null)];
                }

                $symbols = array_values(array_filter(array_map(function ($s) {
                    $s = strtoupper(trim((string)$s));
                    return $s === '' ? null : $s;
                }, $symbols)));

                $intervals = array_values(array_filter(array_map(function ($i) {
                    $i = trim((string)$i);
                    return $i === '' ? null : $i;
                }, $intervals)));

                $groups = [];
                foreach ($symbols as $symbol) {
                    foreach ($intervals as $interval) {
                        $group = self::klineGroupName($symbol, $interval);
                        Gateway::leaveGroup($client_id, $group);
                        $groups[] = $group;
                    }
                }

                Gateway::sendToClient($client_id, json_encode([
                    'type' => 'unsubscribed',
                    'groups' => $groups,
                ]));
                foreach ($symbols as $symbol) {
                    $tickGroup = self::tickGroupName($symbol);
                    Gateway::leaveGroup($client_id, $tickGroup);
                }
            } else {
                Gateway::sendToClient($client_id, json_encode([
                    'type'=>1,
                    'userid'=>$client_id,
                    'message'=>'null'
                ]));
            }
        }
    }

    public static function onClose($client_id)
    {
        // 向所有人发送 
        $data = array(
            'type'=>2,
            'message'=>'close'
        );
        Gateway::sendToAll(json_encode($data));
    }

}
