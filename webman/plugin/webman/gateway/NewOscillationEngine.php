<?php

namespace plugin\webman\gateway;

use support\Db;

class NewOscillationEngine
{
    private static ?bool $schemaReady = null;

    public static function run(
        string $symbol,
        string $interval,
        ?string $startTime = null,
        ?string $endTime = null,
        ?int $confirmBars = null
    ): array {
        self::ensureSchema();

        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '') {
            return ['updated' => 0, 'closed' => 0];
        }

        $keyConfirmBars = $confirmBars ?? 30;
        $outsideConfirmBars = 30;
        $breakExtremePct = 0.01;

        $active = self::getActiveStructure($symbol, $interval);
        if ($active === null) {
            $active = self::createStructure($symbol, $interval, null);
        }

        $xPoints = self::normalizeAndLabelPoints($active['x_points'], 'X');
        $yPoints = self::normalizeAndLabelPoints($active['y_points'], 'Y');
        $state = $active['engine_state'] ?? [];
        $pending = isset($state['pending']) && is_array($state['pending']) ? $state['pending'] : null;
        $inbandCount = isset($state['inband_count']) ? (int)$state['inband_count'] : 0;
        $midTouched = isset($state['mid_touched']) ? (bool)$state['mid_touched'] : false;
        $outsideStreak = isset($state['outside_streak']) ? (int)$state['outside_streak'] : 0;
        $outsideSide = isset($state['outside_side']) ? (string)$state['outside_side'] : null;
        $outsideBreak = isset($state['outside_break']) && is_array($state['outside_break']) ? $state['outside_break'] : null;
        $outsideStartTime = isset($state['outside_start_time']) ? (string)$state['outside_start_time'] : null;
        $outsideStartBollUp = isset($state['outside_start_boll_up']) ? (float)$state['outside_start_boll_up'] : null;
        $outsideStartBollDn = isset($state['outside_start_boll_dn']) ? (float)$state['outside_start_boll_dn'] : null;
        $outsideBandWidth = isset($state['outside_band_width']) ? (float)$state['outside_band_width'] : null;
        $outsideExtreme = isset($state['outside_extreme']) ? (float)$state['outside_extreme'] : null;
        $outsideMove = isset($state['outside_move']) ? (float)$state['outside_move'] : null;
        $lastProcessed = isset($state['last_processed_open_time']) ? (string)$state['last_processed_open_time'] : null;
        $breakExtremePct = isset($state['break_extreme_pct']) ? (float)$state['break_extreme_pct'] : $breakExtremePct;

