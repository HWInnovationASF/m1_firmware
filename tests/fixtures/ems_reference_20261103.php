<?php
// Original supplied 2026-11-03 functions; test harness stubs every side effect.
function parse_hour_to_decimal($value) {
    if (strpos((string)$value, ':') !== false) {
        list($h, $m) = explode(':', (string)$value, 2);
        return (float)$h + (float)$m / 60.0;
    }
    return (float)$value;
}

function ems_clamp($value, $min, $max) {
    return max($min, min($max, $value));
}

// Adjust one or more inverter ceilings, proportional to their AVAILABLE headroom.
// Return desired per-inverter setpoints; never reverse the direction of a release/curtail.
// Estimate additional solar output only for inverters currently near their power ceiling.
// A ceiling far above actual output is NOT recoverable energy under current irradiance.
function ems_estimate_release_kw($commands, $pmax_arr, $pout_arr, $tolerance = 5.0) {
    $gain = 0.0;
    foreach ($commands as $sn => $new_ceiling) {
        $old = (float)($pmax_arr[$sn] ?? 0);
        $actual = (float)($pout_arr[$sn] ?? 0);
        //if ($new_ceiling > $old && $actual >= $old - $tolerance) $gain += $new_ceiling - $old;
		if ($new_ceiling > $old && $actual >= $old - $tolerance) $gain += max(0.0, $new_ceiling - max($old, $actual));
    }
    return $gain;
}

// Curtail against measured SOLAR OUTPUT, not just existing p_max: reducing a
// ceiling from 150 to 145 cannot stop a unit currently producing 70 kW.
// A fractional curtailment proportional to p_out provides a conservative first
// request; subsequent cycles must verify PEA and apply further curtailment.
function ems_curtail_from_output($rated_arr, $pmax_arr, $pout_arr, $required_kw) {
    $commands = [];
    $total_output = 0.0;
    foreach ($rated_arr as $sn => $rated) {
        if (!isset($pmax_arr[$sn], $pout_arr[$sn])) return [];
        $total_output += max(0.0, (float)$pout_arr[$sn]);
    }
    if ($total_output <= 0 || $required_kw <= 0) return [];
    $fraction = min(1.0, $required_kw / $total_output);
    foreach ($rated_arr as $sn => $rated) {
        $actual = max(0.0, (float)$pout_arr[$sn]);
        $current = (float)$pmax_arr[$sn];
        if ($actual <= 0) continue;
        // Round DOWN to avoid skipping small emergency commands.
        $new = max(1, (int)floor($actual * (1.0 - $fraction)));
        if ($new < $current) $commands[$sn] = $new;
    }
    return $commands;
}

function ems_allocate_inverters($rated_arr, $pmax_arr, $pout_arr, $delta_kw, $emergency = false) {
    $result = [];
    $remaining = abs($delta_kw);
    if ($remaining < 0.001) return $result;
    $release = ($delta_kw > 0);
    $room = [];
    foreach ($rated_arr as $sn => $rated_raw) {
        if (!isset($pmax_arr[$sn]) || !is_numeric($pmax_arr[$sn])) continue;
        $rated = (float)$rated_raw;
        //$current = (float)$pmax_arr[$sn];
        $current = $release ? max((float)$pmax_arr[$sn], (float)($pout_arr[$sn] ?? 0)) : (float)$pmax_arr[$sn];
        if ($rated <= 0 || $current < 0 || $current > $rated + 1.0) continue;
        // Solar curtailment must be allowed to reach zero for emergency control.
        $capacity = $release? max(0.0, $rated - $current): max(0.0, $current - 1.0);
        if ($capacity > 0) $room[$sn] = $capacity;
    }
    if (!$room) return $result;
    $remaining = min($remaining, array_sum($room));
    $pending = $room;
    $change = array_fill_keys(array_keys($room), 0.0);
    for ($round = 0; $round < 5 && $remaining > 0.0001 && $pending; $round++) {
        $total_room = array_sum($pending);
        if ($total_room <= 0) break;
        $allocated = 0.0;
        foreach ($pending as $sn => $cap) {
            $take = min($cap, $remaining * $cap / $total_room);
            $change[$sn] += $take;
            $allocated += $take;
            $pending[$sn] -= $take;
            if ($pending[$sn] < 0.0001) unset($pending[$sn]);
        }
        $remaining = max(0.0, $remaining - $allocated);
    }
    foreach ($change as $sn => $shift) {
        //$current = (float)$pmax_arr[$sn];
		$current = $release ? max((float)$pmax_arr[$sn], (float)($pout_arr[$sn] ?? 0)) : (float)$pmax_arr[$sn];
        $rated = (float)$rated_arr[$sn];
        if ($release) $new = min($rated, floor($current + $shift));
        else $new = max(1, ceil($current - $shift));
        if ($release && $new < $current) continue;
        if (!$release && $new > $current) continue;
        if (abs($new - $current) < ($emergency ? 0.5 : 1.0)) continue;
        $result[$sn] = (int)$new;
    }
    return $result;
}


