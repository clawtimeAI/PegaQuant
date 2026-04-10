<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

use support\Db;
use support\Request;
use plugin\webman\gateway\KlineSync;
use plugin\webman\gateway\OscillationEngine;

function _corsHeaders(): array
{
    return [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ];
}

$marketKlinesHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $interval = trim((string)$request->get('interval', ''));
    $limit = (int)$request->get('limit', 1500);
    $beforeMs = (int)$request->get('before_ms', 0);

    if ($limit <= 0) {
        $limit = 1500;
    }
    if ($limit > 1500) {
        $limit = 1500;
    }

    $allowedIntervals = ['1m', '5m', '15m', '30m', '1h', '4h'];
    if ($symbol === '' || !in_array($interval, $allowedIntervals, true)) {
        return json([])->withHeaders(_corsHeaders());
    }

    $table = "kline_$interval";
    $query = Db::table($table)
        ->where('symbol', $symbol)
        ->orderBy('open_time', 'desc');

    if ($beforeMs > 0) {
        $beforeTime = date('Y-m-d H:i:s', (int)floor($beforeMs / 1000));
        $query->where('open_time', '<', $beforeTime);
    }

    $rows = $query
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

    return json($out)->withHeaders(_corsHeaders());
};

$marketBackfillHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $interval = trim((string)$request->get('interval', '1m'));
    $days = (int)$request->get('days', 10);
    if ($days <= 0) {
        $days = 10;
    }
    if ($days > 30) {
        $days = 30;
    }
    if ($symbol === '' || $interval !== '1m') {
        return json([
            'ok' => false,
            'error' => 'only interval=1m supported',
        ])->withHeaders(_corsHeaders());
    }

    $endMs = (int)(microtime(true) * 1000);
    $startMs = $endMs - $days * 24 * 60 * 60 * 1000;
    $result = KlineSync::backfill($symbol, $interval, $startMs, $endMs);
    $range = _klineRange('kline_' . $interval, $symbol);
    return json([
        'ok' => true,
        'result' => $result,
        'range' => $range,
    ])->withHeaders(_corsHeaders());
};

Route::get('/market/klines', $marketKlinesHandler);

Route::get('/api/market/klines', function (Request $request) use ($marketKlinesHandler) {
    return $marketKlinesHandler($request);
});
Route::get('/market/backfill', $marketBackfillHandler);
Route::get('/api/market/backfill', function (Request $request) use ($marketBackfillHandler) {
    return $marketBackfillHandler($request);
});

function _oscJson($v): array
{
    if ($v === null) {
        return [];
    }
    if (is_array($v)) {
        return $v;
    }
    if (is_string($v) && $v !== '') {
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

$oscActiveHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $intervalsRaw = trim((string)$request->get('intervals', ''));
    $allowedIntervals = ['1m', '5m', '15m', '30m', '1h', '4h'];

    if ($symbol === '') {
        return json([])->withHeaders(_corsHeaders());
    }

    $intervals = [];
    if ($intervalsRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $intervalsRaw) as $itv) {
            $itv = trim((string)$itv);
            if ($itv === '') continue;
            if (in_array($itv, $allowedIntervals, true)) {
                $intervals[] = $itv;
            }
        }
    }
    if (empty($intervals)) {
        $intervals = $allowedIntervals;
    }

    $out = [];
    foreach ($intervals as $interval) {
        try {
            $row = Db::table('oscillation_structures')
                ->where('symbol', $symbol)
                ->where('interval', $interval)
                ->where('status', 'ACTIVE')
                ->orderBy('id', 'desc')
                ->first(['id', 'interval', 'x_points', 'y_points', 'start_time', 'updated_at']);
            if (!$row) {
                continue;
            }
            $out[] = [
                'id' => (int)$row->id,
                'symbol' => $symbol,
                'interval' => (string)$row->interval,
                'start_time' => $row->start_time !== null ? (string)$row->start_time : null,
                'updated_at' => $row->updated_at !== null ? (string)$row->updated_at : null,
                'x_points' => _oscJson($row->x_points),
                'y_points' => _oscJson($row->y_points),
            ];
        } catch (\Throwable $e) {
            continue;
        }
    }

    return json($out)->withHeaders(_corsHeaders());
};

Route::get('/trend/oscillation/active', $oscActiveHandler);
Route::get('/api/trend/oscillation/active', function (Request $request) use ($oscActiveHandler) {
    return $oscActiveHandler($request);
});

$strategyEmitHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $mode = strtolower(trim((string)$request->get('mode', 'both')));
    $refresh = (int)$request->get('refresh', 1) !== 0;

    $enable4hPreplan = (int)$request->get('enable_4h_preplan', 1) !== 0;
    $preplanRiskMul = (float)$request->get('preplan_risk_mul', 0.25);
    $preplanExpirySec = (int)$request->get('preplan_expiry_sec', 7200);

    $confirmBars = (int)$request->get('confirm_bars', 30);
    $breakPct = (float)$request->get('break_pct', 0.05);
    $breakExtremePct = (float)$request->get('break_extreme_pct', 0.02);

    if ($symbol === '') {
        return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
    }
    if (!in_array($mode, ['short', 'long', 'both'], true)) {
        $mode = 'both';
    }

    $shortPriorityIntervals = ['5m', '1m'];
    $longPriorityIntervals = ['4h', '1h'];
    $intervals = array_values(array_unique(array_merge($shortPriorityIntervals, $longPriorityIntervals)));

    if ($refresh) {
        foreach ($intervals as $itv) {
            try {
                OscillationEngine::run(
                    symbol: $symbol,
                    interval: $itv,
                    startTime: null,
                    endTime: null,
                    confirmBars: $confirmBars,
                    breakPct: $breakPct,
                    breakExtremePct: $breakExtremePct,
                );
            } catch (\Throwable $e) {
            }
        }
    }

    $states = [];
    $events = [];
    foreach ($intervals as $itv) {
        $row = Db::table('oscillation_structures')
            ->where('symbol', $symbol)
            ->where('interval', $itv)
            ->where('status', 'ACTIVE')
            ->orderBy('id', 'desc')
            ->first(['id', 'x_points', 'y_points', 'engine_state']);
        if (!$row) {
            continue;
        }
        $engine = _oscJson($row->engine_state ?? null);
        $states[$itv] = [
            'id' => (int)$row->id,
            'x_points' => _oscJson($row->x_points),
            'y_points' => _oscJson($row->y_points),
            'pending' => isset($engine['pending']) && is_array($engine['pending']) ? $engine['pending'] : null,
            'last_bar' => null,
        ];

        $sec = _intervalSec($itv);
        $kr = Db::table('kline_' . $itv)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'desc')
            ->first(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_dn']);
        if (!$kr) {
            continue;
        }
        $bar = _klineRowToBar($kr, $sec);
        if ($bar === null) {
            continue;
        }
        $states[$itv]['last_bar'] = $bar;
        $events[$itv] = [
            'broke_dn' => $bar['boll_dn'] !== null && $bar['low'] < (float)$bar['boll_dn'],
            'broke_up' => $bar['boll_up'] !== null && $bar['high'] > (float)$bar['boll_up'],
        ];
    }

    $bar1m = $states['1m']['last_bar'] ?? null;
    if (!is_array($bar1m)) {
        return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
    }

    $triggerIntervals = match ($mode) {
        'long' => $longPriorityIntervals,
        'both' => array_merge($longPriorityIntervals, $shortPriorityIntervals),
        default => $shortPriorityIntervals,
    };

    $triggerItv = null;
    foreach ($triggerIntervals as $itv) {
        if (!isset($events[$itv])) {
            continue;
        }
        if ($events[$itv]['broke_dn'] || $events[$itv]['broke_up']) {
            $triggerItv = $itv;
            break;
        }
    }
    if ($triggerItv === null) {
        return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
    }
    $bar = $states[$triggerItv]['last_bar'] ?? null;
    if (!is_array($bar) || $bar['boll_up'] === null || $bar['boll_dn'] === null || $bar['band_width'] === null || $bar['mb'] === null) {
        return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
    }

    $sec = _intervalSec($triggerItv);
    $isLong = (bool)($events[$triggerItv]['broke_dn'] ?? false);
    $side = $isLong ? 'LONG' : 'SHORT';

    $planKind = in_array($triggerItv, ['1h', '4h'], true) ? 'LONG' : 'SHORT';
    $tfPlan = $triggerItv;
    $sourceItv = $triggerItv;
    $riskMul = 1.0;
    $expiresSec = $sec;
    if ($planKind === 'LONG') {
        $expiresSec = _intervalSec($tfPlan);
        if ($triggerItv === '1h' && $enable4hPreplan) {
            $planKind = 'PREP';
            $tfPlan = '4h';
            $sourceItv = '1h';
            $riskMul = $preplanRiskMul;
            $expiresSec = $preplanExpirySec;
        }
    }

    $entryAnchor = null;
    $thesisRef = null;
    $thesisPrice = null;
    $thesisTime = null;
    $entryRefs = [];
    $entryRefPoints = [];
    $entrySource = null;
    $bw = (float)$bar['band_width'];

    if ($planKind === 'LONG' || $planKind === 'PREP') {
        $points = $isLong ? ($states[$sourceItv]['x_points'] ?? []) : ($states[$sourceItv]['y_points'] ?? []);
        $pending = $states[$sourceItv]['pending'] ?? null;
        $expectKind = $isLong ? 'X' : 'Y';
        $usePendingThesis = is_array($pending) && (($pending['kind'] ?? null) === $expectKind);
        $confirmedCount = count($points);
        if (!$usePendingThesis && $confirmedCount < 2) {
            return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
        }
        if ($usePendingThesis) {
            $thesisRef = $expectKind . (string)($confirmedCount + 1);
            $thesisPrice = (float)$pending['price'];
            $thesisTime = (string)$pending['time'];
        } else {
            $thesis = $points[$confirmedCount - 1];
            $thesisRef = $thesis['label'] ?? null;
            $thesisPrice = isset($thesis['price']) ? (float)$thesis['price'] : null;
            $thesisTime = isset($thesis['time']) ? (string)$thesis['time'] : null;
        }
        $prices = [];
        $entryLimit = $usePendingThesis ? $confirmedCount : ($confirmedCount - 1);
        for ($i = 0; $i < $entryLimit; $i++) {
            $prices[] = (float)$points[$i]['price'];
            $entryRefs[] = $points[$i]['label'] ?? null;
            $entryRefPoints[] = $points[$i];
        }
        if ($tfPlan === '4h' && $sourceItv === '4h') {
            $aux = $isLong ? ($states['1h']['x_points'] ?? []) : ($states['1h']['y_points'] ?? []);
            foreach ($aux as $p) {
                $prices[] = (float)$p['price'];
                $entryRefs[] = $p['label'] ?? null;
                $entryRefPoints[] = $p;
            }
        }
        $entryAnchor = _median($prices);
        if ($entryAnchor === null) {
            return json(['ok' => true, 'plans' => []])->withHeaders(_corsHeaders());
        }
        $entrySource = 'MEDIAN';
    }

    if ($entryAnchor === null) {
        if ($isLong && !empty($states[$triggerItv]['x_points'])) {
            $p = $states[$triggerItv]['x_points'][count($states[$triggerItv]['x_points']) - 1];
            $entryAnchor = (float)$p['price'];
            $entryRefs[] = $p['label'] ?? null;
            $entryRefPoints[] = $p;
            $entrySource = 'LAST_POINT';
        } elseif (!$isLong && !empty($states[$triggerItv]['y_points'])) {
            $p = $states[$triggerItv]['y_points'][count($states[$triggerItv]['y_points']) - 1];
            $entryAnchor = (float)$p['price'];
            $entryRefs[] = $p['label'] ?? null;
            $entryRefPoints[] = $p;
            $entrySource = 'LAST_POINT';
        } else {
            $entryAnchor = $isLong ? (float)$bar['boll_dn'] : (float)$bar['boll_up'];
            $entrySource = 'BOLL';
        }
    }

    $bufSec = ($planKind === 'SHORT') ? $sec : _intervalSec($tfPlan);
    $buf = ($bufSec >= 3600 ? 0.30 : 0.10) * $bw;
    $entryZone = [$entryAnchor - $buf, $entryAnchor + $buf];
    $entryPrice = $entryAnchor;
    $k = 0.10;
    $sl = $isLong ? ((float)$bar['boll_dn'] - $k * $bw) : ((float)$bar['boll_up'] + $k * $bw);
    $tp = (float)$bar['mb'];

    if ($isLong && $tp <= $entryPrice) {
        $tp = $entryPrice + 0.5 * $bw;
    }
    if (!$isLong && $tp >= $entryPrice) {
        $tp = $entryPrice - 0.5 * $bw;
    }
    if ($isLong && $sl >= $entryPrice) {
        $sl = $entryPrice - 0.5 * $bw;
    }
    if (!$isLong && $sl <= $entryPrice) {
        $sl = $entryPrice + 0.5 * $bw;
    }

    $createdTs = time();
    $planId = $symbol . ':' . $tfPlan . ':' . $side . ':' . (string)($bar['close_ts'] ?? $createdTs);
    $plan = [
        'id' => $planId,
        'symbol' => $symbol,
        'side' => $side,
        'tf_trigger' => $triggerItv,
        'tf_plan' => $tfPlan,
        'kind' => $planKind,
        'created_time' => $bar1m['open_time'],
        'created_ts' => $createdTs,
        'active_from_ts' => $createdTs,
        'expires_ts' => $createdTs + $expiresSec,
        'entry_zone' => $entryZone,
        'entry_price' => $entryPrice,
        'entry_source' => $entrySource,
        'entry_ref_points' => $entryRefPoints,
        'sl' => $sl,
        'tp' => $tp,
        'risk_mul' => $riskMul,
        'refPoints' => [
            'entryRefs' => array_values(array_filter($entryRefs, fn($v) => $v !== null)),
            'thesisRef' => $thesisRef,
            'thesisTime' => $thesisTime,
            'thesisPrice' => $thesisPrice,
        ],
    ];

    return json(['ok' => true, 'plans' => [$plan]])->withHeaders(_corsHeaders());
};

Route::get('/strategy/emit', $strategyEmitHandler);
Route::get('/api/strategy/emit', function (Request $request) use ($strategyEmitHandler) {
    return $strategyEmitHandler($request);
});

function _parseTimeUtc(string $time): ?\DateTimeImmutable
{
    $time = trim($time);
    if ($time === '') {
        return null;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, new \DateTimeZone('UTC'));
    if ($dt instanceof \DateTimeImmutable) {
        return $dt;
    }
    try {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    } catch (\Throwable $e) {
        return null;
    }
}

function _klineRowToBar(object $r, int $intervalSec): ?array
{
    $dt = _parseTimeUtc((string)$r->open_time);
    if (!$dt) {
        return null;
    }
    $openTs = $dt->getTimestamp();
    $up = $r->boll_up !== null ? (float)$r->boll_up : null;
    $dn = $r->boll_dn !== null ? (float)$r->boll_dn : null;
    $mb = ($up !== null && $dn !== null) ? (($up + $dn) / 2.0) : null;
    $bw = ($up !== null && $dn !== null) ? ($up - $dn) : null;

    return [
        'open_time' => (string)$r->open_time,
        'open_ts' => $openTs,
        'close_ts' => $openTs + $intervalSec,
        'high' => (float)$r->high,
        'low' => (float)$r->low,
        'close' => (float)$r->close,
        'boll_up' => $up,
        'boll_dn' => $dn,
        'mb' => $mb,
        'band_width' => $bw,
    ];
}

function _oscPointTs(string $time): ?int
{
    $dt = _parseTimeUtc($time);
    if (!$dt) {
        return null;
    }
    return $dt->getTimestamp();
}

