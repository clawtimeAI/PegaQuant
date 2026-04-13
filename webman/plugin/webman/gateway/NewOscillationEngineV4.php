<?php

namespace plugin\webman\gateway;

use support\Db;

class NewOscillationEngineV4
{
    private static ?bool $schemaReady = null;

    public static function run(
        string $symbol,
        string $interval,
        ?string $startTime = null,
        ?string $endTime = null
    ): array {
        self::ensureSchema();

        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);
        if ($symbol === '' || $interval === '') {
            return ['updated' => 0, 'closed' => 0];
        }

        $forceRebuild = $startTime !== null && trim($startTime) !== '';
        if ($forceRebuild) {
            self::deleteAllStructures($symbol, $interval);
        }

        $openFactor = 2.0;
        $closeFactor = 2.0;
        $closeLockFactor = 2.0;
        $breakoutFactor = 1.0;
        $rangeMatureBars = 20;
        $rangeMatureRatio = 1.5;

        $active = self::getActiveStructure($symbol, $interval);
        if ($active === null) {
            $active = self::createStructure($symbol, $interval, null);
        }

        $xPoints = self::normalizeAndLabelPoints($active['x_points'] ?? null, 'X');
        $yPoints = self::normalizeAndLabelPoints($active['y_points'] ?? null, 'Y');

        $state = $active['engine_state'] ?? [];
        $phase = 'STRUCTURE';
        $lastProcessed = isset($state['last_processed_open_time']) ? (string)$state['last_processed_open_time'] : null;
        $lastMouthState = array_key_exists('last_mouth_state', $state) ? (int)($state['last_mouth_state'] ?? 0) : 0;

        $openStartTime = isset($state['open_start_time']) ? (string)$state['open_start_time'] : null;
        $openStartBw = array_key_exists('open_start_bw', $state) && $state['open_start_bw'] !== null ? (float)$state['open_start_bw'] : null;
        $openConfirmTime = isset($state['open_confirm_time']) ? (string)$state['open_confirm_time'] : null;
        $openConfirmBw = array_key_exists('open_confirm_bw', $state) && $state['open_confirm_bw'] !== null ? (float)$state['open_confirm_bw'] : null;
        $peakBwSinceOpenConfirm = array_key_exists('peak_bw_since_open_confirm', $state) && $state['peak_bw_since_open_confirm'] !== null ? (float)$state['peak_bw_since_open_confirm'] : null;

        $closeProbeTime = isset($state['close_probe_time']) ? (string)$state['close_probe_time'] : null;
        $closeProbeBw = array_key_exists('close_probe_bw', $state) && $state['close_probe_bw'] !== null ? (float)$state['close_probe_bw'] : null;
        $closeStartTime = isset($state['close_start_time']) ? (string)$state['close_start_time'] : null;
        $closeStartBw = array_key_exists('close_start_bw', $state) && $state['close_start_bw'] !== null ? (float)$state['close_start_bw'] : null;

        $rangeStartTime = isset($state['range_start_time']) ? (string)$state['range_start_time'] : null;
        $rangeBwMax = array_key_exists('range_bw_max', $state) && $state['range_bw_max'] !== null ? (float)$state['range_bw_max'] : null;
        $rangeBwMin = array_key_exists('range_bw_min', $state) && $state['range_bw_min'] !== null ? (float)$state['range_bw_min'] : null;
        $rangeBarCount = array_key_exists('range_bar_count', $state) ? (int)($state['range_bar_count'] ?? 0) : 0;
        $rangeMature = array_key_exists('range_mature', $state) ? (bool)($state['range_mature'] ?? false) : false;

        $nextOpenStartTime = isset($state['next_open_start_time']) ? (string)$state['next_open_start_time'] : null;
        $nextOpenStartBw = array_key_exists('next_open_start_bw', $state) && $state['next_open_start_bw'] !== null ? (float)$state['next_open_start_bw'] : null;

        $firstMiddleTouchTime = isset($state['first_middle_touch_time']) ? (string)$state['first_middle_touch_time'] : null;
        $pending = isset($state['pending']) && is_array($state['pending']) ? $state['pending'] : null;

        $birthOutsideDir = isset($state['birth_outside_dir']) ? (string)$state['birth_outside_dir'] : null;
        $birthOutsideCount = array_key_exists('birth_outside_count', $state) ? (int)($state['birth_outside_count'] ?? 0) : 0;
        $birthOutsideStartTime = isset($state['birth_outside_start_time']) ? (string)$state['birth_outside_start_time'] : null;
        $birthTemp = isset($state['birth_temp']) && is_array($state['birth_temp']) ? $state['birth_temp'] : null;
        $birthReturnedInside = array_key_exists('birth_returned_inside', $state) ? (bool)($state['birth_returned_inside'] ?? false) : false;

        $birthOutsideCloseBars = 30;

        $table = 'kline_' . $interval;
        $query = Db::table($table)
            ->where('symbol', $symbol)
            ->orderBy('open_time', 'asc')
            ->select(['open_time', 'high', 'low', 'close', 'boll_up', 'boll_mb', 'boll_dn', 'bw', 'mouth_state']);

