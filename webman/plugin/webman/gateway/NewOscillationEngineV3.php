<?php

namespace plugin\webman\gateway;

use support\Db;

class NewOscillationEngineV3
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

        $outsideConfirmBars = 30;
        $inBandConfirmBars = 30;
        $bandWidthFactor = 2.0;

        $active = self::getActiveStructure($symbol, $interval);
        if ($active === null) {
            $active = self::createStructure($symbol, $interval, null);
        }

        $xPoints = self::normalizeAndLabelPoints($active['x_points'] ?? null, 'X');
        $yPoints = self::normalizeAndLabelPoints($active['y_points'] ?? null, 'Y');

        $state = $active['engine_state'] ?? [];
        $phase = isset($state['phase']) ? (string)$state['phase'] : 'WAIT_EPISODE';
        $lastProcessed = isset($state['last_processed_open_time']) ? (string)$state['last_processed_open_time'] : null;

        $pending = isset($state['pending']) && is_array($state['pending']) ? $state['pending'] : null;
        $outsideSide = isset($state['outside_side']) ? (string)$state['outside_side'] : null;
        $outsideStreak = isset($state['outside_streak']) ? (int)$state['outside_streak'] : 0;
        $episodeSide = isset($state['episode_side']) ? (string)$state['episode_side'] : null;
        $episodeStartTime = isset($state['episode_start_time']) ? (string)$state['episode_start_time'] : null;
        $episodeConfirmTime = isset($state['episode_confirm_time']) ? (string)$state['episode_confirm_time'] : null;
        $startBollUp = array_key_exists('start_boll_up', $state) && $state['start_boll_up'] !== null ? (float)$state['start_boll_up'] : null;
        $startBollDn = array_key_exists('start_boll_dn', $state) && $state['start_boll_dn'] !== null ? (float)$state['start_boll_dn'] : null;
        $startBandWidth = array_key_exists('start_band_width', $state) && $state['start_band_width'] !== null ? (float)$state['start_band_width'] : null;
        $startBandTime = isset($state['start_band_time']) ? (string)$state['start_band_time'] : null;

        $reentryTime = isset($state['reentry_time']) ? (string)$state['reentry_time'] : null;
        $maxBandWidthAfterReentry = array_key_exists('max_band_width_after_reentry', $state) && $state['max_band_width_after_reentry'] !== null ? (float)$state['max_band_width_after_reentry'] : null;
        $band2xReached = isset($state['band_2x_reached']) ? (bool)$state['band_2x_reached'] : false;

        $aCandidateTime = isset($state['a_candidate_time']) ? (string)$state['a_candidate_time'] : null;
        $aCandidatePrice = array_key_exists('a_candidate_price', $state) && $state['a_candidate_price'] !== null ? (float)$state['a_candidate_price'] : null;
        $restartSide = isset($state['restart_side']) ? (string)$state['restart_side'] : null;
        $restartStreak = isset($state['restart_streak']) ? (int)$state['restart_streak'] : 0;
        $restartStartTime = isset($state['restart_start_time']) ? (string)$state['restart_start_time'] : null;
        $restartStartBollUp = array_key_exists('restart_start_boll_up', $state) && $state['restart_start_boll_up'] !== null ? (float)$state['restart_start_boll_up'] : null;
        $restartStartBollDn = array_key_exists('restart_start_boll_dn', $state) && $state['restart_start_boll_dn'] !== null ? (float)$state['restart_start_boll_dn'] : null;
        $restartStartBandWidth = array_key_exists('restart_start_band_width', $state) && $state['restart_start_band_width'] !== null ? (float)$state['restart_start_band_width'] : null;
        $restartStartBandTime = isset($state['restart_start_band_time']) ? (string)$state['restart_start_band_time'] : null;
        $lastUpperTouchTime = isset($state['last_upper_touch_time']) ? (string)$state['last_upper_touch_time'] : null;
        $lastLowerTouchTime = isset($state['last_lower_touch_time']) ? (string)$state['last_lower_touch_time'] : null;
        $flowToDnFirstTouchTime = isset($state['flow_to_dn_first_touch_time']) ? (string)$state['flow_to_dn_first_touch_time'] : null;
        $flowToUpFirstTouchTime = isset($state['flow_to_up_first_touch_time']) ? (string)$state['flow_to_up_first_touch_time'] : null;

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
        if (!$forceRebuild && $lastProcessed !== null && trim($lastProcessed) !== '') {
            $query->where('open_time', '>', $lastProcessed);
        }

        $updated = 0;
        $closed = 0;

        foreach ($query->cursor() as $rowObj) {
            $row = self::normalizeKlineRow($rowObj);
            $lastProcessed = $row['open_time'];

            $bollDn = $row['boll_dn'];
            $bollUp = $row['boll_up'];
            if ($pending !== null) {
                $kind = isset($pending['kind']) ? (string)$pending['kind'] : '';
                if ($kind === 'X') {
                    if ($bollDn !== null && (float)$row['low'] < (float)$bollDn) {
                        $pending = self::updatePending($pending, 'X', $row['open_time'], (float)$row['low']);
                    }
                } elseif ($kind === 'Y') {
                    if ($bollUp !== null && (float)$row['high'] > (float)$bollUp) {
                        $pending = self::updatePending($pending, 'Y', $row['open_time'], (float)$row['high']);
                    }
                }
                $isFirstPointOfStructure = count($xPoints) === 0 && count($yPoints) === 0;
                $pendingTime = isset($pending['time']) ? (string)$pending['time'] : '';
                if ($pendingTime !== '' && self::timeLt($pendingTime, (string)$row['open_time'])) {
                    if ($isFirstPointOfStructure) {
                        if (self::isInBand($row) && self::touchedMiddleBand($row)) {
                            $pending['middle_touched'] = true;
                        }
                    } elseif (self::touchedMiddleBand($row)) {
                        $pending['middle_touched'] = true;
                    }
                }
                if (self::isInBand($row)) {
                    $pending['in_band_streak'] = (int)($pending['in_band_streak'] ?? 0) + 1;
                } else {
                    $pending['in_band_streak'] = 0;
                }

                if ((bool)$pending['middle_touched'] || (!$isFirstPointOfStructure && (int)$pending['in_band_streak'] >= $inBandConfirmBars)) {
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
            if ($pending === null) {
                if ($bollDn !== null && (float)$row['low'] < (float)$bollDn) {
                    $pending = self::updatePending($pending, 'X', $row['open_time'], (float)$row['low']);
                } elseif ($bollUp !== null && (float)$row['high'] > (float)$bollUp) {
                    $pending = self::updatePending($pending, 'Y', $row['open_time'], (float)$row['high']);
                }
            }

            $touchUp = self::touchedUpperBand($row);
            $touchDn = self::touchedLowerBand($row);
            $prevLastUpperTouchTime = $lastUpperTouchTime;
            $prevLastLowerTouchTime = $lastLowerTouchTime;
            if ($touchUp) {
                $lastUpperTouchTime = $row['open_time'];
                $flowToDnFirstTouchTime = null;
            }
            if ($touchDn) {
                $lastLowerTouchTime = $row['open_time'];
                $flowToUpFirstTouchTime = null;
            }
            if (!$touchUp && $touchDn && $flowToDnFirstTouchTime === null && $prevLastUpperTouchTime !== null && self::timeLt($prevLastUpperTouchTime, $row['open_time'])) {
                $flowToDnFirstTouchTime = $row['open_time'];
            }
            if (!$touchDn && $touchUp && $flowToUpFirstTouchTime === null && $prevLastLowerTouchTime !== null && self::timeLt($prevLastLowerTouchTime, $row['open_time'])) {
                $flowToUpFirstTouchTime = $row['open_time'];
            }

            if ($phase === 'WAIT_EPISODE') {
                $side = self::outsideSide($row);
                if ($side === null) {
                    $outsideSide = null;
                    $outsideStreak = 0;
                    $restartSide = null;
                    $restartStreak = 0;
                    $restartStartTime = null;
                    $restartStartBollUp = null;
                    $restartStartBollDn = null;
                    $restartStartBandWidth = null;
                    continue;
                }

                if ($outsideSide === null || $outsideSide !== $side) {
                    $outsideSide = $side;
                    $outsideStreak = 1;
                    $episodeSide = $side;
                    $episodeStartTime = $row['open_time'];
                    $episodeConfirmTime = null;
                    $baseline = self::getEpisodeBaseline($symbol, $interval, $side, $episodeStartTime, $xPoints, $yPoints, $active['start_time'] ?? null, $flowToDnFirstTouchTime, $flowToUpFirstTouchTime);
                    $startBandTime = $baseline !== null ? $baseline['time'] : $episodeStartTime;
                    $bandInfo = $baseline !== null ? ['boll_up' => $baseline['boll_up'], 'boll_dn' => $baseline['boll_dn'], 'band_width' => $baseline['band_width']] : self::getBandInfoAtTime($symbol, $interval, $startBandTime);
                    if ($bandInfo !== null) {
                        $startBollUp = $bandInfo['boll_up'];
                        $startBollDn = $bandInfo['boll_dn'];
                        $startBandWidth = $bandInfo['band_width'];
                    } elseif ($row['boll_up'] !== null && $row['boll_dn'] !== null) {
                        $startBollUp = (float)$row['boll_up'];
                        $startBollDn = (float)$row['boll_dn'];
                        $startBandWidth = $startBollUp - $startBollDn;
                        $startBandTime = $episodeStartTime;
                    } else {
                        $startBollUp = null;
                        $startBollDn = null;
                        $startBandWidth = null;
                        $startBandTime = $episodeStartTime;
                    }
                } else {
                    $outsideStreak++;
                }

                if ($outsideStreak >= $outsideConfirmBars) {
                    $episodeConfirmTime = $row['open_time'];
                    $phase = 'WAIT_REENTRY';
                    $reentryTime = null;
                    $maxBandWidthAfterReentry = null;
                    $band2xReached = false;
                    $aCandidateTime = null;
                    $aCandidatePrice = null;
                    $restartSide = null;
                    $restartStreak = 0;
                    $restartStartTime = null;
                    $restartStartBollUp = null;
                    $restartStartBollDn = null;
                    $restartStartBandWidth = null;
                }
                continue;
            }

            if ($phase === 'WAIT_REENTRY') {
                $side = self::outsideSide($row);
                if ($side !== null && $episodeSide !== null && $side !== $episodeSide) {
                    $outsideSide = $side;
                    $outsideStreak = 1;
                    $episodeSide = $side;
                    $episodeStartTime = $row['open_time'];
                    $episodeConfirmTime = null;
                    $baseline = self::getEpisodeBaseline($symbol, $interval, $side, $episodeStartTime, $xPoints, $yPoints, $active['start_time'] ?? null, $flowToDnFirstTouchTime, $flowToUpFirstTouchTime);
                    $startBandTime = $baseline !== null ? $baseline['time'] : $episodeStartTime;
                    $bandInfo = $baseline !== null ? ['boll_up' => $baseline['boll_up'], 'boll_dn' => $baseline['boll_dn'], 'band_width' => $baseline['band_width']] : self::getBandInfoAtTime($symbol, $interval, $startBandTime);
                    if ($bandInfo !== null) {
                        $startBollUp = $bandInfo['boll_up'];
                        $startBollDn = $bandInfo['boll_dn'];
                        $startBandWidth = $bandInfo['band_width'];
                    } elseif ($row['boll_up'] !== null && $row['boll_dn'] !== null) {
                        $startBollUp = (float)$row['boll_up'];
                        $startBollDn = (float)$row['boll_dn'];
                        $startBandWidth = $startBollUp - $startBollDn;
                        $startBandTime = $episodeStartTime;
                    } else {
                        $startBollUp = null;
                        $startBollDn = null;
                        $startBandWidth = null;
                        $startBandTime = $episodeStartTime;
                    }
                    $phase = 'WAIT_EPISODE';
                    $reentryTime = null;
                    $maxBandWidthAfterReentry = null;
                    $band2xReached = false;
                    $aCandidateTime = null;
                    $aCandidatePrice = null;
                    $restartSide = null;
                    $restartStreak = 0;
                    $restartStartTime = null;
                    $restartStartBollUp = null;
                    $restartStartBollDn = null;
                    $restartStartBandWidth = null;
                    continue;
                }
                if (!self::isInBand($row)) {
                    continue;
                }
                $reentryTime = $row['open_time'];
                $maxBandWidthAfterReentry = self::bandWidth($row);
                $band2xReached = $startBandWidth !== null
                    && $maxBandWidthAfterReentry !== null
                    && (float)$maxBandWidthAfterReentry >= ((float)$startBandWidth * $bandWidthFactor);
                if ($aCandidateTime === null && self::isInBand($row) && self::touchedMiddleBand($row) && $row['boll_mb'] !== null) {
                    $aCandidateTime = $row['open_time'];
                    $aCandidatePrice = (float)$row['boll_mb'];
                }
                $restartSide = null;
                $restartStreak = 0;
                $restartStartTime = null;
                $restartStartBollUp = null;
                $restartStartBollDn = null;
                $restartStartBandWidth = null;
                $phase = 'WAIT_CONFIRM';
            }

            if ($phase === 'WAIT_CONFIRM') {
                $bw = self::bandWidth($row);
                if ($bw !== null) {
                    $maxBandWidthAfterReentry = $maxBandWidthAfterReentry === null ? $bw : max((float)$maxBandWidthAfterReentry, $bw);
                }
                if (!$band2xReached && $startBandWidth !== null && $maxBandWidthAfterReentry !== null) {
                    if ((float)$maxBandWidthAfterReentry >= ((float)$startBandWidth * $bandWidthFactor)) {
                        $band2xReached = true;
                    }
                }
                if ($aCandidateTime === null && self::isInBand($row) && self::touchedMiddleBand($row) && $row['boll_mb'] !== null) {
                    $aCandidateTime = $row['open_time'];
                    $aCandidatePrice = (float)$row['boll_mb'];
                }

                $side = self::outsideSide($row);
                if ($side !== null) {
                    if ($restartSide === null || $restartSide !== $side) {
                        $restartSide = $side;
                        $restartStreak = 1;
                        $restartStartTime = $row['open_time'];
                        $baseline = self::getEpisodeBaseline($symbol, $interval, $side, $restartStartTime, $xPoints, $yPoints, $active['start_time'] ?? null, $flowToDnFirstTouchTime, $flowToUpFirstTouchTime);
                        $restartStartBandTime = $baseline !== null ? $baseline['time'] : $restartStartTime;
                        $bandInfo = $baseline !== null ? ['boll_up' => $baseline['boll_up'], 'boll_dn' => $baseline['boll_dn'], 'band_width' => $baseline['band_width']] : self::getBandInfoAtTime($symbol, $interval, $restartStartBandTime);
                        if ($bandInfo !== null) {
                            $restartStartBollUp = $bandInfo['boll_up'];
                            $restartStartBollDn = $bandInfo['boll_dn'];
                            $restartStartBandWidth = $bandInfo['band_width'];
                        } elseif ($row['boll_up'] !== null && $row['boll_dn'] !== null) {
                            $restartStartBollUp = (float)$row['boll_up'];
                            $restartStartBollDn = (float)$row['boll_dn'];
                            $restartStartBandWidth = $restartStartBollUp - $restartStartBollDn;
                            $restartStartBandTime = $restartStartTime;
                        } else {
                            $restartStartBollUp = null;
                            $restartStartBollDn = null;
                            $restartStartBandWidth = null;
                            $restartStartBandTime = $restartStartTime;
                        }
                    } else {
                        $restartStreak++;
                    }

                    if ($restartStreak >= $outsideConfirmBars) {
                        $outsideSide = $restartSide;
                        $outsideStreak = $restartStreak;
                        $episodeSide = $restartSide;
                        $episodeStartTime = $restartStartTime;
                        $episodeConfirmTime = $row['open_time'];
                        $startBollUp = $restartStartBollUp;
                        $startBollDn = $restartStartBollDn;
                        $startBandWidth = $restartStartBandWidth;
                        $startBandTime = $restartStartBandTime;
                        $phase = 'WAIT_REENTRY';
                        $reentryTime = null;
                        $maxBandWidthAfterReentry = null;
                        $band2xReached = false;
                        $aCandidateTime = null;
                        $aCandidatePrice = null;
                        $restartSide = null;
                        $restartStreak = 0;
                        $restartStartTime = null;
                        $restartStartBollUp = null;
                        $restartStartBollDn = null;
                        $restartStartBandWidth = null;
                        $restartStartBandTime = null;
                    }
                    if ($restartStreak >= $outsideConfirmBars) {
                        continue;
                    }
                }
                if ($side === null) {
                    $restartSide = null;
                    $restartStreak = 0;
                    $restartStartTime = null;
                    $restartStartBollUp = null;
                    $restartStartBollDn = null;
                    $restartStartBandWidth = null;
                    $restartStartBandTime = null;
                }

                if ($band2xReached && $aCandidateTime !== null && $aCandidatePrice !== null) {
                    $aPoint = [
                        'time' => $aCandidateTime,
                        'price' => (float)$aCandidatePrice,
                        'side' => $episodeSide,
                    ];
                    $episode = [
                        'side' => $episodeSide,
                        'start_time' => $episodeStartTime,
                        'confirm_time' => $episodeConfirmTime,
                        'start_band_time' => $startBandTime,
                        'start_boll_up' => $startBollUp,
                        'start_boll_dn' => $startBollDn,
                        'start_band_width' => $startBandWidth,
                        'band_width_factor' => $bandWidthFactor,
                        'band_width_threshold' => $startBandWidth !== null ? ((float)$startBandWidth * $bandWidthFactor) : null,
                        'reentry_time' => $reentryTime,
                        'max_band_width_after_reentry' => $maxBandWidthAfterReentry,
                    ];

                    $newStartTime = $episodeStartTime !== null ? (string)$episodeStartTime : (string)$row['open_time'];
                    $splitX = self::splitPointsByBoundary($xPoints, $newStartTime);
                    $splitY = self::splitPointsByBoundary($yPoints, $newStartTime);
                    $oldXPoints = $splitX['before'];
                    $oldYPoints = $splitY['before'];
                    $carryXPoints = $splitX['after'];
                    $carryYPoints = $splitY['after'];

                    $oldPending = null;
                    $carryPending = null;
                    if ($pending !== null && isset($pending['time'])) {
                        $pendingTime = (string)$pending['time'];
                        if (self::timeLt($pendingTime, $newStartTime)) {
                            $oldPending = $pending;
                        } else {
                            $carryPending = $pending;
                        }
                    }

                    $shouldCloseOld = ($active['a_point'] !== null) || (count($xPoints) > 0) || (count($yPoints) > 0);
                    if ($shouldCloseOld) {
                        $closeEngineState = [
                            'phase' => $phase,
                            'last_processed_open_time' => $lastProcessed,
                            'pending' => $oldPending,
                            'outside_side' => $outsideSide,
                            'outside_streak' => $outsideStreak,
                            'episode_side' => $episodeSide,
                            'episode_start_time' => $episodeStartTime,
                            'episode_confirm_time' => $episodeConfirmTime,
                            'start_boll_up' => $startBollUp,
                            'start_boll_dn' => $startBollDn,
                            'start_band_width' => $startBandWidth,
                            'start_band_time' => $startBandTime,
                            'reentry_time' => $reentryTime,
                            'max_band_width_after_reentry' => $maxBandWidthAfterReentry,
                            'band_2x_reached' => $band2xReached,
                            'a_candidate_time' => $aCandidateTime,
                            'a_candidate_price' => $aCandidatePrice,
                            'restart_side' => $restartSide,
                            'restart_streak' => $restartStreak,
                            'restart_start_time' => $restartStartTime,
                            'restart_start_boll_up' => $restartStartBollUp,
                            'restart_start_boll_dn' => $restartStartBollDn,
                            'restart_start_band_width' => $restartStartBandWidth,
                            'restart_start_band_time' => $restartStartBandTime,
                        ];
                        self::closeStructure(
                            (int)$active['id'],
                            $newStartTime,
                            'BREAK_EPISODE_2X',
                            [
                                'episode_start_time' => $episodeStartTime,
                                'episode_confirm_time' => $episodeConfirmTime,
                                'start_band_time' => $startBandTime,
                                'reentry_time' => $reentryTime,
                                'threshold_time' => $row['open_time'],
                                'a_time' => $aCandidateTime,
                                'band_width_factor' => $bandWidthFactor,
                                'start_band_width' => $startBandWidth,
                                'band_width_threshold' => $startBandWidth !== null ? ((float)$startBandWidth * $bandWidthFactor) : null,
                                'max_band_width_after_reentry' => $maxBandWidthAfterReentry,
                            ],
                            $oldXPoints,
                            $oldYPoints,
                            $active['a_point'],
                            $active['episode'],
                            $closeEngineState,
                            $active['start_time']
                        );
                        $closed++;
                    }

                    $active = self::createStructure($symbol, $interval, $newStartTime);
                    $xPoints = self::relabelPoints($carryXPoints, 'X');
                    $yPoints = self::relabelPoints($carryYPoints, 'Y');
                    $pending = $carryPending;

                    $phase = 'WAIT_EPISODE';
                    $outsideSide = null;
                    $outsideStreak = 0;
                    $episodeSide = null;
                    $episodeStartTime = null;
                    $episodeConfirmTime = null;
                    $startBollUp = null;
                    $startBollDn = null;
                    $startBandWidth = null;
                    $startBandTime = null;
                    $reentryTime = null;
                    $maxBandWidthAfterReentry = null;
                    $band2xReached = false;
                    $aCandidateTime = null;
                    $aCandidatePrice = null;
                    $restartSide = null;
                    $restartStreak = 0;
                    $restartStartTime = null;
                    $restartStartBollUp = null;
                    $restartStartBollDn = null;
                    $restartStartBandWidth = null;
                    $restartStartBandTime = null;
                    $lastUpperTouchTime = null;
                    $lastLowerTouchTime = null;
                    $flowToDnFirstTouchTime = null;
                    $flowToUpFirstTouchTime = null;

                    $engineState = [
                        'phase' => $phase,
                        'last_processed_open_time' => $lastProcessed,
                        'pending' => $pending,
                        'outside_side' => $outsideSide,
                        'outside_streak' => $outsideStreak,
                        'episode_side' => $episodeSide,
                        'episode_start_time' => $episodeStartTime,
                        'episode_confirm_time' => $episodeConfirmTime,
                        'start_boll_up' => $startBollUp,
                        'start_boll_dn' => $startBollDn,
                        'start_band_width' => $startBandWidth,
                        'start_band_time' => $startBandTime,
                        'reentry_time' => $reentryTime,
                        'max_band_width_after_reentry' => $maxBandWidthAfterReentry,
                        'band_2x_reached' => $band2xReached,
                        'a_candidate_time' => $aCandidateTime,
                        'a_candidate_price' => $aCandidatePrice,
                        'restart_side' => $restartSide,
                        'restart_streak' => $restartStreak,
                        'restart_start_time' => $restartStartTime,
                        'restart_start_boll_up' => $restartStartBollUp,
                        'restart_start_boll_dn' => $restartStartBollDn,
                        'restart_start_band_width' => $restartStartBandWidth,
                        'restart_start_band_time' => $restartStartBandTime,
                        'last_upper_touch_time' => $lastUpperTouchTime,
                        'last_lower_touch_time' => $lastLowerTouchTime,
                        'flow_to_dn_first_touch_time' => $flowToDnFirstTouchTime,
                        'flow_to_up_first_touch_time' => $flowToUpFirstTouchTime,
                    ];
                    self::saveActiveStructure(
                        (int)$active['id'],
                        $xPoints,
                        $yPoints,
                        $aPoint,
                        $episode,
                        $engineState,
                        $newStartTime
                    );
                    $active['a_point'] = $aPoint;
                    $active['episode'] = $episode;
                    $active['engine_state'] = $engineState;
                    $active['start_time'] = $newStartTime;
                    $updated++;
                }
            }
        }

        $engineState = [
            'phase' => $phase,
            'last_processed_open_time' => $lastProcessed,
            'pending' => $pending,
            'outside_side' => $outsideSide,
            'outside_streak' => $outsideStreak,
            'episode_side' => $episodeSide,
            'episode_start_time' => $episodeStartTime,
            'episode_confirm_time' => $episodeConfirmTime,
            'start_boll_up' => $startBollUp,
            'start_boll_dn' => $startBollDn,
            'start_band_width' => $startBandWidth,
            'start_band_time' => $startBandTime,
            'reentry_time' => $reentryTime,
            'max_band_width_after_reentry' => $maxBandWidthAfterReentry,
            'band_2x_reached' => $band2xReached,
            'a_candidate_time' => $aCandidateTime,
            'a_candidate_price' => $aCandidatePrice,
            'restart_side' => $restartSide,
            'restart_streak' => $restartStreak,
            'restart_start_time' => $restartStartTime,
            'restart_start_boll_up' => $restartStartBollUp,
            'restart_start_boll_dn' => $restartStartBollDn,
            'restart_start_band_width' => $restartStartBandWidth,
            'restart_start_band_time' => $restartStartBandTime,
            'last_upper_touch_time' => $lastUpperTouchTime,
            'last_lower_touch_time' => $lastLowerTouchTime,
            'flow_to_dn_first_touch_time' => $flowToDnFirstTouchTime,
            'flow_to_up_first_touch_time' => $flowToUpFirstTouchTime,
        ];
        self::saveActiveStructure(
            (int)$active['id'],
            $xPoints,
            $yPoints,
            $active['a_point'],
            $active['episode'],
            $engineState,
            $active['start_time']
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
            Db::statement('CREATE TABLE IF NOT EXISTS oscillation_structures_v3 (
                id bigserial PRIMARY KEY,
                symbol text NOT NULL,
                interval text NOT NULL,
                status text NOT NULL DEFAULT \'ACTIVE\',
                x_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                y_points jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                a_point jsonb,
                episode jsonb,
                close_reason text,
                close_condition jsonb,
                engine_state jsonb,
                start_time timestamp,
                end_time timestamp,
                created_at timestamp NOT NULL DEFAULT now(),
                updated_at timestamp NOT NULL DEFAULT now()
            )');
            Db::statement('ALTER TABLE oscillation_structures_v3 ADD COLUMN IF NOT EXISTS x_points jsonb NOT NULL DEFAULT \'[]\'::jsonb');
            Db::statement('ALTER TABLE oscillation_structures_v3 ADD COLUMN IF NOT EXISTS y_points jsonb NOT NULL DEFAULT \'[]\'::jsonb');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v3_symbol_interval_status_idx ON oscillation_structures_v3(symbol, interval, status)');
            Db::statement('CREATE INDEX IF NOT EXISTS oscillation_structures_v3_symbol_interval_id_idx ON oscillation_structures_v3(symbol, interval, id DESC)');
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
    }
    private static function deleteAllStructures(string $symbol, string $interval): void
    {
        try {
            Db::table('oscillation_structures_v3')
                ->where('symbol', $symbol)
                ->where('interval', $interval)
                ->delete();
        } catch (\Throwable $e) {
        }
    }


    private static function getActiveStructure(string $symbol, string $interval): ?array
    {
        $row = Db::table('oscillation_structures_v3')
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
        $id = Db::table('oscillation_structures_v3')->insertGetId([
            'symbol' => $symbol,
            'interval' => $interval,
            'status' => 'ACTIVE',
            'x_points' => self::encodeJson([]),
            'y_points' => self::encodeJson([]),
            'a_point' => null,
            'episode' => null,
            'engine_state' => self::encodeJson([
                'phase' => 'WAIT_EPISODE',
                'last_processed_open_time' => null,
                'pending' => null,
                'outside_side' => null,
                'outside_streak' => 0,
                'episode_side' => null,
                'episode_start_time' => null,
                'episode_confirm_time' => null,
                'start_boll_up' => null,
                'start_boll_dn' => null,
                'start_band_width' => null,
                'start_band_time' => null,
                'reentry_time' => null,
                'max_band_width_after_reentry' => null,
                'band_2x_reached' => false,
                'a_candidate_time' => null,
                'a_candidate_price' => null,
                'restart_side' => null,
                'restart_streak' => 0,
                'restart_start_time' => null,
                'restart_start_boll_up' => null,
                'restart_start_boll_dn' => null,
                'restart_start_band_width' => null,
                'restart_start_band_time' => null,
            'last_upper_touch_time' => null,
            'last_lower_touch_time' => null,
            'flow_to_dn_first_touch_time' => null,
            'flow_to_up_first_touch_time' => null,
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
            'a_point' => null,
            'episode' => null,
            'engine_state' => [
                'phase' => 'WAIT_EPISODE',
                'last_processed_open_time' => null,
                'pending' => null,
                'outside_side' => null,
                'outside_streak' => 0,
                'episode_side' => null,
                'episode_start_time' => null,
                'episode_confirm_time' => null,
                'start_boll_up' => null,
                'start_boll_dn' => null,
                'start_band_width' => null,
                'start_band_time' => null,
                'reentry_time' => null,
                'max_band_width_after_reentry' => null,
                'band_2x_reached' => false,
                'a_candidate_time' => null,
                'a_candidate_price' => null,
                'restart_side' => null,
                'restart_streak' => 0,
                'restart_start_time' => null,
                'restart_start_boll_up' => null,
                'restart_start_boll_dn' => null,
                'restart_start_band_width' => null,
                'restart_start_band_time' => null,
                'last_upper_touch_time' => null,
                'last_lower_touch_time' => null,
                'flow_to_dn_first_touch_time' => null,
                'flow_to_up_first_touch_time' => null,
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
        $aPoint,
        $episode,
        array $engineState,
        ?string $startTime
    ): void {
        Db::table('oscillation_structures_v3')
            ->where('id', $id)
            ->update([
                'status' => 'CLOSED',
                'close_reason' => $reason,
                'close_condition' => self::encodeJson($condition),
                'engine_state' => self::encodeJson($engineState),
                'x_points' => self::encodeJson($xPoints),
                'y_points' => self::encodeJson($yPoints),
                'a_point' => $aPoint === null ? null : self::encodeJson($aPoint),
                'episode' => $episode === null ? null : self::encodeJson($episode),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function saveActiveStructure(
        int $id,
        array $xPoints,
        array $yPoints,
        $aPoint,
        $episode,
        array $engineState,
        ?string $startTime
    ): void {
        Db::table('oscillation_structures_v3')
            ->where('id', $id)
            ->update([
                'x_points' => self::encodeJson($xPoints),
                'y_points' => self::encodeJson($yPoints),
                'a_point' => $aPoint === null ? null : self::encodeJson($aPoint),
                'episode' => $episode === null ? null : self::encodeJson($episode),
                'engine_state' => self::encodeJson($engineState),
                'start_time' => $startTime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private static function normalizeStructureRow(object $row): array
    {
        return [
            'id' => (int)$row->id,
            'symbol' => (string)$row->symbol,
            'interval' => (string)$row->interval,
            'status' => (string)$row->status,
            'x_points' => self::decodeJson($row->x_points) ?? [],
            'y_points' => self::decodeJson($row->y_points) ?? [],
            'a_point' => self::decodeJson($row->a_point),
            'episode' => self::decodeJson($row->episode),
            'engine_state' => self::decodeJson($row->engine_state) ?? [],
            'start_time' => $row->start_time !== null ? (string)$row->start_time : null,
            'end_time' => $row->end_time !== null ? (string)$row->end_time : null,
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
            'boll_mb' => $row->boll_mb !== null ? (float)$row->boll_mb : null,
            'boll_dn' => $row->boll_dn !== null ? (float)$row->boll_dn : null,
        ];
    }

    private static function bandWidth(array $row): ?float
    {
        if ($row['boll_up'] === null || $row['boll_dn'] === null) {
            return null;
        }
        return (float)$row['boll_up'] - (float)$row['boll_dn'];
    }

    private static function touchedMiddleBand(array $row): bool
    {
        if ($row['boll_mb'] === null) {
            return false;
        }
        $mb = (float)$row['boll_mb'];
        return (float)$row['low'] <= $mb && (float)$row['high'] >= $mb;
    }

    private static function touchedUpperBand(array $row): bool
    {
        if ($row['boll_up'] === null) {
            return false;
        }
        return (float)$row['high'] > (float)$row['boll_up'];
    }

    private static function touchedLowerBand(array $row): bool
    {
        if ($row['boll_dn'] === null) {
            return false;
        }
        return (float)$row['low'] < (float)$row['boll_dn'];
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

    private static function updatePending(?array $pending, string $kind, string $time, float $price): array
    {
        if ($pending === null || !isset($pending['kind']) || (string)$pending['kind'] !== $kind) {
            return [
                'kind' => $kind,
                'time' => $time,
                'price' => $price,
                'in_band_streak' => 0,
                'middle_touched' => false,
            ];
        }
        $prevPrice = (float)$pending['price'];
        $out = $pending;
        $out['kind'] = $kind;
        if ($kind === 'X') {
            if ($price < $prevPrice) {
                $out['time'] = $time;
                $out['price'] = $price;
                $out['in_band_streak'] = 0;
                $out['middle_touched'] = false;
                return $out;
            }
        } else {
            if ($price > $prevPrice) {
                $out['time'] = $time;
                $out['price'] = $price;
                $out['in_band_streak'] = 0;
                $out['middle_touched'] = false;
                return $out;
            }
        }
        return $out;
    }

    private static function normalizeAndLabelPoints($raw, string $kind): array
    {
        $arr = self::decodeJson($raw) ?? [];
        if (!is_array($arr)) {
            $arr = [];
        }
        $out = [];
        $i = 0;
        foreach ($arr as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!isset($p['time']) || !isset($p['price'])) {
                continue;
            }
            $ptKind = isset($p['kind']) ? (string)$p['kind'] : $kind;
            if ($ptKind !== $kind) {
                continue;
            }
            $i++;
            $out[] = [
                'time' => (string)$p['time'],
                'price' => (float)$p['price'],
                'kind' => $kind,
                'label' => isset($p['label']) ? (string)$p['label'] : ($kind . (string)$i),
            ];
        }
        return $out;
    }

    private static function timeLt(string $a, string $b): bool
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) {
            return $a < $b;
        }
        return $ta < $tb;
    }

    private static function findLastPointTime(array $points, string $kind, string $beforeTime): ?string
    {
        $best = null;
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['time'])) {
                continue;
            }
            if (isset($p['kind']) && (string)$p['kind'] !== $kind) {
                continue;
            }
            $t = (string)$p['time'];
            if (!self::timeLt($t, $beforeTime)) {
                continue;
            }
            if ($best === null || self::timeLt($best, $t)) {
                $best = $t;
            }
        }
        return $best;
    }

    private static function getBandInfoAtTime(string $symbol, string $interval, string $time): ?array
    {
        if (!preg_match('/^[0-9a-z]+$/', $interval)) {
            return null;
        }
        $table = 'kline_' . $interval;
        try {
            $row = Db::table($table)
                ->where('symbol', $symbol)
                ->where('open_time', $time)
                ->select(['boll_up', 'boll_dn'])
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $up = isset($row->boll_up) ? $row->boll_up : (is_array($row) ? ($row['boll_up'] ?? null) : null);
        $dn = isset($row->boll_dn) ? $row->boll_dn : (is_array($row) ? ($row['boll_dn'] ?? null) : null);
        if ($up === null || $dn === null) {
            return null;
        }
        return [
            'boll_up' => (float)$up,
            'boll_dn' => (float)$dn,
            'band_width' => (float)$up - (float)$dn,
        ];
    }

    private static function chooseBaselineTime(
        string $symbol,
        string $interval,
        string $side,
        string $beforeTime,
        array $xPoints,
        array $yPoints,
        ?string $structureStartTime
    ): ?string {
        $sameKind = $side === 'DN' ? 'X' : 'Y';
        $oppKind = $side === 'DN' ? 'Y' : 'X';

        $sameLast = $sameKind === 'X'
            ? self::findLastPointTime($xPoints, 'X', $beforeTime)
            : self::findLastPointTime($yPoints, 'Y', $beforeTime);
        if ($sameLast !== null) {
            return $sameLast;
        }

        $afterTime = $oppKind === 'X'
            ? self::findLastPointTime($xPoints, 'X', $beforeTime)
            : self::findLastPointTime($yPoints, 'Y', $beforeTime);
        if ($afterTime === null || trim($afterTime) === '') {
            $afterTime = $structureStartTime;
        }
        if ($afterTime === null || trim($afterTime) === '') {
            $afterTime = '1970-01-01 00:00:00';
        }

        return self::findFirstBandTouchTime($symbol, $interval, $side, $afterTime, $beforeTime);
    }

    private static function findFirstBandTouchTime(
        string $symbol,
        string $interval,
        string $side,
        string $afterTime,
        string $beforeTime
    ): ?string {
        if (!preg_match('/^[0-9a-z]+$/', $interval)) {
            return null;
        }
        $table = 'kline_' . $interval;
        $cond = $side === 'DN' ? 'low < boll_dn' : 'high > boll_up';
        try {
            $rows = Db::select(
                "SELECT open_time FROM {$table}
                 WHERE symbol = ?
                   AND open_time >= ?
                   AND open_time < ?
                   AND boll_up IS NOT NULL
                   AND boll_dn IS NOT NULL
                   AND {$cond}
                 ORDER BY open_time ASC
                 LIMIT 1",
                [$symbol, $afterTime, $beforeTime]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($rows)) {
            return null;
        }
        $r = $rows[0];
        if (is_object($r) && isset($r->open_time)) {
            return (string)$r->open_time;
        }
        if (is_array($r) && isset($r['open_time'])) {
            return (string)$r['open_time'];
        }
        return null;
    }

    private static function getEpisodeBaseline(
        string $symbol,
        string $interval,
        string $side,
        string $episodeStartTime,
        array $xPoints,
        array $yPoints,
        ?string $structureStartTime,
        ?string $flowToDnFirstTouchTime,
        ?string $flowToUpFirstTouchTime
    ): ?array {
        if ($side === 'DN') {
            if ($flowToDnFirstTouchTime !== null && trim($flowToDnFirstTouchTime) !== '' && !self::timeLt($episodeStartTime, $flowToDnFirstTouchTime)) {
                $baselineTime = $flowToDnFirstTouchTime;
            } else {
            $lastX = self::findLastPointTime($xPoints, 'X', $episodeStartTime);
            if ($lastX !== null) {
                $baselineTime = $lastX;
            } else {
                $lastY = self::findLastPointTime($yPoints, 'Y', $episodeStartTime);
                $afterTime = $lastY ?? $structureStartTime ?? '1970-01-01 00:00:00';
                $baselineTime = self::findFirstBandTouchTime($symbol, $interval, $side, $afterTime, $episodeStartTime) ?? $episodeStartTime;
            }
            }
        } else {
            if ($flowToUpFirstTouchTime !== null && trim($flowToUpFirstTouchTime) !== '' && !self::timeLt($episodeStartTime, $flowToUpFirstTouchTime)) {
                $baselineTime = $flowToUpFirstTouchTime;
            } else {
            $lastX = self::findLastPointTime($xPoints, 'X', $episodeStartTime);
            $afterTime = $lastX ?? $structureStartTime ?? '1970-01-01 00:00:00';
            $baselineTime = self::findFirstBandTouchTime($symbol, $interval, $side, $afterTime, $episodeStartTime) ?? $episodeStartTime;
            }
        }
        $info = self::getBandInfoAtTime($symbol, $interval, $baselineTime);
        if ($info === null) {
            return null;
        }
        return [
            'time' => $baselineTime,
            'boll_up' => $info['boll_up'],
            'boll_dn' => $info['boll_dn'],
            'band_width' => $info['band_width'],
        ];
    }

    private static function splitPointsByBoundary(array $points, string $boundaryTime): array
    {
        $before = [];
        $after = [];
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['time']) || !isset($p['price'])) {
                continue;
            }
            $t = (string)$p['time'];
            if (self::timeLt($t, $boundaryTime)) {
                $before[] = $p;
            } else {
                $after[] = $p;
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    private static function relabelPoints(array $points, string $kind): array
    {
        $out = [];
        $i = 0;
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['time']) || !isset($p['price'])) {
                continue;
            }
            $ptKind = isset($p['kind']) ? (string)$p['kind'] : $kind;
            if ($ptKind !== $kind) {
                continue;
            }
            $i++;
            $out[] = [
                'time' => (string)$p['time'],
                'price' => (float)$p['price'],
                'kind' => $kind,
                'label' => $kind . (string)$i,
            ];
        }
        return $out;
    }
}
