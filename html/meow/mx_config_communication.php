<!-- <?php echo $_SESSION['user_type'];?> -->

<?php 


$data_all = file_get_contents('./data/mcf_default.json');
// $data_all = file_get_contents('../data/mcf_default.json');
$data_all = $data_all != "" ? json_decode($data_all,true) : array();
// {"devcieSN":"M117210144775061","mx_count":5,"hb_count":60,"wss":"wss://smart.asefa.co.th:5501","ftp_host":"172.16.0.65","ftp_port":2002,"ftp_u":"meowftp","ftp_p":"Asefa@2o23"}
$deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";
$mx_count_f = isset($data_all['mx_count']) ? intval($data_all['mx_count']): 10;
$cml_send_f = isset($data_all['cml_send']) ? intval($data_all['cml_send']): 60;
$hb_count_f = isset($data_all['hb_count']) ? intval($data_all['hb_count']): 60;
$wss_f      = isset($data_all['wss']) ? $data_all['wss']: "";
$ftp_host_f = isset($data_all['ftp_host']) ? $data_all['ftp_host']: "";
$ftp_port_f = isset($data_all['ftp_port']) ? intval($data_all['ftp_port']): 21;
$ftp_u_f    = isset($data_all['ftp_u']) ? $data_all['ftp_u']: "";
$ftp_p_f    = isset($data_all['ftp_p']) ? $data_all['ftp_p']: "";
// echo $deviceSN_f;

?>
<div style="border:1px solid #ddd;border-radius:8px;padding:25px;background:#FFF;padding-left:10vw;padding-right:10vw;">
    <div><div style="font-size:22px;font-weight:700;text-align:center;margin-bottom:15px;">System & Configuration</div></div>

    <div style="font-size:16px;font-weight:700;margin-bottom:5px;">Device setting</div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Device S/N</div>
        <div class="input-group">
            <input type="text" class="form-control" id="device_sn" style="font-size:16px;" placeholder="Ex.:M112345678901234" value="<?php echo $deviceSN_f;?>">
        </div>
    </div>

    <hr>
    
    <div style="font-size:16px;font-weight:700;margin-bottom:5px;">Connection setting</div>
    
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">HTTP server</div>
        <div class="input-group">
            <input type="text" class="form-control" id="httpServer" style="font-size:16px;" placeholder="Ex.:http://api.mdbiot.com/vx_mdbiot_api.php" value="<?php echo isset($data_all['httpServer']) ? $data_all['httpServer']: "http://api.mdbiot.com/vx_mdbiot_api.php";?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">MQTT server</div>
        <div class="input-group">
            <input type="text" class="form-control" id="mqttBroker" style="font-size:16px;"  placeholder="Ex.:mqtt.mdbiot.com" value="<?php echo isset($data_all['mqttBroker']) ? $data_all['mqttBroker']: "mqtt.mdbiot.com";?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">MQTT port</div>
        <div class="input-group">
            <input type="number" class="form-control" id="mqttPort" style="font-size:16px;"  placeholder="Ex.:1883" value="<?php echo isset($data_all['mqttPort']) ? $data_all['mqttPort']: "1883";?>">
        </div>
    </div>

    <br>
    <div style="font-size:16px;font-weight:700;margin-bottom:5px;">Advance setting</div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Heart beat(s)</div>
        <div class="input-group">
            <input type="number" class="form-control" id="hb_count" style="font-size:16px;" placeholder="default:60 s" value="<?php echo $hb_count_f;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Send Log(s)&nbsp</div>
        <div class="input-group">
            <input type="number" class="form-control" id="cml_send" style="font-size:16px;" placeholder="default:60 s" value="<?php echo intval($cml_send_f);?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Count CML(time)&nbsp</div>
        <div class="input-group">
            <input type="number" class="form-control" id="cml_count" style="font-size:16px;" placeholder="default:5 time" value="<?php echo intval($mx_count_f);?>">
        </div>
    </div>
    <hr>
    
    <div style="font-size:16px;font-weight:700;margin-bottom:5px;">FTP setting</div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">FTP host</div>
        <div class="input-group">
            <input type="text" class="form-control" id="ftp_host" style="font-size:16px;" placeholder="Ex.:172.16.0.65" value="<?php echo $ftp_host_f;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">FTP port</div>
        <div class="input-group">
            <input type="number" class="form-control" id="ftp_port" style="font-size:16px;" placeholder="Ex.:2002" value="<?php echo $ftp_port_f;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">FTP username</div>
        <div class="input-group">
            <input type="text" class="form-control" id="ftp_username" style="font-size:16px;" placeholder="Ex.:username" value="<?php echo $ftp_u_f;?>">
        </div>
    </div>

    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">FTP password</div>
        <!-- Example password field intentionally has no preset value. -->
        <div class="input-group">
            <input type="password" class="form-control" name="txtPassword" autocomplete="new-password" id="ftp_password" value="<?php echo $ftp_p_f;?>" minlength="2" style="margin-bottom:5px;" placeholder="Password">
            <div class="input-group-append toggle-ftp-password" style="">
                <span class="input-group-text mdi mdi-eye-outline"></span>
            </div>
        </div>
    </div>
    
    <div><button class="btn btn-primary" onclick="save_communication()" style="width:100%">save</button></div>
