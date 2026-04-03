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

Route::get('/market/klines', $marketKlinesHandler);

Route::get('/api/market/klines', function (Request $request) use ($marketKlinesHandler) {
    return $marketKlinesHandler($request);
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

Route::options('/market/klines', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/market/klines', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/trend/oscillation/active', fn() => response('', 204)->withHeaders(_corsHeaders()));
Route::options('/api/trend/oscillation/active', fn() => response('', 204)->withHeaders(_corsHeaders()));
