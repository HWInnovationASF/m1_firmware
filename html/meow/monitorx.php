<!DOCTYPE html>

    <?php 
    // $rgl_txt = file_get_contents('../rgl.json');
    // $dvl_txt = file_get_contents('../dvl.json');
    $rgl_arr = json_decode($rgl_txt,true);
    $dvl_arr = json_decode($dvl_txt,true);

    $meterSN = isset($_GET['meterSN']) ? $_GET['meterSN']:'';
    ?>
    <?php 
    $template_uid = isset($dashboard_template_uid) ? (int)$dashboard_template_uid : 6;
    if($template_uid < 1 || $template_uid > 6) $template_uid = 6;
    // ใช้ค่าเริ่มต้นเดิม และอนุญาตให้ Template Config กำหนดกลุ่มอุปกรณ์แยกตาม template
    $tplDefaults = require __DIR__ . '/template_group_defaults.php';
    $list_p_mdb1 = $tplDefaults['mdb1'];
    $list_p_mdb1_use = $tplDefaults['mdb1_use'];
    $list_p_mdb2 = $tplDefaults['mdb2'];
    $list_p_mdb2_use = $tplDefaults['mdb2_use'];
    $list_p_mdb3 = $tplDefaults['mdb3'];
    $list_p_mdb4 = $tplDefaults['mdb4'];
    $list_ach = $tplDefaults['ach'];
    $tplSettingsAll = json_decode((string)@file_get_contents(__DIR__ . '/config/dashboard_template.json'), true);
    $tplGroups = $tplSettingsAll['settings']['template' . $template_uid]['groups'] ?? array();
    if (is_array($tplGroups)) foreach ($tplGroups as $tplGroup => $tplMeters) {
        if (!is_array($tplMeters)) continue;
        $tplMeters = array_values(array_filter(array_map('strval', $tplMeters), 'strlen'));
        if ($tplGroup === 'ach') $list_ach = $tplMeters;
        elseif (preg_match('/^mdb[1-4](_use)?$/', $tplGroup)) ${'list_p_' . $tplGroup} = $tplMeters;
    }
    
    include("./template/user/template".$template_uid.".php");
    ?>
    
    