//$config_file: omitted entrypoint
function find_pt_bess($input_arr, $config_arr) {
    global $write_log;

    $pt_main = (float)$input_arr['pt_main'];
    $qt_main = (float)($input_arr['qt_main'] ?? 0);
    $pt_bess = (float)$input_arr['pt_bess']; // + discharge, - charge
    $qt_bess = (float)($input_arr['qt_bess'] ?? 0);
    $bess_soc = (float)$input_arr['bess_soc'];
    $ev_total = (float)($input_arr['ev_charger_total'] ?? 0);
    $ev_limit = (float)($input_arr['ev_charger_limit'] ?? 0);
    $timestamp = (int)($input_arr['TS'] ?? time());
    $device_SN = $input_arr['device_SN'] ?? 'M117289601136254';
    $ev_min_kw = max(0.0, (float)($config_arr['EV']['min_limit_kw'] ?? 30));
    $ev_sid = $config_arr['EV']['sid'] ?? 'TH000001';
    $inv_rated_arr = $config_arr['INV_RATED'] ?? [];
    $inv_p_max_arr = $input_arr['inv']['p_max'] ?? [];
    $inv_p_out_arr = $input_arr['inv']['p_out'] ?? [];
    $inv_total_rated = array_sum($inv_rated_arr);
    $inv_total_p_max = array_sum($inv_p_max_arr);
    $pt_inv = array_sum($inv_p_out_arr);

    $bess_cfg = $config_arr['BESS'] ?? [];
    $bess_allow_control = (bool)($bess_cfg['allow_control'] ?? true); // false = monitor only; never request BESS commands
    $bess_kwh = (float)($bess_cfg['kwh'] ?? 240);
    $max_charge = min(0.0, (float)($bess_cfg['p_max_cha'] ?? -100));
    $max_discharge = max(0.0, (float)($bess_cfg['p_max_dis'] ?? 100));
    $min_soc = (float)($bess_cfg['min_soc'] ?? 10);
    $max_soc = (float)($bess_cfg['max_soc'] ?? 95);
    $bess_online = ($bess_soc > 0 && $bess_soc <= 100);
    $can_charge = ($bess_allow_control && $bess_online && $bess_soc < $max_soc);
    $can_discharge = ($bess_allow_control && $bess_online && $bess_soc > $min_soc);

    $abf_cfg = $config_arr['EMS_Strategy']['anti_backflow'] ?? [];
    $abf_enabled = !empty($abf_cfg['enable']);
    $abf_low = (float)($abf_cfg['trigger_low_grid_kw'] ?? 10);
    $abf_deadzone = (float)($abf_cfg['deadzone'] ?? 30);
	$abf_target =$abf_low+$abf_deadzone/2;
	$abf_high =$abf_low+$abf_deadzone;
    //$abf_target = (float)($abf_cfg['target_grid_kw'] ?? 25);
    //$abf_high = (float)($abf_cfg['trigger_high_grid_kw'] ?? 40);
    $abf_emergency = (float)($abf_cfg['emergency_curtail_kw'] ?? -10);
    $ps_cfg = $config_arr['EMS_Strategy']['peak_shaving'] ?? [];
    $ps_enabled = !empty($ps_cfg['enable']);
    $ps_high = (float)($ps_cfg['trigger_high_grid_kw'] ?? 600);
    $ps_deadzone = (float)($ps_cfg['deadzone'] ?? 30);
	$ps_target=$ps_high-$ps_deadzone/2;
	$ps_low=$ps_high-$ps_deadzone;
    //$ps_target = (float)($ps_cfg['target_grid_kw'] ?? 585);
    //$ps_low = (float)($ps_cfg['trigger_low_grid_kw'] ?? 570);
    //$ps_min_soc = max($min_soc, (float)($ps_cfg['min_soc'] ?? $min_soc));
	$ps_min_soc =$min_soc;
    $is_offpeak_now = is_offpeak(date('Y-m-d H:i:s', $timestamp));
    $weekday = ((int)date('N', $timestamp) <= 5 && !is_holiday(date('Y-m-d', $timestamp)));

    $gross_grid = $pt_main + $pt_bess;
    $target_pt_bess = $pt_bess; // Hold current power unless a strategy asks otherwise.
    $target_qt_bess = 0.0;
    $target_ev_limit = ($ev_limit > 0) ? $ev_limit : null;
    $target_pt_max = $inv_total_p_max;
    $control_source = 'HOLD';
    $recovery_phase = 'NONE';
    $notice_text = 'EMS: คงสถานะการควบคุมปัจจุบัน';
    $notice_sev = 1;
    $echo_text = '';

    // Validate each inverter independently. One missing/invalid meter must not
    // disable Anti-Backflow curtailment for the other healthy inverters.
    $valid_inv_rated = [];
    $valid_inv_p_max = [];
    $valid_inv_p_out = [];
    $invalid_inv_reasons = [];
    foreach ($inv_rated_arr as $sn => $rated) {
        if (!array_key_exists($sn, $inv_p_out_arr) || !array_key_exists($sn, $inv_p_max_arr)) {
            $invalid_inv_reasons[$sn] = 'missing p_out or p_max';
            continue;
        }
        if (!is_numeric($inv_p_out_arr[$sn]) || !is_numeric($inv_p_max_arr[$sn])) {
            $invalid_inv_reasons[$sn] = 'non-numeric p_out or p_max';
            continue;
        }
        $pmax = (float)$inv_p_max_arr[$sn];
        $pout = (float)$inv_p_out_arr[$sn];
        //if (!is_finite($pmax) || !is_finite($pout) || $rated <= 0 || $pmax < 0 || $pmax > $rated + 1 || $pout < -1 || $pout > $rated + 5 || $pout > $pmax + 5) {
		if (!is_finite($pmax) || !is_finite($pout) || $rated <= 0 || $pmax < 0 || $pmax > $rated + 1 || $pout < -1 || $pout > $rated + 5) {
            $invalid_inv_reasons[$sn] = 'out of range: rated=' . $rated . ', p_max=' . $pmax . ', p_out=' . $pout;
            continue;
        }
        $valid_inv_rated[$sn] = $rated;
        $valid_inv_p_max[$sn] = $pmax;
        $valid_inv_p_out[$sn] = max(0.0, $pout);
    }
    $solar_data_ok = (count($valid_inv_rated) > 0); // At least one verified inverter is controllable.
    foreach ($invalid_inv_reasons as $sn => $reason) $echo_text .= "[SOLAR TELEMETRY SKIP] {$sn}: {$reason}\r\n";
    $echo_text .= '[SOLAR TELEMETRY] Valid ' . count($valid_inv_rated) . '/' . count($inv_rated_arr) . " inverter(s)\r\n";

    // STEP 1: TOU: all present conditions must pass (AND).
    // +d: discharge toward min_soc by e_hour; -d: charge toward max_soc by e_hour.
    // Numeric pt_bess: direct kW setpoint. Unsupported legacy "d" is NOT guessed.
    $time_dec = (int)date('G', $timestamp) + (int)date('i', $timestamp) / 60.0 + (int)date('s', $timestamp) / 3600.0;
    $tou_rules = $weekday ? ($config_arr['TOU']['on'] ?? []) : ($config_arr['TOU']['off'] ?? []);
    $tou_matched = false;
    $tou_rule_index = null;
    $tou_eff_dis = ems_clamp((float)($bess_cfg['eff_discharge'] ?? 0.90), 0.01, 1.0);
    $tou_eff_cha = ems_clamp((float)($bess_cfg['eff_charge'] ?? 0.90), 0.01, 1.0);
    foreach ($tou_rules as $index => $rule) {
        $start = parse_hour_to_decimal($rule['b_hour'] ?? 0);
        $end = parse_hour_to_decimal($rule['e_hour'] ?? 0);
        if ($time_dec < $start || $time_dec >= $end || $end <= $start) continue;
        if (isset($rule['min_soc']) && $bess_soc <= (float)$rule['min_soc']) continue;
        if (isset($rule['max_soc']) && $bess_soc >= (float)$rule['max_soc']) continue;
        if (isset($rule['min_pt_main']) && $pt_main <= (float)$rule['min_pt_main']) continue;
        if (isset($rule['max_pt_main']) && $pt_main >= (float)$rule['max_pt_main']) continue;
        if (!array_key_exists('pt_bess', $rule)) continue;
        $mode = $rule['pt_bess'];
        $hours_left = max(0.5, $end - $time_dec);
        if ($mode === '+d') {
            if (!isset($rule['min_soc'])) { $echo_text .= "[STEP 1: TOU] Rule {$index} invalid: +d requires min_soc\r\n"; continue; }
            $usable_kwh = $bess_kwh * max(0.0, $bess_soc - (float)$rule['min_soc']) / 100.0;
            $proposal = min($max_discharge, $usable_kwh * $tou_eff_dis / $hours_left);
            if (!$can_discharge) $proposal = 0.0;
        } elseif ($mode === '-d') {
            if (!isset($rule['max_soc'])) { $echo_text .= "[STEP 1: TOU] Rule {$index} invalid: -d requires max_soc\r\n"; continue; }
            $needed_kwh = $bess_kwh * max(0.0, min($max_soc, (float)$rule['max_soc']) - $bess_soc) / 100.0;
            $proposal = max($max_charge, -$needed_kwh / ($tou_eff_cha * $hours_left));
            if (!$can_charge) $proposal = 0.0;
        } elseif (is_numeric($mode)) {
            $proposal = ems_clamp((float)$mode, $max_charge, $max_discharge);
        } else {
            $echo_text .= "[STEP 1: TOU] Rule {$index} invalid pt_bess; expected +d, -d or numeric\r\n";
            continue;
        }
        $target_pt_bess = round($proposal, 2);
        $tou_matched = true;
        $tou_rule_index = $index;
        $control_source = 'TOU';
        $notice_text = 'TOU: เป้าหมาย BESS ' . number_format($target_pt_bess, 2) . ' kW';
        $echo_text .= "[STEP 1: TOU] Rule {$index} ({$mode}), BESS base target {$target_pt_bess} kW, SOC {$bess_soc}%, time left " . round($hours_left, 3) . " h\r\n";
        break;
    }
    if (!$tou_matched) $echo_text .= "[STEP 1: TOU] No valid matching rule; hold actual BESS power\r\n";
    if (!$bess_allow_control) {
        $target_pt_bess = $pt_bess;
        $control_source = 'BESS_MONITOR_ONLY';
        $echo_text .= "[STEP 1: TOU] BESS control disabled: monitor only\r\n";
    }

    // STEP 2: Peak Shaving. BESS FIRST on rising demand; EV FIRST on recovery.
    // Use ACTUAL PEA. Hold BESS in the Peak dead zone so TOU cannot cause hunting.
    $tou_base_bess = $target_pt_bess;
    $peak_hold_bess = false;
    $peak_ev_step_kw = max(1.0, (float)($ps_cfg['ev_recovery_step_kw'] ?? 20.0));
    if ($ps_enabled && !$is_offpeak_now) {
        if ($pt_main > $ps_high) {
            // The import reduction from TOU is not yet present in measured PEA.
            // Count it before allocating additional Peak Shaving discharge.
            $baseline = ($bess_allow_control && $bess_online) ? $tou_base_bess : $pt_bess;
            $baseline = ems_clamp($baseline, $max_charge, $max_discharge);
            $projected_pea = $pt_main - ($baseline - $pt_bess);
            $remaining = max(0.0, $projected_pea - $ps_target);
            $bess_added = 0.0;
            $target_pt_bess = $baseline;
            if ($can_discharge && $bess_soc > $ps_min_soc) {
                $bess_added = min($remaining, max(0.0, $max_discharge - $baseline));
                $target_pt_bess = $baseline + $bess_added;
                $remaining = max(0.0, $remaining - $bess_added);
            }
            $ev_reduced = 0.0;
            if ($remaining >= 1.0 && $ev_total > $ev_min_kw) {
                $ev_cap = ($ev_limit > 0) ? min($ev_limit, $ev_total) : $ev_total;
                $ev_reduced = min($remaining, max(0.0, $ev_cap - $ev_min_kw));
                if ($ev_reduced >= 1.0) $target_ev_limit = max($ev_min_kw, $ev_cap - $ev_reduced);
            }
            if ($bess_added >= 0.5 || $ev_reduced >= 1.0) {
                $control_source = 'PEAK_SHAVING';
                $notice_text = 'Peak Shaving: เพิ่ม BESS Discharge ก่อน แล้วหรี่ EV หากจำเป็น';
                $notice_sev = 2;
            }
            $echo_text .= "[STEP 2: PEAK] PEA {$pt_main} > {$ps_high}; TOU base {$baseline} kW; projected PEA {$projected_pea} kW; BESS extra +{$bess_added} kW; EV -{$ev_reduced} kW; target {$target_pt_bess} kW\r\n";
        } elseif ($pt_main < $ps_low) {
            $margin = max(0.0, $ps_target - $pt_main);
            $ev_released = 0.0;
            $ev_recovery_pending = false;
            if ($ev_limit > 0 && $ev_total <= 0.5) {
				// ไม่มี EV Charging: ปลด Limit
				$target_ev_limit = null;
				$ev_recovery_pending = true;
				$target_pt_bess = $pt_bess;
				$control_source = 'PEAK_RECOVERY_EV';
				$recovery_phase = 'EV_RELEASE';
				$notice_text = 'Peak Recovery: ไม่มี EV Charging สั่งปลด EV Limit';
				$notice_sev = 2;

			} elseif ($ev_limit > 0 && $ev_total >= $ev_limit - 3.0) {
				// มี EV Charging ใกล้ Limit: ทยอยเพิ่มกำลัง
				$ev_released = min($margin, $peak_ev_step_kw);

				if ($ev_released >= 1.0) {
					$target_ev_limit = $ev_limit + $ev_released;
					$control_source = 'PEAK_RECOVERY_EV';
					$recovery_phase = 'EV_RELEASE';
					$notice_text = 'Peak Recovery: ทยอยเพิ่ม EV Limit';
					$notice_sev = 2;
				}

				$ev_recovery_pending = true;
				$target_pt_bess = $pt_bess;
            } else {
                // Do NOT erase TOU discharge when no Peak Shaving remains.
                // Actual versus TOU is not proof of prior Peak Shaving; use it
                // only to trim a material surplus, never below the TOU baseline.
                $recovery_base = $tou_matched ? $tou_base_bess : 0.0;

				$extra = max(0.0, $pt_bess - max(0.0, $recovery_base));

				// จำกัดการลด Discharge ไม่เกิน 20 kW ต่อรอบ
				$bess_reduction = min($margin, $extra, 20.0);

				if ($bess_reduction >= 0.5) {
					$target_pt_bess = max($recovery_base, $pt_bess - $bess_reduction);
                    $control_source = 'PEAK_RECOVERY_BESS';
                    $recovery_phase = 'BESS_RECOVERY';
                    $notice_text = 'Peak Recovery: ลดเฉพาะ BESS Discharge ส่วนที่เกินจาก TOU';
                    $notice_sev = 2;
                } else {
                    $target_pt_bess = $tou_base_bess;
                    $echo_text .= "[STEP 2: PEAK] No active recovery; preserve TOU base {$tou_base_bess} kW\r\n";
                }
            }
            $echo_text .= "[STEP 2: PEAK] PEA {$pt_main} < {$ps_low}; EV first {$ev_released} kW; EV pending " . ($ev_recovery_pending ? 'YES' : 'NO') . "; TOU base {$tou_base_bess} kW; BESS target {$target_pt_bess} kW\r\n";
        } else {
            $target_pt_bess = $pt_bess;
            $peak_hold_bess = true;
            $control_source = 'PEAK_DEADZONE_HOLD';
            $echo_text .= "[STEP 2: PEAK DEAD ZONE] PEA {$pt_main} in {$ps_low}-{$ps_high}; hold BESS actual {$pt_bess} kW, suppress TOU setpoint\r\n";
        }
    } else {
        $echo_text .= '[STEP 2: PEAK] ' . ($is_offpeak_now ? 'Off-Peak: disabled' : 'Disabled') . "\r\n";
        if ($is_offpeak_now && $ev_limit > 0) {
            $target_ev_limit = $ev_limit + (($ev_total >= $ev_limit - 3.0) ? min($peak_ev_step_kw, max(0.0, $ps_target - $pt_main)) : 0.0);
            if ($target_ev_limit > $ev_limit + 0.5) {
                $control_source = 'PEAK_RECOVERY_EV';
                $notice_text = 'Off-Peak: ผ่อนคลาย EV Limit แบบค่อยเป็นค่อยไป';
                $notice_sev = 2;
            }
        }
    }

    // STEP 3: Anti-Backflow. Trigger on ACTUAL PEA, never on TOU prediction.
    // At ABF HIGH, release the solar LIMIT first, but only count the release as
    // additional generation when output is currently near the ceiling.
    $solar_curtail_required = 0.0;
    $soc_cutoff_stop = ($bess_allow_control && $bess_online && $bess_soc >= $max_soc && $pt_bess < -0.5);
    if ($abf_enabled) {
        $emergency_soc = (float)($abf_cfg['emergency_bess_soc'] ?? 94.0);
        if ($pt_main <= $abf_emergency && (!$bess_allow_control || $bess_soc >= $emergency_soc)) {
            // Emergency bypasses BESS. A direct solar curtailment must be based on
            // measured output instead of assuming that p_max reduction changes p_out.
            $peak_hold_bess = true;
            $target_pt_bess = $pt_bess;
            $solar_curtail_required = max(0.0, $abf_target - $pt_main);
            $control_source = 'EMERGENCY_ABF';
            $recovery_phase = 'EMERGENCY_CURTAIL';
            $notice_text = 'EMERGENCY ABF: หรี่ Solar โดยตรง; PEA ' . number_format($pt_main, 1) . ' kW';
            $notice_sev = 4;
            $echo_text .= "[STEP 3: ABF EMERGENCY] Actual PEA {$pt_main} <= {$abf_emergency}; solar output reduction {$solar_curtail_required} kW\r\n";
        } elseif ($pt_main < $abf_low) {
            $peak_hold_bess = false;
            $required_kw = max(0.0, $abf_target - $pt_main);
            $target_pt_bess = $can_charge ? max($max_charge, $pt_bess - $required_kw) : $pt_bess;
            $absorbed = $can_charge ? max(0.0, $pt_bess - $target_pt_bess) : 0.0;
            $solar_curtail_required = max(0.0, $required_kw - $absorbed);
            $control_source = 'ANTI_BACKFLOW';
            $notice_text = !$can_charge ? 'ABF: BESS ไม่สามารถเพิ่ม Charge ได้ จึงหรี่ Solar ตามความจำเป็น' : 'ABF: เพิ่ม BESS Charge ก่อน' . ($solar_curtail_required > 0 ? ' และหรี่ Solar ตามกำลังที่เหลือ' : '');
            $notice_sev = $solar_curtail_required > 0 ? 3 : 2;
            $echo_text .= "[STEP 3: ABF LOW] PEA {$pt_main} < {$abf_low}; BESS {$target_pt_bess}, solar reduction {$solar_curtail_required} kW\r\n";
        } elseif ($pt_main > $abf_high) {
            // Peak dead-zone hold persists if no safe BESS change is requested.
            $solar_room = max(0.0, array_sum($valid_inv_rated) - array_sum($valid_inv_p_max));
            $release_budget = max(0.0, $pt_main - $abf_target);
            $valid_solar_room = max(
				0.0,
				array_sum($valid_inv_rated) - array_sum($valid_inv_p_max)
			);

			$release_ceiling = $solar_data_ok
				? min($valid_solar_room, $release_budget)
				: 0.0;
            $release_commands = ems_allocate_inverters($valid_inv_rated, $valid_inv_p_max, $valid_inv_p_out, $release_ceiling);
            $effective_solar_gain = ems_estimate_release_kw($release_commands, $inv_p_max_arr, $inv_p_out_arr);
            if ($release_commands) {
                $target_pt_max = $inv_total_p_max + array_sum(array_map(function($sn, $val) use ($inv_p_max_arr) { return $val - $inv_p_max_arr[$sn]; }, array_keys($release_commands), array_values($release_commands)));
                $recovery_phase = 'SOLAR_RELEASE';
                $control_source = 'ANTI_BACKFLOW';
                $notice_text = 'ABF Recovery: ปลดหรี่ Solar (ประเมินกำลังเพิ่มจริง ' . number_format($effective_solar_gain, 1) . ' kW)';
                $notice_sev = 2;
            }
            if ($solar_room > 0 && (!$solar_data_ok || $invalid_inv_reasons)) $recovery_phase = 'BLOCKED_TELEMETRY';

            // The ABF recovery uses physical estimated gain, not the purely
            // administrative difference between p_max and rated.
            // Preserve the TOU proposal if there is enough PEA import margin;
            // if necessary, cap BESS discharge so import stays >= ABF TARGET.
            $safe_max_bess = $pt_bess + max(0.0, $pt_main - $effective_solar_gain - $abf_target);
            $proposed_bess = $target_pt_bess;
            if ($bess_allow_control) {
                $target_pt_bess = min($proposed_bess, $safe_max_bess);
                if ($pt_bess < 0 && $target_pt_bess > $pt_bess && $effective_solar_gain > 0.5 && $solar_room > 0) {
                    // When solar release is physically effective, keep BESS charge
                    // until solar is released; reduce charge on a subsequent cycle.
                    $target_pt_bess = $pt_bess;
                }
                if (abs($target_pt_bess - $proposed_bess) > 0.01) $control_source = 'ANTI_BACKFLOW';
            } else {
                $target_pt_bess = $pt_bess;
            }
            $echo_text .= "[STEP 3: ABF HIGH] PEA {$pt_main} > {$abf_high}; solar room {$solar_room}, effective release {$effective_solar_gain} kW, final BESS target {$target_pt_bess} kW\r\n";
        } else {
            // Hold both charge and discharge. TOU must not move BESS in this band.
            $target_pt_bess = $pt_bess;
            $peak_hold_bess = true;
            if ($control_source === 'TOU' || $control_source === 'HOLD' || $control_source === 'PEAK_DEADZONE_HOLD') $control_source = 'ABF_DEADZONE_HOLD';
            $echo_text .= "[STEP 3: ABF DEAD ZONE] PEA {$pt_main} in {$abf_low}-{$abf_high}; hold BESS {$pt_bess} kW regardless of TOU direction\r\n";
        }
    }

    // SOC cutoff overrides TOU, Peak and ABF dead-zone holds. Do not keep charging
    // indefinitely at max_soc. Compensate the prospective reduction in grid import
    // with solar curtailment, computed from actual measured BESS/grid power.
    if ($soc_cutoff_stop) {
        $target_pt_bess = 0.0;
        $peak_hold_bess = false;
        $control_source = 'BESS_SOC_CUTOFF';
        $recovery_phase = 'SOC_CUTOFF';
        // If the BESS stops charging, grid import will decrease by abs(pt_bess).
        $projected_grid = $pt_main + $pt_bess;
        $needed_solar_reduction = max(0.0, $abf_target - $projected_grid);
        $solar_curtail_required = max($solar_curtail_required, $needed_solar_reduction);
        $target_pt_max = $inv_total_p_max; // Do not release solar simultaneously.
        $echo_text .= '[SOC CUTOFF] SOC ' . $bess_soc . ' >= max_soc ' . $max_soc . '; stop charge ' . $pt_bess . ' kW; required solar curtail ' . round($solar_curtail_required, 2) . " kW\r\n";
    }

    // STEP 4: Apply device limitations; then determine actual outgoing commands.
    if (!$bess_allow_control) $target_pt_bess = $pt_bess;
    if ($peak_hold_bess) $target_pt_bess = $pt_bess;
    if (!$peak_hold_bess) $target_pt_bess = ems_clamp($target_pt_bess, $max_charge, $max_discharge);
    if (!$peak_hold_bess && !$can_charge && $target_pt_bess < 0) $target_pt_bess = ($bess_soc >= $max_soc && $bess_allow_control && $bess_online) ? 0.0 : min(0.0, $pt_bess);
    if (!$peak_hold_bess && $bess_allow_control && !$can_discharge && $target_pt_bess > 0) $target_pt_bess = 0.0;
    if (!$bess_online) $target_pt_bess = $pt_bess;
    $target_pt_bess = round($target_pt_bess, 2);

    $q_cfg = $config_arr['Q_CONTROL'] ?? [];
    if ($bess_allow_control && !empty($q_cfg['enable']) && !empty($q_cfg['use_q_comp']) && $qt_main > (float)($q_cfg['qt_main_max'] ?? 360) && $bess_soc >= (float)($q_cfg['min_soc'] ?? 20)) {
        $excess_q = $qt_main + abs($qt_bess) - (float)($q_cfg['qt_main_min'] ?? 330);
        $target_qt_bess = -min((float)($bess_cfg['q_max_dis'] ?? 100), max(0.0, $excess_q));
        $notice_text = 'Q-Control: ชดเชย Reactive Power | ' . $notice_text;
    }
    $target_qt_bess = round($target_qt_bess, 2);

    $cmd_pt_bess = null;
    $bess_diff = $target_pt_bess - $pt_bess;
    $bess_deadband = 0.5; // Applies uniformly; compare actual power with the final target.
    if ($bess_allow_control && $bess_online && !$peak_hold_bess && abs($bess_diff) >= $bess_deadband) $cmd_pt_bess = $target_pt_bess;
    $cmd_qt_bess = null;
    if ($bess_allow_control && $bess_online && (abs($target_qt_bess - $qt_bess) >= 5 || ($target_qt_bess == 0 && abs($qt_bess) >= 3))) $cmd_qt_bess = (int)round($target_qt_bess);

    $cmd_ev_action = null;
    $cmd_ev_limit = null;
    if ($target_ev_limit !== null && (abs($target_ev_limit - $ev_limit) >= 1 || $ev_limit <= 0)) {
        $cmd_ev_action = 'limit';
        $cmd_ev_limit = (int)round($target_ev_limit);
    } elseif ($target_ev_limit === null && $ev_limit > 0) {
        $cmd_ev_action = 'clear';
    }

    // Compute per-inverter commands. A curtailment needs MEASURED output,
    // otherwise ceilings can change without changing power generation.
    $cmd_inv_list = [];
    $cmd_pt_max = null;
    if ($solar_curtail_required > 0.001) {
        if ($solar_data_ok) {
            $cmd_inv_list = ems_curtail_from_output($valid_inv_rated, $valid_inv_p_max, $valid_inv_p_out, $solar_curtail_required);
            if ($cmd_inv_list) {
                $target_pt_max = $inv_total_p_max;
                foreach ($cmd_inv_list as $sn => $value) $target_pt_max += $value - (float)$inv_p_max_arr[$sn];
                $cmd_pt_max = round($target_pt_max, 2);
                $estimated_reduction = 0.0;
                foreach ($cmd_inv_list as $sn => $value) $estimated_reduction += max(0.0, $valid_inv_p_out[$sn] - $value);
                $echo_text .= '[SOLAR CURTAIL] requested=' . round($solar_curtail_required, 2) . ' kW, estimated=' . round($estimated_reduction, 2) . ' kW, inverters=' . count($cmd_inv_list) . "\r\n";
                if ($estimated_reduction + 0.5 < $solar_curtail_required) {
                    $echo_text .= '[WARNING] Solar curtailment insufficient: remaining=' . round($solar_curtail_required - $estimated_reduction, 2) . " kW; protection must be verified\r\n";
                    $recovery_phase = 'CURTAIL_INSUFFICIENT';
                }
            } else {
                $echo_text .= "[WARNING] No actionable solar curtailment among validated inverters; PEA export may continue\r\n";
                $recovery_phase = 'CURTAIL_UNAVAILABLE';
            }
        } else {
            $echo_text .= "[WARNING] No valid Solar telemetry: cannot issue inverter curtailment; PEA export may continue\r\n";
            $recovery_phase = 'CURTAIL_UNAVAILABLE';
        }
    } else {
        $delta_inv = $target_pt_max - $inv_total_p_max;
        if ($inv_total_rated > 0 && $solar_data_ok && $delta_inv >= 0.5) {
            $cmd_inv_list = ems_allocate_inverters($valid_inv_rated, $valid_inv_p_max, $valid_inv_p_out, $delta_inv);
            if ($cmd_inv_list) {
                $target_pt_max = $inv_total_p_max;
                foreach ($cmd_inv_list as $sn => $value) $target_pt_max += $value - (float)$inv_p_max_arr[$sn];
                $cmd_pt_max = round($target_pt_max, 2);
            }
        }
    }

    // Notice describes the outgoing request, not a generic strategy declaration.
	if ($cmd_pt_bess !== null || $cmd_inv_list || $cmd_ev_action !== null || $cmd_qt_bess !== null) {
        if ($soc_cutoff_stop && $cmd_pt_bess !== null) {
            $notice_text = 'BESS: SOC ถึงขีดจำกัด ' . $max_soc . '% สั่งหยุด Charge' . ($cmd_inv_list ? ' พร้อมหรี่ Solar เพื่อรักษา PEA' : ' (ไม่มีคำสั่งหรี่ Solar)');
            $notice_sev = $cmd_inv_list ? 3 : 4;
        } elseif ($control_source === 'TOU' && $cmd_pt_bess !== null) {
            $notice_text = 'TOU: สั่ง BESS ' . number_format($cmd_pt_bess, 2) . ' kW';
            $notice_sev = 1;
        } elseif ($control_source === 'ANTI_BACKFLOW' || $control_source === 'EMERGENCY_ABF') {
            if ($recovery_phase === 'SOLAR_RELEASE') {
                if ($cmd_pt_bess !== null && $cmd_pt_bess < $pt_bess - 0.5) {
                    $notice_text = 'ABF: สั่งเพิ่ม BESS Charge และปลดหรี่ Solar';
                }
            } elseif ($cmd_pt_bess !== null && $cmd_pt_bess < $pt_bess - 0.5 && $cmd_inv_list) {
                $notice_text = 'ABF: สั่งเพิ่ม BESS Charge และหรี่ Solar';
            } elseif ($cmd_pt_bess !== null && $cmd_pt_bess < $pt_bess - 0.5) {
                $notice_text = 'ABF: สั่งเพิ่ม BESS Charge';
            } elseif ($cmd_inv_list && !$can_charge) {
                $notice_text = 'ABF: BESS Charge ไม่ได้ (SOC ' . $bess_soc . '% / max ' . $max_soc . '% หรืออุปกรณ์ไม่พร้อม) จึงสั่งหรี่ Solar';
            } elseif ($cmd_inv_list) {
                $notice_text = 'ABF: สั่งหรี่ Solar เพื่อป้องกันไฟย้อน';
            } elseif ($cmd_pt_bess !== null) {
                $notice_text = 'ABF: ปรับ BESS เป็น ' . $cmd_pt_bess . ' kW';
            }
        }
    }

    // Never claim a command was executed while DRY RUN is active.
    if (empty($GLOBALS['EMS_DRY_RUN'])) {
        if ($cmd_ev_action === 'limit') read_url('https://ev.mdbiot.com/ev_api.php?mode=oscp&method=limitpowerSID&SID=' . urlencode($ev_sid) . '&limit=' . $cmd_ev_limit);
        if ($cmd_ev_action === 'clear') read_url('https://ev.mdbiot.com/ev_api.php?mode=oscp&method=clearlimitpowerSID&SID=' . urlencode($ev_sid));
        if ($bess_allow_control && $cmd_pt_bess !== null) send_mqtt($device_SN, 'IN130001', 'p_max', (int)round($cmd_pt_bess));
        if ($bess_allow_control && $cmd_qt_bess !== null) send_mqtt($device_SN, 'IN130001', 'q_max', $cmd_qt_bess);
        foreach ($cmd_inv_list as $sn => $pmax) send_mqtt($device_SN, $sn, 'p_max', $pmax);
    } else {
        $echo_text .= "[DRY RUN] No commands sent to devices\r\n";
    }

    $echo_text .= '[FINAL] source=' . $control_source . ', BESS=' . ($cmd_pt_bess === null ? 'NONE' : $cmd_pt_bess) . ', EV=' . ($cmd_ev_action ?? 'NONE') . ', INVERTER=' . ($cmd_pt_max === null ? 'NONE' : $cmd_pt_max) . "\r\n";
    echo $echo_text;

    $has_cmd = ($cmd_pt_bess !== null || $cmd_qt_bess !== null || $cmd_ev_action !== null || (bool)$cmd_inv_list);
    if (empty($GLOBALS['EMS_DRY_RUN'])) {
        if ($has_cmd && !empty($write_log)) write_file('log_bess_control.txt', $echo_text . "\r\n", 'a');
        if ($has_cmd){
			write_file_json('jsons/ems_status_L000001.json', ['text' => $notice_text, 'severity' => $notice_sev, 'category' => 'EMS', 'timestamp' => $timestamp]);
			echo 'Notice: ' . htmlspecialchars($notice_text, ENT_QUOTES, 'UTF-8') . "\r\n<br>";
		}
    }

	if($pt_bess>-3 && $pt_bess<3){
		$cmd_pt_bess=0;
	}

    return [
        'target_pt_bess' => $target_pt_bess,
        'target_qt_bess' => $target_qt_bess,
        'target_pt_max' => round($target_pt_max, 2),
        'target_ev_limit' => $target_ev_limit,
        'cmd_pt_bess' => $cmd_pt_bess,
        'cmd_qt_bess' => $cmd_qt_bess,
        'cmd_pt_max' => $cmd_pt_max,
        'cmd_ev_action' => $cmd_ev_action,
        'cmd_ev_limit' => $cmd_ev_limit,
        'cmd_inv_list' => $cmd_inv_list,
        'control_source' => $control_source,
        'bess_allow_control' => $bess_allow_control,
        'recovery_phase' => $recovery_phase,
        'solar_data_ok' => $solar_data_ok,
        'dry_run' => !empty($GLOBALS['EMS_DRY_RUN'])
    ];
}


// Main input mapping: omitted in offline fixture