        $table = 'kline_' . $interval;
        $query = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'asc')
            ->select(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_mb', 'boll_dn']);

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

            $side = self::outsideSide($row);
            if ($side !== null) {
                if ($outsideSide === null || $outsideSide !== $side) {
                    $outsideSide = $side;
                    $outsideStreak = 1;
                    $outsideBreak = null;
                    $outsideStartTime = $row['open_time'];
                    if ($row['boll_up'] !== null && $row['boll_dn'] !== null) {
                        $outsideStartBollUp = (float)$row['boll_up'];
                        $outsideStartBollDn = (float)$row['boll_dn'];
                        $outsideBandWidth = $outsideStartBollUp - $outsideStartBollDn;
                    } else {
                        $outsideStartBollUp = null;
                        $outsideStartBollDn = null;
                        $outsideBandWidth = null;
                    }
                    $outsideExtreme = $side === 'UP' ? (float)$row['high'] : (float)$row['low'];
                    $outsideMove = null;
                } else {
                    $outsideStreak++;
                }
            } else {
                $outsideSide = null;
                $outsideStreak = 0;
                $outsideBreak = null;
                $outsideStartTime = null;
                $outsideStartBollUp = null;
                $outsideStartBollDn = null;
                $outsideBandWidth = null;
                $outsideExtreme = null;
                $outsideMove = null;
            }

            if ($outsideSide !== null) {
                if ($outsideSide === 'UP') {
                    $outsideExtreme = $outsideExtreme === null ? (float)$row['high'] : max((float)$outsideExtreme, (float)$row['high']);
                    if ($outsideStartBollUp !== null) {
                        $outsideMove = (float)$outsideExtreme - (float)$outsideStartBollUp;
                    } else {
                        $outsideMove = null;
                    }
                } else {
                    $outsideExtreme = $outsideExtreme === null ? (float)$row['low'] : min((float)$outsideExtreme, (float)$row['low']);
                    if ($outsideStartBollDn !== null) {
                        $outsideMove = (float)$outsideStartBollDn - (float)$outsideExtreme;
                    } else {
                        $outsideMove = null;
                    }
                }
            }

            $activeHasKeypoints = !empty($xPoints) || !empty($yPoints);
            if ($outsideSide !== null && $active['status'] === 'ACTIVE' && $activeHasKeypoints) {
                $sideReady = ($outsideSide === 'UP' && count($yPoints) > 0) || ($outsideSide === 'DN' && count($xPoints) > 0);
                $ampOk = $outsideBandWidth !== null && $outsideMove !== null && (float)$outsideMove > ((float)$outsideBandWidth * 0.5);
                if ($sideReady && $outsideStreak >= $outsideConfirmBars && $ampOk) {
                    self::closeStructure(
                        (int)$active['id'],
                        $row['open_time'],
                        'BREAK_OUTSIDE_N',
                        [
                            'outside_side' => $outsideSide,
                            'confirm_bars' => $outsideConfirmBars,
                            'key_confirm_bars' => $keyConfirmBars,
                            'outside_streak' => $outsideStreak,
                            'required_keypoint' => $outsideSide === 'UP' ? 'Y1' : 'X1',
                            'outside_start_time' => $outsideStartTime,
                            'start_boll_up' => $outsideStartBollUp,
                            'start_boll_dn' => $outsideStartBollDn,
                            'band_width' => $outsideBandWidth,
                            'band_width_threshold' => $outsideBandWidth !== null ? ((float)$outsideBandWidth * 0.5) : null,
                            'outside_extreme' => $outsideExtreme,
                            'outside_move' => $outsideMove,
                        ],
                        $xPoints,
                        $yPoints,
                        [
                            'last_processed_open_time' => $row['open_time'],
                            'outside_side' => $outsideSide,
                            'outside_streak' => $outsideStreak,
                            'outside_break' => null,
                            'outside_start_time' => $outsideStartTime,
                            'outside_start_boll_up' => $outsideStartBollUp,
                            'outside_start_boll_dn' => $outsideStartBollDn,
                            'outside_band_width' => $outsideBandWidth,
                            'outside_extreme' => $outsideExtreme,
                            'outside_move' => $outsideMove,
                            'pending' => null,
                            'inband_count' => 0,
                            'mid_touched' => false,
                            'confirm_bars' => $keyConfirmBars,
                            'outside_confirm_bars' => $outsideConfirmBars,
                            'break_extreme_pct' => $breakExtremePct,
                        ],
                        $activeStartTime
                    );
                    $closedStructures++;

                    $active = self::createStructure($symbol, $interval, $row['open_time']);
                    $xPoints = [];
                    $yPoints = [];
                    $pending = [
                        'kind' => $outsideSide === 'UP' ? 'Y' : 'X',
                        'time' => $row['open_time'],
                        'price' => $outsideSide === 'UP' ? (float)$row['high'] : (float)$row['low'],
                    ];
                    $inbandCount = 0;
                    $midTouched = false;
                    $outsideBreak = null;
                    $outsideStartTime = null;
                    $outsideStartBollUp = null;
                    $outsideStartBollDn = null;
                    $outsideBandWidth = null;
                    $outsideExtreme = null;
                    $outsideMove = null;
                    $activeStartTime = $active['start_time'];
                }
            }

            $bollDn = $row['boll_dn'];
            $bollUp = $row['boll_up'];
            if ($bollDn !== null && $row['low'] < $bollDn) {
                $pending = self::updatePending($pending, 'X', $row['open_time'], $row['low'], true);
                $inbandCount = 0;
                $midTouched = false;
            } elseif ($bollUp !== null && $row['high'] > $bollUp) {
                $pending = self::updatePending($pending, 'Y', $row['open_time'], $row['high'], false);
                $inbandCount = 0;
                $midTouched = false;
            } else {
                if ($pending === null) {
                    $inbandCount = 0;
                    $midTouched = false;
                    continue;
                }

                $kind = (string)$pending['kind'];
                $isFirstOfKind = ($kind === 'X' && count($xPoints) === 0) || ($kind === 'Y' && count($yPoints) === 0);

                if ($isFirstOfKind) {
                    if (!self::isInBand($row)) {
                        $inbandCount = 0;
                        $midTouched = false;
                        continue;
                    }
                    $inbandCount++;
                    if (!$midTouched && self::touchedMiddleBand($row)) {
                        $midTouched = true;
                    }
                    if ($inbandCount < $keyConfirmBars) {
                        continue;
                    }
                    if (!$midTouched) {
                        continue;
                    }
                } else {
                    if (!self::touchedMiddleBand($row)) {
                        continue;
                    }
                }

                $point = [
                    'time' => (string)$pending['time'],
                    'price' => (float)$pending['price'],
                    'kind' => $kind,
                ];
                if ($point['kind'] === 'X') {
                    $point['label'] = 'X' . (string)(count($xPoints) + 1);
                    $xPoints[] = $point;
                } else {
                    $point['label'] = 'Y' . (string)(count($yPoints) + 1);
                    $yPoints[] = $point;
                }
                $updatedPoints++;

                if ($activeStartTime === null) {
                    $activeStartTime = $point['time'];
                }

                $pending = null;
                $inbandCount = 0;
                $midTouched = false;
            }
        }

        $engineState = [
            'last_processed_open_time' => $lastProcessed,
            'outside_side' => $outsideSide,
            'outside_streak' => $outsideStreak,
            'outside_break' => $outsideBreak,
            'outside_start_time' => $outsideStartTime,
            'outside_start_boll_up' => $outsideStartBollUp,
            'outside_start_boll_dn' => $outsideStartBollDn,
            'outside_band_width' => $outsideBandWidth,
            'outside_extreme' => $outsideExtreme,
            'outside_move' => $outsideMove,
            'pending' => $pending,
            'inband_count' => $inbandCount,
            'mid_touched' => $midTouched,
            'confirm_bars' => $keyConfirmBars,
            'outside_confirm_bars' => $outsideConfirmBars,
            'break_extreme_pct' => $breakExtremePct,
        ];

        $xPoints = self::normalizeAndLabelPoints($xPoints, 'X');
        $yPoints = self::normalizeAndLabelPoints($yPoints, 'Y');
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
            Db::statement('CREATE TABLE IF NOT EXISTS oscillation_structures_v2 (
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
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v2_symbol_interval_status_idx ON oscillation_structures_v2(symbol, interval, status)');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v2_symbol_interval_id_idx ON oscillation_structures_v2(symbol, interval, id DESC)');
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
    }

    private static function getActiveStructure(string $symbol, string $interval): ?array
    {
        $row = Db::table('oscillation_structures_v2')
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
        $id = Db::table('oscillation_structures_v2')->insertGetId([
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'x_points' => self::encodeJson([]),
            'y_points' => self::encodeJson([]),
            'engine_state' => self::encodeJson([
                'last_processed_open_time' => null,
                'outside_side' => null,
                'outside_streak' => 0,
                'outside_break' => null,
                'outside_start_time' => null,
                'outside_start_boll_up' => null,
                'outside_start_boll_dn' => null,
                'outside_band_width' => null,
                'outside_extreme' => null,
                'outside_move' => null,
                'pending' => null,
                'inband_count' => 0,
                'mid_touched' => false,
                'confirm_bars' => 30,
                'outside_confirm_bars' => 30,
                'break_extreme_pct' => 0.01,
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
                'outside_side' => null,
                'outside_streak' => 0,
                'outside_break' => null,
                'outside_start_time' => null,
                'outside_start_boll_up' => null,
                'outside_start_boll_dn' => null,
                'outside_band_width' => null,
                'outside_extreme' => null,
                'outside_move' => null,
                'pending' => null,
                'inband_count' => 0,
                'mid_touched' => false,
                'confirm_bars' => 30,
                'outside_confirm_bars' => 30,
                'break_extreme_pct' => 0.01,
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
        $xPoints = self::normalizeAndLabelPoints($xPoints, 'X');
        $yPoints = self::normalizeAndLabelPoints($yPoints, 'Y');
        Db::table('oscillation_structures_v2')
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
        $xPoints = self::normalizeAndLabelPoints($xPoints, 'X');
        $yPoints = self::normalizeAndLabelPoints($yPoints, 'Y');
        Db::table('oscillation_structures_v2')
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
        $xPoints = self::normalizeAndLabelPoints(is_array($xPoints) ? $xPoints : [], 'X');
        $yPoints = self::normalizeAndLabelPoints(is_array($yPoints) ? $yPoints : [], 'Y');
        $engineState = self::decodeJson($row->engine_state) ?? null;

        return [
            'id' => (int)$row->id,
            'symbol' => (string)$row->symbol,
            'interval' => (string)$row->interval,
            'status' => (string)$row->status,
            'x_points' => $xPoints,
            'y_points' => $yPoints,
            'engine_state' => is_array($engineState) ? $engineState : null,
            'start_time' => isset($row->start_time) ? ($row->start_time !== null ? (string)$row->start_time : null) : null,
        ];
    }

    private static function normalizeAndLabelPoints(array $points, string $kind): array
    {
        $out = [];
        foreach ($points as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!isset($p['time']) || !isset($p['price'])) {
                continue;
            }
            $time = (string)$p['time'];
            $price = (float)$p['price'];
            if ($time === '' || !is_finite($price)) {
                continue;
            }
            $row = $p;
            $row['time'] = $time;
            $row['price'] = $price;
            $row['kind'] = $kind;
            $out[] = $row;
        }

        $i = 1;
        foreach ($out as &$p) {
            $p['label'] = $kind . (string)$i;
            $i++;
        }
        unset($p);

        return $out;
    }

    private static function normalizeKlineRow(object $row): array
    {
        return [
            'open_time' => (string)$row->open_time,
            'high' => (float)$row->high,
            'low' => (float)$row->low,
            'close' => (float)$row->close,
            'boll_up' => $row->boll_up !== null ? (float)$row->boll_up : null,
            'boll_mb' => $row->boll_mb !== null ? (float)$row->boll_mb : null,
            'boll_dn' => $row->boll_dn !== null ? (float)$row->boll_dn : null,
        ];
    }

    private static function touchedMiddleBand(array $row): bool
    {
        if ($row['boll_mb'] === null) {
            return false;
        }
        $mb = (float)$row['boll_mb'];
        $lo = (float)$row['low'];
        $hi = (float)$row['high'];
        return $lo <= $mb && $hi >= $mb;
    }

    private static function isInBand(array $row): bool
    {
        if ($row['boll_dn'] === null || $row['boll_up'] === null) {
            return false;
        }
        $c = (float)$row['close'];
        return $c >= (float)$row['boll_dn'] && $c <= (float)$row['boll_up'];
    }

    private static function outsideSide(array $row): ?string
    {
        if ($row['boll_dn'] === null || $row['boll_up'] === null) {
            return null;
        }
        $c = (float)$row['close'];
        if ($c > (float)$row['boll_up']) {
            return 'UP';
        }
        if ($c < (float)$row['boll_dn']) {
            return 'DN';
        }
        return null;
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

    private static function extremeKeyPriceForSide(?string $outsideSide, array $xPoints, array $yPoints): ?float
    {
        if ($outsideSide === 'UP') {
            $max = null;
            foreach ($yPoints as $p) {
                if (!is_array($p) || !isset($p['price'])) {
                    continue;
                }
                $v = (float)$p['price'];
                if (!is_finite($v)) {
                    continue;
                }
                if ($max === null || $v > $max) {
                    $max = $v;
                }
            }
            return $max;
        }
        if ($outsideSide === 'DN') {
            $min = null;
            foreach ($xPoints as $p) {
                if (!is_array($p) || !isset($p['price'])) {
                    continue;
                }
                $v = (float)$p['price'];
                if (!is_finite($v)) {
                    continue;
                }
                if ($min === null || $v < $min) {
                    $min = $v;
                }
            }
            return $min;
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
