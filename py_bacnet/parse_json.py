import json

input_data = {"10110": {
        "name": "dx_pre",
        "name2":"Diagnostic Present"
      },
      "10111": {
        "name": "dx_sh_pre",
        "name2":"Diagnostic Shutdown Present"
      },
      "10112": {
        "name": "dx_mn_rst",
        "name2":"Diagnostic_ Manual Reset Required"
      },
      "10113": {
        "name": "dx_lc_mn_rst",
        "name2":"Diagnostic_ Local Manual Reset Required"
      },
      "10114": {
        "name": "dx_pre_inf",
        "name2":"Diagnostic Present_ Information"
      },
      "10115": {
        "name": "dx_pre_adv",
        "name2":"Diagnostic Present_ Advisory"
      },
      "10116": {
        "name": "dx_pre_cri",
        "name2":"Diagnostic Present_ Critical"
      },
      "10117": {
        "name": "dx_pre_ser",
        "name2":"Diagnostic Present_ Service Required"
      },
      "11001": {
        "name": "com_loss_out",
        "name2":"Comm Loss_ Outdoor Air Temperature"
      },
      "11002": {
        "name": "dx_fdis_oil_heat",
        "name2":"Diagnostic_ Force Disable Oil Heating Wait Control"
      },
      "11004": {
        "name": "dx_out_air_temp",
        "name2":"Diagnostic_ Outdoor Air Temperature Sensor"
      },
      "11005": {
        "name": "dx_sw_err_1001",
        "name2":"Diagnostic_ Software Error 1001_ Call Trane Service"
      },
      "11006": {
        "name": "dx_sw_err_1002",
        "name2":"Diagnostic_ Software Error 1002_ Call Trane Service"
      },
      "11007": {
        "name": "dx_sw_err_1003",
        "name2":"Diagnostic_ Software Error 1003_ Call Trane Service"
      },
      "11012": {
        "name": "com_loss_cond_prs_c1",
        "name2":"Comm Loss_ Condenser Rfgt Pressure Ckt1"
      },
      "11013": {
        "name": "com_loss_cond_prs_c2",
        "name2":"Comm Loss_ Condenser Rfgt Pressure Ckt2"
      },
      "11022": {
        "name": "com_loss_evap_temp_c1",
        "name2":"Comm Loss_ Evap Rfgt Pool Temp Ckt1"
      },
      "11023": {
        "name": "com_loss_evap_temp_c2",
        "name2":"Comm Loss_ Evap Rfgt Pool Temp Ckt2"
      },
      "11024": {
        "name": "com_loss_ext_ckt_c1",
        "name2":"Comm Loss_ External Ckt Lockout Ckt1"
      },
      "11025": {
        "name": "com_loss_ext_ckt_c2",
        "name2":"Comm Loss_ External Ckt Lockout Ckt2"
      },
      "11026": {
        "name": "com_loss_oil_si_c1",
        "name2":"Comm Loss_ Oil Loss Level Sensor Input Ckt1"
      },
      "11027": {
        "name": "com_loss_oil_si_c2",
        "name2":"Comm Loss_ Oil Loss Level Sensor Input Ckt2"
      },
      "11028": {
        "name": "com_loss_oil_rt_c1",
        "name2":"Comm Loss_ Oil Return Purge Valve Ckt1"
      },
      "11029": {
        "name": "com_loss_oil_rt_c2",
        "name2":"Comm Loss_ Oil Return Purge Valve Ckt2"
      },
      "11030": {
        "name": "com_loss_oil_sup_temp_c1",
        "name2":"Comm Loss_ Oil Supply Temperature Ckt1"
      },
      "11031": {
        "name": "com_loss_oil_sup_temp_c2",
        "name2":"Comm Loss_ Oil Supply Temperature Ckt2"
      },
      "11032": {
        "name": "com_loss_liq_prs_c1",
        "name2":"Comm Loss_ Liquid Line Pressure Ckt1"
      },
      "11033": {
        "name": "com_loss_liq_prs_c2",
        "name2":"Comm Loss_ Liquid Line Pressure Ckt2"
      },
      "11034": {
        "name": "com_loss_liq_temp_c1",
        "name2":"Comm Loss_ Liquid Line Temperature Ckt1"
      },
      "11035": {
        "name": "com_loss_liq_temp_c2",
        "name2":"Comm Loss_ Liquid Line Temperature Ckt2"
      },
      "11038": {
        "name": "com_loss_thermo_heat_vlv_c1",
        "name2":"CommLoss_ Thermosiphon Heating Valve_ Ckt1"
      },
      "11039": {
        "name": "com_loss_thermo_heat_vlv_c2",
        "name2":"CommLoss_ Thermosiphon Heating Valve_ Ckt2"
      },
      "11040": {
        "name": "dx_cond_prs_c1",
        "name2":"Diagnostic_ Condenser Rfgt Pressure Sensor Ckt1"
      },
      "11041": {
        "name": "dx_cond_prs_c2",
        "name2":"Diagnostic_ Condenser Rfgt Pressure Sensor Ckt2"
      },
      "11052": {
        "name": "dx_evap_temp_c1",
        "name2":"Diagnostic_ Evap Rfgt Pool Temp Sensor Ckt1"
      },
      "11053": {
        "name": "dx_evap_temp_c2",
        "name2":"Diagnostic_ Evap Rfgt Pool Temp Sensor Ckt2"
      },
      "11054": {
        "name": "dx_evap_temp_err_c1",
        "name2":"Diagnostic_ Evap Rfgt Pool Temp Sensor Error Ckt1"
      },
      "11055": {
        "name": "dx_evap_temp_err_c2",
        "name2":"Diagnostic_ Evap Rfgt Pool Temp Sensor Error Ckt2"
      },
      "11058": {
        "name": "dx_oil_temp_c1",
        "name2":"Diagnostic_ Oil Supply Temperature Sensor Ckt1"
      },
      "11059": {
        "name": "dx_oil_temp_c2",
        "name2":"Diagnostic_ Oil Supply Temperature Sensor Ckt2"
      },
      "11060": {
        "name": "dx_pdown_term_c1",
        "name2":"Diagnostic_ Pumpdown Terminated By Time Ckt1"
      },
      "11061": {
        "name": "dx_pdown_term_c2",
        "name2":"Diagnostic_ Pumpdown Terminated By Time Ckt2"
      },
      "11062": {
        "name": "dx_liq_prs_c1",
        "name2":"Diagnostic_ Liquid Line Pressure Sensor Ckt1"
      },
      "11063": {
        "name": "dx_liq_prs_c2",
        "name2":"Diagnostic_ Liquid Line Pressure Sensor Ckt2"
      },
      "11064": {
        "name": "dx_liq_temp_c1",
        "name2":"Diagnostic_ Liquid Line Temp Sensor Ckt1"
      },
      "11065": {
        "name": "dx_liq_temp_c2",
        "name2":"Diagnostic_ Liquid Line Temp Sensor Ckt2"
      },
      "11068": {
        "name": "dx_evap_appr_err_c1",
        "name2":"Diagnostic_ Evaporator Approach Error Ckt1"
      },
      "11069": {
        "name": "dx_evap_appr_err_c2",
        "name2":"Diagnostic_ Evaporator Approach Error Ckt2"
      },
      "11078": {
        "name": "dx_low_evap_temp_c1",
        "name2":"Diagnostic_ Low Evaporator Rfgt Temp Ckt 1_ Unit Off"
      },
      "11079": {
        "name": "dx_low_evap_temp_c2",
        "name2":"Diagnostic_ Low Evaporator Rfgt Temp Ckt 2_ Unit Off"
      },
      "11080": {
        "name": "dx_low_refr_temp_c1",
        "name2":"Diagnostic_ Low Refrigerant Temperature Ckt1"
      },
      "11081": {
        "name": "dx_low_refr_temp_c2",
        "name2":"Diagnostic_ Low Refrigerant Temperature Ckt2"
      },
      "11082": {
        "name": "dx_low_suct_prs_c1",
        "name2":"Diagnostic_ Low Suction Refrigerant Pressure Ckt1"
      },
      "11083": {
        "name": "dx_low_suct_prs_c2",
        "name2":"Diagnostic_ Low Suction Refrigerant Pressure Ckt2"
      },
      "11084": {
        "name": "com_los_oil_temp_1a",
        "name2":"Comm Loss_ Oil Temperature _ 1A"
      },
      "11086": {
        "name": "com_los_cpr_temp_1a",
        "name2":"Comm Loss_ Cprsr Disch Rfgt Temp _ 1A"
      },
      "11090": {
        "name": "com_los_oil_prs_1a",
        "name2":"Comm Loss_ Oil Pressure _ 1A"
      },
      "11094": {
        "name": "dx_cprs_disch_temp_1a",
        "name2":"Diagnostic_ Cprsr Disch Rfgt Temp Sensor _ 1A"
      },
      "11096": {
        "name": "dx_oil_temp_1a",
        "name2":"Diagnostic_ Oil Temperature Sensor _ 1A"
      },
      "11098": {
        "name": "dx_hi_cpr_disch_temp_1a",
        "name2":"Diagnostic_ High Cprsr Rfgt Discharge Temp _ 1A"
      },
      "11100": {
        "name": "dx_hi_diff_prs_1a",
        "name2":"Diagnostic_ High Differential Rfgt Pressure _ 1A"
      },
      "11102": {
        "name": "dx_hi_oil_temp_1a",
        "name2":"Diagnostic_ High Oil Temperature _ 1A"
      },
      "11104": {
        "name": "dx_hi_pres_cut_1a",
        "name2":"Diagnostic_ High Pressure Cutout _ 1A"
      },
      "11106": {
        "name": "dx_hi_refr_prs_ratio_1a",
        "name2":"Diagnostic_ High Refrigerant Pressure Ratio _ 1A"
      },
      "11110": {
        "name": "dx_low_diff_prs_1a",
        "name2":"Diagnostic_ Low Differential Rfgt Pressure _ 1A"
      },
      "11112": {
        "name": "dx_low_disch_sph_1a",
        "name2":"Diagnostic_ Low Discharge Superheat _ 1A"
      },
      "11114": {
        "name": "dx_low_oil_flow_1a",
        "name2":"Diagnostic_ Low Oil Flow _ 1A"
      },
      "11117": {
        "name": "dx_ndiff_prs_1a",
        "name2":"Diagnostic_ No Differential Rfgt Pressure _ 1A"
      },
      "11119": {
        "name": "dx_ndiff_prs_lockout_1a",
        "name2":"Diagnostic_ No Differential Rfgt Pressure Lockout _ 1A"
      },
      "11120": {
        "name": "dx_oil_flow_protect_1a",
        "name2":"Diagnostic_ Oil Flow Protection Fault _ 1A"
      },
      "11122": {
        "name": "dx_oil_prs_1a",
        "name2":"Diagnostic_ Oil Pressure Sensor _ 1A"
      },
      "11124": {
        "name": "dx_vlow_disch_sph_1a",
        "name2":"Diagnostic_ Very Low Discharge Superheat _ 1A"
      },
      "11125": {
        "name": "dx_vlow_evap_prs_1a",
        "name2":"Diagnostic_ Very Low Evap Rfgt Pressure _ 1A"
      },
      "11127": {
        "name": "com_loss_oil_temp_1a",
        "name2":"Comm Loss_ Oil Temperature _ 2A"
      },
      "11129": {
        "name": "com_loss_cpr_disch_temp_2a",
        "name2":"Comm Loss_ Cprsr Disch Rfgt Temp _ 2A"
      },
      "11133": {
        "name": "com_loss_oil_prs_2a",
        "name2":"Comm Loss_ Oil Pressure _ 2A"
      },
      "11137": {
        "name": "dx_cprs_disch_temp_2a",
        "name2":"Diagnostic_ Cprsr Disch Rfgt Temp Sensor _ 2A"
      },
      "11139": {
        "name": "dx_oil_temp_2a",
        "name2":"Diagnostic_ Oil Temperature Sensor _ 2A"
      },
      "11141": {
        "name": "dx_hi_cpr_disch_temp_2a",
        "name2":"Diagnostic_ High Cprsr Rfgt Discharge Temp _ 2A"
      },
      "11143": {
        "name": "dx_hi_diff_prs_2a",
        "name2":"Diagnostic_ High Differential Rfgt Pressure _ 2A"
      },
      "11145": {
        "name": "dx_hi_oil_temp_2a",
        "name2":"Diagnostic_ High Oil Temperature _ 2A"
      },
      "11147": {
        "name": "dx_hi_prs_cut_2a",
        "name2":"Diagnostic_ High Pressure Cutout _ 2A"
      },
      "11149": {
        "name": "dx_hi_refr_prs_ratio_2a",
        "name2":"Diagnostic_ High Refrigerant Pressure Ratio _ 2A"
      },
      "11153": {
        "name": "dx_low_diff_prs_2a",
        "name2":"Diagnostic_ Low Differential Rfgt Pressure _ 2A"
      },
      "11155": {
        "name": "dx_low_disch_sph_2a",
        "name2":"Diagnostic_ Low Discharge Superheat _ 2A"
      },
      "11157": {
        "name": "dx_low_oil_flow_2a",
        "name2":"Diagnostic_ Low Oil Flow _ 2A"
      },
      "11160": {
        "name": "dx_ndiff_prs_2a",
        "name2":"Diagnostic_ No Differential Rfgt Pressure _ 2A"
      },
      "11162": {
        "name": "dx_ndiff_prs_lockout_2a",
        "name2":"Diagnostic_ No Differential Rfgt Pressure Lockout _ 2A"
      },
      "11163": {
        "name": "dx_oil_flow_protect_2a",
        "name2":"Diagnostic_ Oil Flow Protection Fault _ 2A"
      },
      "11165": {
        "name": "dx_oil_prs_2a",
        "name2":"Diagnostic_ Oil Pressure Sensor _ 2A"
      },
      "11167": {
        "name": "dx_vlow_disch_sph_2a",
        "name2":"Diagnostic_ Very Low Discharge Superheat _ 2A"
      },
      "11168": {
        "name": "dx_vlow_evap_prs_2a",
        "name2":"Diagnostic_ Very Low Evap Rfgt Pressure _ 2A"
      },
      "11170": {
        "name": "dx_afd_mot_i_ol_1a",
        "name2":"Diagnostic_ AFD Motor Current Overload _ 1A"
      },
      "11171": {
        "name": "dx_afd_fault_1a",
        "name2":"Diagnostic_ AFD Fault _ 1A"
      },
      "11184": {
        "name": "com_los_spd_cmd_1a",
        "name2":"Comm Loss_ Speed Command 1A"
      },
      "11187": {
        "name": "com_los_suct_prs_1a",
        "name2":"Comm Loss_ Suction Rfgt Pressure _ 1A"
      },
      "11193": {
        "name": "dx_afd_rla_fb_1a",
        "name2":"Diagnostic_ AFD %RLA Feedback _ 1A"
      },
      "11194": {
        "name": "dx_str_hr_mod_1a",
        "name2":"Diagnostic_ Starts_Hours Modified 1A"
      },
      "11196": {
        "name": "dx_suct_refr_prs_1a",
        "name2":"Diagnostic_ Suction Refrigerant Pressure Sensor _ 1A"
      },
      "11198": {
        "name": "dx_com_los_valv_1a",
        "name2":"Diagnostic_ Comm Loss_ Var Vi Valve _ Cprsr 1A"
      },
      "11199": {
        "name": "dx_afd_mot_i_ol_2a",
        "name2":"Diagnostic_ AFD Motor Current Overload _ 2A"
      },
      "11200": {
        "name": "dx_afd_fault_2a",
        "name2":"Diagnostic_ AFD Fault _ 2A"
      },
      "11213": {
        "name": "com_los_spd_cmd_2a",
        "name2":"Comm Loss_ Speed Command 2A"
      },
      "11216": {
        "name": "com_los_suct_prs_2a",
        "name2":"Comm Loss_ Suction Rfgt Pressure _ 2A"
      },
      "11222": {
        "name": "dx_afd_rla_fb_2a",
        "name2":"Diagnostic_ AFD %RLA Feedback _ 2A"
      },
      "11223": {
        "name": "dx_str_hr_mod_2a",
        "name2":"Diagnostic_ Starts_Hours Modified 2A"
      },
      "11225": {
        "name": "dx_suct_refr_prs_2a",
        "name2":"Diagnostic_ Suction Refrigerant Pressure Sensor _ 2A"
      },
      "11227": {
        "name": "dx_com_los_valv_2a",
        "name2":"Comm Loss_ Var Vi Valve _ Cprsr 2A"
      },
      "11230": {
        "name": "dx_exc_cond_prs_1a",
        "name2":"Diagnostic_ Excessive Condenser Pressure Ckt1"
      },
      "11231": {
        "name": "dx_exc_cond_prs_2a",
        "name2":"Diagnostic_ Excessive Condenser Pressure Ckt2"
      },
      "11234": {
        "name": "dx_evap_wf_lost",
        "name2":"Diagnostic_ Evaporator Water Flow Lost"
      },
      "11235": {
        "name": "dx_evap_wf_overdue",
        "name2":"Diagnostic_ Evaporator Water Flow Overdue"
      },
      "11236": {
        "name": "dx_hi_evap_refr_prs",
        "name2":"Diagnostic_ High Evaporator Refrigerant Pressure"
      },
      "11237": {
        "name": "dx_hi_evap_water_temp",
        "name2":"Diagnostic_ High Evaporator Water Temperature"
      },
      "11238": {
        "name": "com_los_evap_wf_sw",
        "name2":"Comm Loss_ Evaporator Water Flow Switch"
      },
      "11240": {
        "name": "com_los_off_cycle_frz_rl",
        "name2":"Comm Loss_ Off_cycle Freeze Prot Relay"
      },
      "11241": {
        "name": "com_los_elec_expan_valv_c1",
        "name2":"Comm Loss_ Electronic Expansion Valve Ckt1"
      },
      "11242": {
        "name": "com_los_elec_expan_valv_c2",
        "name2":"Comm Loss_ Electronic Expansion Valve Ckt2"
      },
      "11245": {
        "name": "com_los_emer_stop_feedback",
        "name2":"Comm Loss_ Emergency Stop Feedback Input"
      },
      "11249": {
        "name": "com_los_ext_auto_stop",
        "name2":"Comm Loss_ External Auto_Stop"
      },
      "11250": {
        "name": "com_los_program_rl1",
        "name2":"Comm Loss_ Programmable Relay Board 1"
      },
      "11252": {
        "name": "dx_emer_stop_feedback",
        "name2":"Diagnostic_ Emergency Stop Feedback Input"
      },
      "11255": {
        "name": "com_los_cond_fan_c1",
        "name2":"Comm Loss_ Condenser Fan Enable Ckt1"
      },
      "11256": {
        "name": "com_los_cond_fan_c2",
        "name2":"Comm Loss_ Condenser Fan Enable Ckt2"
      },
      "11257": {
        "name": "com_los_cond_enbl_c1_2",
        "name2":"Comm Loss_ Cond Fan Enbl Shared Ckt 1&2"
      },
      "11276": {
        "name": "com_los_fan_inv_cmd_c1",
        "name2":"Comm Loss_ Fan Inverter Speed Command Ckt1"
      },
      "11277": {
        "name": "com_los_fan_inv_cmd_c2",
        "name2":"Comm Loss_ Fan Inverter Speed Command Ckt2"
      },
      "11278": {
        "name": "com_los_fan_inv_cmd_shrd_c1_2",
        "name2":"Comm Loss_ Fan Inv Spd Cmd_ Shrd Ckt1&2"
      },
      "11327": {
        "name": "dx_ai_cond_fan_fault_c1",
        "name2":"Diagnostic_ AI Condenser Fan Fault Ckt1"
      },
      "11328": {
        "name": "dx_ai_cond_fan_fault_c2",
        "name2":"Diagnostic_ AI Condenser Fan Fault Ckt2"
      },
      "11329": {
        "name": "com_los_motor_wind_temp1_1a",
        "name2":"Comm Loss_ Motor Winding Temperature 1 _ 1A"
      },
      "11330": {
        "name": "com_los_motor_wind_temp2_1a",
        "name2":"Comm Loss_ Motor Winding Temperature 2 _ 1A"
      },
      "11332": {
        "name": "dx_hi_motor_wind_temp_1a",
        "name2":"Diagnostic_ High Motor Winding Temperature _ 1A"
      },
      "11334": {
        "name": "dx_motor_wind_temp_1a",
        "name2":"Diagnostic_ Motor Winding Temp Sensor _ Cprsr1A"
      },
      "11335": {
        "name": "dx_restart_hibit_invk_1a",
        "name2":"Diagnostic_ Restart Inhibit Invoked _ 1A"
      },
      "11337": {
        "name": "com_los_motor_wind_temp1_2a",
        "name2":"Comm Loss_ Motor Winding Temperature 1 _ 2A"
      },
      "11338": {
        "name": "com_los_motor_wind_temp2_2a",
        "name2":"Comm Loss_ Motor Winding Temperature 1 _ 2A"
      },
      "11340": {
        "name": "dx_hi_motor_wind_temp_2a",
        "name2":"Diagnostic_ High Motor Winding Temperature _ 2A"
      },
      "11342": {
        "name": "dx_motor_wind_temp_2a",
        "name2":"Diagnostic_ Motor Winding Temp Sensor _ Cprsr2A"
      },
      "11343": {
        "name": "dx_motor_wind_temp_2a",
        "name2":"Diagnostic_ Restart Inhibit Invoked _ 2A"
      },
      "11346": {
        "name": "dx_mp_invd_conf",
        "name2":"Diagnostic_ MP_ Invalid Configuration"
      },
      "11348": {
        "name": "dx_mp_reset_occd",
        "name2":"Diagnostic_ MP_ Reset Has Occurred"
      },
      "11352": {
        "name": "com_los_evap_water_pump1",
        "name2":"Comm Loss_ Evaporator Water Pump 1 Relay"
      },
      "11353": {
        "name": "dx_evap_pump1_start",
        "name2":"Diagnostic_ Evap Pump 1 Starts Run time Written"
      },
      "11360": {
        "name": "dx_evap_water_flow_lost_pump1",
        "name2":"Diagnostic_ Evaporator Water Flow Lost _ Pump1"
      },
      "11362": {
        "name": "dx_evap_water_flow_overdue_pump1",
        "name2":"Diagnostic_ Evaporator Water Flow Overdue _ Pump1"
      },
      "11364": {
        "name": "dx_chiller_service",
        "name2":"Diagnostic_ Chiller Service Recommended"
      },
      "11365": {
        "name": "dx_evap_water_pump1_svc",
        "name2":"Diagnostic_ Evap Water Pump 1 Svc Recommended"
      },
      "11367": {
        "name": "dx_mfr_maint_1a",
        "name2":"Diagnostic_ Mfr Maintenance Recommended _ 1A"
      },
      "11369": {
        "name": "dx_mfr_maint_2a",
        "name2":"Diagnostic_ Mfr Maintenance Recommended _ 2A"
      },
      "11373": {
        "name": "dx_oil_filt_change_1a",
        "name2":"Diagnostic_ Oil Filter Change Recommended _ Cprsr1A"
      },
      "11374": {
        "name": "dx_oil_filt_change_2a",
        "name2":"Diagnostic_ Oil Filter Change Recommended _ Cprsr2A"
      },
      "11375": {
        "name": "dx_afd_interp_fail_1a",
        "name2":"Diagnostic_ AFD Interrupt Failure _ 1A"
      },
      "11405": {
        "name": "dx_arm_start_fail_1a",
        "name2":"Diagnostic_ Starter Failed to Arm_Start _ 1A"
      },
      "11424": {
        "name": "dx_afd_interp_fail_2a",
        "name2":"Diagnostic_ AFD Interrupt Failure _ 2A"
      },
      "11452": {
        "name": "dx_arm_start_fail_2a",
        "name2":"Diagnostic_ Starter Failed to Arm_Start _ 2A"
      },
      "11474": {
        "name": "com_los_evap_enter_water_temp",
        "name2":"Comm Loss_ Evap Entering Water Temp"
      },
      "11476": {
        "name": "com_los_evap_leav_water_temp",
        "name2":"Comm Loss_ Evap Leaving Water Temp"
      },
      "11486": {
        "name": "dx_evap_enter_water_temp",
        "name2":"Diagnostic_ Evaporator Entering Water Temp Sensor"
      },
      "11488": {
        "name": "dx_evap_leav_water_temp",
        "name2":"Diagnostic_ Evaporator Leaving Water Temp Sensor"
      },
      "11489": {
        "name": "dx_invert_evap_water_temp",
        "name2":"Diagnostic_ Inverted Evaporator Water Temperature"
      },
      "11490": {
        "name": "dx_low_evap_water_temp_off",
        "name2":"Diagnostic_ Low Evaporator Water Temp (Unit Off)"
      },
      "11491": {
        "name": "dx_low_evap_water_temp_on",
        "name2":"Diagnostic_ Low Evaporator Water Temp (Unit On)"
      },
      "11422": {
        "name": "dx_unexpc_shutdown_1a",
        "name2":"Diagnostic_ Unexpected Starter Shutdown _ 1A"
      },
      "11468": {
        "name": "dx_unexpc_shutdown_2a",
        "name2":"Diagnostic_ Unexpected Starter Shutdown _ 2A"
      }}
output = {}

for key, val in input_data.items():
    name = val["name"]
    output[name] = {
        "Alarm": {"Min": "", "Max": ""},
        "Warning": {"Min": "1", "Max": "1"},
        "Active": 1
    }

print(json.dumps(output, indent=2))