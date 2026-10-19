<!DOCTYPE html>

    <?php 
    // $rgl_txt = file_get_contents('../rgl.json');
    // $dvl_txt = file_get_contents('../dvl.json');
    $rgl_arr = json_decode($rgl_txt,true);
    $dvl_arr = json_decode($dvl_txt,true);

    $meterSN = isset($_GET['meterSN']) ? $_GET['meterSN']:'';
    ?>
    
    <div style="display:flex;flex-direction: column;height:100vh;background:#FFF;padding-top:15px;">
        <!-- <div class="data-all"></div> -->
        <div style="display:flex;justify-content:center;margin-bottom:10px;"> 
            <div style="display:flex;margin-right:5px;"><div style="margin-right:5px;">Device All: </div><div id="count-meter">-</div> </div> 
            <div style="margin-right:5px;"> | </div>
            <div> <a href="/api_mdbiot.php" type="button" target="_blank"><button class="btn btn-dark" style="cursor:pointer;">api</button></a></div> 
        </div>

        <div style="display:flex;justify-content:center;">
            <div style="font-weight:500;margin-right:5px;">Date Time: </div>
            <div class="dt-val" ></div>
        </div>
        
        <div>
            <?php foreach($dvl_arr as $k=>$v){
                if($meterSN == ''){
            ?>
            <div id="meter-list" ></div>
            <?php } 
                if($meterSN == $k){
                    // echo '<div class="rmid-'.$meterSN.'-i_a"></div>';
                    // foreach(array_values($rgl_arr[$v['model']]) as $krgl=>$vrgl){ 

                    // }
                    // echo $k."==".json_encode();
                    ?>
                    <div style="border:1px solid #000;border-radius:8px;padding: 5px;margin:5px;width: 50vw;padding:10px;">
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_ab</div>
                                <div class="rmid-<?php echo $k?>-v_ab"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_bc</div>
                                <div class="rmid-<?php echo $k?>-v_bc"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_ca</div>
                                <div class="rmid-<?php echo $k?>-v_ca"> - </div>
                            </div>
                            <div>
                                <div>V</div>
                            </div>
                        </div>
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_an</div>
                                <div class="rmid-<?php echo $k?>-v_an"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_bn</div>
                                <div class="rmid-<?php echo $k?>-v_bn"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>v_cn</div>
                                <div class="rmid-<?php echo $k?>-v_cn"> - </div>
                            </div>
                            <div>
                                <div>V</div>
                            </div>
                        </div>
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>i_a</div>
                                <div class="rmid-<?php echo $k?>-i_a"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>i_b</div>
                                <div class="rmid-<?php echo $k?>-i_b"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>i_c</div>
                                <div class="rmid-<?php echo $k?>-i_c"> - </div>
                            </div>
                            <div>
                                <div>A</div>
                            </div>
                        </div>
                        <hr>

                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>p_a</div>
                                <div class="rmid-<?php echo $k?>-p_a"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>p_b</div>
                                <div class="rmid-<?php echo $k?>-p_b"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>p_c</div>
                                <div class="rmid-<?php echo $k?>-p_c"> - </div>
                            </div>
                            <div>
                                <div>kVA</div>
                            </div>
                        </div>
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>s_a</div>
                                <div class="rmid-<?php echo $k?>-s_a"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>s_b</div>
                                <div class="rmid-<?php echo $k?>-s_b"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>s_c</div>
                                <div class="rmid-<?php echo $k?>-s_c"> - </div>
                            </div>
                            <div>
                                <div>kVA</div>
                            </div>
                        </div>
                        
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>q_a</div>
                                <div class="rmid-<?php echo $k?>-q_a"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>q_b</div>
                                <div class="rmid-<?php echo $k?>-q_b"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>q_c</div>
                                <div class="rmid-<?php echo $k?>-q_c"> - </div>
                            </div>
                            <div>
                                <div>kVAR</div>
                            </div>
                        </div>
                        <hr>
                        <div style="display:flex;justify-content: space-between;">
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>pf_t</div>
                                <div class="rmid-<?php echo $k?>-pf_t"> - </div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>f</div>
                                <div class="rmid-<?php echo $k?>-f"> - </div>
                                <div>Hz</div>
                            </div>
                            <div style="display:flex;width: 30vw;justify-content: space-around;">
                                <div>i_n</div>
                                <div class="rmid-<?php echo $k?>-i_n"> - </div>
                                <div>A</div>
                            </div>
                            <div>
                                <div></div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            }?>
        </div>
    </div>
    
<script>

    let dvl = [];
    dvl = <?php echo json_encode($dvl_arr);?>;
    // console.log(dvl);

        setData();

        setInterval(setData, 1000);

    
    function send_url(url){
        console.log(url);
        let url2 = '';

        if(url=='1' || url==1) url2='https://api.mdbiot.com/vx_smart_api.php?mode=SendCMDSmartDevice&pr2={"CMD_text":"M117181619197000|RL000001|RL1|write|1"}';
        if(url=='2' || url==2) url2='https://api.mdbiot.com/vx_smart_api.php?mode=SendCMDSmartDevice&pr2={"CMD_text":"M117181619197000|RL000001|RL1|write|2"}';
        // alert(url2);
        
        let settings = {
            "url": url2,
            "method": "POST",
            "timeout": 0,
        };

        $.ajax(settings).done(function (response) {
            console.log(response);
            Swal.fire({
                position: "center",
                icon: "success",
                title: "Send command success",
                showConfirmButton: false,
                timer: 1000
            });

            // location.reload();
        });
    }
    
    function setData(){
        
        
        // const queryString = window.location.search;
        // const urlParams = new URLSearchParams(queryString);
        // const serialMain = urlParams.get('serial')
        // console.log('serialMain',serialMain);
        // url = "https://innovation.asefa.co.th/iot/asystem/config/debug/websocket/log/"+serialMain+".json"
        // url = "https://innovation.asefa.co.th/iot/asystem/config/debug/websocket/apirt005.php?serial="+serialMain
        url = "../api_mdbiot.php";
        // console.log(url);

        fetch(url)
        .then(function (response) {
        return response.json() // แปลงข้อมูลที่ได้เป็น json
        // return response.text()
        })
        .then(function (data) {
            // console.log(data);

            let dt = data.dt;
            let data_all = data.data;
            let key_meter = data_all != undefined ? Object.keys(data_all):[];
            let html_txt = "";
            let count_meter = 0;
            let select_meterSN = '<?php echo $meterSN;?>';

            // console.log(dt);
            $('.data-all').html(JSON.stringify(data_all));
            $('.dt-val').html(dt);

            for(let i=0 ;i<key_meter.length;i++){
                // console.log(data_all[key_meter[i]]);
                let key_measure = Object.keys(data_all[key_meter[i]]);
                // console.log(key_meter[i],key_measure);
                // for(let j=0 ;j<key_measure.length;j++){
                //     let vals = data_all[key_meter[i]][key_measure[j]];
                //     // console.log(key_meter[i],key_measure[j],vals);
                // }

                if(select_meterSN == key_meter[i]){
                    // console.log(data_all[key_meter[i]]);
                    // $('.rmid-'+key_meter[i]+'-').html();
                    for (const [key, value] of Object.entries(data_all[key_meter[i]])) {
                        // console.log(`${key}: ${value}`);
                        $('.rmid-'+key_meter[i]+'-'+key).html(value);
                    }
                }

                if(select_meterSN == ''){
                    if(data_all[key_meter[i]]['RL1'] == "1"){
                        status_bk = "OFF"
                    }else if(data_all[key_meter[i]]['RL1'] == "2"){
                        status_bk = "ON"
                    }


                    html_txt += '<div style="border:1px solid #000;border-radius:8px;padding: 5px;margin:5px;">'
                    +'<div style="text-align:center;cursor:pointer;" onclick="linkpagemeter(\''+key_meter[i]+'\')">['+key_meter[i]+'] '+dvl[key_meter[i]].name+' </div>'
                    +'<hr/>'
                    +'<div style="display:flex;flex-direction: column;justify-content:space-between;width:200px;">';


                    if(key_meter[i].substring(0, 2) == "IN"){
                        html_txt += '<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>p_max : </div> <div>'+data_all[key_meter[i]]['p_max']+'</div> <div>kW</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>p_out : </div> <div>'+data_all[key_meter[i]]['p_out']+'</div> <div>kW</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>in_dca_1 : </div> <div>'+data_all[key_meter[i]]['in_dca_1']+'</div> <div>A</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>in_dca_2 : </div> <div>'+data_all[key_meter[i]]['in_dca_2']+'</div> <div>A</div></div>';
                    }

                    if(key_meter[i].substring(0, 2) == "PM"){
                        html_txt += '<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>v_an : </div> <div>'+data_all[key_meter[i]]['v_an']+'</div> <div>V</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>i_avg : </div> <div>'+data_all[key_meter[i]]['i_avg']+'</div> <div>A</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>e_p : </div> <div>'+data_all[key_meter[i]]['e_p']+'</div> <div>kWh</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>p_t : </div> <div>'+data_all[key_meter[i]]['p_t']+'</div> <div>kW</div></div>';
                    }

                    if(key_meter[i].substring(0, 2) == "RL") {
                        html_txt+='<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>RL1 : </div> <div>'+data_all[key_meter[i]]['RL1']+'</div> <div>n/a</div ></div>'
                        + '<div> Status Breaker : '+status_bk+'</div>'
                        +'<div><button class="btn btn-danger" style="margin-right:5px;"  onclick="send_url(1)">OFF</button><button class="btn btn-success"  style="margin-right:5px;"  onclick="send_url(2)">ON</button></div>';
                    }

                    if(key_meter[i].substring(0, 2) == "SI"){
                        html_txt += '<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>SI1 : </div> <div>'+data_all[key_meter[i]]['SI1']+'</div> <div>n/a</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>SI2 : </div> <div>'+data_all[key_meter[i]]['SI2']+'</div> <div>n/a</div></div>'
                    }

                    if(key_meter[i].substring(0, 2) == "S1" ) {
                        html_txt += '<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>Humidity : </div> <div>'+data_all[key_meter[i]]['hm']+'</div> <div>%</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>High Temp : </div> <div>'+data_all[key_meter[i]]['temp1']+'</div> <div>°C</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>Low  Temp : </div> <div>'+data_all[key_meter[i]]['temp2']+'</div> <div>°C</div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>motion : </div> <div>'+parseInt(data_all[key_meter[i]]['motion'])+'</div> <div></div></div>'
                        +'<div style="display:flex;text-wrap:nowrap;justify-content:space-between;">'+'<div>smoke : </div> <div>'+parseInt(data_all[key_meter[i]]['smoke'])+'</div> <div></div></div>';
                        // console.log(data_all[key_meter[i]]);
                        
                    }


                    html_txt += '</div>';
                    html_txt += '</div>';
                }
                count_meter++;
                
            }
            $("#count-meter").html(count_meter);
            $("#meter-list").html('<div style="display:flex;flex-wrap:wrap;justify-content:center;">'+html_txt+'</div>');

        });
    }

function linkpagemeter(meterSN) {
    window.location.href='index.php?page=dashboard&meterSN='+meterSN;
    // console.log(window.location.href);
    
}
</script>