</div>


<script>
    $(document).ready(function () {
        let user_type = "<?php echo $_SESSION['user_type'];?>";
        let inputField = document.getElementById("device_sn");
        let ftp_host = document.getElementById("ftp_host");
        let ftp_port = document.getElementById("ftp_port");
        let value = inputField.value;
        if(user_type == "superadmin" || user_type == "admin"){
            inputField.removeAttribute("disabled");
            ftp_host.removeAttribute("disabled");
            ftp_port.removeAttribute("disabled");
        }else {
            inputField.setAttribute("disabled", true);
            ftp_host.setAttribute("disabled", true);
            ftp_port.setAttribute("disabled", true);
        }



    });

    $('.toggle-ftp-password').click(function(){
        $(this).children().toggleClass('mdi-eye-outline mdi-eye-off-outline');
        let input = $(this).prev();
        input.attr('type', input.attr('type') === 'password' ? 'text' : 'password');
    });

    function writeTextFile(filepath, output) {
        let txtFile = new File(filepath);
        txtFile.open("w"); //
        txtFile.writeln(output);
        txtFile.close();
    }

    function save_communication(){
        let data_arr = {deviceSN:String($('#device_sn').val()),mx_count:parseInt($('#cml_count').val()),cml_send:parseInt($('#cml_send').val()),hb_count:parseInt($('#hb_count').val() !== undefined ? $('#hb_count').val():60),wss:String($('#wss_server').val()),mqttBroker:String($('#mqttBroker').val()),mqttPort:parseInt($('#mqttPort').val()),httpServer:String($('#httpServer').val()),ftp_host:String($('#ftp_host').val()),ftp_port:parseInt($('#ftp_port').val()),ftp_u:String($('#ftp_username').val()),ftp_p:String($('#ftp_password').val())};
        console.log(data_arr);
        
        Swal.fire({
            title: "Do you want to save the changes?",
            // showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: "Save",
            // denyButtonText: `Don't save`
        }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
                $.ajax({
                    type: 'GET',
                    url: "./mx_function_writefile.php",
                    data: {
                        dataE: JSON.stringify(data_arr)
                    },
                    success: function(response) {
                        console.log(response);
                        // Swal.fire("Saved!", "", "success");
                        Swal.fire({
                            position: "center",
                            icon: "success",
                            title: "Your work has been saved",
                            showConfirmButton: false,
                            timer: 1500
                        });

                    },
                    error: function(xhr, status, error) {
                        console.error('Error:', error);
                        Swal.fire("Changes are not saved", "", "info");
                    }
                });
            } else if (result.isDenied) {
                Swal.fire("Changes are not saved", "", "info");
            } else {
                Swal.fire("Changes are not saved", "", "info");
            }
        });

    }
</script>

<style>
    input[type=number]{
        text-align:center;
    }
    input[type=text]{
        text-align:center;
    }
    input[type=password]{
        text-align:center;
    }
    .input{
        width:100%;
    }
</style>
