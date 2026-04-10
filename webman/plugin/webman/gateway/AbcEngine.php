<?php

namespace plugin\webman\gateway;

use support\Db;

class AbcEngine
{
    private static ?bool $schemaReady = null;

    public static function run(
        string $symbol,
        string $interval,
        ?string $startTime = null,
        ?string $endTime = null,
        ?int $bBreakRefreshTimes = null,
        ?float $bRefreshEps = null,
        ?int $outsideBars = null,
        ?int $slopeWindow = null,
        ?float $slopePct = null,
        ?float $cBreakAPct = null,
        ?float $impulseBodyRatio = null,
        ?float $impulseRangeRatio = null,
        ?float $impulseBodyToRangeRatio = null,
        ?int $impulseN = null,
        ?float $impulseNRatio = null,
        ?float $impulseBullRatioMin = null,
        ?int $kpConfirmBars = null
    ): array {
        self::ensureSchema();

        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '') {
            return ['updated' => 0, 'closed' => 0];
        }

        $bBreakRefreshTimes = $bBreakRefreshTimes ?? 3;
        $bRefreshEps = $bRefreshEps ?? 1.0;
        $outsideBars = $outsideBars ?? 20;
        $slopeWindow = $slopeWindow ?? 50;
        $slopePct = $slopePct ?? 0.002;
        $cBreakAPct = $cBreakAPct ?? 0.01;
        $impulseBodyRatio = $impulseBodyRatio ?? 0.8;
        $impulseRangeRatio = $impulseRangeRatio ?? 1.2;
        $impulseBodyToRangeRatio = $impulseBodyToRangeRatio ?? 0.6;
        $impulseN = $impulseN ?? 3;
        $impulseNRatio = $impulseNRatio ?? 1.2;
        $impulseBullRatioMin = $impulseBullRatioMin ?? 0.6;
        $kpConfirmBars = $kpConfirmBars ?? 30;

        $active = self::getActiveStructure($symbol, $interval);
        if ($active === null) {
            $active = self::createStructure($symbol, $interval, null);
        }

        $state = $active['engine_state'] ?? [];
        $lastProcessed = isset($state['last_processed_open_time']) ? (string)$state['last_processed_open_time'] : null;
        $mode = isset($state['mode']) ? (string)$state['mode'] : 'S0';
        $direction = isset($state['direction']) ? (string)$state['direction'] : null;
        $aTemp = isset($state['a_temp']) && is_array($state['a_temp']) ? $state['a_temp'] : null;
        $aPoint = $active['a_point'];
        $aConfirmTime = $active['a_confirm_time'];
        $bTemp = isset($state['b_temp']) && is_array($state['b_temp']) ? $state['b_temp'] : null;
        $bPoints = $active['b_points'];
        $cPoints = $active['c_points'];
        $touchedOpposite = isset($state['touched_opposite']) ? (bool)$state['touched_opposite'] : false;
        $outsideStreak = isset($state['outside_streak']) ? (int)$state['outside_streak'] : 0;
        $dnHist = isset($state['dn_hist']) && is_array($state['dn_hist']) ? $state['dn_hist'] : [];
        $upHist = isset($state['up_hist']) && is_array($state['up_hist']) ? $state['up_hist'] : [];
        $mbHist = isset($state['mb_hist']) && is_array($state['mb_hist']) ? $state['mb_hist'] : [];
        $impulseBuf = isset($state['impulse_buf']) && is_array($state['impulse_buf']) ? $state['impulse_buf'] : [];
        $kpPending = isset($state['kp_pending']) && is_array($state['kp_pending']) ? $state['kp_pending'] : null;
        $kpInbandCount = isset($state['kp_inband_count']) ? (int)$state['kp_inband_count'] : 0;
        $kpXn = isset($state['kp_x_n']) ? (int)$state['kp_x_n'] : 0;
        $kpYn = isset($state['kp_y_n']) ? (int)$state['kp_y_n'] : 0;
        $bBreak = isset($state['b_break']) && is_array($state['b_break']) ? $state['b_break'] : [
            'count' => 0,
            'min' => null,
        ];

