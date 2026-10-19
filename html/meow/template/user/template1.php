<div style="display:flex;flex-direction: column;background:#FFF;padding-top:15px;">
    <!-- <div class="data-all"></div> -->
    <div style="display:flex;justify-content:center;margin-bottom:10px;"> 
        <div style="display:flex;align-items: center;margin-right:5px;"><div style="margin-right:5px;">Device All: </div><div id="count-meter">-</div> </div> 
        <div style="display:flex;align-items: center;margin-right:5px;"> | </div>
        <div> <a href="/api_mdbiotx.php" type="button" target="_blank"><button class="btn btn-dark" style="cursor:pointer;">api</button></a></div> 
    </div>

    <div style="display:flex;justify-content:center;">
        <div style="display:flex;">
            <div style="font-weight:500;margin-right:5px;text-wrap:nowrap;">Date Time: </div>
            <div class="dt-val" style="width:100%;"></div>
        </div>
    </div>

    <div class="device-show-container" style="">
        <?php foreach($dvl_arr as $k=>$v){
                // echo json_encode($k);

            if($meterSN == ''){
        ?>
        <!-- <div id="meter-list" ></div> -->
        <div class="device-show-rows">
            <div style="border:1px solid #000;border-radius:8px;padding: 5px;margin:5px;">
                <div style="text-align:center;cursor:pointer;" onclick="linkpagemeter('<?php echo $k;?>')">[<?php echo $k;?>]<?php echo $dvl_arr[$k]['name'];?></div>
                <hr style="margin:0.25rem;">

                <?php 
                if(preg_match('/PM/',$k)){
                ?>
                <div style="display:flex;flex-direction: column;justify-content:space-between;">
                    <!-- <div id="" ><?php echo $k;?></div> -->
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">v_an : </div> 
                        <div class="rmidx-<?php echo $k;?>-v_an"  style="display: flex;justify-content: flex-end;">-</div>
                        <div  style="display: flex;justify-content: flex-end;">V</div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">e_p : </div>
                        <div class="rmidx-<?php echo $k;?>-e_p" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;">kWh</div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="minwidth:33%;display: flex;justify-content: flex-end;">p_t : </div>
                        <div class="rmidx-<?php echo $k;?>-p_t" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;">kW</div>
                    </div>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=p_t" frameborder="0"></iframe>
                    <hr style="margin:0.25rem;">
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div><i class="fa-solid fa-clock"></i> Last update : </div>
                        <div class="rmidx-<?php echo $k;?>-dt">-</div>
                    </div>
                </div>
                <?php } 
                if(preg_match('/IN/',$k)){
                ?>
                <div style="display:flex;flex-direction: column;justify-content:space-between;">
                    <!-- <div id="" ><?php echo $k;?></div> -->
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">p_in : </div> 
                        <div class="rmidx-<?php echo $k;?>-p_in"  style="display: flex;justify-content: flex-end;">-</div>
                        <div  style="display: flex;justify-content: flex-end;">kW</div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">p_out : </div>
                        <div class="rmidx-<?php echo $k;?>-p_out" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;">kW</div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="minwidth:33%;display: flex;justify-content: flex-end;">e_p : </div>
                        <div class="rmidx-<?php echo $k;?>-e_p" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;">kWh</div>
                    </div>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=p_out" frameborder="0"></iframe>

                    <hr style="margin:0.25rem;">
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div><i class="fa-solid fa-clock"></i> Last update : </div>
                        <div class="rmidx-<?php echo $k;?>-dt">-</div>
                    </div>
                </div>

                <?php } 
                if(preg_match('/SI/',$k)){
                ?>
                <div style="display:flex;flex-direction: column;justify-content:space-between;">
                    <!-- <div id="" ><?php echo $k;?></div> -->
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">SI1 : </div> 
                        <div class="rmidx-<?php echo $k;?>-SI1"  style="display: flex;justify-content: flex-end;">-</div>
                        <div  style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">SI2 : </div>
                        <div class="rmidx-<?php echo $k;?>-SI2" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="minwidth:33%;display: flex;justify-content: flex-end;">SI3 : </div>
                        <div class="rmidx-<?php echo $k;?>-SI3" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="minwidth:33%;display: flex;justify-content: flex-end;">temp1 : </div>
                        <div class="rmidx-<?php echo $k;?>-temp1" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=SI1" frameborder="0"></iframe>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=SI2" frameborder="0"></iframe>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=SI3" frameborder="0"></iframe>

                    <hr style="margin:0.25rem;">
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div><i class="fa-solid fa-clock"></i> Last update : </div>
                        <div class="rmidx-<?php echo $k;?>-dt">-</div>
                    </div>
                </div>

                <?php } 
                if(preg_match('/AC/',$k)){
                ?>
                <div style="display:flex;flex-direction: column;justify-content:space-between;">
                    <!-- <div id="" ><?php echo $k;?></div> -->
                     <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">mode : </div> 
                        <div class="rmidx-<?php echo $k;?>-mode"  style="display: flex;justify-content: flex-end;">-</div>
                        <div  style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">temp : </div> 
                        <div class="rmidx-<?php echo $k;?>-temp"  style="display: flex;justify-content: flex-end;">-</div>
                        <div  style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">sp : </div>
                        <div class="rmidx-<?php echo $k;?>-sp" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div style="display: flex;justify-content: flex-end;">ac-mode : </div>
                        <div class="rmidx-<?php echo $k;?>-ac_mode" style="display: flex;justify-content: flex-end;">-</div>
                        <div style="display: flex;justify-content: flex-end;"></div>
                    </div>
                    <iframe src="zx_chartx.php?meter_sn=<?php echo $k;?>&measure=temp" frameborder="0"></iframe>

                    <hr style="margin:0.25rem;">
                    <div style="display:flex;text-wrap:nowrap;justify-content:space-between;">
                        <div><i class="fa-solid fa-clock"></i> Last update : </div>
                        <div class="rmidx-<?php echo $k;?>-dt">-</div>
                    </div>
                </div>

                <?php } 
                ?>
            </div>
        </div>
        <?php 
        ?>

        <?php
            }
            if($meterSN == $k){
                // echo '<div class="rmidx-'.$meterSN.'-i_a"></div>';
                // foreach(array_values($rgl_arr[$v['model']]) as $krgl=>$vrgl){ 

                // }
                // echo $k."==".json_encode();
                $template_id = array();
                $template_id[$meterSN] = 2;
                include("./template/device/template".$template_id[$meterSN].".php");
                ?>
                <!-- <div style="border:1px solid #000;border-radius:8px;padding: 5px;margin:5px;width: 50vw;padding:10px;">
                    <div style="text-align:center;cursor:pointer;" onclick="linkpagemeter('<?php echo $k;?>')">[<?php echo $k;?>]<?php echo $dvl_arr[$k]['name'];?></div>
                    <hr/>
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_ab</div>
                            <div class="rmidx-<?php echo $k?>-v_ab"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_bc</div>
                            <div class="rmidx-<?php echo $k?>-v_bc"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_ca</div>
                            <div class="rmidx-<?php echo $k?>-v_ca"> - </div>
                        </div>
                        <div>
                            <div>V</div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_an</div>
                            <div class="rmidx-<?php echo $k?>-v_an"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_bn</div>
                            <div class="rmidx-<?php echo $k?>-v_bn"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">v_cn</div>
                            <div class="rmidx-<?php echo $k?>-v_cn"> - </div>
                        </div>
                        <div>
                            <div>V</div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">i_a</div>
                            <div class="rmidx-<?php echo $k?>-i_a"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">i_b</div>
                            <div class="rmidx-<?php echo $k?>-i_b"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">i_c</div>
                            <div class="rmidx-<?php echo $k?>-i_c"> - </div>
                        </div>
                        <div>
                            <div>A</div>
                        </div>
                    </div>
                    <hr>

                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">p_a</div>
                            <div class="rmidx-<?php echo $k?>-p_a"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">p_b</div>
                            <div class="rmidx-<?php echo $k?>-p_b"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">p_c</div>
                            <div class="rmidx-<?php echo $k?>-p_c"> - </div>
                        </div>
                        <div>
                            <div>kVA</div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">s_a</div>
                            <div class="rmidx-<?php echo $k?>-s_a"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">s_b</div>
                            <div class="rmidx-<?php echo $k?>-s_b"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">s_c</div>
                            <div class="rmidx-<?php echo $k?>-s_c"> - </div>
                        </div>
                        <div>
                            <div>kVA</div>
                        </div>
                    </div>
                    
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">q_a</div>
                            <div class="rmidx-<?php echo $k?>-q_a"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">q_b</div>
                            <div class="rmidx-<?php echo $k?>-q_b"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">q_c</div>
                            <div class="rmidx-<?php echo $k?>-q_c"> - </div>
                        </div>
                        <div>
                            <div>kVAR</div>
                        </div>
                    </div>
                    <hr>
                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">p_t</div>
                            <div class="rmidx-<?php echo $k?>-p_t"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">e_p</div>
                            <div class="rmidx-<?php echo $k?>-e_p"> - </div>
                            <div>Hz</div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">i_a</div>
                            <div class="rmidx-<?php echo $k?>-i_a"> - </div>
                            <div>A</div>
                        </div>
                        <div>
                            <div></div>
                        </div>
                    </div>
                    <hr>

                    <div style="display:flex;justify-content: space-between;">
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">pf_t</div>
                            <div class="rmidx-<?php echo $k?>-pf_t"> - </div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">f</div>
                            <div class="rmidx-<?php echo $k?>-f"> - </div>
                            <div>Hz</div>
                        </div>
                        <div style="display:flex;width: 30vw;justify-content: space-around;">
                            <div style="font-weight:700;">i_n</div>
                            <div class="rmidx-<?php echo $k?>-i_n"> - </div>
                            <div>A</div>
                        </div>
                        <div>
                            <div></div>
                        </div>
                    </div>
                    <hr>
                    <div style="display:flex;text-wrap:nowrap;justify-content:flex-start;">
                        <div>Last update : </div>
                        <div class="rmidx-<?php echo $k;?>-dt">-</div>
                    </div>
                </div> -->
                <?php
            }
        }?>
    </div>
</div>