function _oscPointsFromDb(string $symbol, string $interval, int $openTs): ?array
{
    try {
        $row = Db::table('oscillation_structures')
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('status', 'ACTIVE')
            ->orderBy('id', 'desc')
            ->first(['id', 'x_points', 'y_points']);
        if (!$row) {
            return null;
        }
        $x = _oscJson($row->x_points);
        $y = _oscJson($row->y_points);
        $filter = function ($p) use ($openTs) {
            if (!is_array($p)) {
                return false;
            }
            $ts = _oscPointTs((string)($p['time'] ?? ''));
            return $ts !== null && $ts <= $openTs;
        };
        $x = is_array($x) ? array_values(array_filter($x, $filter)) : [];
        $y = is_array($y) ? array_values(array_filter($y, $filter)) : [];
        return [
            'structure_id' => (int)$row->id,
            'x_points' => $x,
            'y_points' => $y,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function _barIsInBand(array $bar): bool
{
    if ($bar['boll_up'] === null || $bar['boll_dn'] === null) {
        return false;
    }
    return $bar['close'] <= $bar['boll_up'] && $bar['close'] >= $bar['boll_dn'];
}

function _oscCheckBreak(array $bar, array $xPoints, array $yPoints, float $breakPct, float $breakExtremePct): ?array
{
    $low = $bar['low'];
    $high = $bar['high'];

    if (!empty($xPoints)) {
        $last = (float)$xPoints[count($xPoints) - 1]['price'];
        $min = $last;
        foreach ($xPoints as $p) {
            $min = min($min, (float)$p['price']);
        }
        $condLast = $low < $last * (1.0 - $breakPct);
        $condExtreme = $low < $min * (1.0 - $breakExtremePct);
        if ($condLast && $condExtreme) {
            return [
                'kind' => 'X',
                'low' => $low,
                'last' => $last,
                'extreme' => $min,
            ];
        }
    }

    if (!empty($yPoints)) {
        $last = (float)$yPoints[count($yPoints) - 1]['price'];
        $max = $last;
        foreach ($yPoints as $p) {
            $max = max($max, (float)$p['price']);
        }
        $condLast = $high > $last * (1.0 + $breakPct);
        $condExtreme = $high > $max * (1.0 + $breakExtremePct);
        if ($condLast && $condExtreme) {
            return [
                'kind' => 'Y',
                'high' => $high,
                'last' => $last,
                'extreme' => $max,
            ];
        }
    }

    return null;
}

function _oscUpdatePending(?array $pending, string $kind, string $time, float $price, bool $preferLower): array
{
    if ($pending === null) {
        return ['kind' => $kind, 'time' => $time, 'price' => $price];
    }
    if ((string)$pending['kind'] !== $kind) {
        return ['kind' => $kind, 'time' => $time, 'price' => $price];
    }
    $pendingPrice = (float)$pending['price'];
    if ($preferLower) {
        if ($price < $pendingPrice) {
            return ['kind' => $kind, 'time' => $time, 'price' => $price];
        }
        return $pending;
    }
    if ($price > $pendingPrice) {
        return ['kind' => $kind, 'time' => $time, 'price' => $price];
    }
    return $pending;
}

function _oscFeedBar(array &$st, array $bar): array
{
    $breakInfo = _oscCheckBreak($bar, $st['x_points'], $st['y_points'], $st['break_pct'], $st['break_extreme_pct']);
    if ($breakInfo !== null) {
        $st['x_points'] = [];
        $st['y_points'] = [];
        $st['pending'] = null;
        $st['inband_count'] = 0;
        $st['start_time'] = $bar['open_time'];
    }

    $bollDn = $bar['boll_dn'];
    $bollUp = $bar['boll_up'];

    if ($bollDn !== null && $bar['low'] < $bollDn) {
        $st['pending'] = _oscUpdatePending($st['pending'], 'X', $bar['open_time'], $bar['low'], true);
        $st['inband_count'] = 0;
    } elseif ($bollUp !== null && $bar['high'] > $bollUp) {
        $st['pending'] = _oscUpdatePending($st['pending'], 'Y', $bar['open_time'], $bar['high'], false);
        $st['inband_count'] = 0;
    } else {
        if ($st['pending'] !== null && _barIsInBand($bar)) {
            $st['inband_count']++;
            if ($st['inband_count'] >= $st['confirm_bars']) {
                $pending = $st['pending'];
                $point = [
                    'time' => (string)$pending['time'],
                    'price' => (float)$pending['price'],
                    'kind' => (string)$pending['kind'],
                ];
                if ($point['kind'] === 'X') {
                    $point['label'] = 'X' . (string)(count($st['x_points']) + 1);
                    $st['x_points'][] = $point;
                } else {
                    $point['label'] = 'Y' . (string)(count($st['y_points']) + 1);
                    $st['y_points'][] = $point;
                }
                if ($st['start_time'] === null) {
                    $st['start_time'] = $point['time'];
                }
                $st['pending'] = null;
                $st['inband_count'] = 0;
            }
        } else {
            $st['inband_count'] = 0;
        }
    }

    $st['last_bar'] = $bar;

    return [
        'break' => $breakInfo,
        'broke_dn' => $bollDn !== null && $bar['low'] < $bollDn,
        'broke_up' => $bollUp !== null && $bar['high'] > $bollUp,
    ];
}

function _median(array $nums): ?float
{
    if (empty($nums)) {
        return null;
    }
    sort($nums);
    $n = count($nums);
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return (float)$nums[$mid];
    }
    return ((float)$nums[$mid - 1] + (float)$nums[$mid]) / 2.0;
}

function _tpStepsDefault(): array
{
    return [
        ['target' => 'MB', 'pct' => 0.5],
        ['target' => 'BAND', 'pct' => 0.4],
    ];
}

function _tpStepsParse(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    try {
        $obj = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return [];
    }
    if (!is_array($obj)) {
        return [];
    }
    $out = [];
    foreach ($obj as $it) {
        if (!is_array($it)) {
            continue;
        }
        $target = strtoupper(trim((string)($it['target'] ?? '')));
        if (!in_array($target, ['MB', 'UP', 'DN', 'BAND'], true)) {
            continue;
        }
        $pct = (float)($it['pct'] ?? 0);
        if (!is_finite($pct) || $pct <= 0 || $pct > 1) {
            continue;
        }
        $out[] = ['target' => $target, 'pct' => $pct];
        if (count($out) >= 6) {
            break;
        }
    }
    $sum = 0.0;
    foreach ($out as $s) {
        $sum += (float)$s['pct'];
    }
    if ($sum > 0.999999) {
        return [];
    }
    return $out;
}

function _slFromEntry(float $entry, bool $isLong, float $slPct): float
{
    if ($slPct <= 0) {
        $slPct = 0.01;
    }
    return $isLong ? ($entry * (1.0 - $slPct)) : ($entry * (1.0 + $slPct));
}

function _intervalSec(string $interval): int
{
    return match ($interval) {
        '1m' => 60,
        '5m' => 300,
        '15m' => 900,
        '30m' => 1800,
        '1h' => 3600,
        '4h' => 14400,
        default => 60,
    };
}

function _btJson($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function _ensureBacktestSchema(): void
{
    static $ok = null;
    if ($ok === true || $ok === false) {
        return;
    }
    try {
        Db::statement('CREATE TABLE IF NOT EXISTS backtest_runs (
            id bigserial PRIMARY KEY,
            symbol text NOT NULL,
            start_ms bigint NOT NULL,
            end_ms bigint NOT NULL,
            effective_start_ms bigint NOT NULL,
            effective_end_ms bigint NOT NULL,
            mode text NOT NULL,
            params jsonb NOT NULL,
            summary jsonb NOT NULL,
            warnings jsonb,
            states jsonb,
            created_at timestamp NOT NULL DEFAULT now()
        )');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_runs_symbol_created_at_idx ON backtest_runs(symbol, created_at DESC)');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_runs_symbol_window_idx ON backtest_runs(symbol, start_ms, end_ms)');

        Db::statement('CREATE TABLE IF NOT EXISTS backtest_plans (
            id bigserial PRIMARY KEY,
            run_id bigint NOT NULL,
            plan_id bigint NOT NULL,
            created_time text NOT NULL,
            created_close_ts bigint NOT NULL,
            active_from_ts bigint NOT NULL,
            expires_ts bigint NOT NULL,
            side text NOT NULL,
            kind text NOT NULL,
            tf_trigger text NOT NULL,
            tf_plan text NOT NULL,
            trigger_open_time text NOT NULL,
            trigger_close_ts bigint NOT NULL,
            plan jsonb NOT NULL,
            created_at timestamp NOT NULL DEFAULT now()
        )');
        Db::statement('CREATE UNIQUE INDEX IF NOT EXISTS backtest_plans_run_id_plan_id_uq ON backtest_plans(run_id, plan_id)');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_plans_run_id_created_close_ts_idx ON backtest_plans(run_id, created_close_ts)');

        Db::statement('CREATE TABLE IF NOT EXISTS backtest_trades (
            id bigserial PRIMARY KEY,
            run_id bigint NOT NULL,
            seq int NOT NULL,
            type text NOT NULL,
            time text NOT NULL,
            side text,
            reason text,
            price double precision,
            qty double precision,
            fee double precision,
            pnl double precision,
            plan_id bigint,
            created_at timestamp NOT NULL DEFAULT now()
        )');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_trades_run_id_seq_idx ON backtest_trades(run_id, seq)');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_trades_run_id_time_idx ON backtest_trades(run_id, time)');

        Db::statement('CREATE TABLE IF NOT EXISTS backtest_equity (
            id bigserial PRIMARY KEY,
            run_id bigint NOT NULL,
            seq int NOT NULL,
            time text NOT NULL,
            equity double precision NOT NULL,
            created_at timestamp NOT NULL DEFAULT now()
        )');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_equity_run_id_seq_idx ON backtest_equity(run_id, seq)');
        Db::statement('CREATE INDEX IF NOT EXISTS backtest_equity_run_id_time_idx ON backtest_equity(run_id, time)');

        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }
}

function _klineRange(string $table, string $symbol): ?array
{
    $min = Db::table($table)->where('symbol', $symbol)->orderBy('open_time', 'asc')->first(['open_time']);
    $max = Db::table($table)->where('symbol', $symbol)->orderBy('open_time', 'desc')->first(['open_time']);
    if (!$min || !$max) {
        return null;
    }
    $minTime = (string)$min->open_time;
    $maxTime = (string)$max->open_time;
    $minTs = strtotime($minTime);
    $maxTs = strtotime($maxTime);
    if ($minTs === false || $maxTs === false) {
        return null;
    }
    return [
        'min_time' => $minTime,
        'max_time' => $maxTime,
        'min_ms' => (int)($minTs * 1000),
        'max_ms' => (int)($maxTs * 1000),
    ];
}

$backtestRunHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $startMs = (int)$request->get('start_ms', 0);
    $endMs = (int)$request->get('end_ms', 0);
    $save = (int)$request->get('save', 1) === 1;
    $saveEquity = (int)$request->get('save_equity', 0) === 1;

    if ($symbol === '' || $startMs <= 0 || $endMs <= 0 || $endMs <= $startMs) {
        return json([
            'ok' => false,
            'error' => 'missing/invalid params: symbol, start_ms, end_ms',
        ])->withHeaders(_corsHeaders());
    }

    $initialEquity = (float)$request->get('initial_equity', 10000);
    if ($initialEquity <= 0) {
        $initialEquity = 10000;
    }
    $riskPct = (float)$request->get('risk_pct', 0.005);
    if ($riskPct <= 0) {
        $riskPct = 0.005;
    }
    $feeBps = (float)$request->get('fee_bps', 4);
    if ($feeBps < 0) {
        $feeBps = 0;
    }
    $slipBps = (float)$request->get('slippage_bps', 1);
    if ($slipBps < 0) {
        $slipBps = 0;
    }

    $slPct = (float)$request->get('sl_pct', 0.01);
    if (!is_finite($slPct) || $slPct <= 0 || $slPct > 0.2) {
        $slPct = 0.01;
    }
    $tpSteps = _tpStepsParse((string)$request->get('tp_steps', ''));
    if (empty($tpSteps)) {
        $tpSteps = _tpStepsDefault();
    }

    $mode = strtolower(trim((string)$request->get('mode', 'short')));
    if (!in_array($mode, ['short', 'long', 'both'], true)) {
        $mode = 'short';
    }
    $enable4hPreplan = (int)$request->get('enable_4h_preplan', 1) === 1;
    $cooldownMult = (float)$request->get('cooldown_mult', 1.0);
    if ($cooldownMult < 0) {
        $cooldownMult = 0;
    }
    $preplanRiskMul = (float)$request->get('preplan_risk_mult', 0.3);
    if ($preplanRiskMul <= 0 || $preplanRiskMul > 1) {
        $preplanRiskMul = 0.3;
    }
    $preplanExpirySec = (int)$request->get('preplan_expiry_sec', 3600);
    if ($preplanExpirySec <= 0) {
        $preplanExpirySec = 3600;
    }

    $confirmBars = (int)$request->get('confirm_bars', 30);
    if ($confirmBars <= 0) {
        $confirmBars = 30;
    }
    $breakPct = (float)$request->get('break_pct', 0.05);
    if ($breakPct <= 0) {
        $breakPct = 0.05;
    }
    $breakExtremePct = (float)$request->get('break_extreme_pct', 0.02);
    if ($breakExtremePct <= 0) {
        $breakExtremePct = 0.02;
    }

    $startTs = (int)floor($startMs / 1000);
    $endTs = (int)floor($endMs / 1000);
    $warmupMs = (int)$request->get('warmup_ms', 0);
    if ($warmupMs <= 0) {
        $warmupMs = ($mode === 'long' || $mode === 'both') ? (14 * 24 * 60 * 60 * 1000) : (2 * 24 * 60 * 60 * 1000);
    }

    $warnings = [];
    $range1m = _klineRange('kline_1m', $symbol);
    if ($range1m === null) {
        return json([
            'ok' => false,
            'error' => 'no kline_1m data for symbol',
        ])->withHeaders(_corsHeaders());
    }
    $availableStartTs = (int)floor($range1m['min_ms'] / 1000);
    $availableEndTs = (int)floor($range1m['max_ms'] / 1000);
    $tradeStartTs = max($startTs, $availableStartTs);
    $tradeEndTs = min($endTs, $availableEndTs);
    if ($tradeStartTs !== $startTs) {
        $warnings[] = 'start_ms earlier than available kline_1m data, clamped to min available';
    }
    if ($tradeEndTs !== $endTs) {
        $warnings[] = 'end_ms later than available kline_1m data, clamped to max available';
    }
    if ($tradeEndTs <= $tradeStartTs) {
        return json([
            'ok' => false,
            'error' => 'requested window has no overlap with available kline_1m data',
            'data_range_1m' => $range1m,
        ])->withHeaders(_corsHeaders());
    }

    $warmupStartTs = max($availableStartTs, max(0, $tradeStartTs - (int)floor($warmupMs / 1000)));
    if ($warmupStartTs > $tradeStartTs) {
        $warnings[] = 'warmup truncated by available kline_1m range';
    }
    $warmupStartTime = (new \DateTimeImmutable('@' . $warmupStartTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $startTime = (new \DateTimeImmutable('@' . $tradeStartTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endTime = (new \DateTimeImmutable('@' . $tradeEndTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $intervals = ['1m', '5m', '15m', '30m', '1h', '4h'];
    $states = [];
    foreach ($intervals as $itv) {
        $states[$itv] = [
            'interval' => $itv,
            'confirm_bars' => $confirmBars,
            'break_pct' => $breakPct,
            'break_extreme_pct' => $breakExtremePct,
            'pending' => null,
            'inband_count' => 0,
            'x_points' => [],
            'y_points' => [],
            'start_time' => null,
            'last_bar' => null,
        ];
    }

    $barsByCloseTs = [];
    foreach (['5m', '15m', '30m', '1h', '4h'] as $itv) {
        $sec = _intervalSec($itv);
        $startForItv = (new \DateTimeImmutable('@' . max(0, $warmupStartTs - $sec)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = Db::table('kline_' . $itv)
            ->where('symbol', $symbol)
            ->where('open_time', '>=', $startForItv)
            ->where('open_time', '<=', $endTime)
            ->orderBy('open_time', 'asc')
            ->get(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_dn'])
            ->toArray();

        $map = [];
        foreach ($rows as $r) {
            $bar = _klineRowToBar($r, $sec);
            if ($bar === null) {
                continue;
            }
            if ($bar['close_ts'] < $warmupStartTs || $bar['close_ts'] > $tradeEndTs) {
                continue;
            }
            $map[(string)$bar['close_ts']] = $bar;
        }
        $barsByCloseTs[$itv] = $map;
    }

    $cash = $initialEquity;
    $position = null;
    $pendingOrder = null;
    $trades = [];
    $equityCurve = [];
    $planIdSeq = 0;
    $plans = [];
    $cooldowns = [];

    $shortPriorityIntervals = ['5m', '1m'];
    $longPriorityIntervals = ['4h', '1h'];
    $k1mQuery = Db::table('kline_1m')
        ->where('symbol', $symbol)
        ->where('open_time', '>=', $warmupStartTime)
        ->where('open_time', '<=', $endTime)
        ->orderBy('open_time', 'asc')
        ->select(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_dn']);

    foreach ($k1mQuery->cursor() as $r) {
        $bar1m = _klineRowToBar($r, 60);
        if ($bar1m === null) {
            continue;
        }

        $openTs = $bar1m['open_ts'];
        $closeTs = $bar1m['close_ts'];
        $trading = $openTs >= $tradeStartTs;

        if ($trading && $pendingOrder !== null && $position === null && isset($pendingOrder['expires_ts']) && (int)$pendingOrder['expires_ts'] <= $openTs) {
            $trades[] = [
                'type' => 'CANCEL',
                'time' => $bar1m['open_time'],
                'reason' => 'EXPIRE',
                'plan_id' => $pendingOrder['plan']['id'] ?? null,
            ];
            $pendingOrder = null;
        }

        if ($trading && $pendingOrder !== null && (int)$pendingOrder['active_from_ts'] <= $openTs && $position === null) {
            $side = (string)$pendingOrder['side'];
            $orderPrice = (float)$pendingOrder['price'];
            $canFill = $side === 'LONG' ? ($bar1m['low'] <= $orderPrice) : ($bar1m['high'] >= $orderPrice);
            if ($canFill) {
                $slippage = $slipBps / 10000.0;
                $fillPrice = $side === 'LONG' ? $orderPrice * (1.0 + $slippage) : $orderPrice * (1.0 - $slippage);
                $qty = (float)$pendingOrder['qty'];
                $fillSl = _slFromEntry($fillPrice, $side === 'LONG', (float)($pendingOrder['sl_pct'] ?? $slPct));
                $feeRate = $feeBps / 10000.0;
                $fee = $qty * $fillPrice * $feeRate;
                $cash -= $fee;
                $position = [
                    'side' => $side,
                    'qty' => $qty,
                    'rem' => $qty,
                    'entry' => $fillPrice,
                    'entry_time' => $bar1m['open_time'],
                    'sl' => $fillSl,
                    'sl_pct' => (float)($pendingOrder['sl_pct'] ?? $slPct),
                    'tp_steps' => $pendingOrder['tp_steps'] ?? $tpSteps,
                    'tp_done' => [],
                    'plan' => $pendingOrder['plan'],
                ];
                $trades[] = [
                    'type' => 'OPEN',
                    'time' => $bar1m['open_time'],
                    'side' => $side,
                    'price' => $fillPrice,
                    'qty' => $qty,
                    'fee' => $fee,
                    'plan_id' => $pendingOrder['plan']['id'],
                ];
                $pendingOrder = null;
            }
        }

        if ($trading && $position !== null) {
            $side = (string)$position['side'];
            $entry = (float)$position['entry'];
            $qty0 = (float)$position['qty'];
            $rem = (float)($position['rem'] ?? $qty0);
            $sl = (float)$position['sl'];
            $slHit = $side === 'LONG' ? ($bar1m['low'] <= $sl) : ($bar1m['high'] >= $sl);
            if ($slHit) {
                $slippage = $slipBps / 10000.0;
                $fill = $side === 'LONG' ? ($sl * (1.0 - $slippage)) : ($sl * (1.0 + $slippage));
                $pnl = $side === 'LONG' ? ($rem * ($fill - $entry)) : ($rem * ($entry - $fill));
                $feeRate = $feeBps / 10000.0;
                $fee = $rem * $fill * $feeRate;
                $cash += $pnl;
                $cash -= $fee;
                $trades[] = [
                    'type' => 'CLOSE',
                    'time' => $bar1m['open_time'],
                    'side' => $side,
                    'reason' => 'SL',
                    'price' => $fill,
                    'qty' => $rem,
                    'fee' => $fee,
                    'pnl' => $pnl,
                    'plan_id' => $position['plan']['id'],
                ];
                $position = null;
            } else {
                $plan = $position['plan'] ?? [];
                $tfPlan = (string)($plan['tf_plan'] ?? '1m');
                $secPlan = _intervalSec($tfPlan);
                $tfCloseTs = intdiv($openTs, $secPlan) * $secPlan;
                $tfBar = $barsByCloseTs[$tfPlan][(string)$tfCloseTs] ?? null;
                $steps = is_array($position['tp_steps'] ?? null) ? (array)$position['tp_steps'] : [];
                $done = is_array($position['tp_done'] ?? null) ? (array)$position['tp_done'] : [];
                $doneSet = [];
                foreach ($done as $d) {
                    $doneSet[(string)$d] = true;
                }
                if (is_array($tfBar) && !empty($steps)) {
                    $slippage = $slipBps / 10000.0;
                    $feeRate = $feeBps / 10000.0;
                    for ($i = 0; $i < count($steps); $i++) {
                        if ($rem <= 0) {
                            break;
                        }
                        if (isset($doneSet[(string)$i])) {
                            continue;
                        }
                        $st = $steps[$i];
                        if (!is_array($st)) {
                            continue;
                        }
                        $target = strtoupper(trim((string)($st['target'] ?? '')));
                        $pct = (float)($st['pct'] ?? 0);
                        if ($pct <= 0 || $pct > 1) {
                            $doneSet[(string)$i] = true;
                            $done[] = $i;
                            continue;
                        }
                        $tpPx = null;
                        if ($target === 'MB') {
                            $tpPx = $tfBar['mb'] ?? null;
                        } elseif ($target === 'UP') {
                            $tpPx = $tfBar['boll_up'] ?? null;
                        } elseif ($target === 'DN') {
                            $tpPx = $tfBar['boll_dn'] ?? null;
                        } elseif ($target === 'BAND') {
                            $tpPx = ($side === 'LONG') ? ($tfBar['boll_up'] ?? null) : ($tfBar['boll_dn'] ?? null);
                        }
                        if ($tpPx === null) {
                            continue;
                        }
                        $tpPx = (float)$tpPx;
                        if (($side === 'LONG' && $tpPx <= $entry) || ($side !== 'LONG' && $tpPx >= $entry)) {
                            continue;
                        }
                        $hit = $side === 'LONG' ? ($bar1m['high'] >= $tpPx) : ($bar1m['low'] <= $tpPx);
                        if (!$hit) {
                            continue;
                        }
                        $closeQty = min($rem, $qty0 * $pct);
                        if ($closeQty <= 0) {
                            $doneSet[(string)$i] = true;
                            $done[] = $i;
                            continue;
                        }
                        $fill = $side === 'LONG' ? ($tpPx * (1.0 - $slippage)) : ($tpPx * (1.0 + $slippage));
                        $pnl = $side === 'LONG' ? ($closeQty * ($fill - $entry)) : ($closeQty * ($entry - $fill));
                        $fee = $closeQty * $fill * $feeRate;
                        $cash += $pnl;
                        $cash -= $fee;
                        $trades[] = [
                            'type' => 'CLOSE',
                            'time' => $bar1m['open_time'],
                            'side' => $side,
                            'reason' => 'TP' . (string)($i + 1),
                            'price' => $fill,
                            'qty' => $closeQty,
                            'fee' => $fee,
                            'pnl' => $pnl,
                            'plan_id' => $position['plan']['id'],
                        ];
                        $rem -= $closeQty;
                        $doneSet[(string)$i] = true;
                        $done[] = $i;
                    }
                    $position['rem'] = $rem;
                    $position['tp_done'] = $done;
                    if ($rem <= 0) {
                        $position = null;
                    }
                }
            }
        }

        $events = [];
        $events['1m'] = _oscFeedBar($states['1m'], $bar1m);

        foreach (['5m', '15m', '30m', '1h', '4h'] as $itv) {
            $m = $barsByCloseTs[$itv] ?? [];
            $k = (string)$closeTs;
            if (isset($m[$k])) {
                $events[$itv] = _oscFeedBar($states[$itv], $m[$k]);
            }
        }

        $unreal = 0.0;
        if ($position !== null) {
            $qty = (float)$position['qty'];
            $entry = (float)$position['entry'];
            $unreal = ((string)$position['side'] === 'LONG')
                ? ($qty * ($bar1m['close'] - $entry))
                : ($qty * ($entry - $bar1m['close']));
        }
        if ($trading) {
            $equityCurve[] = [
                'time' => $bar1m['open_time'],
                'equity' => $cash + $unreal,
            ];
        }

        if ($trading && $position === null && $pendingOrder === null) {
            $triggerIntervals = match ($mode) {
                'long' => $longPriorityIntervals,
                'both' => array_merge($longPriorityIntervals, $shortPriorityIntervals),
                default => $shortPriorityIntervals,
            };
            $trigger = null;
            $triggerItv = null;
            foreach ($triggerIntervals as $itv) {
                if (!isset($events[$itv])) {
                    continue;
                }
                if ($events[$itv]['broke_dn'] || $events[$itv]['broke_up']) {
                    $trigger = $events[$itv];
                    $triggerItv = $itv;
                    break;
                }
            }

            if ($trigger !== null && $triggerItv !== null) {
                $bar = $states[$triggerItv]['last_bar'];
                if (is_array($bar) && $bar['boll_up'] !== null && $bar['boll_dn'] !== null && $bar['band_width'] !== null && $bar['mb'] !== null) {
                    $sec = _intervalSec($triggerItv);
                    $isLong = (bool)$trigger['broke_dn'];
                    $side = $isLong ? 'LONG' : 'SHORT';

                    $planKind = in_array($triggerItv, ['1h', '4h'], true) ? 'LONG' : 'SHORT';
                    $tfPlan = $triggerItv;
                    $sourceItv = $triggerItv;
                    $riskMul = 1.0;
                    $expiresSec = $sec;
                    if ($planKind === 'LONG') {
                        $expiresSec = _intervalSec($tfPlan);
                        if ($triggerItv === '1h' && $enable4hPreplan) {
                            $planKind = 'PREP';
                            $tfPlan = '4h';
                            $sourceItv = '1h';
                            $riskMul = $preplanRiskMul;
                            $expiresSec = $preplanExpirySec;
                        }
                    }

                    $entryAnchor = null;
                    $thesisRef = null;
                    $thesisPrice = null;
                    $thesisTime = null;
                    $entryRefs = [];
                    $entryRefPoints = [];
                    $entrySource = null;
                    $entryStructureId = null;
                    $bw = (float)$bar['band_width'];

                    if ($planKind === 'LONG' || $planKind === 'PREP') {
                        $src = _oscPointsFromDb($symbol, $sourceItv, $openTs);
                        $points = null;
                        if ($src !== null) {
                            $entryStructureId = (int)$src['structure_id'];
                            $points = $isLong ? ($src['x_points'] ?? []) : ($src['y_points'] ?? []);
                        }
                        if (!is_array($points)) {
                            $points = $isLong ? ($states[$sourceItv]['x_points'] ?? []) : ($states[$sourceItv]['y_points'] ?? []);
                        }
                        $confirmedCount = count($points);
                        if ($confirmedCount < 2) {
                            continue;
                        }
                        $thesis = $points[$confirmedCount - 1];
                        $thesisRef = $thesis['label'] ?? null;
                        $thesisPrice = isset($thesis['price']) ? (float)$thesis['price'] : null;
                        $thesisTime = isset($thesis['time']) ? (string)$thesis['time'] : null;
                        $prices = [];
                        $entryLimit = $confirmedCount - 1;
                        for ($i = 0; $i < $entryLimit; $i++) {
                            $prices[] = (float)$points[$i]['price'];
                            $entryRefs[] = $points[$i]['label'] ?? null;
                            $entryRefPoints[] = $points[$i];
                        }
                        if ($tfPlan === '4h' && $sourceItv === '4h') {
                            $auxSrc = _oscPointsFromDb($symbol, '1h', $openTs);
                            $aux = null;
                            if ($auxSrc !== null) {
                                $aux = $isLong ? ($auxSrc['x_points'] ?? []) : ($auxSrc['y_points'] ?? []);
                            }
                            if (!is_array($aux)) {
                                $aux = $isLong ? ($states['1h']['x_points'] ?? []) : ($states['1h']['y_points'] ?? []);
                            }
                            foreach ($aux as $p) {
                                $prices[] = (float)$p['price'];
                                $entryRefs[] = $p['label'] ?? null;
                                $entryRefPoints[] = $p;
                            }
                        }
                        $entryAnchor = _median($prices);
                        if ($entryAnchor === null) {
                            continue;
                        }
                        $entrySource = 'MEDIAN';
                    }

                    if ($entryAnchor === null) {
                        $src = _oscPointsFromDb($symbol, $triggerItv, $openTs);
                        $xs = null;
                        $ys = null;
                        if ($src !== null) {
                            $entryStructureId = (int)$src['structure_id'];
                            $xs = $src['x_points'] ?? null;
                            $ys = $src['y_points'] ?? null;
                        }
                        if (!is_array($xs)) {
                            $xs = $states[$triggerItv]['x_points'] ?? [];
                        }
                        if (!is_array($ys)) {
                            $ys = $states[$triggerItv]['y_points'] ?? [];
                        }
                        if ($isLong && !empty($xs)) {
                            $p = $xs[count($xs) - 1];
                            $entryAnchor = (float)$p['price'];
                            $entryRefs[] = $p['label'] ?? null;
                            $entryRefPoints[] = $p;
                            $entrySource = 'LAST_POINT';
                        } elseif (!$isLong && !empty($ys)) {
                            $p = $ys[count($ys) - 1];
                            $entryAnchor = (float)$p['price'];
                            $entryRefs[] = $p['label'] ?? null;
                            $entryRefPoints[] = $p;
                            $entrySource = 'LAST_POINT';
                        } else {
                            $entryAnchor = $isLong ? (float)$bar['boll_dn'] : (float)$bar['boll_up'];
                            $entrySource = 'BOLL';
                        }
                    }

                    $bufSec = ($planKind === 'SHORT') ? $sec : _intervalSec($tfPlan);
                    $buf = ($bufSec >= 3600 ? 0.30 : 0.10) * $bw;
                    $entryZone = [$entryAnchor - $buf, $entryAnchor + $buf];
                    $entryPrice = $entryAnchor;
                            $sl = _slFromEntry($entryPrice, $isLong, $slPct);

                    $risk = ($cash) * $riskPct * $riskMul;
                    $dist = abs($entryPrice - $sl);
                    if ($dist > 0) {
                        $qty = $risk / $dist;
                        if ($qty > 0) {
                            $coolKey = $side . ':' . $tfPlan;
                            $cd = isset($cooldowns[$coolKey]) ? (int)$cooldowns[$coolKey] : 0;
                            if ($cd > 0 && $openTs < $cd) {
                                continue;
                            }
                            $planIdSeq++;
                            $plan = [
                                'id' => $planIdSeq,
                                'symbol' => $symbol,
                                'side' => $side,
                                'tf_trigger' => $triggerItv,
                                'tf_plan' => $tfPlan,
                                'kind' => $planKind,
                                'created_time' => $bar1m['open_time'],
                                'entry_zone' => $entryZone,
                                'entry_price' => $entryPrice,
                                'entry_source' => $entrySource,
                                'entry_structure_id' => $entryStructureId,
                                'entry_ref_points' => $entryRefPoints,
                                'sl_pct' => $slPct,
                                'sl' => $sl,
                                'tp_steps' => $tpSteps,
                                'refPoints' => [
                                    'entryRefs' => array_values(array_filter($entryRefs, fn($v) => $v !== null)),
                                    'thesisRef' => $thesisRef,
                                    'thesisTime' => $thesisTime,
                                    'thesisPrice' => $thesisPrice,
                                ],
                            ];
                            $plan['created_close_ts'] = $closeTs;
                            $plan['active_from_ts'] = $closeTs;
                            $plan['expires_ts'] = $closeTs + $expiresSec;
                            $plan['trigger'] = [
                                'interval' => $triggerItv,
                                'open_time' => (string)($bar['open_time'] ?? ''),
                                'close_ts' => isset($bar['close_ts']) ? (int)$bar['close_ts'] : 0,
                                'broke_dn' => (bool)($trigger['broke_dn'] ?? false),
                                'broke_up' => (bool)($trigger['broke_up'] ?? false),
                                'boll_up' => isset($bar['boll_up']) ? (float)$bar['boll_up'] : null,
                                'boll_dn' => isset($bar['boll_dn']) ? (float)$bar['boll_dn'] : null,
                                'mb' => isset($bar['mb']) ? (float)$bar['mb'] : null,
                                'band_width' => isset($bar['band_width']) ? (float)$bar['band_width'] : null,
                                'high' => isset($bar['high']) ? (float)$bar['high'] : null,
                                'low' => isset($bar['low']) ? (float)$bar['low'] : null,
                                'close' => isset($bar['close']) ? (float)$bar['close'] : null,
                            ];
                            $plans[] = $plan;
                            $pendingOrder = [
                                'side' => $side,
                                'price' => $entryPrice,
                                'qty' => $qty,
                                'sl_pct' => $slPct,
                                'tp_steps' => $tpSteps,
                                'active_from_ts' => $closeTs,
                                'expires_ts' => $closeTs + $expiresSec,
                                'plan' => $plan,
                            ];
                            $cooldownSec = (int)round(_intervalSec($tfPlan) * $cooldownMult);
                            if ($cooldownSec > 0) {
                                $cooldowns[$coolKey] = $openTs + $cooldownSec;
                            }
                        }
                    }
                }
            }
        }
    }

    $wins = 0;
    $losses = 0;
    $pnlSum = 0.0;
    $peak = $initialEquity;
    $maxDd = 0.0;
    foreach ($equityCurve as $pt) {
        $eq = (float)$pt['equity'];
        if ($eq > $peak) {
            $peak = $eq;
        }
        if ($peak > 0) {
            $dd = ($peak - $eq) / $peak;
            if ($dd > $maxDd) {
                $maxDd = $dd;
            }
        }
    }
    foreach ($trades as $t) {
        if (($t['type'] ?? '') === 'CLOSE') {
            $p = (float)($t['pnl'] ?? 0);
            $pnlSum += $p;
            if ($p >= 0) {
                $wins++;
            } else {
                $losses++;
            }
        }
    }

    $payload = [
        'ok' => true,
        'warnings' => $warnings,
        'data_range_1m' => $range1m,
        'params' => [
            'symbol' => $symbol,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'effective_start_ms' => (int)($tradeStartTs * 1000),
            'effective_end_ms' => (int)($tradeEndTs * 1000),
            'initial_equity' => $initialEquity,
            'risk_pct' => $riskPct,
            'fee_bps' => $feeBps,
            'slippage_bps' => $slipBps,
            'sl_pct' => $slPct,
            'tp_steps' => $tpSteps,
            'mode' => $mode,
            'enable_4h_preplan' => $enable4hPreplan ? 1 : 0,
            'cooldown_mult' => $cooldownMult,
            'preplan_risk_mult' => $preplanRiskMul,
            'preplan_expiry_sec' => $preplanExpirySec,
            'warmup_ms' => $warmupMs,
            'confirm_bars' => $confirmBars,
            'break_pct' => $breakPct,
            'break_extreme_pct' => $breakExtremePct,
        ],
        'summary' => [
            'trades' => count($trades),
            'closed_trades' => $wins + $losses,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) : 0.0,
            'pnl' => $pnlSum,
            'max_drawdown' => $maxDd,
            'final_cash' => $cash,
        ],
        'trades_list' => $trades,
        'equity_curve' => $equityCurve,
        'states' => [
            '1m' => ['x_points' => $states['1m']['x_points'], 'y_points' => $states['1m']['y_points']],
            '5m' => ['x_points' => $states['5m']['x_points'], 'y_points' => $states['5m']['y_points']],
            '15m' => ['x_points' => $states['15m']['x_points'], 'y_points' => $states['15m']['y_points']],
            '30m' => ['x_points' => $states['30m']['x_points'], 'y_points' => $states['30m']['y_points']],
            '1h' => ['x_points' => $states['1h']['x_points'], 'y_points' => $states['1h']['y_points']],
            '4h' => ['x_points' => $states['4h']['x_points'], 'y_points' => $states['4h']['y_points']],
        ],
    ];

    $runId = null;
    if ($save) {
        _ensureBacktestSchema();
        try {
            $runId = Db::table('backtest_runs')->insertGetId([
                'symbol' => $symbol,
                'start_ms' => (int)$startMs,
                'end_ms' => (int)$endMs,
                'effective_start_ms' => (int)($tradeStartTs * 1000),
                'effective_end_ms' => (int)($tradeEndTs * 1000),
                'mode' => $mode,
                'params' => _btJson($payload['params']),
                'summary' => _btJson($payload['summary']),
                'warnings' => _btJson($payload['warnings']),
                'states' => _btJson($payload['states']),
            ]);
            if ($runId) {
                if (!empty($plans)) {
                    $pRows = [];
                    foreach ($plans as $p) {
                        $pRows[] = [
                            'run_id' => (int)$runId,
                            'plan_id' => (int)($p['id'] ?? 0),
                            'created_time' => (string)($p['created_time'] ?? ''),
                            'created_close_ts' => (int)($p['created_close_ts'] ?? 0),
                            'active_from_ts' => (int)($p['active_from_ts'] ?? 0),
                            'expires_ts' => (int)($p['expires_ts'] ?? 0),
                            'side' => (string)($p['side'] ?? ''),
                            'kind' => (string)($p['kind'] ?? ''),
                            'tf_trigger' => (string)($p['tf_trigger'] ?? ''),
                            'tf_plan' => (string)($p['tf_plan'] ?? ''),
                            'trigger_open_time' => (string)($p['trigger']['open_time'] ?? ''),
                            'trigger_close_ts' => (int)($p['trigger']['close_ts'] ?? 0),
                            'plan' => _btJson($p),
                        ];
                        if (count($pRows) >= 200) {
                            Db::table('backtest_plans')->insert($pRows);
                            $pRows = [];
                        }
                    }
                    if (!empty($pRows)) {
                        Db::table('backtest_plans')->insert($pRows);
                    }
                }

                $rows = [];
                $seq = 0;
                foreach ($trades as $t) {
                    $seq++;
                    $rows[] = [
                        'run_id' => (int)$runId,
                        'seq' => (int)$seq,
                        'type' => (string)($t['type'] ?? ''),
                        'time' => (string)($t['time'] ?? ''),
                        'side' => isset($t['side']) ? (string)$t['side'] : null,
                        'reason' => isset($t['reason']) ? (string)$t['reason'] : null,
                        'price' => isset($t['price']) ? (float)$t['price'] : null,
                        'qty' => isset($t['qty']) ? (float)$t['qty'] : null,
                        'fee' => isset($t['fee']) ? (float)$t['fee'] : null,
                        'pnl' => isset($t['pnl']) ? (float)$t['pnl'] : null,
                        'plan_id' => isset($t['plan_id']) ? (int)$t['plan_id'] : null,
                    ];
                    if (count($rows) >= 500) {
                        Db::table('backtest_trades')->insert($rows);
                        $rows = [];
                    }
                }
                if (!empty($rows)) {
                    Db::table('backtest_trades')->insert($rows);
                }

                if ($saveEquity) {
                    $eqRows = [];
                    $eqSeq = 0;
                    foreach ($equityCurve as $pt) {
                        $eqSeq++;
                        $eqRows[] = [
                            'run_id' => (int)$runId,
                            'seq' => (int)$eqSeq,
                            'time' => (string)($pt['time'] ?? ''),
                            'equity' => (float)($pt['equity'] ?? 0.0),
                        ];
                        if (count($eqRows) >= 1000) {
                            Db::table('backtest_equity')->insert($eqRows);
                            $eqRows = [];
                        }
                    }
                    if (!empty($eqRows)) {
                        Db::table('backtest_equity')->insert($eqRows);
                    }
                }
            }
        } catch (\Throwable $e) {
            $runId = null;
        }
    }

    $payload['run_id'] = $runId;
    $payload['saved'] = $runId !== null ? 1 : 0;
    $payload['saved_equity'] = ($runId !== null && $saveEquity) ? 1 : 0;

    return json($payload)->withHeaders(_corsHeaders());
};

Route::get('/backtest/run', $backtestRunHandler);
Route::get('/api/backtest/run', function (Request $request) use ($backtestRunHandler) {
    return $backtestRunHandler($request);
});

$backtestRangeHandler = function (Request $request) {
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    if ($symbol === '') {
        return json([
            'ok' => false,
            'error' => 'missing symbol',
        ])->withHeaders(_corsHeaders());
    }
    $intervals = ['1m', '5m', '15m', '30m', '1h', '4h'];
    $ranges = [];
    foreach ($intervals as $itv) {
        $range = _klineRange('kline_' . $itv, $symbol);
        if ($range !== null) {
            $ranges[$itv] = $range;
        }
    }
    return json([
        'ok' => true,
        'symbol' => $symbol,
        'ranges' => $ranges,
    ])->withHeaders(_corsHeaders());
};

Route::get('/backtest/range', $backtestRangeHandler);
Route::get('/api/backtest/range', function (Request $request) use ($backtestRangeHandler) {
    return $backtestRangeHandler($request);
});

$backtestRunsHandler = function (Request $request) {
    _ensureBacktestSchema();
    $symbol = strtoupper(trim((string)$request->get('symbol', '')));
    $limit = (int)$request->get('limit', 50);
    $offset = (int)$request->get('offset', 0);
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }
    if ($offset < 0) {
        $offset = 0;
    }
    $q = Db::table('backtest_runs')->orderBy('id', 'desc');
    if ($symbol !== '') {
        $q->where('symbol', $symbol);
    }
    $rows = $q->offset($offset)->limit($limit)->get([
        'id',
        'symbol',
        'mode',
        'start_ms',
        'end_ms',
        'effective_start_ms',
        'effective_end_ms',
        'summary',
        'created_at',
    ])->toArray();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r->id,
            'symbol' => (string)$r->symbol,
            'mode' => (string)$r->mode,
            'start_ms' => (int)$r->start_ms,
            'end_ms' => (int)$r->end_ms,
            'effective_start_ms' => (int)$r->effective_start_ms,
            'effective_end_ms' => (int)$r->effective_end_ms,
            'summary' => _oscJson($r->summary),
            'created_at' => $r->created_at !== null ? (string)$r->created_at : null,
        ];
    }
    return json(['ok' => true, 'rows' => $out])->withHeaders(_corsHeaders());
};

$backtestRunGetHandler = function (Request $request) {
    _ensureBacktestSchema();
    $id = (int)$request->get('id', 0);
    if ($id <= 0) {
        return json(['ok' => false, 'error' => 'missing id'])->withHeaders(_corsHeaders());
    }
    $r = Db::table('backtest_runs')->where('id', $id)->first();
    if (!$r) {
        return json(['ok' => false, 'error' => 'not found'])->withHeaders(_corsHeaders());
    }
    return json([
        'ok' => true,
        'row' => [
            'id' => (int)$r->id,
            'symbol' => (string)$r->symbol,
            'mode' => (string)$r->mode,
            'start_ms' => (int)$r->start_ms,
            'end_ms' => (int)$r->end_ms,
            'effective_start_ms' => (int)$r->effective_start_ms,
            'effective_end_ms' => (int)$r->effective_end_ms,
            'params' => _oscJson($r->params),
            'summary' => _oscJson($r->summary),
            'warnings' => _oscJson($r->warnings),
            'states' => _oscJson($r->states),
            'created_at' => $r->created_at !== null ? (string)$r->created_at : null,
        ],
    ])->withHeaders(_corsHeaders());
};

$backtestTradesHandler = function (Request $request) {
    _ensureBacktestSchema();
    $id = (int)$request->get('id', 0);
    $limit = (int)$request->get('limit', 200);
    $offset = (int)$request->get('offset', 0);
    $withPlan = (int)$request->get('with_plan', 0) === 1;
    if ($id <= 0) {
        return json(['ok' => false, 'error' => 'missing id'])->withHeaders(_corsHeaders());
    }
    if ($limit <= 0) {
        $limit = 200;
    }
    if ($limit > 2000) {
        $limit = 2000;
    }
    if ($offset < 0) {
        $offset = 0;
    }
    $rows = Db::table('backtest_trades')
        ->where('run_id', $id)
        ->orderBy('seq', 'asc')
        ->offset($offset)
        ->limit($limit)
        ->get(['seq', 'type', 'time', 'side', 'reason', 'price', 'qty', 'fee', 'pnl', 'plan_id'])
        ->toArray();
    $planMap = [];
    if ($withPlan) {
        $ids = [];
        foreach ($rows as $t) {
            if ($t->plan_id !== null) {
                $ids[(string)$t->plan_id] = (int)$t->plan_id;
            }
        }
        if (!empty($ids)) {
            $plans = Db::table('backtest_plans')
                ->where('run_id', $id)
                ->whereIn('plan_id', array_values($ids))
                ->get([
                    'plan_id',
                    'created_time',
                    'created_close_ts',
                    'active_from_ts',
                    'expires_ts',
                    'side',
                    'kind',
                    'tf_trigger',
                    'tf_plan',
                    'trigger_open_time',
                    'trigger_close_ts',
                    'plan',
                ])->toArray();
            foreach ($plans as $p) {
                $planMap[(string)$p->plan_id] = [
                    'plan_id' => (int)$p->plan_id,
                    'created_time' => (string)$p->created_time,
                    'created_close_ts' => (int)$p->created_close_ts,
                    'active_from_ts' => (int)$p->active_from_ts,
                    'expires_ts' => (int)$p->expires_ts,
                    'side' => (string)$p->side,
                    'kind' => (string)$p->kind,
                    'tf_trigger' => (string)$p->tf_trigger,
                    'tf_plan' => (string)$p->tf_plan,
                    'trigger_open_time' => (string)$p->trigger_open_time,
                    'trigger_close_ts' => (int)$p->trigger_close_ts,
                    'plan' => _oscJson($p->plan),
                ];
            }
        }
    }
    $out = [];
    foreach ($rows as $t) {
        $pid = $t->plan_id !== null ? (int)$t->plan_id : null;
        $out[] = [
            'seq' => (int)$t->seq,
            'type' => (string)$t->type,
            'time' => (string)$t->time,
            'side' => $t->side !== null ? (string)$t->side : null,
            'reason' => $t->reason !== null ? (string)$t->reason : null,
            'price' => $t->price !== null ? (float)$t->price : null,
            'qty' => $t->qty !== null ? (float)$t->qty : null,
            'fee' => $t->fee !== null ? (float)$t->fee : null,
            'pnl' => $t->pnl !== null ? (float)$t->pnl : null,
            'plan_id' => $pid,
            'plan' => ($withPlan && $pid !== null) ? ($planMap[(string)$pid] ?? null) : null,
        ];
    }
    return json(['ok' => true, 'rows' => $out])->withHeaders(_corsHeaders());
};

$backtestPlansHandler = function (Request $request) {
    _ensureBacktestSchema();
    $id = (int)$request->get('id', 0);
    $limit = (int)$request->get('limit', 200);
    $offset = (int)$request->get('offset', 0);
    if ($id <= 0) {
        return json(['ok' => false, 'error' => 'missing id'])->withHeaders(_corsHeaders());
    }
    if ($limit <= 0) {
        $limit = 200;
    }
    if ($limit > 2000) {
        $limit = 2000;
    }
    if ($offset < 0) {
        $offset = 0;
    }
    $rows = Db::table('backtest_plans')
        ->where('run_id', $id)
        ->orderBy('plan_id', 'asc')
        ->offset($offset)
        ->limit($limit)
        ->get([
            'plan_id',
            'created_time',
            'created_close_ts',
            'active_from_ts',
            'expires_ts',
            'side',
            'kind',
            'tf_trigger',
            'tf_plan',
            'trigger_open_time',
            'trigger_close_ts',
            'plan',
        ])->toArray();
    $out = [];
    foreach ($rows as $p) {
        $out[] = [
            'plan_id' => (int)$p->plan_id,
            'created_time' => (string)$p->created_time,
            'created_close_ts' => (int)$p->created_close_ts,
            'active_from_ts' => (int)$p->active_from_ts,
            'expires_ts' => (int)$p->expires_ts,
            'side' => (string)$p->side,
            'kind' => (string)$p->kind,
            'tf_trigger' => (string)$p->tf_trigger,
            'tf_plan' => (string)$p->tf_plan,
            'trigger_open_time' => (string)$p->trigger_open_time,
            'trigger_close_ts' => (int)$p->trigger_close_ts,
            'plan' => _oscJson($p->plan),
        ];
    }
    return json(['ok' => true, 'rows' => $out])->withHeaders(_corsHeaders());
};

$backtestPlanGetHandler = function (Request $request) {
    _ensureBacktestSchema();
    $id = (int)$request->get('id', 0);
    $planId = (int)$request->get('plan_id', 0);
    if ($id <= 0 || $planId <= 0) {
        return json(['ok' => false, 'error' => 'missing id/plan_id'])->withHeaders(_corsHeaders());
    }
    $p = Db::table('backtest_plans')->where('run_id', $id)->where('plan_id', $planId)->first();
    if (!$p) {
        return json(['ok' => false, 'error' => 'not found'])->withHeaders(_corsHeaders());
    }
    return json([
        'ok' => true,
        'row' => [
            'plan_id' => (int)$p->plan_id,
            'created_time' => (string)$p->created_time,
            'created_close_ts' => (int)$p->created_close_ts,
            'active_from_ts' => (int)$p->active_from_ts,
            'expires_ts' => (int)$p->expires_ts,
            'side' => (string)$p->side,
            'kind' => (string)$p->kind,
            'tf_trigger' => (string)$p->tf_trigger,
            'tf_plan' => (string)$p->tf_plan,
            'trigger_open_time' => (string)$p->trigger_open_time,
            'trigger_close_ts' => (int)$p->trigger_close_ts,
            'plan' => _oscJson($p->plan),
        ],
    ])->withHeaders(_corsHeaders());
};

$backtestEquityHandler = function (Request $request) {
    _ensureBacktestSchema();
    $id = (int)$request->get('id', 0);
    $limit = (int)$request->get('limit', 2000);
    $offset = (int)$request->get('offset', 0);
    if ($id <= 0) {
        return json(['ok' => false, 'error' => 'missing id'])->withHeaders(_corsHeaders());
    }
    if ($limit <= 0) {
        $limit = 2000;
    }
    if ($limit > 20000) {
        $limit = 20000;
    }
    if ($offset < 0) {
        $offset = 0;
    }
    $rows = Db::table('backtest_equity')
        ->where('run_id', $id)
        ->orderBy('seq', 'asc')
        ->offset($offset)
        ->limit($limit)
        ->get(['seq', 'time', 'equity'])
        ->toArray();
    $out = [];
    foreach ($rows as $pt) {
        $out[] = [
            'seq' => (int)$pt->seq,
            'time' => (string)$pt->time,
            'equity' => (float)$pt->equity,
        ];
    }
    return json(['ok' => true, 'rows' => $out])->withHeaders(_corsHeaders());
};

Route::get('/backtest/runs', $backtestRunsHandler);
Route::get('/api/backtest/runs', function (Request $request) use ($backtestRunsHandler) {
    return $backtestRunsHandler($request);
});
Route::get('/backtest/run/get', $backtestRunGetHandler);
Route::get('/api/backtest/run/get', function (Request $request) use ($backtestRunGetHandler) {
    return $backtestRunGetHandler($request);
});
Route::get('/backtest/run/trades', $backtestTradesHandler);
Route::get('/api/backtest/run/trades', function (Request $request) use ($backtestTradesHandler) {
    return $backtestTradesHandler($request);
});
Route::get('/backtest/run/plans', $backtestPlansHandler);
Route::get('/api/backtest/run/plans', function (Request $request) use ($backtestPlansHandler) {
    return $backtestPlansHandler($request);
});
Route::get('/backtest/run/plan/get', $backtestPlanGetHandler);
Route::get('/api/backtest/run/plan/get', function (Request $request) use ($backtestPlanGetHandler) {
    return $backtestPlanGetHandler($request);
});
Route::get('/backtest/run/equity', $backtestEquityHandler);
Route::get('/api/backtest/run/equity', function (Request $request) use ($backtestEquityHandler) {
    return $backtestEquityHandler($request);
});

Route::options('/market/klines', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/market/klines', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/market/backfill', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/market/backfill', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/trend/oscillation/active', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/trend/oscillation/active', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/strategy/emit', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/strategy/emit', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/range', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/range', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/runs', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/runs', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run/get', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run/get', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run/trades', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run/trades', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run/plans', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run/plans', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run/plan/get', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run/plan/get', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/backtest/run/equity', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/backtest/run/equity', fn() => response('', 204)->withHeaders(_corsHeaders()));
