<?php

namespace plugin\webman\gateway;

use support\Db;

class OscillationEngine
{
    private static ?bool $schemaReady = null;

    public static function run(
        string $symbol,
        string $interval,
        ?string $startTime = null,
        ?string $endTime = null,
        ?int $confirmBars = null,
        ?float $breakPct = null,
        ?float $breakExtremePct = null
    ): array {
        self::ensureSchema();

        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '') {
            return ['updated' => 0, 'closed' => 0];
        }

        $confirmBars = $confirmBars ?? 30;
        $breakPct = $breakPct ?? 0.05;
        $breakExtremePct = $breakExtremePct ?? 0.02;

        $active = self::getActiveStructure($symbol, $interval);
        if ($active === null) {
            $active = self::createStructure($symbol, $interval, null);
        }

        $xPoints = $active['x_points'];
        $yPoints = $active['y_points'];
        $state = $active['engine_state'] ?? [];
        $pending = $state['pending'] ?? null;
        $inbandCount = isset($state['inband_count']) ? (int)$state['inband_count'] : 0;
        $lastProcessed = isset($state['last_processed_open_time']) ? (string)$state['last_processed_open_time'] : null;

        $table = 'kline_' . $interval;
        $query = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'asc')
            ->select(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_dn']);

        if ($startTime !== null && trim($startTime) !== '') {
            $query->where('open_time', '>=', $startTime);
        }
        if ($endTime !== null && trim($endTime) !== '') {
            $query->where('open_time', '<=', $endTime);
        }
        if ($lastProcessed !== null && trim($lastProcessed) !== '') {
            $query->where('open_time', '>', $lastProcessed);
        }

        $updatedPoints = 0;
        $closedStructures = 0;
        $activeStartTime = $active['start_time'];

        foreach ($query->cursor() as $rowObj) {
            $row = self::normalizeKlineRow($rowObj);
            $lastProcessed = $row['open_time'];

            $breakInfo = self::checkBreak($row, $xPoints, $yPoints, $breakPct, $breakExtremePct);
            if ($breakInfo !== null && $active['status'] === 'ACTIVE') {
                self::closeStructure(
                    (int)$active['id'],
                    $row['open_time'],
                    'BREAK_DUAL',
                    $breakInfo,
                    $xPoints,
                    $yPoints,
                    [
                        'last_processed_open_time' => $row['open_time'],
                        'pending' => null,
                        'inband_count' => 0,
                        'confirm_bars' => $confirmBars,
                        'break_pct' => $breakPct,
                        'break_extreme_pct' => $breakExtremePct,
                    ],
                    $activeStartTime
                );
                $closedStructures++;

                $active = self::createStructure($symbol, $interval, $row['open_time']);
                $xPoints = [];
                $yPoints = [];
                $pending = null;
                $inbandCount = 0;
                $activeStartTime = $active['start_time'];
            }

            $bollDn = $row['boll_dn'];
            $bollUp = $row['boll_up'];
            if ($bollDn !== null && $row['low'] < $bollDn) {
                $pending = self::updatePending($pending, 'X', $row['open_time'], $row['low'], true);
                $inbandCount = 0;
            } elseif ($bollUp !== null && $row['high'] > $bollUp) {
                $pending = self::updatePending($pending, 'Y', $row['open_time'], $row['high'], false);
                $inbandCount = 0;
            } else {
                if ($pending !== null && self::isInBand($row)) {
                    $inbandCount++;
                    if ($inbandCount >= $confirmBars) {
                        $point = [
                            'time' => (string)$pending['time'],
                            'price' => (float)$pending['price'],
                            'kind' => (string)$pending['kind'],
                        ];
                        if ($point['kind'] === 'X') {
                            $xPoints[] = $point;
                        } else {
                            $yPoints[] = $point;
                        }
                        $updatedPoints++;

                        if ($activeStartTime === null) {
                            $activeStartTime = $point['time'];
                        }

                        $pending = null;
                        $inbandCount = 0;
                    }
                } else {
                    $inbandCount = 0;
                }
            }
        }

        $engineState = [
            'last_processed_open_time' => $lastProcessed,
            'pending' => $pending,
            'inband_count' => $inbandCount,
            'confirm_bars' => $confirmBars,
            'break_pct' => $breakPct,
            'break_extreme_pct' => $breakExtremePct,
        ];

        self::saveActiveStructure(
            (int)$active['id'],
            $xPoints,
            $yPoints,
            $engineState,
            $activeStartTime
        );

        return [
            'updated' => $updatedPoints,
            'closed' => $closedStructures,
        ];
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaReady === true) {
            return;
        }
        if (self::$schemaReady === false) {
            return;
        }

        try {
            Db::statement('CREATE TABLE IF NOT EXISTS oscillation_structures (
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
            )');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_symbol_interval_status_idx ON oscillation_structures(symbol, interval, status)');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_symbol_interval_id_idx ON oscillation_structures(symbol, interval, id DESC)');
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
    }

    private static function getActiveStructure(string $symbol, string $interval): ?array
    {
        $row = Db::table('oscillation_structures')
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('status', 'ACTIVE')
            ->orderBy('id', 'desc')
            ->first();

        if (!$row) {
            return null;
        }

        return self::normalizeStructureRow($row);
    }

    private static function createStructure(string $symbol, string $interval, ?string $startTime): array
    {
        $now = date('Y-m-d H:i:s');
        $id = Db::table('oscillation_structures')->insertGetId([
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'x_points' => self::encodeJson([]),
            'y_points' => self::encodeJson([]),
            'engine_state' => self::encodeJson([
                'last_processed_open_time' => null,
                'pending' => null,
                'inband_count' => 0,
            ]),
            'start_time' => $startTime,
            'end_time' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'x_points' => [],
            'y_points' => [],
            'engine_state' => [
                'last_processed_open_time' => null,
                'pending' => null,
                'inband_count' => 0,
            ],
            'start_time' => $startTime,
        ];
    }

    private static function closeStructure(
        int $id,
        string $endTime,
        string $reason,
        array $condition,
        array $xPoints,
        array $yPoints,
        array $engineState,
        ?string $startTime
    ): void {
        Db::table('oscillation_structures')
            ->where('id', $id)
            ->update([
                'status' => 'CLOSED',
                'close_reason' => $reason,
                'close_condition' => self::encodeJson($condition),
                'engine_state' => self::encodeJson($engineState),
                'x_points' => self::encodeJson($xPoints),
                'y_points' => self::encodeJson($yPoints),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function saveActiveStructure(
        int $id,
        array $xPoints,
        array $yPoints,
        array $engineState,
        ?string $startTime
    ): void {
        Db::table('oscillation_structures')
            ->where('id', $id)
            ->update([
                'x_points' => self::encodeJson($xPoints),
                'y_points' => self::encodeJson($yPoints),
                'engine_state' => self::encodeJson($engineState),
                'start_time' => $startTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function normalizeStructureRow(object $row): array
    {
        $xPoints = self::decodeJson($row->x_points) ?? [];
        $yPoints = self::decodeJson($row->y_points) ?? [];
        $engineState = self::decodeJson($row->engine_state) ?? null;

        return [
            'id' => (int)$row->id,
            'symbol' => (string)$row->symbol,
            'interval' => (string)$row->interval,
            'status' => (string)$row->status,
            'x_points' => is_array($xPoints) ? $xPoints : [],
            'y_points' => is_array($yPoints) ? $yPoints : [],
            'engine_state' => is_array($engineState) ? $engineState : null,
            'start_time' => isset($row->start_time) ? ($row->start_time !== null ? (string)$row->start_time : null) : null,
        ];
    }

    private static function normalizeKlineRow(object $row): array
    {
        return [
            'open_time' => (string)$row->open_time,
            'high' => (float)$row->high,
            'low' => (float)$row->low,
            'close' => (float)$row->close,
            'boll_up' => $row->boll_up !== null ? (float)$row->boll_up : null,
            'boll_dn' => $row->boll_dn !== null ? (float)$row->boll_dn : null,
        ];
    }

    private static function isInBand(array $row): bool
    {
        if ($row['boll_dn'] === null || $row['boll_up'] === null) {
            return false;
        }
        $c = (float)$row['close'];
        return $c >= (float)$row['boll_dn'] && $c <= (float)$row['boll_up'];
    }

    private static function updatePending(?array $pending, string $kind, string $time, float $price, bool $preferLower): array
    {
        if ($pending === null || !isset($pending['kind']) || (string)$pending['kind'] !== $kind) {
            return ['kind' => $kind, 'time' => $time, 'price' => $price];
        }

        $prev = (float)($pending['price'] ?? 0.0);
        if ($preferLower) {
            if ($price < $prev) {
                return ['kind' => $kind, 'time' => $time, 'price' => $price];
            }
        } else {
            if ($price > $prev) {
                return ['kind' => $kind, 'time' => $time, 'price' => $price];
            }
        }

        return $pending;
    }

    private static function checkBreak(array $row, array $xPoints, array $yPoints, float $breakPct, float $breakExtremePct): ?array
    {
        if (!empty($xPoints)) {
            $last = (float)($xPoints[count($xPoints) - 1]['price'] ?? 0.0);
            $min = $last;
            foreach ($xPoints as $p) {
                $v = (float)($p['price'] ?? 0.0);
                if ($v < $min) {
                    $min = $v;
                }
            }
            $lastThreshold = $last * (1.0 - $breakPct);
            $extremeThreshold = $min * (1.0 - $breakExtremePct);
            $low = (float)$row['low'];
            if ($low < $lastThreshold && $low < $extremeThreshold) {
                return [
                    'side' => 'X',
                    'break_price' => $low,
                    'break_open_time' => (string)$row['open_time'],
                    'last_key_price' => $last,
                    'extreme_key_price' => $min,
                    'break_last_pct' => $breakPct,
                    'break_extreme_pct' => $breakExtremePct,
                    'last_threshold' => $lastThreshold,
                    'extreme_threshold' => $extremeThreshold,
                ];
            }
        }

        if (!empty($yPoints)) {
            $last = (float)($yPoints[count($yPoints) - 1]['price'] ?? 0.0);
            $max = $last;
            foreach ($yPoints as $p) {
                $v = (float)($p['price'] ?? 0.0);
                if ($v > $max) {
                    $max = $v;
                }
            }
            $lastThreshold = $last * (1.0 + $breakPct);
            $extremeThreshold = $max * (1.0 + $breakExtremePct);
            $high = (float)$row['high'];
            if ($high > $lastThreshold && $high > $extremeThreshold) {
                return [
                    'side' => 'Y',
                    'break_price' => $high,
                    'break_open_time' => (string)$row['open_time'],
                    'last_key_price' => $last,
                    'extreme_key_price' => $max,
                    'break_last_pct' => $breakPct,
                    'break_extreme_pct' => $breakExtremePct,
                    'last_threshold' => $lastThreshold,
                    'extreme_threshold' => $extremeThreshold,
                ];
            }
        }

        return null;
    }

    private static function encodeJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeJson($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}