<script>

    let dvl = [];
    dvl = <?php echo json_encode($dvl_arr);?>;
    // console.log(dvl);

        setData_x();

        setInterval(setData_x, 1000);

    
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
    
    function setData_x(){
        
        
        // const queryString = window.location.search;
        // const urlParams = new URLSearchParams(queryString);
        // const serialMain = urlParams.get('serial')
        // console.log('serialMain',serialMain);
        // url = "https://innovation.asefa.co.th/iot/asystem/config/debug/websocket/log/"+serialMain+".json"
        // url = "https://innovation.asefa.co.th/iot/asystem/config/debug/websocket/apirt005.php?serial="+serialMain
        url = "./api_mdbiotx.php";
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
            let list_p_mdb1 = JSON.parse('<?php echo json_encode($list_p_mdb1,256);?>');
            let list_p_mdb2 = JSON.parse('<?php echo json_encode($list_p_mdb2,256);?>');
            let list_p_mdb3 = JSON.parse('<?php echo json_encode($list_p_mdb3,256);?>');
            let list_p_mdb4 = JSON.parse('<?php echo json_encode($list_p_mdb4,256);?>');
            // console.log(list_p_mdb2);
            let sum_pt1=0;
            let sum_pt2=0;
            let sum_pt3=0;
            let sum_pt4=0;
            let sum_pt_use=0;
            

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
                for (const [key, value] of Object.entries(data_all[key_meter[i]])) {
                    // console.log(`${key}: ${value}`);
                    // console.log(typeof(list_p_mdb2));
                    
                    // console.log(`[${typeof(key_meter[i])}] ${key}: ${list_p_mdb2}`);
                    if(list_p_mdb2.includes(key_meter[i])){
                    // console.log(`[${typeof(key_meter[i])}] ${key}: ${list_p_mdb2}`);
                        if(key == "p_t"){
                            // console.log(key,"===",value);
                            sum_pt2+=parseInt(value);
                        }
                    }
                    if(list_p_mdb1.includes(key_meter[i])){
                    // console.log(`[${typeof(key_meter[i])}] ${key}: ${list_p_mdb2}`);
                        if(key == "p_t"){
                            // console.log(key,"===",value);
                            sum_pt1+=parseInt(value);
                        }
                    }
                    if(list_p_mdb3.includes(key_meter[i])){
                    // console.log(`[${typeof(key_meter[i])}] ${key}: ${list_p_mdb2}`);
                        if(key == "p_t"){
                            // console.log(key,"===",value);
                            sum_pt3+=parseInt(value);
                        }
                    }
                    if(list_p_mdb4.includes(key_meter[i])){
                    // console.log(`[${typeof(key_meter[i])}] ${key}: ${list_p_mdb2}`);
                        if(key == "p_t"){
                            // console.log(key,"===",value);
                            sum_pt4+=parseInt(value);
                        }
                    }
                    if(key == 'dt'){
                        // console.log(parseInt(value));
                        
                        // Create a new JavaScript Date object based on the timestamp
                        // multiplied by 1000 so that the argument is in milliseconds, not seconds
                        let date = new Date(value * 1000);
                        let hours = date.getHours();
                        let minutes = "0" + date.getMinutes();
                        let seconds = "0" + date.getSeconds();
                        let formattedTime = hours + ':' + minutes.substr(-2) + ':' + seconds.substr(-2);
                        // console.log(formattedTime);

                        $('.rmidx-'+key_meter[i]+'-'+key).html(formattedTime);
                    }else{
                        $('.rmidx-'+key_meter[i]+'-'+key).html(value);
                    }
                    // $('.rmid-'+key_meter[i]+'-dt').html(value);
                }
                if(key_meter[i] == select_meterSN){
                    // console.log(data_all[key_meter[i]]);
                    // $('.rmid-'+key_meter[i]+'-').html();
                    // for (const [key, value] of Object.entries(data_all[key_meter[i]])) {
                    //     console.log(`${key}: ${value}`);
                    //     // $('.rmid-'+key_meter[i]+'-'+key).html(value);
                    // }
                }

                if(select_meterSN == ''){
                    if(data_all[key_meter[i]]['RL1'] == "1"){
                        status_bk = "OFF"
                    }else if(data_all[key_meter[i]]['RL1'] == "2"){
                        status_bk = "ON"
                    }


                    html_txt += '<div style="border:1px solid #000;border-radius:8px;padding: 5px;margin:5px;">'
                    +'<div style="text-align:center;cursor:pointer;" onclick="linkpagemeter(\''+key_meter[i]+'\')">['+key_meter[i]+'] '+(dvl[key_meter[i]] ? dvl[key_meter[i]].name : key_meter[i])+' </div>'
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
            // console.log(sum_pt);
            
            $("#sum-mdb2").html(sum_pt2);
            $("#sum-mdb1").html(sum_pt1);
            $("#sum-mdb3").html(sum_pt3);
            $("#sum-mdb4").html(sum_pt4);
            $("#count-meter").html(count_meter);
            // $("#meter-list").html('<div style="display:flex;flex-wrap:wrap;justify-content:center;">'+html_txt+'</div>');

        });
    }

function linkpagemeter(meterSN) {
    window.location.href='index.php?page=dashboardx&meterSN='+meterSN;
    // console.log(window.location.href);
    
}
</script>


<style>
    .device-show-rows{
        width: 25%;
    }
    .device-show-container{
        display:flex;
        flex-wrap:wrap;
        justify-content:center;
        padding:20px 20px 20px 20px;
    }
    /* Mobile first styles (default) */

    /* Small devices (phones, up to 600px) */
    @media (max-width: 600px) {
        .device-show-rows{
            width: 100%;
        }
        .device-show-container{
            display:flex;
            flex-wrap:wrap;
            justify-content:center;
            padding:20px 20px 20px 20px;
        }
    }

    /* Medium devices (tablets, 601px–900px) */
    @media (min-width: 601px) and (max-width: 900px) {
        .device-show-rows{
            width: 33.32%;
        }
        .device-show-container{
            display:flex;
            flex-wrap:wrap;
            justify-content:center;
            padding:20px 20px 20px 20px;
        }
    }

    /* Large devices (desktops, 901px–1200px) */
    @media (min-width: 901px) and (max-width: 1200px) {
        .device-show-rows{
            width: 25%;
        }
        .device-show-container{
            display:flex;
            flex-wrap:wrap;
            justify-content:center;
            padding:20px 50px 20px 50px;
        }
    }

    /* Extra large screens (1201px and above) */
    @media (min-width: 1201px) {
        .device-show-rows{
            width: 25%;
        }
        .device-show-container{
            display:flex;
            flex-wrap:wrap;
            justify-content:center;
            padding:20px 50px 20px 50px;
        }
    }

</style>