        if ($startTime !== null && trim($startTime) !== '') {
            $query->where('open_time', '>=', $startTime);
        }
        if ($endTime !== null && trim($endTime) !== '') {
            $query->where('open_time', '<=', $endTime);
        }
        if (!$forceRebuild && $lastProcessed !== null && trim($lastProcessed) !== '') {
            $query->where('open_time', '>', $lastProcessed);
        }

        $updated = 0;
        $closed = 0;

        foreach ($query->cursor() as $rowObj) {
            $row = self::normalizeKlineRow($rowObj);
            $lastProcessed = $row['open_time'];

            $bw = $row['bw'];
            $ms = (int)$row['mouth_state'];

            $bollUp = $row['boll_up'];
            $bollDn = $row['boll_dn'];
            $close = (float)$row['close'];

            $outsideUp = $bollUp !== null && $close > (float)$bollUp;
            $outsideDn = $bollDn !== null && $close < (float)$bollDn;
            $isOutside = $outsideUp || $outsideDn;
            $isInside = $bollUp !== null && $bollDn !== null && $close <= (float)$bollUp && $close >= (float)$bollDn;

            if ($isOutside) {
                $dir = $outsideUp ? 'UP' : 'DOWN';
                if ($birthOutsideDir === null || $birthOutsideDir !== $dir || $birthReturnedInside) {
                    $birthOutsideDir = $dir;
                    $birthOutsideCount = 1;
                    $birthOutsideStartTime = $row['open_time'];
                    if ($dir === 'UP') {
                        $birthTemp = [
                            'kind' => 'Y',
                            'time' => $row['open_time'],
                            'price' => (float)$row['high'],
                        ];
                    } else {
                        $birthTemp = [
                            'kind' => 'X',
                            'time' => $row['open_time'],
                            'price' => (float)$row['low'],
                        ];
                    }
                    $birthReturnedInside = false;
                } else {
                    $birthOutsideCount++;
                    if ($birthTemp === null || !is_array($birthTemp)) {
                        $birthTemp = null;
                    }
                    if ($dir === 'UP') {
                        $h = (float)$row['high'];
                        $p = $birthTemp !== null && array_key_exists('price', $birthTemp) ? (float)$birthTemp['price'] : null;
                        if ($p === null || $h > $p) {
                            $birthTemp = [
                                'kind' => 'Y',
                                'time' => $row['open_time'],
                                'price' => $h,
                            ];
                        }
                    } else {
                        $l = (float)$row['low'];
                        $p = $birthTemp !== null && array_key_exists('price', $birthTemp) ? (float)$birthTemp['price'] : null;
                        if ($p === null || $l < $p) {
                            $birthTemp = [
                                'kind' => 'X',
                                'time' => $row['open_time'],
                                'price' => $l,
                            ];
                        }
                    }
                }
            } else {
                if ($birthOutsideDir !== null) {
                    if ($birthOutsideCount >= $birthOutsideCloseBars && $isInside) {
                        $birthReturnedInside = true;
                    } elseif ($birthOutsideCount > 0 && $birthOutsideCount < $birthOutsideCloseBars && $isInside) {
                        $birthOutsideDir = null;
                        $birthOutsideCount = 0;
                        $birthOutsideStartTime = null;
                        $birthTemp = null;
                        $birthReturnedInside = false;
                    }
                }
            }

            if ($birthReturnedInside && $birthOutsideStartTime !== null && trim($birthOutsideStartTime) !== '' && $birthTemp !== null && self::touchedMiddleBand($row)) {
                $boundaryTime = $birthOutsideStartTime;
                $confirmedKind = isset($birthTemp['kind']) ? (string)$birthTemp['kind'] : null;
                $confirmedTime = isset($birthTemp['time']) ? (string)$birthTemp['time'] : null;
                $confirmedPrice = array_key_exists('price', $birthTemp) ? (float)$birthTemp['price'] : null;

                if ($confirmedKind !== null && $confirmedTime !== null && $confirmedTime !== '' && $confirmedPrice !== null) {
                    $condition = [
                        'birth_outside_close_bars' => $birthOutsideCloseBars,
                        'birth_outside_dir' => $birthOutsideDir,
                        'birth_outside_count' => $birthOutsideCount,
                        'birth_outside_start_time' => $birthOutsideStartTime,
                        'birth_confirm_time' => $row['open_time'],
                        'birth_confirm_middle_touch' => true,
                        'birth_confirmed_point' => [
                            'kind' => $confirmedKind,
                            'time' => $confirmedTime,
                            'price' => $confirmedPrice,
                        ],
                    ];

                    if (($active['start_time'] ?? null) !== null) {
                        $xPointsToClose = [];
                        foreach ($xPoints as $p) {
                            if (!is_array($p)) {
                                continue;
                            }
                            $t = isset($p['time']) ? (string)$p['time'] : '';
                            if ($t !== '' && self::timeLt($t, $boundaryTime)) {
                                $xPointsToClose[] = $p;
                            }
                        }
                        $yPointsToClose = [];
                        foreach ($yPoints as $p) {
                            if (!is_array($p)) {
                                continue;
                            }
                            $t = isset($p['time']) ? (string)$p['time'] : '';
                            if ($t !== '' && self::timeLt($t, $boundaryTime)) {
                                $yPointsToClose[] = $p;
                            }
                        }

                        $engineStateClosed = self::buildEngineState([
                            'phase' => $phase,
                            'last_processed_open_time' => $lastProcessed,
                            'last_mouth_state' => $ms,
                            'open_start_time' => $openStartTime,
                            'open_start_bw' => $openStartBw,
                            'open_confirm_time' => $openConfirmTime,
                            'open_confirm_bw' => $openConfirmBw,
                            'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                            'close_probe_time' => $closeProbeTime,
                            'close_probe_bw' => $closeProbeBw,
                            'close_start_time' => $closeStartTime,
                            'close_start_bw' => $closeStartBw,
                            'range_start_time' => $rangeStartTime,
                            'range_bw_max' => $rangeBwMax,
                            'range_bw_min' => $rangeBwMin,
                            'range_bar_count' => $rangeBarCount,
                            'range_mature' => $rangeMature,
                            'next_open_start_time' => $nextOpenStartTime,
                            'next_open_start_bw' => $nextOpenStartBw,
                            'first_middle_touch_time' => $firstMiddleTouchTime,
                            'pending' => $pending,
                            'birth_outside_dir' => $birthOutsideDir,
                            'birth_outside_count' => $birthOutsideCount,
                            'birth_outside_start_time' => $birthOutsideStartTime,
                            'birth_temp' => $birthTemp,
                            'birth_returned_inside' => $birthReturnedInside,
                        ]);
                        self::closeStructure(
                            (int)$active['id'],
                            $boundaryTime,
                            'BIRTH_NEW_STRUCTURE',
                            $condition,
                            $xPointsToClose,
                            $yPointsToClose,
                            $engineStateClosed,
                            $active['start_time'] ?? null,
                            $openStartTime,
                            $openStartBw,
                            $openConfirmTime,
                            $openConfirmBw,
                            $closeStartTime,
                            $closeStartBw,
                            $peakBwSinceOpenConfirm
                        );
                        $closed++;

                        $active = self::createStructure($symbol, $interval, $boundaryTime);
                        $xPoints = [];
                        $yPoints = [];
                    } else {
                        $active['start_time'] = $boundaryTime;
                        Db::table('oscillation_structures_v4')
                            ->where('id', (int)$active['id'])
                            ->update([
                                'start_time' => $boundaryTime,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                        $updated++;
                    }

                    $openStartTime = null;
                    $openStartBw = null;
                    $openConfirmTime = null;
                    $openConfirmBw = null;
                    $peakBwSinceOpenConfirm = null;
                    $closeProbeTime = null;
                    $closeProbeBw = null;
                    $closeStartTime = null;
                    $closeStartBw = null;
                    $rangeStartTime = null;
                    $rangeBwMax = null;
                    $rangeBwMin = null;
                    $rangeBarCount = 0;
                    $rangeMature = false;
                    $nextOpenStartTime = null;
                    $nextOpenStartBw = null;

                    $point = [
                        'time' => $confirmedTime,
                        'price' => $confirmedPrice,
                        'kind' => $confirmedKind,
                        'label' => $confirmedKind . '1',
                    ];
                    if ($confirmedKind === 'X') {
                        $xPoints[] = $point;
                    } else {
                        $yPoints[] = $point;
                    }

                    $firstMiddleTouchTime = $row['open_time'];
                    $pending = null;

                    $birthOutsideDir = null;
                    $birthOutsideCount = 0;
                    $birthOutsideStartTime = null;
                    $birthTemp = null;
                    $birthReturnedInside = false;

                    $updated++;
                    $lastMouthState = $ms;
                    continue;
                }
            }

            if ($phase === 'WAIT_BREAKOUT') {
                if ($nextOpenStartTime === null) {
                    if (($lastMouthState === 2 || $lastMouthState === 0) && $ms === 1 && $bw !== null) {
                        $nextOpenStartTime = $row['open_time'];
                        $nextOpenStartBw = (float)$bw;
                    }
                } elseif ($ms === 2) {
                    $nextOpenStartTime = null;
                    $nextOpenStartBw = null;
                } elseif ($ms === 1 && $bw !== null && $nextOpenStartBw !== null && $nextOpenStartBw > 0.0) {
                    if ((float)$bw >= $nextOpenStartBw * $openFactor) {
                        $openStartTime = $nextOpenStartTime;
                        $openStartBw = $nextOpenStartBw;
                        $openConfirmTime = $row['open_time'];
                        $openConfirmBw = (float)$bw;
                        $peakBwSinceOpenConfirm = (float)$bw;
                        $closeProbeTime = null;
                        $closeProbeBw = null;
                        $closeStartTime = null;
                        $closeStartBw = null;
                        $rangeStartTime = null;
                        $rangeBwMax = null;
                        $rangeBwMin = null;
                        $rangeBarCount = 0;
                        $rangeMature = false;
                        $nextOpenStartTime = null;
                        $nextOpenStartBw = null;
                        $firstMiddleTouchTime = null;
                        $pending = null;
                        $xPoints = [];
                        $yPoints = [];
                        $phase = 'TREND';

                        $active['start_time'] = $openConfirmTime;
                        Db::table('oscillation_structures_v4')
                            ->where('id', (int)$active['id'])
                            ->update([
                                'start_time' => $openConfirmTime,
                                'open_start_time' => $openStartTime,
                                'open_start_bw' => $openStartBw,
                                'open_confirm_time' => $openConfirmTime,
                                'open_confirm_bw' => $openConfirmBw,
                                'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                        $updated++;
                    }
                }

                $lastMouthState = $ms;
                continue;
            }

            if ($phase === 'TREND') {
                if ($bw !== null && $openConfirmTime !== null) {
                    if ($peakBwSinceOpenConfirm === null || (float)$bw > $peakBwSinceOpenConfirm) {
                        $peakBwSinceOpenConfirm = (float)$bw;
                    }
                }

                if ($openConfirmTime !== null) {
                    if ($lastMouthState === 1 && $ms === 2) {
                        $closeProbeTime = $row['open_time'];
                        $closeProbeBw = $bw !== null ? (float)$bw : null;
                    }

                    if ($closeProbeTime !== null) {
                        if ($lastMouthState === 2 && $ms === 1) {
                            $closeProbeTime = null;
                            $closeProbeBw = null;
                        } elseif ($bw !== null && $peakBwSinceOpenConfirm !== null && $peakBwSinceOpenConfirm > 0.0) {
                            if ((float)$bw <= $peakBwSinceOpenConfirm / $closeLockFactor) {
                                $closeStartTime = $closeProbeTime;
                                $closeStartBw = $closeProbeBw;
                                $closeProbeTime = null;
                                $closeProbeBw = null;
                                Db::table('oscillation_structures_v4')
                                    ->where('id', (int)$active['id'])
                                    ->update([
                                        'close_start_time' => $closeStartTime,
                                        'close_start_bw' => $closeStartBw,
                                        'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                                        'updated_at' => date('Y-m-d H:i:s'),
                                    ]);
                                $updated++;
                            }
                        }
                    }

                    if ($closeStartTime !== null && $closeStartBw !== null && $closeStartBw > 0.0 && $bw !== null) {
                        if ((float)$bw < $closeStartBw / $closeFactor) {
                            $phase = 'RANGE_BUILDING';
                            $rangeStartTime = $row['open_time'];
                            $rangeBwMax = null;
                            $rangeBwMin = null;
                            $rangeBarCount = 0;
                            $rangeMature = false;
                            $nextOpenStartTime = null;
                            $nextOpenStartBw = null;
                            $closeProbeTime = null;
                            $closeProbeBw = null;
                        }
                    }
                }
            } elseif ($phase === 'RANGE_BUILDING' || $phase === 'RANGE_READY' || $phase === 'BREAKOUT_CANDIDATE') {
                if ($phase === 'BREAKOUT_CANDIDATE') {
                    if ($ms === 2) {
                        $nextOpenStartTime = null;
                        $nextOpenStartBw = null;
                        $phase = $rangeMature ? 'RANGE_READY' : 'RANGE_BUILDING';
                    } elseif ($ms === 1 && $bw !== null && $nextOpenStartBw !== null && $nextOpenStartBw > 0.0) {
                        if ($rangeBwMax !== null && (float)$bw >= $nextOpenStartBw * $openFactor && (float)$bw > $rangeBwMax * $breakoutFactor) {
                            $breakoutTime = $row['open_time'];
                            $breakoutBw = (float)$bw;
                            $condition = [
                                'open_factor' => $openFactor,
                                'close_factor' => $closeFactor,
                                'close_lock_factor' => $closeLockFactor,
                                'breakout_factor' => $breakoutFactor,
                                'range_mature_bars' => $rangeMatureBars,
                                'range_mature_ratio' => $rangeMatureRatio,
                                'range_start_time' => $rangeStartTime,
                                'range_bw_max' => $rangeBwMax,
                                'range_bw_min' => $rangeBwMin,
                                'range_bar_count' => $rangeBarCount,
                                'open_start_time' => $nextOpenStartTime,
                                'open_start_bw' => $nextOpenStartBw,
                                'open_confirm_time' => $breakoutTime,
                                'open_confirm_bw' => $breakoutBw,
                            ];
                            $engineState = self::buildEngineState([
                                'phase' => $phase,
                                'last_processed_open_time' => $lastProcessed,
                                'last_mouth_state' => $ms,
                                'open_start_time' => $openStartTime,
                                'open_start_bw' => $openStartBw,
                                'open_confirm_time' => $openConfirmTime,
                                'open_confirm_bw' => $openConfirmBw,
                                'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                                'close_probe_time' => $closeProbeTime,
                                'close_probe_bw' => $closeProbeBw,
                                'close_start_time' => $closeStartTime,
                                'close_start_bw' => $closeStartBw,
                                'range_start_time' => $rangeStartTime,
                                'range_bw_max' => $rangeBwMax,
                                'range_bw_min' => $rangeBwMin,
                                'range_bar_count' => $rangeBarCount,
                                'range_mature' => $rangeMature,
                                'next_open_start_time' => $nextOpenStartTime,
                                'next_open_start_bw' => $nextOpenStartBw,
                                'first_middle_touch_time' => $firstMiddleTouchTime,
                                'pending' => $pending,
                            ]);
                            self::closeStructure(
                                (int)$active['id'],
                                $breakoutTime,
                                'BREAKOUT_CONFIRMED',
                                $condition,
                                $xPoints,
                                $yPoints,
                                $engineState,
                                $active['start_time'] ?? null,
                                $openStartTime,
                                $openStartBw,
                                $openConfirmTime,
                                $openConfirmBw,
                                $closeStartTime,
                                $closeStartBw,
                                $peakBwSinceOpenConfirm
                            );
                            $closed++;

                            $active = self::createStructure($symbol, $interval, $breakoutTime);
                            $xPoints = [];
                            $yPoints = [];
                            $phase = 'TREND';
                            $openStartTime = $nextOpenStartTime;
                            $openStartBw = $nextOpenStartBw;
                            $openConfirmTime = $breakoutTime;
                            $openConfirmBw = $breakoutBw;
                            $peakBwSinceOpenConfirm = $breakoutBw;
                            $closeProbeTime = null;
                            $closeProbeBw = null;
                            $closeStartTime = null;
                            $closeStartBw = null;
                            $rangeStartTime = null;
                            $rangeBwMax = null;
                            $rangeBwMin = null;
                            $rangeBarCount = 0;
                            $rangeMature = false;
                            $nextOpenStartTime = null;
                            $nextOpenStartBw = null;
                            $firstMiddleTouchTime = null;
                            $pending = null;
                            $active['start_time'] = $breakoutTime;

                            Db::table('oscillation_structures_v4')
                                ->where('id', (int)$active['id'])
                                ->update([
                                    'start_time' => $breakoutTime,
                                    'open_start_time' => $openStartTime,
                                    'open_start_bw' => $openStartBw,
                                    'open_confirm_time' => $openConfirmTime,
                                    'open_confirm_bw' => $openConfirmBw,
                                    'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                            $updated++;
                            $lastMouthState = $ms;
                            continue;
                        }
                    }
                } else {
                    if ($ms === 1 && $bw !== null && $phase === 'RANGE_READY' && $lastMouthState === 2) {
                        $nextOpenStartTime = $row['open_time'];
                        $nextOpenStartBw = (float)$bw;
                        $phase = 'BREAKOUT_CANDIDATE';
                    } elseif ($ms === 1) {
                        $phase = 'TREND';
                        $rangeStartTime = null;
                        $rangeBwMax = null;
                        $rangeBwMin = null;
                        $rangeBarCount = 0;
                        $rangeMature = false;
                        $nextOpenStartTime = null;
                        $nextOpenStartBw = null;
                        $closeProbeTime = null;
                        $closeProbeBw = null;
                        $closeStartTime = null;
                        $closeStartBw = null;
                    } elseif ($ms === 2 && $bw !== null) {
                        if ($rangeStartTime === null) {
                            $rangeStartTime = $row['open_time'];
                        }
                        $rangeBarCount++;
                        if ($rangeBwMax === null || (float)$bw > $rangeBwMax) {
                            $rangeBwMax = (float)$bw;
                        }
                        if ($rangeBwMin === null || (float)$bw < $rangeBwMin) {
                            $rangeBwMin = (float)$bw;
                        }
                        if (!$rangeMature && $rangeBarCount >= $rangeMatureBars && $rangeBwMin !== null && $rangeBwMin > 0.0 && $rangeBwMax !== null) {
                            if ($rangeBwMax / $rangeBwMin >= $rangeMatureRatio) {
                                $rangeMature = true;
                                $phase = 'RANGE_READY';
                            }
                        }
                    }
                }
            }

            if (($active['start_time'] ?? null) !== null) {
                $isFirstPointOfStructure = count($xPoints) === 0 && count($yPoints) === 0;

                if ($firstMiddleTouchTime === null && self::touchedMiddleBand($row)) {
                    $firstMiddleTouchTime = $row['open_time'];
                    $pending = null;
                }

                if ($firstMiddleTouchTime !== null) {
                    $pending = self::updatePendingFromRow($pending, $row);
                    if ($pending !== null) {
                        $pendingTime = isset($pending['time']) ? (string)$pending['time'] : '';
                        if ($pendingTime !== '' && self::timeLt($pendingTime, (string)$row['open_time'])) {
                            if (self::touchedMiddleBand($row)) {
                                if (!$isFirstPointOfStructure || self::timeLt($firstMiddleTouchTime, $pendingTime)) {
                                    $kind = (string)$pending['kind'];
                                    $point = [
                                        'time' => (string)$pending['time'],
                                        'price' => (float)$pending['price'],
                                        'kind' => $kind,
                                    ];
                                    if ($kind === 'X') {
                                        $point['label'] = 'X' . (string)(count($xPoints) + 1);
                                        $xPoints[] = $point;
                                    } else {
                                        $point['label'] = 'Y' . (string)(count($yPoints) + 1);
                                        $yPoints[] = $point;
                                    }
                                    $pending = null;
                                    $updated++;
                                }
                            }
                        }
                    }
                }
            }

            $lastMouthState = $ms;
        }

        $engineState = self::buildEngineState([
            'phase' => $phase,
            'last_processed_open_time' => $lastProcessed,
            'last_mouth_state' => $lastMouthState,
            'open_start_time' => $openStartTime,
            'open_start_bw' => $openStartBw,
            'open_confirm_time' => $openConfirmTime,
            'open_confirm_bw' => $openConfirmBw,
            'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
            'close_probe_time' => $closeProbeTime,
            'close_probe_bw' => $closeProbeBw,
            'close_start_time' => $closeStartTime,
            'close_start_bw' => $closeStartBw,
            'range_start_time' => $rangeStartTime,
            'range_bw_max' => $rangeBwMax,
            'range_bw_min' => $rangeBwMin,
            'range_bar_count' => $rangeBarCount,
            'range_mature' => $rangeMature,
            'next_open_start_time' => $nextOpenStartTime,
            'next_open_start_bw' => $nextOpenStartBw,
            'birth_outside_dir' => $birthOutsideDir,
            'birth_outside_count' => $birthOutsideCount,
            'birth_outside_start_time' => $birthOutsideStartTime,
            'birth_temp' => $birthTemp,
            'birth_returned_inside' => $birthReturnedInside,
            'first_middle_touch_time' => $firstMiddleTouchTime,
            'pending' => $pending,
        ]);

        self::saveActiveStructure(
            (int)$active['id'],
            $xPoints,
            $yPoints,
            $engineState,
            $active['start_time'] ?? null,
            $openStartTime,
            $openStartBw,
            $openConfirmTime,
            $openConfirmBw,
            $closeStartTime,
            $closeStartBw,
            $peakBwSinceOpenConfirm
        );

        return ['updated' => $updated, 'closed' => $closed];
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
            Db::statement('CREATE TABLE IF NOT EXISTS oscillation_structures_v4 (
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
                open_start_time timestamp,
                open_start_bw numeric(20,10),
                open_confirm_time timestamp,
                open_confirm_bw numeric(20,10),
                close_start_time timestamp,
                close_start_bw numeric(20,10),
                peak_bw_since_open_confirm numeric(20,10),
                created_at timestamp NOT NULL DEFAULT now(),
                updated_at timestamp NOT NULL DEFAULT now()
            )');
            Db::statement('ALTER TABLE oscillation_structures_v4 ADD COLUMN IF NOT EXISTS x_points jsonb NOT NULL DEFAULT \'[]\'::jsonb');
            Db::statement('ALTER TABLE oscillation_structures_v4 ADD COLUMN IF NOT EXISTS y_points jsonb NOT NULL DEFAULT \'[]\'::jsonb');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v4_symbol_interval_status_idx ON oscillation_structures_v4(symbol, interval, status)');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v4_symbol_interval_id_idx ON oscillation_structures_v4(symbol, interval, id DESC)');
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
    }

    private static function deleteAllStructures(string $symbol, string $interval): void
    {
        try {
            Db::table('oscillation_structures_v4')
                ->where('symbol', $symbol)
                ->where('interval', $interval)
                ->delete();
        } catch (\Throwable $e) {
        }
    }

    private static function getActiveStructure(string $symbol, string $interval): ?array
    {
        $row = Db::table('oscillation_structures_v4')
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
        $id = Db::table('oscillation_structures_v4')->insertGetId([
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'x_points' => self::encodeJson([]),
            'y_points' => self::encodeJson([]),
            'close_reason' => null,
            'close_condition' => null,
            'engine_state' => self::encodeJson(self::buildEngineState([
                'phase' => 'STRUCTURE',
                'last_processed_open_time' => null,
                'last_mouth_state' => 0,
                'open_start_time' => null,
                'open_start_bw' => null,
                'open_confirm_time' => null,
                'open_confirm_bw' => null,
                'peak_bw_since_open_confirm' => null,
                'close_probe_time' => null,
                'close_probe_bw' => null,
                'close_start_time' => null,
                'close_start_bw' => null,
                'range_start_time' => null,
                'range_bw_max' => null,
                'range_bw_min' => null,
                'range_bar_count' => 0,
                'range_mature' => false,
                'next_open_start_time' => null,
                'next_open_start_bw' => null,
                'birth_outside_dir' => null,
                'birth_outside_count' => 0,
                'birth_outside_start_time' => null,
                'birth_temp' => null,
                'birth_returned_inside' => false,
                'first_middle_touch_time' => null,
                'pending' => null,
            ])),
            'start_time' => $startTime,
            'end_time' => null,
            'open_start_time' => null,
            'open_start_bw' => null,
            'open_confirm_time' => null,
            'open_confirm_bw' => null,
            'close_start_time' => null,
            'close_start_bw' => null,
            'peak_bw_since_open_confirm' => null,
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
            'engine_state' => self::buildEngineState([
                'phase' => 'STRUCTURE',
                'last_processed_open_time' => null,
                'last_mouth_state' => 0,
                'open_start_time' => null,
                'open_start_bw' => null,
                'open_confirm_time' => null,
                'open_confirm_bw' => null,
                'peak_bw_since_open_confirm' => null,
                'close_probe_time' => null,
                'close_probe_bw' => null,
                'close_start_time' => null,
                'close_start_bw' => null,
                'range_start_time' => null,
                'range_bw_max' => null,
                'range_bw_min' => null,
                'range_bar_count' => 0,
                'range_mature' => false,
                'next_open_start_time' => null,
                'next_open_start_bw' => null,
                'birth_outside_dir' => null,
                'birth_outside_count' => 0,
                'birth_outside_start_time' => null,
                'birth_temp' => null,
                'birth_returned_inside' => false,
                'first_middle_touch_time' => null,
                'pending' => null,
            ]),
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
        ?string $startTime,
        ?string $openStartTime,
        ?float $openStartBw,
        ?string $openConfirmTime,
        ?float $openConfirmBw,
        ?string $closeStartTime,
        ?float $closeStartBw,
        ?float $peakBwSinceOpenConfirm
    ): void {
        Db::table('oscillation_structures_v4')
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
                'open_start_time' => $openStartTime,
                'open_start_bw' => $openStartBw,
                'open_confirm_time' => $openConfirmTime,
                'open_confirm_bw' => $openConfirmBw,
                'close_start_time' => $closeStartTime,
                'close_start_bw' => $closeStartBw,
                'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function saveActiveStructure(
        int $id,
        array $xPoints,
        array $yPoints,
        array $engineState,
        ?string $startTime,
        ?string $openStartTime,
        ?float $openStartBw,
        ?string $openConfirmTime,
        ?float $openConfirmBw,
        ?string $closeStartTime,
        ?float $closeStartBw,
        ?float $peakBwSinceOpenConfirm
    ): void {
        Db::table('oscillation_structures_v4')
            ->where('id', $id)
            ->update([
                'x_points' => self::encodeJson($xPoints),
                'y_points' => self::encodeJson($yPoints),
                'engine_state' => self::encodeJson($engineState),
                'start_time' => $startTime,
                'open_start_time' => $openStartTime,
                'open_start_bw' => $openStartBw,
                'open_confirm_time' => $openConfirmTime,
                'open_confirm_bw' => $openConfirmBw,
                'close_start_time' => $closeStartTime,
                'close_start_bw' => $closeStartBw,
                'peak_bw_since_open_confirm' => $peakBwSinceOpenConfirm,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function normalizeKlineRow($rowObj): array
    {
        $row = (array)$rowObj;
        return [
            'open_time' => isset($row['open_time']) ? (string)$row['open_time'] : (string)($rowObj->open_time ?? ''),
            'high' => isset($row['high']) ? (float)$row['high'] : (float)($rowObj->high ?? 0),
            'low' => isset($row['low']) ? (float)$row['low'] : (float)($rowObj->low ?? 0),
            'close' => isset($row['close']) ? (float)$row['close'] : (float)($rowObj->close ?? 0),
            'boll_up' => self::maybeFloat($rowObj->boll_up ?? ($row['boll_up'] ?? null)),
            'boll_mb' => self::maybeFloat($rowObj->boll_mb ?? ($row['boll_mb'] ?? null)),
            'boll_dn' => self::maybeFloat($rowObj->boll_dn ?? ($row['boll_dn'] ?? null)),
            'bw' => self::maybeFloat($rowObj->bw ?? ($row['bw'] ?? null)),
            'mouth_state' => isset($row['mouth_state']) ? (int)$row['mouth_state'] : (int)($rowObj->mouth_state ?? 0),
        ];
    }

    private static function maybeFloat($v): ?float
    {
        if ($v === null) {
            return null;
        }
        if ($v === '') {
            return null;
        }
        return (float)$v;
    }

    private static function touchedMiddleBand(array $row): bool
    {
        $mb = $row['boll_mb'];
        if ($mb === null) {
            return false;
        }
        return (float)$row['low'] <= (float)$mb && (float)$row['high'] >= (float)$mb;
    }

    private static function updatePendingFromRow($pending, array $row): ?array
    {
        $bollDn = $row['boll_dn'];
        $bollUp = $row['boll_up'];
        if ($bollDn !== null && (float)$row['low'] < (float)$bollDn) {
            return self::updatePending($pending, 'X', $row['open_time'], (float)$row['low']);
        }
        if ($bollUp !== null && (float)$row['high'] > (float)$bollUp) {
            return self::updatePending($pending, 'Y', $row['open_time'], (float)$row['high']);
        }
        return $pending;
    }

    private static function updatePending($pending, string $kind, string $time, float $price): array
    {
        if ($pending === null || !is_array($pending)) {
            return [
                'kind' => $kind,
                'time' => $time,
                'price' => $price,
            ];
        }
        $pKind = isset($pending['kind']) ? (string)$pending['kind'] : '';
        if ($pKind !== $kind) {
            return [
                'kind' => $kind,
                'time' => $time,
                'price' => $price,
            ];
        }
        $pPrice = isset($pending['price']) ? (float)$pending['price'] : null;
        if ($kind === 'X') {
            if ($pPrice === null || $price < $pPrice) {
                $pending['time'] = $time;
                $pending['price'] = $price;
            }
        } else {
            if ($pPrice === null || $price > $pPrice) {
                $pending['time'] = $time;
                $pending['price'] = $price;
            }
        }
        return $pending;
    }

    private static function normalizeStructureRow($rowObj): array
    {
        $row = (array)$rowObj;
        $engineState = self::decodeJson($rowObj->engine_state ?? ($row['engine_state'] ?? null));
        return [
            'id' => (int)($rowObj->id ?? ($row['id'] ?? 0)),
            'symbol' => (string)($rowObj->symbol ?? ($row['symbol'] ?? '')),
            'interval' => (string)($rowObj->interval ?? ($row['interval'] ?? '')),
            'status' => (string)($rowObj->status ?? ($row['status'] ?? 'ACTIVE')),
            'x_points' => self::decodeJson($rowObj->x_points ?? ($row['x_points'] ?? null)) ?: [],
            'y_points' => self::decodeJson($rowObj->y_points ?? ($row['y_points'] ?? null)) ?: [],
            'engine_state' => is_array($engineState) ? $engineState : [],
            'start_time' => $rowObj->start_time !== null ? (string)$rowObj->start_time : ($row['start_time'] ?? null),
        ];
    }

    private static function buildEngineState(array $overrides): array
    {
        return [
            'phase' => $overrides['phase'] ?? 'STRUCTURE',
            'last_processed_open_time' => $overrides['last_processed_open_time'] ?? null,
            'last_mouth_state' => $overrides['last_mouth_state'] ?? 0,
            'open_start_time' => $overrides['open_start_time'] ?? null,
            'open_start_bw' => $overrides['open_start_bw'] ?? null,
            'open_confirm_time' => $overrides['open_confirm_time'] ?? null,
            'open_confirm_bw' => $overrides['open_confirm_bw'] ?? null,
            'peak_bw_since_open_confirm' => $overrides['peak_bw_since_open_confirm'] ?? null,
            'close_probe_time' => $overrides['close_probe_time'] ?? null,
            'close_probe_bw' => $overrides['close_probe_bw'] ?? null,
            'close_start_time' => $overrides['close_start_time'] ?? null,
            'close_start_bw' => $overrides['close_start_bw'] ?? null,
            'range_start_time' => $overrides['range_start_time'] ?? null,
            'range_bw_max' => $overrides['range_bw_max'] ?? null,
            'range_bw_min' => $overrides['range_bw_min'] ?? null,
            'range_bar_count' => $overrides['range_bar_count'] ?? 0,
            'range_mature' => $overrides['range_mature'] ?? false,
            'next_open_start_time' => $overrides['next_open_start_time'] ?? null,
            'next_open_start_bw' => $overrides['next_open_start_bw'] ?? null,
            'birth_outside_dir' => $overrides['birth_outside_dir'] ?? null,
            'birth_outside_count' => $overrides['birth_outside_count'] ?? 0,
            'birth_outside_start_time' => $overrides['birth_outside_start_time'] ?? null,
            'birth_temp' => $overrides['birth_temp'] ?? null,
            'birth_returned_inside' => $overrides['birth_returned_inside'] ?? false,
            'first_middle_touch_time' => $overrides['first_middle_touch_time'] ?? null,
            'pending' => $overrides['pending'] ?? null,
        ];
    }

    private static function normalizeAndLabelPoints($v, string $kind): array
    {
        $arr = [];
        if (is_array($v)) {
            $arr = $v;
        } elseif (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $arr = $decoded;
            }
        }
        $out = [];
        foreach ($arr as $p) {
            if (!is_array($p)) {
                continue;
            }
            $t = isset($p['time']) ? (string)$p['time'] : null;
            $price = isset($p['price']) ? (float)$p['price'] : null;
            if ($t === null || $t === '' || $price === null) {
                continue;
            }
            $out[] = [
                'time' => $t,
                'price' => $price,
                'kind' => $kind,
            ];
        }
        for ($i = 0; $i < count($out); $i++) {
            $out[$i]['label'] = $kind . (string)($i + 1);
        }
        return $out;
    }

    private static function timeLt(string $a, string $b): bool
    {
        return strtotime($a) < strtotime($b);
    }

    private static function encodeJson($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeJson($v)
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);
            return $decoded;
        }
        return null;
    }
}