        $table = 'kline_' . $interval;
        $query = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'asc')
            ->select(['open_time', 'open', 'high', 'low', 'close', 'boll_up', 'boll_mb', 'boll_dn']);

        if ($startTime !== null && trim($startTime) !== '') {
            $query->where('open_time', '>=', $startTime);
        }
        if ($endTime !== null && trim($endTime) !== '') {
            $query->where('open_time', '<=', $endTime);
        }
        if ($lastProcessed !== null && trim($lastProcessed) !== '') {
            $query->where('open_time', '>', $lastProcessed);
        }

        $updated = 0;
        $closed = 0;
        $activeStartTime = $active['start_time'];

        foreach ($query->cursor() as $rowObj) {
            $row = self::normalizeKlineRow($rowObj);
            $lastProcessed = $row['open_time'];

            self::pushHist($dnHist, $row['boll_dn'], 220);
            self::pushHist($upHist, $row['boll_up'], 220);
            self::pushHist($mbHist, $row['boll_mb'], 220);
            self::pushImpulseBuf($impulseBuf, $row, 10);

            $inBand = self::isInBand($row);
            $touchMb = self::touchesMb($row);
            $touchUp = self::touchesUp($row);
            $touchDn = self::touchesDn($row);

            $kpEvent = null;
            if ($row['boll_dn'] !== null && (float)$row['low'] < (float)$row['boll_dn']) {
                $kpPending = self::updateKpPending($kpPending, 'X', (string)$row['open_time'], (float)$row['low']);
                $kpInbandCount = 0;
            } elseif ($row['boll_up'] !== null && (float)$row['high'] > (float)$row['boll_up']) {
                $kpPending = self::updateKpPending($kpPending, 'Y', (string)$row['open_time'], (float)$row['high']);
                $kpInbandCount = 0;
            } else {
                if ($kpPending !== null && $inBand) {
                    $kpInbandCount++;
                    if ($kpInbandCount >= $kpConfirmBars) {
                        $kind = (string)($kpPending['kind'] ?? '');
                        if ($kind === 'X') {
                            $kpXn++;
                            $label = 'X' . (string)$kpXn;
                        } else {
                            $kpYn++;
                            $label = 'Y' . (string)$kpYn;
                        }
                        $kpEvent = [
                            'time' => (string)($kpPending['time'] ?? ''),
                            'price' => (float)($kpPending['price'] ?? 0.0),
                            'kind' => $kind,
                            'label' => $label,
                            'source' => 'KLINE',
                        ];
                        $kpPending = null;
                        $kpInbandCount = 0;
                    }
                } else {
                    $kpInbandCount = 0;
                }
            }

            if ($kpEvent !== null) {
                if ($mode === 'S0') {
                    [$direction, $aTemp] = self::applyAKeypoint($direction, $aTemp, $kpEvent);
                } elseif ($mode === 'S1') {
                    if ($aConfirmTime !== null && strcmp((string)$kpEvent['time'], (string)$aConfirmTime) > 0) {
                        [$bTemp, $bBreak] = self::applyBKeypoint($direction, $bTemp, $bBreak, $kpEvent, $bRefreshEps);
                        if ($direction !== null && self::isOppositeKeypoint($direction, (string)$kpEvent['kind'])) {
                            $touchedOpposite = true;
                        }
                    }
                }
            }

            if (($mode === 'S1' || $mode === 'S2') && $direction !== null) {
                $imp = self::checkImpulseBreak(
                    $direction,
                    $row,
                    $impulseBuf,
                    $impulseBodyRatio,
                    $impulseRangeRatio,
                    $impulseBodyToRangeRatio,
                    $impulseN,
                    $impulseNRatio,
                    $impulseBullRatioMin
                );
                if ($imp !== null) {
                    self::closeStructure(
                        (int)$active['id'],
                        $row['open_time'],
                        'IMPULSE_BREAK',
                        [
                            'mode' => $mode,
                            'direction' => $direction,
                            'impulse' => $imp,
                        ],
                        $aPoint,
                        $bPoints,
                        $cPoints,
                        [
                            'last_processed_open_time' => $row['open_time'],
                            'mode' => 'S0',
                            'direction' => null,
                            'a_temp' => null,
                            'b_temp' => null,
                            'touched_opposite' => false,
                            'outside_streak' => 0,
                            'dn_hist' => $dnHist,
                            'up_hist' => $upHist,
                            'mb_hist' => $mbHist,
                            'impulse_buf' => $impulseBuf,
                            'kp_pending' => $kpPending,
                            'kp_inband_count' => $kpInbandCount,
                            'kp_x_n' => $kpXn,
                            'kp_y_n' => $kpYn,
                            'kp_confirm_bars' => $kpConfirmBars,
                            'b_break' => ['count' => 0, 'min' => null],
                            'params' => self::paramsState(
                                $bBreakRefreshTimes,
                                $bRefreshEps,
                                $outsideBars,
                                $slopeWindow,
                                $slopePct,
                                $cBreakAPct,
                                $impulseBodyRatio,
                                $impulseRangeRatio,
                                $impulseBodyToRangeRatio,
                                $impulseN,
                                $impulseNRatio,
                                $impulseBullRatioMin
                            ),
                        ],
                        $activeStartTime,
                        $aConfirmTime
                    );
                    $closed++;

                    $active = self::createStructure($symbol, $interval, $row['open_time']);
                    $mode = 'S0';
                    $direction = null;
                    $aTemp = null;
                    $aPoint = null;
                    $aConfirmTime = null;
                    $bTemp = null;
                    $bPoints = [];
                    $cPoints = [];
                    $touchedOpposite = false;
                    $outsideStreak = 0;
                    $activeStartTime = $active['start_time'];
                    $bBreak = ['count' => 0, 'min' => null];
                    $updated++;
                    continue;
                }
            }

            if ($mode === 'S0') {
                if ($aTemp !== null && $inBand && $touchMb && strcmp((string)$row['open_time'], (string)$aTemp['time']) > 0) {
                    $direction = (string)$aTemp['direction'];
                    $aPoint = self::buildPointFromTemp($aTemp, 'A');
                    $aConfirmTime = (string)$row['open_time'];
                    $activeStartTime = $activeStartTime ?? (string)$aPoint['time'];
                    $mode = 'S1';
                    $bTemp = null;
                    $bPoints = [];
                    $cPoints = [];
                    $touchedOpposite = false;
                    $outsideStreak = 0;
                    $bBreak = ['count' => 0, 'min' => null];
                    $updated++;
                }
            } elseif ($mode === 'S1') {
                if ($direction !== null && !$touchedOpposite) {
                    if ($direction === 'UP' && $touchDn) {
                        $touchedOpposite = true;
                    }
                    if ($direction === 'DN' && $touchUp) {
                        $touchedOpposite = true;
                    }
                }

                if ($direction !== null && !$touchedOpposite) {
                    if (($direction === 'UP' && $touchUp) || ($direction === 'DN' && $touchDn)) {
                        $mode = 'S0';
                        $direction = null;
                        $aTemp = null;
                        $aPoint = null;
                        $aConfirmTime = null;
                        $bTemp = null;
                        $bPoints = [];
                        $cPoints = [];
                        $outsideStreak = 0;
                        $bBreak = ['count' => 0, 'min' => null];
                        $updated++;
                        continue;
                    }
                }

                if ($bTemp === null && empty($bPoints) && $inBand && $touchMb && $aConfirmTime !== null && strcmp((string)$row['open_time'], (string)$aConfirmTime) > 0) {
                    $bTemp = [
                        'time' => (string)$row['open_time'],
                        'price' => (float)$row['close'],
                        'source' => 'MB_TOUCH',
                    ];
                    $bBreak = ['count' => 0, 'min' => null];
                    $updated++;
                }

                if ($bTemp !== null) {
                    if ($direction === 'UP') {
                        $bBreak = self::updateBreakMin($bBreak, (float)$row['low'], $bRefreshEps);
                    } elseif ($direction === 'DN') {
                        $bBreak = self::updateBreakMax($bBreak, (float)$row['high'], $bRefreshEps);
                    }

                    if ($bBreak['count'] >= $bBreakRefreshTimes) {
                        self::closeStructure(
                            (int)$active['id'],
                            $row['open_time'],
                            'B_BREAK_REFRESH',
                            [
                                'direction' => $direction,
                                'refresh_times' => $bBreak['count'],
                                'refresh_threshold' => $bBreakRefreshTimes,
                                'eps' => $bRefreshEps,
                                'b_temp' => $bTemp,
                            ],
                            $aPoint,
                            $bPoints,
                            $cPoints,
                            [
                                'last_processed_open_time' => $row['open_time'],
                                'mode' => 'S0',
                                'direction' => null,
                                'a_temp' => null,
                                'b_temp' => null,
                                'touched_opposite' => false,
                                'outside_streak' => 0,
                                'dn_hist' => $dnHist,
                                'up_hist' => $upHist,
                                'mb_hist' => $mbHist,
                                'impulse_buf' => $impulseBuf,
                                'kp_pending' => $kpPending,
                                'kp_inband_count' => $kpInbandCount,
                                'kp_x_n' => $kpXn,
                                'kp_y_n' => $kpYn,
                                'kp_confirm_bars' => $kpConfirmBars,
                                'b_break' => ['count' => 0, 'min' => null],
                                'params' => self::paramsState($bBreakRefreshTimes, $bRefreshEps, $outsideBars, $slopeWindow, $slopePct, $cBreakAPct, $impulseBodyRatio, $impulseRangeRatio, $impulseBodyToRangeRatio, $impulseN, $impulseNRatio, $impulseBullRatioMin),
                            ],
                            $activeStartTime,
                            $aConfirmTime
                        );
                        $closed++;

                        $active = self::createStructure($symbol, $interval, $row['open_time']);
                        $state = $active['engine_state'] ?? [];
                        $mode = 'S0';
                        $direction = null;
                        $aTemp = null;
                        $aPoint = null;
                        $aConfirmTime = null;
                        $bTemp = null;
                        $bPoints = [];
                        $cPoints = [];
                        $touchedOpposite = false;
                        $outsideStreak = 0;
                        $activeStartTime = $active['start_time'];
                        $bBreak = ['count' => 0, 'min' => null];
                        continue;
                    }

                    if ($inBand && $touchMb) {
                        $bPoints[] = self::buildBPointFromTemp($bTemp, (string)$row['open_time']);
                        $bTemp = null;
                        $outsideStreak = 0;
                        $bBreak = ['count' => 0, 'min' => null];
                        $updated++;
                    }
                }

                if ($direction !== null) {
                    $outsideNow = false;
                    if ($direction === 'UP') {
                        $outsideNow = $row['boll_dn'] !== null && (float)$row['close'] < (float)$row['boll_dn'];
                    } else {
                        $outsideNow = $row['boll_up'] !== null && (float)$row['close'] > (float)$row['boll_up'];
                    }
                    if ($outsideNow) {
                        $outsideStreak++;
                    } else {
                        $outsideStreak = 0;
                    }

                    if ($outsideStreak >= $outsideBars) {
                        $slopeInfo = self::slopeInfoForBreak($direction, $dnHist, $upHist, $mbHist, $slopeWindow);
                        if ($slopeInfo !== null && $slopeInfo['slope_pct'] !== null) {
                            $ok = false;
                            if ($direction === 'UP') {
                                $ok = $slopeInfo['slope_pct'] < -abs($slopePct);
                            } else {
                                $ok = $slopeInfo['slope_pct'] > abs($slopePct);
                            }
                            if ($ok) {
                                self::closeStructure(
                                    (int)$active['id'],
                                    $row['open_time'],
                                    'B_BREAK_SLOPE',
                                    [
                                        'direction' => $direction,
                                        'outside_streak' => $outsideStreak,
                                        'outside_bars' => $outsideBars,
                                        'slope_window' => $slopeWindow,
                                        'slope_pct_threshold' => $slopePct,
                                        'slope' => $slopeInfo,
                                    ],
                                    $aPoint,
                                    $bPoints,
                                    $cPoints,
                                    [
                                        'last_processed_open_time' => $row['open_time'],
                                        'mode' => 'S0',
                                        'direction' => null,
                                        'a_temp' => null,
                                        'b_temp' => null,
                                        'touched_opposite' => false,
                                        'outside_streak' => 0,
                                        'dn_hist' => $dnHist,
                                        'up_hist' => $upHist,
                                        'mb_hist' => $mbHist,
                                        'impulse_buf' => $impulseBuf,
                                        'kp_pending' => $kpPending,
                                        'kp_inband_count' => $kpInbandCount,
                                        'kp_x_n' => $kpXn,
                                        'kp_y_n' => $kpYn,
                                        'kp_confirm_bars' => $kpConfirmBars,
                                        'b_break' => ['count' => 0, 'min' => null],
                                        'params' => self::paramsState($bBreakRefreshTimes, $bRefreshEps, $outsideBars, $slopeWindow, $slopePct, $cBreakAPct, $impulseBodyRatio, $impulseRangeRatio, $impulseBodyToRangeRatio, $impulseN, $impulseNRatio, $impulseBullRatioMin),
                                    ],
                                    $activeStartTime,
                                    $aConfirmTime
                                );
                                $closed++;

                                $active = self::createStructure($symbol, $interval, $row['open_time']);
                                $mode = 'S0';
                                $direction = null;
                                $aTemp = null;
                                $aPoint = null;
                                $aConfirmTime = null;
                                $bTemp = null;
                                $bPoints = [];
                                $cPoints = [];
                                $touchedOpposite = false;
                                $outsideStreak = 0;
                                $activeStartTime = $active['start_time'];
                                $bBreak = ['count' => 0, 'min' => null];
                                continue;
                            }
                        }
                    }
                }

                $canC = !empty($bPoints) || ($bTemp !== null);
                if ($direction !== null && $canC) {
                    if ($direction === 'UP' && $touchUp) {
                        $cPoints[] = self::buildCPointFromRow($row, 'UP');
                        $mode = 'S2';
                        $updated++;
                    } elseif ($direction === 'DN' && $touchDn) {
                        $cPoints[] = self::buildCPointFromRow($row, 'DN');
                        $mode = 'S2';
                        $updated++;
                    }
                }
            } elseif ($mode === 'S2') {
                if ($direction !== null && $inBand && $touchMb) {
                    $lastC = self::lastPoint($cPoints);
                    self::closeStructure(
                        (int)$active['id'],
                        $row['open_time'],
                        'C_RETRACE_MB',
                        [
                            'direction' => $direction,
                            'c_point' => $lastC,
                        ],
                        $aPoint,
                        $bPoints,
                        $cPoints,
                        [
                            'last_processed_open_time' => $row['open_time'],
                            'mode' => 'S1',
                            'direction' => $direction,
                            'a_temp' => null,
                            'b_temp' => null,
                            'touched_opposite' => false,
                            'outside_streak' => 0,
                            'dn_hist' => $dnHist,
                            'up_hist' => $upHist,
                            'mb_hist' => $mbHist,
                            'impulse_buf' => $impulseBuf,
                            'kp_pending' => $kpPending,
                            'kp_inband_count' => $kpInbandCount,
                            'kp_x_n' => $kpXn,
                            'kp_y_n' => $kpYn,
                            'kp_confirm_bars' => $kpConfirmBars,
                            'b_break' => ['count' => 0, 'min' => null],
                            'params' => self::paramsState($bBreakRefreshTimes, $bRefreshEps, $outsideBars, $slopeWindow, $slopePct, $cBreakAPct, $impulseBodyRatio, $impulseRangeRatio, $impulseBodyToRangeRatio, $impulseN, $impulseNRatio, $impulseBullRatioMin),
                        ],
                        $activeStartTime,
                        $aConfirmTime
                    );
                    $closed++;

                    $active = self::createStructure($symbol, $interval, $row['open_time']);
                    $aPoint = $lastC !== null ? self::mapCToNextA($lastC) : null;
                    $aConfirmTime = $aPoint !== null ? (string)$row['open_time'] : null;
                    $mode = $aPoint !== null ? 'S1' : 'S0';
                    $direction = $aPoint !== null ? $direction : null;
                    $aTemp = null;
                    $bTemp = null;
                    $bPoints = [];
                    $cPoints = [];
                    $touchedOpposite = false;
                    $outsideStreak = 0;
                    $activeStartTime = $active['start_time'] ?? ($aPoint !== null ? (string)$aPoint['time'] : null);
                    $bBreak = ['count' => 0, 'min' => null];
                    $updated++;
                    continue;
                }

                if ($aPoint !== null && $direction !== null) {
                    $aPrice = (float)$aPoint['price'];
                    $end = false;
                    if ($direction === 'UP') {
                        if ((float)$row['high'] > $aPrice * (1.0 + $cBreakAPct)) {
                            $end = true;
                        }
                    } else {
                        if ((float)$row['low'] < $aPrice * (1.0 - $cBreakAPct)) {
                            $end = true;
                        }
                    }
                    if ($end) {
                        self::closeStructure(
                            (int)$active['id'],
                            $row['open_time'],
                            'C_BREAK_A',
                            [
                                'direction' => $direction,
                                'a_price' => $aPrice,
                                'break_pct' => $cBreakAPct,
                                'bar' => [
                                    'open_time' => $row['open_time'],
                                    'high' => $row['high'],
                                    'low' => $row['low'],
                                    'close' => $row['close'],
                                ],
                            ],
                            $aPoint,
                            $bPoints,
                            $cPoints,
                            [
                                'last_processed_open_time' => $row['open_time'],
                                'mode' => 'S0',
                                'direction' => null,
                                'a_temp' => null,
                                'b_temp' => null,
                                'touched_opposite' => false,
                                'outside_streak' => 0,
                                'dn_hist' => $dnHist,
                                'up_hist' => $upHist,
                                'mb_hist' => $mbHist,
                                'impulse_buf' => $impulseBuf,
                                'kp_pending' => $kpPending,
                                'kp_inband_count' => $kpInbandCount,
                                'kp_x_n' => $kpXn,
                                'kp_y_n' => $kpYn,
                                'kp_confirm_bars' => $kpConfirmBars,
                                'b_break' => ['count' => 0, 'min' => null],
                                'params' => self::paramsState($bBreakRefreshTimes, $bRefreshEps, $outsideBars, $slopeWindow, $slopePct, $cBreakAPct, $impulseBodyRatio, $impulseRangeRatio, $impulseBodyToRangeRatio, $impulseN, $impulseNRatio, $impulseBullRatioMin),
                            ],
                            $activeStartTime,
                            $aConfirmTime
                        );
                        $closed++;

                        $active = self::createStructure($symbol, $interval, $row['open_time']);
                        $mode = 'S0';
                        $direction = null;
                        $aTemp = null;
                        $aPoint = null;
                        $aConfirmTime = null;
                        $bTemp = null;
                        $bPoints = [];
                        $cPoints = [];
                        $touchedOpposite = false;
                        $outsideStreak = 0;
                        $activeStartTime = $active['start_time'];
                        $bBreak = ['count' => 0, 'min' => null];
                        continue;
                    }
                }
            }
        }

        $engineState = [
            'last_processed_open_time' => $lastProcessed,
            'mode' => $mode,
            'direction' => $direction,
            'a_temp' => $aTemp,
            'b_temp' => $bTemp,
            'touched_opposite' => $touchedOpposite,
            'outside_streak' => $outsideStreak,
            'dn_hist' => $dnHist,
            'up_hist' => $upHist,
            'mb_hist' => $mbHist,
            'impulse_buf' => $impulseBuf,
            'kp_pending' => $kpPending,
            'kp_inband_count' => $kpInbandCount,
            'kp_x_n' => $kpXn,
            'kp_y_n' => $kpYn,
            'kp_confirm_bars' => $kpConfirmBars,
            'b_break' => $bBreak,
            'params' => self::paramsState($bBreakRefreshTimes, $bRefreshEps, $outsideBars, $slopeWindow, $slopePct, $cBreakAPct, $impulseBodyRatio, $impulseRangeRatio, $impulseBodyToRangeRatio, $impulseN, $impulseNRatio, $impulseBullRatioMin),
        ];

        self::saveActiveStructure(
            (int)$active['id'],
            $direction,
            $mode,
            $aPoint,
            $aConfirmTime,
            $bPoints,
            $cPoints,
            $engineState,
            $activeStartTime
        );

        return [
            'updated' => $updated,
            'closed' => $closed,
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
            Db::statement('CREATE TABLE IF NOT EXISTS abc_structures (
                id bigserial PRIMARY KEY,
                symbol text NOT NULL,
                interval text NOT NULL,
                status text NOT NULL DEFAULT \'ACTIVE\',
                direction text,
                last_state text,
                a_point jsonb,
                a_confirm_time timestamp,
                b_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                c_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                close_reason text,
                close_condition jsonb,
                engine_state jsonb,
                start_time timestamp,
                end_time timestamp,
                created_at timestamp NOT NULL DEFAULT now(),
                updated_at timestamp NOT NULL DEFAULT now()
            )');
            Db::statement('CREATE INDEX IF NOT EXISTS abc_structures_symbol_interval_status_idx ON abc_structures(symbol, interval, status)');
            Db::statement('CREATE INDEX IF NOT EXISTS abc_structures_symbol_interval_id_idx ON abc_structures(symbol, interval, id DESC)');
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
    }

    private static function getActiveStructure(string $symbol, string $interval): ?array
    {
        $row = Db::table('abc_structures')
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
        $id = Db::table('abc_structures')->insertGetId([
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'direction' => null,
            'last_state' => 'S0',
            'a_point' => null,
            'a_confirm_time' => null,
            'b_points' => self::encodeJson([]),
            'c_points' => self::encodeJson([]),
            'engine_state' => self::encodeJson([
                'last_processed_open_time' => null,
                'mode' => 'S0',
                'direction' => null,
                'a_temp' => null,
                'b_temp' => null,
                'touched_opposite' => false,
                'outside_streak' => 0,
                'dn_hist' => [],
                'up_hist' => [],
                'mb_hist' => [],
                'impulse_buf' => [],
                'kp_pending' => null,
                'kp_inband_count' => 0,
                'kp_x_n' => 0,
                'kp_y_n' => 0,
                'kp_confirm_bars' => 30,
                'b_break' => ['count' => 0, 'min' => null],
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
            'direction' => null,
            'last_state' => 'S0',
            'a_point' => null,
            'a_confirm_time' => null,
            'b_points' => [],
            'c_points' => [],
            'engine_state' => [
                'last_processed_open_time' => null,
                'mode' => 'S0',
                'direction' => null,
                'a_temp' => null,
                'b_temp' => null,
                'touched_opposite' => false,
                'outside_streak' => 0,
                'dn_hist' => [],
                'up_hist' => [],
                'mb_hist' => [],
                'impulse_buf' => [],
                'kp_pending' => null,
                'kp_inband_count' => 0,
                'kp_x_n' => 0,
                'kp_y_n' => 0,
                'kp_confirm_bars' => 30,
                'b_break' => ['count' => 0, 'min' => null],
            ],
            'start_time' => $startTime,
        ];
    }

    private static function closeStructure(
        int $id,
        string $endTime,
        string $reason,
        array $condition,
        ?array $aPoint,
        array $bPoints,
        array $cPoints,
        array $engineState,
        ?string $startTime,
        ?string $aConfirmTime
    ): void {
        Db::table('abc_structures')
            ->where('id', $id)
            ->update([
                'status' => 'CLOSED',
                'close_reason' => $reason,
                'close_condition' => self::encodeJson($condition),
                'engine_state' => self::encodeJson($engineState),
                'a_point' => $aPoint !== null ? self::encodeJson($aPoint) : null,
                'a_confirm_time' => $aConfirmTime,
                'b_points' => self::encodeJson($bPoints),
                'c_points' => self::encodeJson($cPoints),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function saveActiveStructure(
        int $id,
        ?string $direction,
        string $mode,
        ?array $aPoint,
        ?string $aConfirmTime,
        array $bPoints,
        array $cPoints,
        array $engineState,
        ?string $startTime
    ): void {
        Db::table('abc_structures')
            ->where('id', $id)
            ->update([
                'direction' => $direction,
                'last_state' => $mode,
                'a_point' => $aPoint !== null ? self::encodeJson($aPoint) : null,
                'a_confirm_time' => $aConfirmTime,
                'b_points' => self::encodeJson($bPoints),
                'c_points' => self::encodeJson($cPoints),
                'engine_state' => self::encodeJson($engineState),
                'start_time' => $startTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function normalizeStructureRow(object $row): array
    {
        $aPoint = self::decodeJson($row->a_point);
        $bPoints = self::decodeJson($row->b_points) ?? [];
        $cPoints = self::decodeJson($row->c_points) ?? [];
        $engineState = self::decodeJson($row->engine_state) ?? null;

        return [
            'id' => (int)$row->id,
            'symbol' => (string)$row->symbol,
            'interval' => (string)$row->interval,
            'status' => (string)$row->status,
            'direction' => isset($row->direction) ? ($row->direction !== null ? (string)$row->direction : null) : null,
            'last_state' => isset($row->last_state) ? (string)$row->last_state : 'S0',
            'a_point' => is_array($aPoint) ? $aPoint : null,
            'a_confirm_time' => isset($row->a_confirm_time) ? ($row->a_confirm_time !== null ? (string)$row->a_confirm_time : null) : null,
            'b_points' => is_array($bPoints) ? $bPoints : [],
            'c_points' => is_array($cPoints) ? $cPoints : [],
            'engine_state' => is_array($engineState) ? $engineState : null,
            'start_time' => isset($row->start_time) ? ($row->start_time !== null ? (string)$row->start_time : null) : null,
        ];
    }

    private static function normalizeKlineRow(object $row): array
    {
        return [
            'open_time' => (string)$row->open_time,
            'open' => (float)$row->open,
            'high' => (float)$row->high,
            'low' => (float)$row->low,
            'close' => (float)$row->close,
            'boll_up' => $row->boll_up !== null ? (float)$row->boll_up : null,
            'boll_mb' => $row->boll_mb !== null ? (float)$row->boll_mb : null,
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

    private static function touchesMb(array $row): bool
    {
        if ($row['boll_mb'] === null) {
            return false;
        }
        $mb = (float)$row['boll_mb'];
        return (float)$row['low'] <= $mb && (float)$row['high'] >= $mb;
    }

    private static function touchesUp(array $row): bool
    {
        if ($row['boll_up'] === null) {
            return false;
        }
        return (float)$row['high'] >= (float)$row['boll_up'];
    }

    private static function touchesDn(array $row): bool
    {
        if ($row['boll_dn'] === null) {
            return false;
        }
        return (float)$row['low'] <= (float)$row['boll_dn'];
    }

    private static function updateKpPending(?array $pending, string $kind, string $time, float $price): array
    {
        if ($pending === null || !isset($pending['kind']) || (string)$pending['kind'] !== $kind) {
            return [
                'kind' => $kind,
                'time' => $time,
                'price' => $price,
            ];
        }

        $prev = (float)($pending['price'] ?? 0.0);
        if ($kind === 'X') {
            if ($price < $prev) {
                $pending['time'] = $time;
                $pending['price'] = $price;
            }
        } else {
            if ($price > $prev) {
                $pending['time'] = $time;
                $pending['price'] = $price;
            }
        }

        return $pending;
    }

    private static function buildOscKeypoints(array $osc): array
    {
        $out = [];
        $x = $osc['x_points'];
        $y = $osc['y_points'];
        foreach ($x as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!isset($p['time'], $p['price'], $p['label'])) {
                continue;
            }
            $out[] = [
                'kind' => 'X',
                'time' => (string)$p['time'],
                'price' => (float)$p['price'],
                'label' => (string)$p['label'],
            ];
        }
        foreach ($y as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!isset($p['time'], $p['price'], $p['label'])) {
                continue;
            }
            $out[] = [
                'kind' => 'Y',
                'time' => (string)$p['time'],
                'price' => (float)$p['price'],
                'label' => (string)$p['label'],
            ];
        }

        usort($out, function ($a, $b) {
            return strcmp((string)$a['time'], (string)$b['time']);
        });

        return array_values($out);
    }

    private static function applyAKeypoint(?string $direction, ?array $aTemp, array $kp): array
    {
        $src = isset($kp['source']) ? (string)$kp['source'] : 'OSC';
        $kpDir = $kp['kind'] === 'Y' ? 'UP' : 'DN';
        if ($aTemp === null) {
            return [$kpDir, [
                'direction' => $kpDir,
                'time' => $kp['time'],
                'price' => (float)$kp['price'],
                'source' => $src,
                'osc' => [
                    'kind' => $kp['kind'],
                    'label' => $kp['label'],
                    'time' => $kp['time'],
                    'price' => (float)$kp['price'],
                ],
            ]];
        }

        $aDir = isset($aTemp['direction']) ? (string)$aTemp['direction'] : null;
        $aTime = isset($aTemp['time']) ? (string)$aTemp['time'] : null;
        $aPrice = isset($aTemp['price']) ? (float)$aTemp['price'] : null;

        if ($aDir !== $kpDir && $aTime !== null && strcmp((string)$kp['time'], (string)$aTime) > 0) {
            return [$kpDir, [
                'direction' => $kpDir,
                'time' => $kp['time'],
                'price' => (float)$kp['price'],
                'source' => $src,
                'osc' => [
                    'kind' => $kp['kind'],
                    'label' => $kp['label'],
                    'time' => $kp['time'],
                    'price' => (float)$kp['price'],
                ],
            ]];
        }

        if ($aDir === $kpDir) {
            if ($kpDir === 'UP') {
                if ($aPrice === null || (float)$kp['price'] > $aPrice) {
                    $aTemp['time'] = $kp['time'];
                    $aTemp['price'] = (float)$kp['price'];
                    $aTemp['source'] = $src;
                    $aTemp['osc'] = [
                        'kind' => $kp['kind'],
                        'label' => $kp['label'],
                        'time' => $kp['time'],
                        'price' => (float)$kp['price'],
                    ];
                }
            } else {
                if ($aPrice === null || (float)$kp['price'] < $aPrice) {
                    $aTemp['time'] = $kp['time'];
                    $aTemp['price'] = (float)$kp['price'];
                    $aTemp['source'] = $src;
                    $aTemp['osc'] = [
                        'kind' => $kp['kind'],
                        'label' => $kp['label'],
                        'time' => $kp['time'],
                        'price' => (float)$kp['price'],
                    ];
                }
            }
        }

        return [$direction ?? $aDir, $aTemp];
    }

    private static function isOppositeKeypoint(string $direction, string $kpKind): bool
    {
        if ($direction === 'UP') {
            return $kpKind === 'X';
        }
        if ($direction === 'DN') {
            return $kpKind === 'Y';
        }
        return false;
    }

    private static function applyBKeypoint(?string $direction, ?array $bTemp, array $bBreak, array $kp, float $eps): array
    {
        if ($direction === null) {
            return [$bTemp, $bBreak];
        }

        $src = isset($kp['source']) ? (string)$kp['source'] : 'OSC';
        $wantKind = $direction === 'UP' ? 'X' : 'Y';
        if ($kp['kind'] !== $wantKind) {
            return [$bTemp, $bBreak];
        }

        if ($bTemp === null) {
            $bTemp = [
                'time' => $kp['time'],
                'price' => (float)$kp['price'],
                'source' => $src,
                'osc' => [
                    'kind' => $kp['kind'],
                    'label' => $kp['label'],
                    'time' => $kp['time'],
                    'price' => (float)$kp['price'],
                ],
            ];
            $bBreak = ['count' => 0, 'min' => null];
            return [$bTemp, $bBreak];
        }

        $prev = (float)($bTemp['price'] ?? 0.0);
        $next = (float)$kp['price'];
        $better = false;
        if ($direction === 'UP') {
            $better = $next < ($prev - $eps);
        } else {
            $better = $next > ($prev + $eps);
        }
        if ($better) {
            $bTemp['time'] = $kp['time'];
            $bTemp['price'] = $next;
            $bTemp['source'] = $src;
            $bTemp['osc'] = [
                'kind' => $kp['kind'],
                'label' => $kp['label'],
                'time' => $kp['time'],
                'price' => $next,
            ];
            if (!isset($bBreak['count'])) {
                $bBreak['count'] = 0;
            }
            $bBreak['count'] = (int)$bBreak['count'] + 1;
        }

        return [$bTemp, $bBreak];
    }

    private static function buildPointFromTemp(array $temp, string $role): array
    {
        $out = [
            'role' => $role,
            'time' => (string)($temp['time'] ?? ''),
            'price' => (float)($temp['price'] ?? 0.0),
            'source' => (string)($temp['source'] ?? 'TEMP'),
        ];
        if (isset($temp['osc']) && is_array($temp['osc'])) {
            $out['osc'] = $temp['osc'];
        }
        return $out;
    }

    private static function buildBPointFromTemp(array $temp, string $confirmTime): array
    {
        $out = [
            'role' => 'B',
            'time' => (string)($temp['time'] ?? ''),
            'price' => (float)($temp['price'] ?? 0.0),
            'confirm_time' => $confirmTime,
            'source' => (string)($temp['source'] ?? 'TEMP'),
        ];
        if (isset($temp['osc']) && is_array($temp['osc'])) {
            $out['osc'] = $temp['osc'];
        }
        return $out;
    }

    private static function buildCPointFromRow(array $row, string $direction): array
    {
        $price = $direction === 'UP' ? (float)$row['high'] : (float)$row['low'];
        $out = [
            'role' => 'C',
            'time' => (string)$row['open_time'],
            'price' => $price,
            'source' => 'TOUCH_BAND',
        ];
        return $out;
    }

    private static function mapCToNextA(array $cPoint): array
    {
        $out = $cPoint;
        $out['role'] = 'A';
        $out['source'] = 'FROM_C';
        unset($out['confirm_time']);
        return $out;
    }

    private static function lastPoint(array $points): ?array
    {
        if (empty($points)) {
            return null;
        }
        $p = $points[count($points) - 1];
        return is_array($p) ? $p : null;
    }

    private static function updateBreakMin(array $bBreak, float $low, float $eps): array
    {
        $min = $bBreak['min'] ?? null;
        if ($min === null || !is_finite((float)$min)) {
            $bBreak['min'] = $low;
            $bBreak['count'] = 0;
            return $bBreak;
        }
        if ($low < ((float)$min - $eps)) {
            $bBreak['min'] = $low;
            $bBreak['count'] = (int)($bBreak['count'] ?? 0) + 1;
        }
        return $bBreak;
    }

    private static function updateBreakMax(array $bBreak, float $high, float $eps): array
    {
        $min = $bBreak['min'] ?? null;
        if ($min === null || !is_finite((float)$min)) {
            $bBreak['min'] = $high;
            $bBreak['count'] = 0;
            return $bBreak;
        }
        if ($high > ((float)$min + $eps)) {
            $bBreak['min'] = $high;
            $bBreak['count'] = (int)($bBreak['count'] ?? 0) + 1;
        }
        return $bBreak;
    }

    private static function slopeInfoForBreak(string $direction, array $dnHist, array $upHist, array $mbHist, int $window): ?array
    {
        $arr = $direction === 'UP' ? $dnHist : $upHist;
        if (count($arr) < max(2, $window + 1)) {
            return null;
        }
        $slice = array_slice($arr, -($window + 1));
        $first = $slice[0];
        $last = $slice[count($slice) - 1];
        if ($first === null || $last === null) {
            return null;
        }
        $mbLast = null;
        if (!empty($mbHist)) {
            $mbLast = $mbHist[count($mbHist) - 1];
        }
        if ($mbLast === null || !is_finite((float)$mbLast) || (float)$mbLast == 0.0) {
            $mbLast = $last;
        }
        $slopeRaw = ((float)$last - (float)$first) / (float)$window;
        $slopePct = $slopeRaw / (float)$mbLast;
        return [
            'slope_raw' => $slopeRaw,
            'slope_pct' => $slopePct,
            'first' => (float)$first,
            'last' => (float)$last,
            'mb_last' => (float)$mbLast,
        ];
    }

    private static function findNearestOsc(array $keypoints, int $kpI, string $time, ?string $kind): ?array
    {
        $i = min(max($kpI - 1, 0), count($keypoints) - 1);
        for ($j = $i; $j >= 0; $j--) {
            $kp = $keypoints[$j];
            if (!is_array($kp)) {
                continue;
            }
            if ($kind !== null && (string)$kp['kind'] !== $kind) {
                continue;
            }
            if (strcmp((string)$kp['time'], $time) <= 0) {
                return [
                    'kind' => (string)$kp['kind'],
                    'label' => (string)$kp['label'],
                    'time' => (string)$kp['time'],
                    'price' => (float)$kp['price'],
                ];
            }
        }
        return null;
    }

    private static function paramsState(int $bBreakRefreshTimes, float $bRefreshEps, int $outsideBars, int $slopeWindow, float $slopePct, float $cBreakAPct, float $impulseBodyRatio, float $impulseRangeRatio, float $impulseBodyToRangeRatio, int $impulseN, float $impulseNRatio, float $impulseBullRatioMin): array
    {
        return [
            'b_break_refresh_times' => $bBreakRefreshTimes,
            'b_refresh_eps' => $bRefreshEps,
            'outside_bars' => $outsideBars,
            'slope_window' => $slopeWindow,
            'slope_pct' => $slopePct,
            'c_break_a_pct' => $cBreakAPct,
            'impulse_body_ratio' => $impulseBodyRatio,
            'impulse_range_ratio' => $impulseRangeRatio,
            'impulse_body_to_range_ratio' => $impulseBodyToRangeRatio,
            'impulse_n' => $impulseN,
            'impulse_n_ratio' => $impulseNRatio,
            'impulse_bull_ratio_min' => $impulseBullRatioMin,
        ];
    }

    private static function pushImpulseBuf(array &$buf, array $row, int $maxLen): void
    {
        $buf[] = [
            'open_time' => (string)$row['open_time'],
            'open' => (float)$row['open'],
            'high' => (float)$row['high'],
            'low' => (float)$row['low'],
            'close' => (float)$row['close'],
            'boll_up' => $row['boll_up'] !== null ? (float)$row['boll_up'] : null,
            'boll_mb' => $row['boll_mb'] !== null ? (float)$row['boll_mb'] : null,
            'boll_dn' => $row['boll_dn'] !== null ? (float)$row['boll_dn'] : null,
        ];
        if (count($buf) > $maxLen) {
            $buf = array_slice($buf, -$maxLen);
        }
    }

    private static function checkImpulseBreak(
        string $direction,
        array $row,
        array $buf,
        float $bodyRatio,
        float $rangeRatio,
        float $bodyToRangeRatio,
        int $n,
        float $nRatio,
        float $bullRatioMin
    ): ?array {
        $mb = $row['boll_mb'];
        $up = $row['boll_up'];
        $dn = $row['boll_dn'];
        if ($mb === null || $up === null || $dn === null) {
            return null;
        }
        $distUp = (float)$up - (float)$mb;
        $distDn = (float)$mb - (float)$dn;
        if ($distUp <= 0.0 || $distDn <= 0.0) {
            return null;
        }

        $open = (float)$row['open'];
        $close = (float)$row['close'];
        $high = (float)$row['high'];
        $low = (float)$row['low'];
        $body = abs($close - $open);
        $range = max($high - $low, 0.0);

        if ($direction === 'UP') {
            if ($close > (float)$up) {
                $dist = $distUp;
                $ok1 = $body >= $dist * $bodyRatio || $range >= $dist * $rangeRatio;
                $ok2 = true;
                if ($range > 0.0) {
                    $ok2 = ($close > $open) && (($body / $range) >= $bodyToRangeRatio);
                } else {
                    $ok2 = $close > $open;
                }
                if ($ok1 && $ok2) {
                    return [
                        'type' => 'IMPULSE_1',
                        'dist' => $dist,
                        'body' => $body,
                        'range' => $range,
                        'body_ratio' => $bodyRatio,
                        'range_ratio' => $rangeRatio,
                        'body_to_range_ratio' => $bodyToRangeRatio,
                        'bar' => [
                            'open_time' => (string)$row['open_time'],
                            'open' => $open,
                            'high' => $high,
                            'low' => $low,
                            'close' => $close,
                            'boll_up' => (float)$up,
                            'boll_mb' => (float)$mb,
                            'boll_dn' => (float)$dn,
                        ],
                    ];
                }
            }
        } else {
            if ($close < (float)$dn) {
                $dist = $distDn;
                $ok1 = $body >= $dist * $bodyRatio || $range >= $dist * $rangeRatio;
                $ok2 = true;
                if ($range > 0.0) {
                    $ok2 = ($close < $open) && (($body / $range) >= $bodyToRangeRatio);
                } else {
                    $ok2 = $close < $open;
                }
                if ($ok1 && $ok2) {
                    return [
                        'type' => 'IMPULSE_1',
                        'dist' => $dist,
                        'body' => $body,
                        'range' => $range,
                        'body_ratio' => $bodyRatio,
                        'range_ratio' => $rangeRatio,
                        'body_to_range_ratio' => $bodyToRangeRatio,
                        'bar' => [
                            'open_time' => (string)$row['open_time'],
                            'open' => $open,
                            'high' => $high,
                            'low' => $low,
                            'close' => $close,
                            'boll_up' => (float)$up,
                            'boll_mb' => (float)$mb,
                            'boll_dn' => (float)$dn,
                        ],
                    ];
                }
            }
        }

        $n = max(2, (int)$n);
        if (count($buf) < $n) {
            return null;
        }
        $slice = array_slice($buf, -$n);
        $first = $slice[0];
        if (!is_array($first) || !isset($first['open'])) {
            return null;
        }
        $open0 = (float)$first['open'];
        $move = abs($close - $open0);
        $dist = $direction === 'UP' ? $distUp : $distDn;
        if ($move < $dist * $nRatio) {
            return null;
        }
        $outsideHit = false;
        $trendCount = 0;
        foreach ($slice as $b) {
            if (!is_array($b)) {
                continue;
            }
            $o = (float)($b['open'] ?? 0.0);
            $c = (float)($b['close'] ?? 0.0);
            $h = (float)($b['high'] ?? 0.0);
            $l = (float)($b['low'] ?? 0.0);
            $bu = $b['boll_up'] ?? null;
            $bd = $b['boll_dn'] ?? null;
            if ($direction === 'UP') {
                if ($bu !== null && ($c > (float)$bu || $h >= (float)$bu)) {
                    $outsideHit = true;
                }
                if ($c > $o) {
                    $trendCount++;
                }
            } else {
                if ($bd !== null && ($c < (float)$bd || $l <= (float)$bd)) {
                    $outsideHit = true;
                }
                if ($c < $o) {
                    $trendCount++;
                }
            }
        }
        if (!$outsideHit) {
            return null;
        }
        $ratio = $trendCount / (float)$n;
        if ($ratio < $bullRatioMin) {
            return null;
        }

        return [
            'type' => 'IMPULSE_N',
            'n' => $n,
            'dist' => $dist,
            'move' => $move,
            'move_ratio' => $nRatio,
            'trend_ratio' => $ratio,
            'trend_ratio_min' => $bullRatioMin,
            'from_open_time' => (string)($first['open_time'] ?? ''),
            'to_open_time' => (string)$row['open_time'],
        ];
    }

    private static function pushHist(array &$hist, $value, int $maxLen): void
    {
        $hist[] = $value !== null ? (float)$value : null;
        if (count($hist) > $maxLen) {
            $hist = array_slice($hist, -$maxLen);
        }
    }

    private static function getActiveOscillationStructure(string $symbol, string $interval): ?array
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
            $x = self::decodeJson($row->x_points) ?? [];
            $y = self::decodeJson($row->y_points) ?? [];
            return [
                'id' => (int)$row->id,
                'x_points' => is_array($x) ? $x : [],
                'y_points' => is_array($y) ? $y : [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
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
