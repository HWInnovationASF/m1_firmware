<?php
    require_once __DIR__ . '/session_bootstrap.php';
    // echo $_SESSION['user_type'];
    // ===========================================
    // require './con_db.php';

    $data_all = file_get_contents('./data/mcf_default.json');
    // $data_all = file_get_contents('../data/mcf_default.json');
    $data_all = $data_all != "" ? json_decode($data_all,true) : array();
    $deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";


?>
<!-- <div>
    <div><div>Advance setting</div></div>
    <div style="display:flex;">
        <div>Serial :</div>
        <div>
            <?php if($_SESSION['user_type'] == "superadmin"){?>
            <input type="text" id="device_sn" value="<?php echo $deviceSN_f; ?>" placeholder="Ex.:M112345678901234" >
            <?php }?>
        
        </div>
    </div>
    <div><button class="btn btn-primary" onclick="save_main()">save</button></div>
</div> -->


<?php 
    // $rgl_txt = file_get_contents('./rgl.json');
    // $dvl_txt = file_get_contents('./dvl.json');
    // $cml_txt = file_get_contents('./cml.json');
    // $rgl_arr = json_decode($rgl_txt,true);
    // $dvl_arr = json_decode($dvl_txt,true);
    // $cml_arr = json_decode($cml_txt,true);
    // echo json_encode(array_keys($rgl_arr));
    
    // echo $dvl_txt;
    $page_list = isset($_GET['pg']) ? $_GET['pg']:'meter';
    $meterSN = isset($_GET['meterSN']) ? $_GET['meterSN']:'';
    $modelName = isset($_GET['modelName']) ? $_GET['modelName']:'';

?>

<div style="display:flex;flex-direction: column;width:100%;padding: 5px 15px 5px 15px;">
    <!-- <div style="display:flex;">
        <m_breadcrumbs class="btn btn-outline-dark" onclick="window.location.href='index.php?page=device&pg=meter'">Meter</m_breadcrumbs>
        <m_breadcrumbs class="btn btn-outline-dark" onclick="window.location.href='index.php?page=device&pg=sensor'">Sensor</m_breadcrumbs>
    </div> -->

    <div class="card">
        <div class="card-header" style="display:flex;justify-content: space-between;">
            <div style="display:flex;align-items: center;">
                <m_breadcrumbs onclick="window.location.href='index.php?page=device&pg=meter'"><?php echo $deviceSN_f;?></m_breadcrumbs>
                <m_breadcrumbs onclick="window.location.href='index.php?page=device&pg=sensor&meterSN=<?php echo $meterSN;?>'"><?php echo "/".$meterSN;?></m_breadcrumbs>
            </div>
            <div style="display:flex;align-items: center;">
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="text" class="form-control" id="myInput" onkeyup="myFunction_search()" placeholder="Search for names.." title="Type in a name">
                </div>
                &nbsp;

                <?php 
                    if($page_list == "model"){?>
                    <button class="btn btn-success" onclick="add_model()" style="text-wrap:nowrap;">+new model</button>
                <?php    }elseif($page_list == "sensor"){ ?>
                    <button class="btn btn-success" style="text-wrap:nowrap;">+new CML</button>
                <?php }
                else{?>
                <button class="btn btn-primary" onclick="window.location.href='index.php?page=device&pg=model&meterSN=<?php echo $meterSN;?>'" style="text-wrap:nowrap;">meter model</button>
                <?php } ?>
            </div>
        </div>
        <div class="card-body hide-scrollbar" style="">
            <?php if($page_list == "meter"){?>
        
            <!-- <button class="btn btn-primary" onclick="update_dvl_2server('dvl.json')">Upload DVL all</button> -->
            <div style="display:flex;justify-content: flex-end;margin-bottom:5px;">
                <div><button class="btn btn-danger" id="bulkDeleteDevices" type="button" disabled onclick="deleteSelectedDevices()"><i class="fa-regular fa-trash-can"></i> Delete selected (<span id="bulkDeleteCount">0</span>)</button></div>
                &nbsp;
                <div ><button class="btn btn-dark "  onclick="import_m1('')" ><i class="fa-solid fa-file-export"></i> Import Config</button></div>
                &nbsp;
                <div ><button class="btn btn-dark"  onclick="export_m1('dvl.json')" ><i class="fa-solid fa-file-export"></i> Export Config</button></div>
                &nbsp;
                <div ><button class="btn btn-info"  onclick="window.location.href='index.php?page=device&pg=cmlall'" >CML All </button></div>
                &nbsp;
                <div ><button class="btn btn-success" onclick="add_device()">new Device </button></div>
            </div>
            <table id="customers" style="border-radius:8px;">
                <tr>
                    <th style="width:42px;text-align:center;"><input type="checkbox" id="selectAllDevices" aria-label="Select all devices"></th>
                    <th>No.</th>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Model</th>
                    <th>Mode</th>
                    <th>Meter ID</th>
                    <th>detail</th>
                </tr>
                <?php 
                $i_m=0;
                ksort($dvl_arr);
                foreach($dvl_arr as $k=>$v){
                    $i_m++;
                ?>
                <tr>
                    <td style="text-align:center;"><input type="checkbox" class="device-delete-check" value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>" aria-label="Select <?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>"></td>
                    <td><?php echo $i_m;?></td>
                    <td><?php echo $k;?></td>
                    <td><?php echo $dvl_arr[$k]['name'];?></td>
                    <td><?php echo $dvl_arr[$k]['model'];?></td>
                    <td><?php echo htmlspecialchars($dvl_arr[$k]['mode'] ?? (isset($dvl_arr[$k]['ipaddress']) ? "tcp" : "rtu"));?></td>
                    <td><?php echo htmlspecialchars($dvl_arr[$k]['deviceID'] ?? '');?></td>
                    <td>
                        <div style="display:flex;justify-content: center;">
                            <button class="btn btn-dark" id="sendsensor" onclick="window.location.href='index.php?page=device&pg=sensor&meterSN=<?php echo $k;?>'" >+CML</button> &nbsp;
                            <button class="btn btn-info" id="sendinfo" onclick='show_cml("<?php echo $k;?>",<?php echo json_encode($dvl_arr[$k]);?>)' ><i class="fa-regular fa-circle-info"></i></button> &nbsp;
                            <!-- <button class="btn btn-info" id="sendinfo" onclick='check_info(<?php echo json_encode($dvl_arr[$k]);?>)' ><i class="fa-solid fa-circle-info"></i></button> &nbsp; -->
                            <button class="btn btn-warning" onclick='check_edit(<?php echo json_encode($dvl_arr[$k]);?>,"<?php echo $k?>")'><i class="fa-regular fa-pen-to-square"></i></button> &nbsp;
                            <button class="btn btn-danger" onclick="updateFile('<?php echo $k?>')"><i class="fa-regular fa-trash-can"></i></button> 
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </table>
            <?php }elseif($page_list == "sensor"){ ?>
                <!-- <div style="display:flex;justify-content:flex-end;margin-bottom:10px;"><button class="btn btn-primary">Save change</button></div> -->
            <?php
                $selectedDevice = $dvl_arr[$meterSN] ?? [];
                $selectedModel = $selectedDevice['model'] ?? '';
                $selectedModelRegisters = ($selectedModel !== '' && isset($rgl_arr[$selectedModel]) && is_array($rgl_arr[$selectedModel]))
                    ? $rgl_arr[$selectedModel]
                    : [];
                $selectedModelInfoJson = json_encode(
                    [
                        'model' => $selectedModel,
                        'registers' => $selectedModelRegisters
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                );
            ?>
            <script type="application/json" id="selected-model-registers"><?php echo $selectedModelInfoJson !== false ? $selectedModelInfoJson : '{"model":"","registers":{}}'; ?></script>
            <div style="display:flex;justify-content: flex-end;margin-bottom:5px;">
                <div style="display:flex;justify-content:flex-start;margin-bottom:10px;"><button class="btn btn-info" onclick="info_meter_from_page()">Info <?php echo htmlspecialchars($selectedModel, ENT_QUOTES, 'UTF-8');?></button></div>&nbsp
                <div style="display:flex;justify-content:flex-end;margin-bottom:10px;"><button class="btn btn-success" onclick="add_cml('<?php echo $meterSN;?>')">+add CML</button></div>
            </div>
            
            <table id="customers" style="border-radius:8px;">
                <tr>
                    <th>CMLID</th>
                    <th>Mode</th>
                    <th>Register start - end</th>
                    <th>Act</th>
                </tr>
                <?php 
                // ksort($rgl_arr[$dvl_arr[$meterSN]['model']]);
                // foreach($rgl_arr[$dvl_arr[$meterSN]['model']] as $k=>$v){
                foreach($cml_arr as $k=>$v){
                    if(array_key_exists($meterSN,$v[array_keys($v)[0]])){
                    ?>
                    <tr>
                        <td><?php echo $k;?></td>
                        <td>
                            <select class="form-select" name="" id="val-fn-<?php echo $k?>">
                                <option value="" selected>-</option>
                                <!-- <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                <option value="read_holding_tcp">read_holding_tcp</option>
                                <option value="read_holding_rtu">read_holding_rtu</option> -->


                                <?php if(array_keys($v)[0] == "read_holding_tcp"){?>
                                <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                <?php }else{ ?>
                                <option value="read_holding_tcp">read_holding_tcp</option>
                                <?php } ?>


                                <?php if(array_keys($v)[0] == "read_holding_rtu"){?>
                                <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                <?php }else{ ?>
                                <option value="read_holding_rtu">read_holding_rtu</option>
                                <?php } ?>

                                <?php if(array_keys($v)[0] == "recv_holding_rtu"){?>
                                <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                <?php }else{ ?>
                                <option value="recv_holding_rtu">recv_holding_rtu</option>
                                <?php } ?>
                            </select>
                            
                        </td>
                        <td>
                            <?php 
                            // echo 'model :'.$dvl_arr[$meterSN]['model']."\n";
                            // echo json_encode($v[array_keys($v)[0]][$meterSN]);
                            // echo $k."==".array_keys($v)[0]."==".$meterSN;
                            /*
                            foreach($rgl_arr[$dvl_arr[$meterSN]['model']] as $k2=>$v2){

                                if($k2 >= $v[array_keys($v)[0]][$meterSN][0] && $k2 <= $v[array_keys($v)[0]][$meterSN][1] ){
                            ?>
                            <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" checked> <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                            <?php
                                }else{
                                ?>
                            <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" > <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                            <?php }} */?>
                            <div style="display:flex;">
                                <p>Start</p><input type="text" class="form-control" id="val-start-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][$meterSN][0];?>" placeholder="[start,end]">
                                <p>End</p><input type="text" class="form-control" id="val-end-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][$meterSN][1];?>" placeholder="[start,end]">
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;">
                                <button class="btn btn-dark" onclick="save_cml('<?php echo $meterSN;?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')"><i class="fa-solid fa-floppy-disk"></i></button> &nbsp;
                                <button class="btn btn-dark" onclick="del_cml('<?php echo $meterSN;?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')"><i class="fa-regular fa-trash-can"></i></button>
                            </div>
                        </td>
                        <!-- <td><?php echo json_encode($v[array_keys($v)[0]]);?></td> -->
                    </tr>
                    <?php 
                    }
                }?>
            </table>
            <?php }elseif($page_list == "model"){ ?>
                <div style="display:flex;justify-content: space-between;margin-bottom:10px;">
                    <div style="display:flex;">
                        <?php 
                        // echo json_encode($_SESSION,256);
                        if($_SESSION['username'] == "dev"){
                        ?>
                        <button class="btn btn-primary" onclick="update2server('rgl.json')">Upload model all</button>
                        <!-- <button class="btn btn-primary" onclick="syncModel('rgl.json')">Sync model</button> -->
                        <?php }?>
                    </div>
                    <div style="display:flex;">
                        <!-- <button class="btn btn-danger" onclick="del_model('<?php echo $modelName;?>')">Del model</button>
                        &nbsp -->
                        <button class="btn btn-success" onclick="add_rgl('<?php echo $modelName;?>')">+add RGL</button>
                    </div>
                </div>
                
                <!-- <div style="display:flex;margin-bottom:10px;"> -->
                    <p style="text-wrap:nowrap;">Meter model: </p>
                    <select class="form-select" name="model" id="selectModel" onchange="select_model()">
                        <option value="<?php echo $modelName != "" ? $modelName:'';?>"><?php echo $modelName != "" ? $modelName:'-';?></option>
                        <?php 
                        
                        foreach($rgl_arr as $k=>$v){?>
                        <option value="<?php echo $k;?>" <?php echo $modelName == $k ? "selected" : ""; ?>><?php echo $k;?></option>
                        <?php } ?>
                    </select>
                    <br>
                    <?php
                        $modelDetailArr = isset($detail_rgl_txt) && $detail_rgl_txt !== '' ? json_decode($detail_rgl_txt, true) : array();
                        if (!is_array($modelDetailArr)) $modelDetailArr = array();
                        $currentModelType = isset($modelDetailArr[$modelName]['type']) ? (string)$modelDetailArr[$modelName]['type'] : '';
                        $modelTypes = array('meter'=>'PM','copro'=>'S1','inverter'=>'IN','chiller'=>'AC','battery'=>'BE','sensor'=>'SI','control'=>'RL');
                    ?>
                    <div class="card" style="padding:12px 14px;margin-bottom:14px;border:1px solid #dbe5ef;">
                        <div style="display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap;">
                            <label style="flex:1;min-width:210px;margin:0;"><span style="display:block;margin-bottom:5px;font-weight:700;">Model Type</span>
                                <select class="form-select" id="modelClassificationType" <?php echo $modelName === '' ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Type --</option>
                                    <?php foreach($modelTypes as $typeName=>$typeAbbr){ ?>
                                    <option value="<?php echo $typeName;?>" <?php echo $currentModelType === $typeName ? 'selected' : ''; ?>><?php echo ucfirst($typeName).' ('.$typeAbbr.')';?></option>
                                    <?php } ?>
                                </select>
                            </label>
                            <button type="button" class="btn btn-primary" onclick="saveModelClassification()" <?php echo $modelName === '' ? 'disabled' : ''; ?>><i class="fa-solid fa-plus"></i> Add / Update Type</button>
                            <button type="button" class="btn btn-danger" onclick="removeModelClassification()" <?php echo $modelName === '' || $currentModelType === '' ? 'disabled' : ''; ?>><i class="fa-regular fa-trash-can"></i> Remove Type</button>
                        </div>
                        <small style="margin-top:7px;color:#6c7c8d;">Current: <b><?php echo $currentModelType !== '' ? htmlspecialchars($currentModelType, ENT_QUOTES, 'UTF-8') : 'Not assigned';?></b></small>
                    </div>
                <!-- </div> -->
                <div style="overflow-x: scroll;">
                    <table id="customers" style="border-radius:8px;">
                            <tr>
                                <th>No.</th>
                                <th>register</th>
                                <th>Name</th>
                                <th>type</th>
                                <th>unit</th>
                                <th>decimal</th>
                                <th>multiply</th>
                                <th>Edit</th>
                            </tr>
                            <?php 
                            $i_c=0;
                            // ksort(array_keys($rgl_arr[$modelName]));
                            if(array_key_exists($modelName,$rgl_arr)){
                                ksort($rgl_arr[$modelName]);
                            }
                            foreach($rgl_arr[$modelName] as $k=>$v){
                                $i_c++;
                                ?>
                            <tr>
                                <td><?php echo $i_c;?></td>
                                <!-- <td><input type="text" class="form-control" id="model-register-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $k;?>" disabled> </td> -->
                                <td><?php echo $k;?></td>
                                <td>
                                    <div style="display:none;"><?php echo $v['name'];?></div>
                                    <input type="text" class="form-control" id="model-name-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $v['name'];?>" style="min-width:100px;text-align:center;"> 
                                </td>
                                <td>
                                    <select  class="form-select" name="" id="model-type-<?php echo $modelName;?>-<?php echo $k;?>" style="min-width:100px;text-align:center;">
                                        <option value="<?php echo $v['type'];?>"><?php echo $v['type'];?></option>
                                        <option value="float32">float32</option>
                                        <option value="float32 inverse">float32 inverse</option>
                                        <option value="int sign16">int sign16</option>
                                        <option value="int unsign16">int unsign16</option>
                                        <option value="int16 binary">int16 binary</option>
                                        <option value="long unsign32">long unsign32</option>
                                        <option value="long unsign32 inverse">long unsign32 inverse</option>
                                        <option value="long sign32">long sign32</option>
                                        <option value="long unsign64">long unsign64</option>
                                        <option value="long sign64">long sign64</option>
                                    </select>
                                </td>
                                <td><input type="text" class="form-control" id="model-unit-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $v['unit'];?>" style="min-width:100px;text-align:center;"> </td>
                                <td><input type="text" class="form-control" id="model-decimal-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $v['decimal'];?>" style="min-width:100px;text-align:center;"> </td>
                                <td><input type="text" class="form-control" id="model-multiply-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $v['multiply'];?>" style="min-width:100px;text-align:center;"> </td>
                                <td>
                                    <div style="display:flex;">
                                        <button class="btn btn-primary" onclick="save_rgl('<?php echo $modelName;?>','<?php echo $k;?>')"><i class="fa-solid fa-floppy-disk"></i></button>
                                        &nbsp
                                        <button class="btn btn-danger" onclick="del_rgl('<?php echo $modelName;?>','<?php echo $k;?>')"><i class="fa-regular fa-trash-can"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>

                        </table>
                </div>
            <?php } elseif($page_list == "cmlall"){ ?>
                <div>
                <div style="display:flex;justify-content:flex-start;margin-bottom:10px;"><button class="btn btn-danger" onclick="clear_cml()">Clear All</button></div>
                    <table id="customers" style="border-radius:8px;">
                        <tr>
                            <th>No.</th>
                            <th>cmdid</th>
                            <th>model</th>
                            <th>Function</th>
                            <th>meter_SN</th>
                            <th>Register start - end</th>
                            <th>Act</th>
                        </tr>
                        <?php 
                        $i_c=0;
                        foreach($cml_arr as $k=>$v){
                            $i_c++;
                            ?>
                        <tr>
                            <td><?php echo $i_c;?></td>
                            <td><?php echo $k;?></td>
                            <td><?php echo $dvl_arr[array_keys($v[array_keys($v)[0]])[0]]['model'];?></td>

                            <td>
                                <select class="form-select" name="" id="val-fn-<?php echo $k?>">
                                    <option value="" selected>-</option>
                                    <!-- <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <option value="read_holding_tcp">read_holding_tcp</option>
                                    <option value="read_holding_rtu">read_holding_rtu</option> -->


                                    <?php if(array_keys($v)[0] == "read_holding_tcp"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_holding_tcp">read_holding_tcp</option>
                                    <?php } ?>


                                    <?php if(array_keys($v)[0] == "read_holding_rtu"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_holding_rtu">read_holding_rtu</option>
                                    <?php } ?>

                                    <?php if(array_keys($v)[0] == "recv_holding_rtu"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="recv_holding_rtu">recv_holding_rtu</option>
                                    <?php } ?>

                                </select>
                            </td>
                            <td>
                                <input type="text" class="form-control" name="" id="val-meterSN-<?php echo $k?>" value="<?php echo array_keys($v[array_keys($v)[0]])[0];?>">
                                
                            </td>
                            <td>
                                <?php 
                                // echo 'model :'.$dvl_arr[$meterSN]['model']."\n";
                                // echo json_encode($v[array_keys($v)[0]][$meterSN]);
                                // echo $k."==".array_keys($v)[0]."==".json_encode($v[array_keys($v)[0]]);
                                // echo json_encode($v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][0]);
                                /*
                                foreach($rgl_arr[$dvl_arr[$meterSN]['model']] as $k2=>$v2){

                                    if($k2 >= $v[array_keys($v)[0]][$meterSN][0] && $k2 <= $v[array_keys($v)[0]][$meterSN][1] ){
                                ?>
                                <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" checked> <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                                <?php
                                    }else{
                                    ?>
                                <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" > <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                                <?php }} */?>
                                <div style="display:flex;">
                                    <p>Start</p><input type="text" class="form-control" id="val-start-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][0];?>" placeholder="[start,end]">
                                    <p>End</p><input type="text" class="form-control" id="val-end-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][1];?>" placeholder="[start,end]">
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;">
                                    <button class="btn btn-dark" onclick="save_cml('<?php echo array_keys($v[array_keys($v)[0]])[0];?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')"><i class="fa-solid fa-floppy-disk"></i></button>&nbsp;
                                    <button class="btn btn-dark" onclick="del_cml('<?php echo array_keys($v[array_keys($v)[0]])[0];?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')"><i class="fa-regular fa-trash-can"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>

                    </table>
                </div>
            <?php } ?>
        </div>
    </div>

    
</div>


<script>
function syncDeviceSelection() {
    const boxes = Array.from(document.querySelectorAll('.device-delete-check'));
    const selected = boxes.filter(box => box.checked);
    const selectAll = document.getElementById('selectAllDevices');
    const button = document.getElementById('bulkDeleteDevices');
    const count = document.getElementById('bulkDeleteCount');
    if (count) count.textContent = selected.length;
    if (button) button.disabled = selected.length === 0;
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}

document.querySelectorAll('.device-delete-check').forEach(box => box.addEventListener('change', syncDeviceSelection));
document.getElementById('selectAllDevices')?.addEventListener('change', event => {
    document.querySelectorAll('.device-delete-check').forEach(box => { box.checked = event.target.checked; });
    syncDeviceSelection();
});

async function deleteSelectedDevices() {
    const selected = Array.from(document.querySelectorAll('.device-delete-check:checked')).map(box => box.value);
    if (!selected.length) return;
    const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const confirmation = await Swal.fire({
        title: `Delete ${selected.length} devices?`,
        html: '<div style="max-height:180px;overflow:auto;text-align:left;font-size:.8rem">' + selected.map(id => '<div>• '+escapeHtml(id)+'</div>').join('') + '</div><small style="display:block;margin-top:10px">Command List entries belonging to these devices will also be removed.</small>',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Delete selected', cancelButtonText: 'Cancel', confirmButtonColor: '#d33'
    });
    if (!confirmation.isConfirmed) return;
    Swal.fire({title:'Deleting devices…', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading()});
    try {
        const response = await fetch('./fn_api.php?action=device_del_bulk', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({meter_SN:selected})
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Delete failed');
        await Swal.fire({icon:'success', title:'Deleted', text:`Removed ${result.deleted_count} devices and ${result.deleted_commands} command entries.`, timer:1400, showConfirmButton:false});
        location.reload();
    } catch (error) {
        Swal.fire({icon:'error', title:'Delete failed', text:error.message});
    }
}

const tasks = document.querySelectorAll(".task");

// Load saved state
tasks.forEach((task, index) => {
    task.checked = localStorage.getItem("task" + index) === "true";
    task.addEventListener("change", () => {
        localStorage.setItem("task" + index, task.checked);
    });
});

let allChecked = false;
function selectAll() {
    const tasks = document.querySelectorAll(".task");
    allChecked = !allChecked;

    tasks.forEach(task => task.checked = allChecked);

    document.getElementById("toggleButton").textContent = allChecked ? "✖ Uncheck All" : "✔ Select All";
}

function check_info(data) {
    // Swal.fire(JSON.stringify(data));
    // console.log(JSON.stringify(data));
    console.log(data);
    Swal.fire({
        title: "<strong>"+data.name+"</strong>",
        // icon: "info",
        html: `
            <div style="display:flex;justify-content:center;flex-direction: column;">
                <div style="display:flex;"><p>Model :</p><p>${data.model}</p></div>
                <div style="display:flex;"><p>Mode :</p><p>${data.mode}</p></div>
                <div style="display:flex;"><p>IP :</p><p>${data.ipaddress || data.ip || ''}</p></div>
                <div style="display:flex;"><p>ID :</p><p>${data.deviceID || data.DeviceInstance || ''}</p>
            </div>
        `,
        showCloseButton: true,
        showCancelButton: false,
        focusConfirm: false,
        confirmButtonText: `
            <i class="fa fa-thumbs-up"></i> OK
        `,
        cancelButtonAriaLabel: "Thumbs down"
    });
    
}

function show_cml(metersn,data) {
    (async () => {
        console.log(data);
        let cml_txt = '<?php echo json_encode($cml_arr,256);?>'
        let cml_arr;
        if(cml_txt){ cml_arr = JSON.parse(cml_txt); } 
        // console.log(cml_arr);
        let output_html = '<table class="styled-table" style="font-size:12px;">';
        output_html += '<tr>';
        output_html += '<th>cmdid</th>';
        output_html += '<th>function</th>';
        output_html += '<th style="text-wrap:nowrap;">register [start,end]</th>';
        output_html += '</tr>';

        Object.keys(cml_arr).forEach(key => {
            if(metersn in Object.values(cml_arr[key])[0]){
                // console.log(metersn,key, Object.values(cml_arr[key])[0][metersn]);
                output_html += `<tr > 
                <td style="text-wrap:nowrap;">${key}</td>
                <td style="text-wrap:nowrap;">${Object.keys(cml_arr[key])[0]}</td>
                <td style="text-wrap:nowrap;">${Object.values(cml_arr[key])[0][metersn]}</td>
                </tr>`;
            }
        });
        output_html += '</table>';

        
        


        const { value: formValues } = await Swal.fire({
            title: 'Show Command List',
            background: "#fff ",
            html:
                // `<div><button class="btn btn-primary" onclick"add_cml('${metersn}')">${metersn}add cml</button></div>`+
                `<div style="display:flex;justify-content:center;flex-direction: column;">
                    <div style="display:flex;"><p>Model :</p><p>${data.model}</p></div>
                    <div style="display:flex;"><p>Mode :</p><p>${data.mode}</p></div>
                    <div style="display:flex;"><p>IP :</p><p>${data.ipaddress}</p></div>
                    <div style="display:flex;"><p>ID :</p><p>${data.deviceID}</p>
                </div>`+
                output_html
                ,
            focusConfirm: false
        })

    })()
    
}

function info_meter_from_page() {
    const dataElement = document.getElementById('selected-model-registers');
    let payload = {};

    try {
        payload = JSON.parse(dataElement?.textContent || '{}');
    } catch (error) {
        console.error('Cannot parse model registers:', error);
    }

    info_meter(payload.model || '', payload.registers || {});
}

function info_meter(model,data) {
    const escapeInfo = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[char]));
    const rows = [];
    const bankGroups = [];
    const registerData = data && typeof data === 'object' ? data : {};
    const entries = Object.entries(registerData);
    const isLegacyFlat = entries.some(([, value]) => value && typeof value === 'object' && 'name' in value);

    const addBankRows = (bank, registers) => {
        if (!registers || typeof registers !== 'object') return;
        const bankRows = [];
        Object.entries(registers)
            .sort(([addressA],[addressB]) => Number(addressA) - Number(addressB))
            .forEach(([address, info]) => {
                if (!info || typeof info !== 'object' || !info.name) return;
                const row = {
                    bank,
                    address,
                    name: info.name,
                    type: info.type || '',
                    unit: info.unit || '',
                    multiply: info.multiply ?? '',
                    decimal: info.decimal ?? ''
                };
                rows.push(row);
                bankRows.push(row);
            });
        if (bankRows.length) bankGroups.push({ bank, rows: bankRows });
    };

    if (isLegacyFlat) {
        addBankRows('default', registerData);
    } else {
        entries.forEach(([bank, registers]) => addBankRows(bank, registers));
    }

    const data_html = bankGroups.map((group, index) => {
        const functionName = String(group.bank);
        const bankRows = group.rows.map(row => `
            <tr>
                <td>${escapeInfo(row.address)}</td>
                <td><b>${escapeInfo(row.name)}</b></td>
                <td>${escapeInfo(row.type)}</td>
                <td>${escapeInfo(row.unit) || '—'}</td>
                <td>${escapeInfo(row.decimal) || '—'}</td>
                <td>${escapeInfo(row.multiply) || '—'}</td>
            </tr>
        `).join('');

        return `
            <details class="rgl-bank-group" ${index === 0 ? 'open' : ''}>
                <summary>
                    <span class="rgl-function-name">
                        <small>FUNCTION</small>
                        ${escapeInfo(functionName)}
                    </span>
                    <span class="rgl-bank-count">${group.rows.length} registers</span>
                </summary>
                <div class="rgl-bank-table-wrap">
                    <table class="styled-table rgl-info-table">
                        <thead><tr>
                            <th>ADDRESS</th>
                            <th>NAME</th>
                            <th>DATA TYPE</th>
                            <th>UNIT</th>
                            <th>DECIMAL</th>
                            <th>MULTIPLY</th>
                        </tr></thead>
                        <tbody>${bankRows}</tbody>
                    </table>
                </div>
            </details>
        `;
    }).join('');

    Swal.fire({
        title: "<strong>"+model+"</strong>",
        width: 'min(1050px, 96vw)',
        html: `
            <div class="rgl-info-summary">
                <span>MODEL</span>
                <b>${escapeInfo(model)}</b>
                <em>${rows.length} registers</em>
            </div>
            <div class="rgl-info-wrap">
                ${data_html || '<div class="rgl-info-empty">ไม่พบ Register สำหรับ Model นี้ใน rgl.json</div>'}
            </div>
        `,
        showCloseButton: true,
        showCancelButton: false,
        focusConfirm: false,
        confirmButtonText: `
            OK
        `,
        cancelButtonAriaLabel: "Thumbs down"
    });
    
}

function add_device(meterSN) {

    (async () => {
        let select_option="";
        select_option += '<div>Model:<select class="form-select" id="model_">';
        <?php foreach($rgl_arr as $k=>$v){?>
            select_option += '<option value="<?php echo $k;?>" ><?php echo $k;?></option>';
        <?php } ?>
        select_option+='</select></div>'

        let input_mode = "";
        input_mode += '<div id="add-mb-rtu" style="display:none;">RTU';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Baurdrate :</p><input type="text" class="form-control" id="baurdrate_" value=""></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Parity :</p>';
        input_mode += '<select class="form-select" id="parity_"><option value="">-</option><option value="none">none</option><option value="even">even</option></select></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        input_mode += '<div id="add-mb-bacnet" style="display:none;">BACNET';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">BACnet/IP Address :</p><input type="text" class="form-control" id="bacnet_ipaddress_" value=""></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        input_mode += '<div id="add-mb-tcp" style="display:none;">TCP';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">IP Address :</p><input type="text" class="form-control" id="ipaddress_" value=""></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Port :</p><input type="text" class="form-control" id="port_" value="502"></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        const { value: formValues } = await Swal.fire({
        title: 'Config Meter',
        background: "#fff ",
        html:
            '<div>Serial number:<input type="text" class="form-control" id="sn_" value=""></div>'+
            '<div>Name:<input type="text" class="form-control" id="name_" value=""></div>'+
            '<div>Device ID:<input type="text" class="form-control" id="deviceID_" value="1"></div>'+
            select_option+
            '<div>Mode:<select class="form-select" id="mode_" onchange="add_selectMode()">'+
            '<option value="" >-</option>'+
            '<option value="rtu" >rtu</option>'+
            '<option value="tcp" >tcp</option>'+
            '<option value="bacnet" >BACNET</option>'+
            '</select></div>'+
            input_mode
            ,
        focusConfirm: true,
        preConfirm: () => {
            let res_arr = new Object();
            res_arr.meter_SN = String(document.getElementById('sn_').value);
            res_arr.name = String(document.getElementById('name_').value);
            res_arr.deviceID = String(document.getElementById('deviceID_').value);
            res_arr.mode = String(document.getElementById('mode_').value);
            res_arr.model = String(document.getElementById('model_').value);
            if(res_arr.mode == "rtu"){
                res_arr.baurdrate = String(document.getElementById('baurdrate_').value);
                res_arr.parity = String(document.getElementById('parity_').value);
            }
            if(res_arr.mode == "tcp"){
                res_arr.ipaddress = String(document.getElementById('ipaddress_').value);
                res_arr.port = String(document.getElementById('port_').value);
            }
            if(res_arr.mode == "bacnet"){
                res_arr.ipaddress = String(document.getElementById('bacnet_ipaddress_').value);
            }
            // console.log(res_arr);
            


            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    console.log(formValues);
                    
                    let timerInterval;
                    
                    $.ajax({  
                        method: 'post',
                        url: "./add_device.php?meter_SN="+formValues.meter_SN,
                        data: JSON.stringify(formValues),
                        success: function(response) {
                            console.log(response);
                            Swal.fire({
                                title: "Auto close alert!",
                                html: "I will close in <b></b> milliseconds.",
                                timer: 1000,
                                timerProgressBar: true,
                                didOpen: () => {
                                    Swal.showLoading();
                                    const timer = Swal.getPopup().querySelector("b");
                                    timerInterval = setInterval(() => {
                                    timer.textContent = `${Swal.getTimerLeft()}`;
                                    }, 100);
                                },
                                willClose: () => {
                                    clearInterval(timerInterval);
                                    location.reload('refresh:1');
                                }
                                }).then((result) => {
                                /* Read more about handling dismissals below */
                                if (result.dismiss === Swal.DismissReason.timer) {
                                    // console.log("I was closed by the timer");
                                }
                            });
                            // setTimeout( function() { location.reload('refresh:1'); }, 200);
                        }
                    });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()
}

function add_cml(meterSN){
    (async () => {
        let select_option="";
        select_option += '<div>Model:<select class="form-select" id="model_">';
        <?php foreach($rgl_arr as $k=>$v){?>
            select_option += '<option value="<?php echo $k;?>" ><?php echo $k;?></option>';
        <?php } ?>
        select_option+='</select></div>'

        let input_mode = "";
        input_mode += '<div id="add-mb-rtu" style="display:none;">RTU';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Baurdrate :</p><input type="text" class="form-control" id="baurdrate_" value=""></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Parity :</p>';
        input_mode += '<select class="form-select" id="parity_"><option value="">-</option><option value="none">none</option><option value="even">even</option></select></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        input_mode += '<div id="add-mb-tcp" style="display:none;">TCP';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">IP Address :</p><input type="text" class="form-control" id="ipaddress_" value=""></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Port :</p><input type="text" class="form-control" id="port_" value=""></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        const { value: formValues } = await Swal.fire({
        title: 'Add Command List',
        background: "#fff ",
        html:
            '<div>Function:<select class="form-select" id="mode_cml" onchange="">'+
            '<option value="read_holding_rtu" >read_holding_rtu</option>'+
            '<option value="read_holding_tcp" >read_holding_tcp</option>'+
            '<option value="recv_holding_rtu" >recv_holding_rtu</option>'+
            '</select></div>'+
            '<div style="display:flex;justify-content:space-between;">'+
            '<div>Start:<input type="text" class="form-control" id="start_cml" value="0" style="text-align:center;"></div>'+
            '<div>End:<input type="text" class="form-control" id="end_cml" value="1" style="text-align:center;"></div>'+
            '</div>'
            ,
        focusConfirm: true,
        preConfirm: () => {
            let val_start = parseInt(document.getElementById('start_cml').value);
            let val_end = parseInt(document.getElementById('end_cml').value);
            let res_arr = new Object();
            res_arr.meter_SN = meterSN;
            res_arr.fn = String(document.getElementById('mode_cml').value);
            res_arr.fn = String(document.getElementById('mode_cml').value);
            res_arr.data = [val_start,val_end];
            console.log(res_arr.fn,'[',val_start,',',val_end,']');
            
            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    console.log(formValues);
                    
                    let timerInterval;
                    
                    $.ajax({  
                        method: 'post',
                        url: "./add_cml.php?meter_SN="+formValues.meter_SN,
                        data: JSON.stringify(formValues),
                        success: function(response) {
                            console.log(response);
                            Swal.fire({
                                title: "Auto close alert!",
                                html: "I will close in <b></b> milliseconds.",
                                timer: 500,
                                timerProgressBar: true,
                                didOpen: () => {
                                    Swal.showLoading();
                                    const timer = Swal.getPopup().querySelector("b");
                                    timerInterval = setInterval(() => {
                                    timer.textContent = `${Swal.getTimerLeft()}`;
                                    }, 100);
                                },
                                willClose: () => {
                                    clearInterval(timerInterval);
                                    location.reload('refresh:1');
                                }
                                }).then((result) => {
                                /* Read more about handling dismissals below */
                                if (result.dismiss === Swal.DismissReason.timer) {
                                    // console.log("I was closed by the timer");
                                }
                            });
                            // setTimeout( function() { location.reload('refresh:1'); }, 200);
                        }
                    });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()
}

function check_edit(data,keys) {
    // Swal.fire(JSON.stringify(data));
    // console.log(JSON.stringify(data));
    let names = data.name;
    let baurdrate = data.baurdrate != undefined ? data.baurdrate:9600;
    let parity = data.parity != undefined ? data.parity:"none";
    let ipaddress = data.ipaddress != undefined ? data.ipaddress:"";
    let port_tcp = data.port != undefined ? data.port:502;
    let fn_mode = data.mode != undefined ? data.mode:"rtu";
    let deviceID = data.deviceID != undefined ? data.deviceID:1;
    let input_mode ="";

    

    // Infer the legacy mode only when the saved device has no explicit mode.
    // BACNET devices also have an IP address and must not be forced to TCP.
    if(!data.mode){
        if(ipaddress){fn_mode="tcp";}
        else if(baurdrate){fn_mode="rtu";}
    }

    let select_option = '<div>Model:<select class="form-select" id="model_'+keys+'">';
    <?php foreach($rgl_arr as $k=>$v){?>
        if(data.model == '<?php echo $k;?>'){
            select_option += '<option value="<?php echo $k;?>" selected><?php echo $k;?></option>';
        }else{
            select_option += '<option value="<?php echo $k;?>" ><?php echo $k;?></option>';
        }
    <?php } ?>
    select_option+='</select></div>'
    let status_display_tcp = "";
    let status_display_rtu = "";
    let select_mode = '<div>Mode:<select class="form-select" id="mode_'+keys+'" onchange="selectMode(\''+keys+'\')">';
    select_mode+='<option value="">-</option>';
    if(fn_mode == 'rtu'){
        select_mode+='<option value="rtu" selected>RTU</option>';
        
    }else{
        select_mode+='<option value="rtu">RTU</option>';
    }
    if(fn_mode == 'tcp'){
        select_mode+='<option value="tcp" selected>TCP</option>';
        
    }else{
        select_mode+='<option value="tcp" >TCP</option>';
    }
    if(fn_mode == 'bacnet'){
        select_mode+='<option value="bacnet" selected>BACNET</option>';
    }else{
        select_mode+='<option value="bacnet">BACNET</option>';
    }
    select_mode+='</select></div>';
    

        input_mode += '<div id="mb-rtu-'+keys+'" style="display:'+(fn_mode === 'rtu' ? '' : 'none')+';">RTU';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Baurdrate :</p><input type="text" class="form-control" id="baurdrate_'+keys+'" value="'+baurdrate+'"></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Parity :</p>';
        input_mode += '<select class="form-select" id="parity_'+keys+'"><option value="">-</option>';
        if(parity == 'none'){
            input_mode += '<option value="none" selected>none</option>';
            input_mode += '<option value="even">even</option>';
        }
        if(parity == 'even'){
            input_mode += '<option value="none" >none</option>';
            input_mode += '<option value="even" selected>even</option>';
        }
        input_mode += '</select>';
        input_mode += '</div>';
        input_mode += '</div>';

        input_mode += '<div id="mb-bacnet-'+keys+'" style="display:'+(fn_mode === 'bacnet' ? '' : 'none')+';">BACNET';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">BACnet/IP Address :</p><input type="text" class="form-control" id="bacnet_ipaddress_'+keys+'" value="'+ipaddress+'"></div>';
        input_mode += '</div>';
        input_mode += '</div>';

        input_mode += '<div id="mb-tcp-'+keys+'" style="display:'+(fn_mode === 'tcp' ? '' : 'none')+';">TCP';
        input_mode += '<div style="display:flex;">';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">IP Address :</p><input type="text" class="form-control" id="ipaddress_'+keys+'" value="'+ipaddress+'"></div>';
        input_mode += '<div style="display:flex;"><p style="text-wrap:nowrap;">Port :</p><input type="text" class="form-control" id="port_'+keys+'" value="'+port_tcp+'"></div>';
        input_mode += '</div>';
        input_mode += '</div>';

    // console.log(data,keys);
    (async () => {
        const { value: formValues } = await Swal.fire({
        title: 'Config Meter',
        background: "#fff ",
        html:
            '<div>Serial number:<input type="text" class="form-control" id="sn_'+keys+'" value="'+keys+'"></div>'+
            '<div>Name:<input type="text" class="form-control" id="name_'+keys+'" value="'+names+'"></div>'+
            select_option+
            select_mode+
            input_mode+
            '<div>Device ID:<input type="text" class="form-control" id="deviceID_'+keys+'" value="'+deviceID+'"></div>'
            ,
        focusConfirm: true,
        preConfirm: () => {
            // et update_all = {name:String(m_name),devtype:String(m_modeltype),mainconSN:String(m_mainConfig),modelID:String(m_modelID),comtype:String(m_comtype),ipaddress:String(m_ipaddress),maddress:String(m_maddress),mainconID:parseInt(m_mainconID),company_id:parseInt(m_companyID),priority:parseInt(m_priority),baudrate:String(m_baudrate),deleted:0,deviceStatus:'N'};
            // serialnumber:String(devSN),
            let res_arr = new Object();
            res_arr.meter_SN = String(document.getElementById('sn_'+keys).value);
            res_arr.name = String(document.getElementById('name_'+keys).value);
            res_arr.model = String(document.getElementById('model_'+keys).value);
            res_arr.mode = String(document.getElementById('mode_'+keys).value);
            if(res_arr.mode == "rtu"){
                res_arr.baurdrate = String(document.getElementById('baurdrate_'+keys).value);
                res_arr.parity = String(document.getElementById('parity_'+keys).value);
            }
            if(res_arr.mode == "tcp"){
                res_arr.ipaddress = String(document.getElementById('ipaddress_'+keys).value);
                res_arr.port = String(document.getElementById('port_'+keys).value);
            }
            if(res_arr.mode == "bacnet"){
                res_arr.ipaddress = String(document.getElementById('bacnet_ipaddress_'+keys).value);
            }
            res_arr.deviceID = String(document.getElementById('deviceID_'+keys).value);
            // return {meter_SN:String(document.getElementById('sn_'+keys).value),name:String(document.getElementById('name_'+keys).value),model:String(document.getElementById('model_'+keys).value),mode:String(document.getElementById('mode_'+keys).value),deleted:0}
            // console.log(res_arr);
            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    // console.log(formValues);
                    
                    let timerInterval;
                    
                    $.ajax({  
                        method: 'post',
                        url: "./add_device.php?meter_SN="+keys,
                        data: JSON.stringify(formValues),
                        success: function(response) {
                            console.log(response);
                            Swal.fire({
                                title: "Auto close alert!",
                                html: "I will close in <b></b> milliseconds.",
                                timer: 1000,
                                timerProgressBar: true,
                                didOpen: () => {
                                    Swal.showLoading();
                                    const timer = Swal.getPopup().querySelector("b");
                                    timerInterval = setInterval(() => {
                                    timer.textContent = `${Swal.getTimerLeft()}`;
                                    }, 100);
                                },
                                willClose: () => {
                                    clearInterval(timerInterval);
                                    location.reload('refresh:1');
                                }
                                }).then((result) => {
                                /* Read more about handling dismissals below */
                                if (result.dismiss === Swal.DismissReason.timer) {
                                    // console.log("I was closed by the timer");
                                }
                            });
                            // setTimeout( function() { location.reload('refresh:1'); }, 200);
                        }
                    });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()

    
    
}

function updateFile(keys) {
    // console.log(keys);
    // $.ajax({  
    //     type: 'GET',
    //     url: "./del_device.php",
    //     data: 'meter_SN='+keys,
    //     success: function(response) {
    //         console.log(response);
    //         setTimeout( function() { location.reload('refresh:1'); }, 200);
    //     }
    // });
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./del_device.php",
                data: 'meter_SN='+keys,
                success: function(response) {
                    console.log(response);
                    swalWithBootstrapButtons.fire({
                        title: "Deleted!",
                        text: "Your file has been deleted.",
                        icon: "success",
                        timer: 500,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                                timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 1000);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            location.reload('refresh:1');
                        }

                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            icon: "error"
            });
        }
    });
}

function select_model(){
    let model_name = $('#selectModel').val();
    console.log(model_name);
    window.location.href='index.php?page=device&pg=model&modelName='+model_name;
}

async function updateModelClassification(type) {
    const model = <?php echo json_encode($modelName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (!model) return;
    const response = await fetch('./fn_api.php?action=detail_set&model='+encodeURIComponent(model)+'&type='+encodeURIComponent(type));
    const result = await response.json();
    if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Unable to update Model Type');
    return result;
}

async function saveModelClassification() {
    const type = document.getElementById('modelClassificationType')?.value || '';
    if (!type) { Swal.fire({icon:'warning', title:'Select a Type first'}); return; }
    try {
        await updateModelClassification(type);
        await Swal.fire({icon:'success', title:'Model Type saved', timer:1000, showConfirmButton:false});
        location.reload();
    } catch (error) { Swal.fire({icon:'error', title:'Save failed', text:error.message}); }
}

async function removeModelClassification() {
    const confirmation = await Swal.fire({title:'Remove Model Type?', text:'The model and its registers will remain unchanged.', icon:'warning', showCancelButton:true, confirmButtonText:'Remove Type', confirmButtonColor:'#d33'});
    if (!confirmation.isConfirmed) return;
    try {
        await updateModelClassification('');
        await Swal.fire({icon:'success', title:'Model Type removed', timer:1000, showConfirmButton:false});
        location.reload();
    } catch (error) { Swal.fire({icon:'error', title:'Remove failed', text:error.message}); }
}

function selectMode(keys){
    
    let mode = $('#mode_'+keys).val() ;
    console.log(keys,mode);
    const rtuPanel = document.getElementById('mb-rtu-'+keys);
    const tcpPanel = document.getElementById('mb-tcp-'+keys);
    const bacnetPanel = document.getElementById('mb-bacnet-'+keys);
    if(rtuPanel) rtuPanel.style.display = mode === 'rtu' ? '' : 'none';
    if(tcpPanel) tcpPanel.style.display = mode === 'tcp' ? '' : 'none';
    if(bacnetPanel) bacnetPanel.style.display = mode === 'bacnet' ? '' : 'none';
    if(mode == 'rtu'){
        // $('.mb-rtu').addStyle("display","");
        // $('.mb-tcp').addStyle("display","none");
        // document.getElementById("mb-tcp").style.setProperty('display', 'none');
        // document.getElementById("mb-rtu").style.setProperty('display', '');

    }
    else if(mode == 'tcp'){

        // $('.mb-rtu').addStyle("display","none");
        // $('.mb-tcp').addStyle("display","");
        // document.getElementById("mb-rtu").style.setProperty('display', 'none');
        // document.getElementById("mb-tcp").style.setProperty('display', '');

    }
    if(mode == ''){

    // document.getElementById("mb-rtu").style.setProperty('display', 'none');
    // document.getElementById("mb-tcp").style.setProperty('display', 'none');

    }
    
}

function add_selectMode(){
    
    let mode = $('#mode_').val();
    document.getElementById("add-mb-bacnet").style.display = mode === 'bacnet' ? '' : 'none';
    if(mode == 'rtu'){
        document.getElementById("add-mb-tcp").style.display = "none";
        document.getElementById("add-mb-rtu").style.display = "";
    }
    if(mode == 'tcp'){
        document.getElementById("add-mb-rtu").style.display = "none";
        document.getElementById("add-mb-tcp").style.display = "";
    }
    if(mode == ''){
        document.getElementById("add-mb-rtu").style.display = "none";
        document.getElementById("add-mb-tcp").style.display = "none";
    }
    
}

function save_cml(meterSN,cmdid,fn){
    let val_start = $('#val-start-'+cmdid).val();
    let val_end = $('#val-end-'+cmdid).val();
    let val_fn = $('#val-fn-'+cmdid).val();
    console.log(cmdid,val_fn,'[',val_start,',',val_end,']');
    let res_arr = new Object();
    res_arr.cmdid = cmdid;
    res_arr.fn = val_fn;
    res_arr.data = [parseInt(val_start),parseInt(val_end)];
    // res_arr[cmdid] = {functions:[val_start,val_end]};
    console.log(meterSN,res_arr);
    

    Swal.fire({
        title: "Do you want to save the changes?",
        showDenyButton: false,
        showCancelButton: true,
        confirmButtonText: "Save",
        denyButtonText: `Don't save`
    }).then((result) => {
        /* Read more about isConfirmed, isDenied below */
        if (result.isConfirmed) {
            let timerInterval;
            let formValues;

            $.ajax({  
                method: 'post',
                url: "./add_cml.php?meter_SN="+meterSN,
                data: JSON.stringify(res_arr),
                success: function(response) {
                    console.log(response);
                    Swal.fire({
                        title: "Auto close alert!",
                        html: "I will close in <b></b> milliseconds.",
                        timer: 1000,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                            timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 100);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            location.reload('refresh:1');
                        }
                        }).then((result) => {
                        /* Read more about handling dismissals below */
                        if (result.dismiss === Swal.DismissReason.timer) {
                            // console.log("I was closed by the timer");
                        }
                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
        } else if (result.isDenied) {
            // Swal.fire("Changes are not saved", "", "info");
        }
    });
}

function del_cml(meterSN,cmdid,fn){
    console.log(meterSN,cmdid,fn);
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./del_cml.php",
                data: 'cmdid='+cmdid,
                success: function(response) {
                    console.log(response);
                    // swalWithBootstrapButtons.fire({
                    //     title: "Deleted!",
                    //     text: "Your file has been deleted.",
                    //     icon: "success",
                    //     timer: 1500,
                    //     timerProgressBar: true,
                    //     didOpen: () => {
                    //         Swal.showLoading();
                    //         const timer = Swal.getPopup().querySelector("b");
                    //         timerInterval = setInterval(() => {
                    //         timer.textContent = `${Swal.getTimerLeft()}`;
                    //         }, 100);
                    //     },
                    //     willClose: () => {
                    //         clearInterval(timerInterval);
                    //         // location.reload('refresh:1');
                    //     }

                    // });
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            icon: "error"
            });
        }
    });
}

function save_rgl(model,register){
    let name = $('#model-name-'+model+'-'+register).val();
    let type = $('#model-type-'+model+'-'+register).val();
    let unit = $('#model-unit-'+model+'-'+register).val();
    let decimal = $('#model-decimal-'+model+'-'+register).val();
    let multiply = $('#model-multiply-'+model+'-'+register).val();
    console.log(model,register,name,type,unit);
    let res_arr = new Object();
    let res_arr2 = new Object();
    res_arr.name = name;
    res_arr.type = type;
    res_arr.unit = unit;
    res_arr.decimal = decimal != undefined ? decimal : 3;
    res_arr.multiply = multiply != undefined ? multiply : 1;
    res_arr2[register] = res_arr;

    Swal.fire({
        title: "Do you want to save the changes?",
        showDenyButton: false,
        showCancelButton: true,
        confirmButtonText: "Save",
        denyButtonText: `Don't save`
    }).then((result) => {
        /* Read more about isConfirmed, isDenied below */
        if (result.isConfirmed) {
            let timerInterval;
            let formValues;

            $.ajax({  
                method: 'post',
                url: "./add_rgl.php?model="+model+'&register='+register,
                data: JSON.stringify(res_arr2),
                success: function(response) {
                    console.log(response);
                    Swal.fire({
                        title: "Auto close alert!",
                        html: "I will close in <b></b> milliseconds.",
                        timer: 1000,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                            timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 100);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            // location.reload('refresh:1');
                        }
                        }).then((result) => {
                        /* Read more about handling dismissals below */
                        if (result.dismiss === Swal.DismissReason.timer) {
                            // console.log("I was closed by the timer");
                        }
                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
        } else if (result.isDenied) {
            // Swal.fire("Changes are not saved", "", "info");
        }
    });

}
function del_rgl(model,register){
    console.log(model,register);
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./del_rgl.php",
                data: 'model='+model+'&register='+register,
                success: function(response) {
                    console.log(response);
                    // swalWithBootstrapButtons.fire({
                    //     title: "Deleted!",
                    //     text: "Your file has been deleted.",
                    //     icon: "success",
                    //     timer: 1500,
                    //     timerProgressBar: true,
                    //     didOpen: () => {
                    //         Swal.showLoading();
                    //         const timer = Swal.getPopup().querySelector("b");
                    //         timerInterval = setInterval(() => {
                    //         timer.textContent = `${Swal.getTimerLeft()}`;
                    //         }, 100);
                    //     },
                    //     willClose: () => {
                    //         clearInterval(timerInterval);
                    //         // location.reload('refresh:1');
                    //     }

                    // });
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
}

function add_rgl(model){
    if(model){
        (async () => {

            const { value: formValues } = await Swal.fire({
            title: 'New Model',
            background: "#fff ",
            html:
                '<div>Name:<input type="text" class="form-control" id="rgl-name" value=""></div>'+
                '<div>Register:<input type="text" class="form-control" id="rgl-register" value=""></div>'+
                '<div>Type:'+
                '<select class="form-select" id="rgl-type">'+
                '<option value="float32">float32</option>'+
                '<option value="float32 inverse">float32 inverse</option>'+
                '<option value="int sign16">int sign16</option>'+
                '<option value="int unsign16">int unsign16</option>'+
                '<option value="int16 binary">int16 binary</option>'+
                '<option value="long unsign32">long unsign32</option>'+
                '<option value="long unsign32 inverse">long unsign32 inverse</option>'+
                '<option value="long sign32">long sign32</option>'+
                '<option value="long unsign64">long unsign64</option>'+
                '<option value="long sign64">long sign64</option>'+
                '</select>'+
                '</div>'+
                '<div>Unit:<input type="text" class="form-control" id="rgl-unit" value=""></div>'+
                '<div>Decimal:<input type="text" class="form-control" id="rgl-decimal" value="3"></div>'+
                '<div>Multiply:<input type="text" class="form-control" id="rgl-multiply" value="1"></div>'
                ,
            focusConfirm: true,
            preConfirm: () => {
                let res_arr = new Object();
                let res_arr2 = new Object();
                let res_arr3 = new Object();
                res_arr.name = String(document.getElementById('rgl-name').value);
                res_arr3.register = String(document.getElementById('rgl-register').value);
                res_arr.type = String(document.getElementById('rgl-type').value);
                res_arr.unit = String(document.getElementById('rgl-unit').value);
                res_arr.decimal = String(document.getElementById('rgl-decimal').value);
                res_arr.multiply = String(document.getElementById('rgl-multiply').value);
                // console.log(res_arr);
                res_arr2[res_arr3.register] = res_arr;


                return res_arr2;
            }
            })

            if(formValues){
                // Swal.fire(JSON.stringify(formValues))
                Swal.fire({
                    title: "Json{}",
                    width: 600,
                    text:JSON.stringify(formValues),
                    padding: "3em",
                    color: "#716add",
                    background: "#fff url(../asset/img/trees.png)",
                    backdrop: `
                        rgba(0,0,123,0.4)
                        url("../asset/img/nyan-cat.gif")
                        left top
                        no-repeat
                    `,
                    showDenyButton: true,
                    // showCancelButton: true,
                    confirmButtonText: 'Yes',
                    denyButtonText: 'No',
                    customClass: {
                        actions: 'my-actions',
                        cancelButton: 'order-1 right-gap',
                        confirmButton: 'order-2',
                        denyButton: 'order-3',
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // statement ajax save to DB
                        // Swal.fire('Saved!', '', 'success')
                        console.log(formValues);
                        // let urlkwh = new window.URL(document.location); 
                        // urlkwh.searchParams.set("", date_kwh);
                        // location.replace(urlkwh);
                        // location.replace("./index.php?page=device&pg=model&modelName="+formValues.model_Nname);

                        // let timerInterval;
                        
                        $.ajax({  
                            method: 'post',
                            url: "./add_rgl.php?model="+model+'&register='+Object.keys(formValues),
                            data: JSON.stringify(formValues),
                            success: function(response) {
                                console.log(response);
                                Swal.fire({
                                    title: "Auto close alert!",
                                    html: "I will close in <b></b> milliseconds.",
                                    timer: 500,
                                    timerProgressBar: true,
                                    didOpen: () => {
                                        Swal.showLoading();
                                        const timer = Swal.getPopup().querySelector("b");
                                        timerInterval = setInterval(() => {
                                        timer.textContent = `${Swal.getTimerLeft()}`;
                                        }, 100);
                                    },
                                    willClose: () => {
                                        clearInterval(timerInterval);
                                        location.reload('refresh:1');
                                    }
                                    }).then((result) => {
                                    /* Read more about handling dismissals below */
                                    if (result.dismiss === Swal.DismissReason.timer) {
                                        // console.log("I was closed by the timer");
                                    }
                                });
                                // setTimeout( function() { location.reload('refresh:1'); }, 200);
                            }
                        });

                        
                    } else if (result.isDenied) {
                        // Swal.fire('Changes are not saved', '', 'info')
                    }
                });
            }
        })()
    }else{
        alert("model is not found!!")
    }
}

function add_model(){
    (async () => {

        const { value: formValues } = await Swal.fire({
        title: 'New Model',
        background: "#fff ",
        html:
            '<div>Model Name:<input type="text" class="form-control" id="model-name" value=""></div>'
            ,
        focusConfirm: true,
        preConfirm: () => {
            let res_arr = new Object();
            res_arr.model_Nname = String(document.getElementById('model-name').value);
            // console.log(res_arr);
            


            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    console.log(formValues);
                    let urlkwh = new window.URL(document.location); 
                    // urlkwh.searchParams.set("", date_kwh);
                    // location.replace(urlkwh);
                    location.replace("./index.php?page=device&pg=model&modelName="+formValues.model_Nname);

                    // let timerInterval;
                    
                    // $.ajax({  
                    //     method: 'post',
                    //     url: "./add_rgl.php?model="+formValues.meter_SN,
                    //     data: JSON.stringify(formValues),
                    //     success: function(response) {
                    //         console.log(response);
                    //         Swal.fire({
                    //             title: "Auto close alert!",
                    //             html: "I will close in <b></b> milliseconds.",
                    //             timer: 1000,
                    //             timerProgressBar: true,
                    //             didOpen: () => {
                    //                 Swal.showLoading();
                    //                 const timer = Swal.getPopup().querySelector("b");
                    //                 timerInterval = setInterval(() => {
                    //                 timer.textContent = `${Swal.getTimerLeft()}`;
                    //                 }, 100);
                    //             },
                    //             willClose: () => {
                    //                 clearInterval(timerInterval);
                    //                 location.reload('refresh:1');
                    //             }
                    //             }).then((result) => {
                    //             /* Read more about handling dismissals below */
                    //             if (result.dismiss === Swal.DismissReason.timer) {
                    //                 // console.log("I was closed by the timer");
                    //             }
                    //         });
                    //         // setTimeout( function() { location.reload('refresh:1'); }, 200);
                    //     }
                    // });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()
}

function del_model(model){
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
            title: "Are you sure?",
            text: "You won't be able to revert this!",
            icon: "warning",
            showCancelButton: true,
            cancelButtonText: "No, cancel!",
            confirmButtonText: "Yes, delete it!",
            reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./del_rgl.php",
                data: 'model='+model,
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            // location.reload('refresh:1');
                            window.location.href='index.php?page=device&pg=model&modelName=';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
                title: "Cancelled",
                text: "Your imaginary file is safe :)",
                showConfirmButton: false,
                timer: 500,
                icon: "error"
            });
        }
    });
}

function import_m1(fname) {
    // console.log(fname);
    Swal.fire({
        title: 'Upload JSON Files',
        html: '<input type="file" id="fileInput" class="form-control" multiple accept=".json">',
        showCancelButton: true,
        confirmButtonText: 'Upload',
        preConfirm: () => {
            const files = document.getElementById('fileInput').files;
            if (files.length === 0) {
            Swal.showValidationMessage('Please select at least one JSON file.');
            return false;
            }

            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
            }

            return fetch('upload.php', {
            method: 'POST',
            body: formData
            })
            .then(res => res.json())
            .catch(err => {
            Swal.showValidationMessage(`Upload error: ${err}`);
            });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Swal.fire('Success!', 'Files uploaded/merged successfully.', 'success');
            Swal.fire({
                title: "Success!",
                text: "Your action was completed successfully.",
                icon: "success",
                confirmButtonText: "OK"
            }).then((result) => {
                if (result.isConfirmed) {
                    location.reload(); // Reloads the current page
                }
            });
        }
    });


    

}

function export_m1(fname) {
    console.log('==========');
    
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
    });
    swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "Export this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No",
        confirmButtonText: "Yes",
        reverseButtons: false
    }).then((result) => {
    if (result.isConfirmed) {
        // window.location.href = 'mx_export.php';
        Swal.fire({
            title: "Auto close alert!",
            html: "I will close in <b></b> milliseconds.",
            timer: 1000,
            timerProgressBar: true,
            didOpen: () => {
                Swal.showLoading();
                const timer = Swal.getPopup().querySelector("b");
                timerInterval = setInterval(() => {
                timer.textContent = `${Swal.getTimerLeft()}`;
                }, 100);
            },
            willClose: () => {
                clearInterval(timerInterval);
                location.reload('refresh:1');
            }
            }).then((result) => {
            /* Read more about handling dismissals below */
            if (result.dismiss === Swal.DismissReason.timer) {
                // console.log("I was closed by the timer");
                window.location.href = 'mx_export.php';
            }
        });
    } else if (
        /* Read more about handling dismissals below */
        result.dismiss === Swal.DismissReason.cancel
    ) {
        swalWithBootstrapButtons.fire({
        title: "Cancelled",
        text: "Your imaginary file is safe :)",
        showConfirmButton: false,
        timer: 500,
        icon: "error"
        });
    }
});

    
}

// function update_dvl_2server(fname){
//     console.log(fname);
    
//     let timerInterval;
//     const swalWithBootstrapButtons = Swal.mixin({
//         customClass: {
//             confirmButton: "btn btn-success swal-confirm",
//             cancelButton: "btn btn-danger swal-cancel"
//         },
//         buttonsStyling: false
//         });
//         swalWithBootstrapButtons.fire({
//         title: "Are you sure?",
//         text: "You won't be upload this!",
//         icon: "warning",
//         showCancelButton: true,
//         cancelButtonText: "No",
//         confirmButtonText: "Yes",
//         reverseButtons: false
//         }).then((result) => {
//         if (result.isConfirmed) {
//             $.ajax({  
//                 type: 'POST',
//                 url: "./mx_update_dvl.php",
//                 data: '',
//                 success: function(response) {
//                     console.log(response);
                    
//                     Swal.fire({
//                         position: "center",
//                         icon: "success",
//                         title: "Your work has been saved",
//                         showConfirmButton: false,
//                         timer: 1500,
//                         willClose: () => {
//                             // location.reload('refresh:1');
//                             // window.location.href='index.php?page=device&pg=model&modelName=';
//                         }
//                     });

//                     // setTimeout( function() { location.reload('refresh:1'); }, 200);
//                 }
//             });
            
//         } else if (
//             /* Read more about handling dismissals below */
//             result.dismiss === Swal.DismissReason.cancel
//         ) {
//             swalWithBootstrapButtons.fire({
//             title: "Cancelled",
//             text: "Your imaginary file is safe :)",
//             showConfirmButton: false,
//             timer: 500,
//             icon: "error"
//             });
//         }
//     });
    
// }

function update2server(fname){
    console.log(fname);
    
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be upload this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No",
        confirmButtonText: "Yes",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'POST',
                url: "./mx_update_config2server.php",
                data: '',
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            // location.reload('refresh:1');
                            // window.location.href='index.php?page=device&pg=model&modelName=';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
    
}

function syncModel(fname){
    console.log(fname);
    
    // let timerInterval;
    // const swalWithBootstrapButtons = Swal.mixin({
    //     customClass: {
    //         confirmButton: "btn btn-success swal-confirm",
    //         cancelButton: "btn btn-danger swal-cancel"
    //     },
    //     buttonsStyling: false
    //     });
    //     swalWithBootstrapButtons.fire({
    //     title: "Are you sure?",
    //     text: "You won't be upload this!",
    //     icon: "warning",
    //     showCancelButton: true,
    //     cancelButtonText: "No",
    //     confirmButtonText: "Yes",
    //     reverseButtons: false
    //     }).then((result) => {
    //     if (result.isConfirmed) {
    //         $.ajax({  
    //             type: 'POST',
    //             url: "./mx_update_config2server.php",
    //             data: '',
    //             success: function(response) {
    //                 console.log(response);
                    
    //                 Swal.fire({
    //                     position: "center",
    //                     icon: "success",
    //                     title: "Your work has been saved",
    //                     showConfirmButton: false,
    //                     timer: 1500,
    //                     willClose: () => {
    //                         // location.reload('refresh:1');
    //                         // window.location.href='index.php?page=device&pg=model&modelName=';
    //                     }
    //                 });

    //                 // setTimeout( function() { location.reload('refresh:1'); }, 200);
    //             }
    //         });
            
    //     } else if (
    //         /* Read more about handling dismissals below */
    //         result.dismiss === Swal.DismissReason.cancel
    //     ) {
    //         swalWithBootstrapButtons.fire({
    //         title: "Cancelled",
    //         text: "Your imaginary file is safe :)",
    //         showConfirmButton: false,
    //         timer: 500,
    //         icon: "error"
    //         });
    //     }
    // });
    
}

function clear_cml(){
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./clear_cml.php",
                data: '',
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                            // window.location.href='index.php?page=device&pg=model&modelName=';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
}

function myFunction_search() {
  var input, filter, table, tr, td, i, txtValue;
  input = document.getElementById("myInput");
  filter = input.value.toUpperCase();
  table = document.getElementById("customers");
  tr = table.getElementsByTagName("tr");
  for (i = 0; i < tr.length; i++) {
    // console.log(tr[i].getElementsByTagName("td").length);
    try {
        td = tr[i].getElementsByTagName("td")[0];
        td1 = tr[i].getElementsByTagName("td")[1];
        td2 = tr[i].getElementsByTagName("td")[2];
        td3 = tr[i].getElementsByTagName("td")[3];
        td4 = tr[i].getElementsByTagName("td")[4];
        console.log();
        

        if (td && td1 && td2) {
        txtValue = td.textContent || td.innerText;
        txtValue1 = td1.textContent || td1.innerText;
        txtValue2 = td2.textContent || td2.innerText;
        txtValue3 = td3.textContent || td3.innerText;
        txtValue4 = td4.textContent || td4.innerText;
        if ((txtValue.toUpperCase().indexOf(filter) > -1) || (txtValue1.toUpperCase().indexOf(filter) > -1) || (txtValue2.toUpperCase().indexOf(filter) > -1) || (txtValue3.toUpperCase().indexOf(filter) > -1) || (txtValue4.toUpperCase().indexOf(filter) > -1)) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
        }
    }
    catch(err) {
        // document.getElementById("demo").innerHTML = err.message;
        console.log("err:",err);
        
    }

    // td1 = tr[i].getElementsByTagName("td")[1];
    // if (td1) {
    //   txtValue = td1.textContent || td1.innerText;
    //   if (txtValue.toUpperCase().indexOf(filter) > -1) {
    //     tr[i].style.display = "";
    //   } else {
    //     tr[i].style.display = "none";
    //   }
    // }   
  }
}

// $( document ).ready(function() {
//     console.log( "ready!" );
// });
</script>

<style>
#customers {
  font-family: Arial, Helvetica, sans-serif;
  border-collapse: collapse;
  width: 100%;
  border-top-left-radius: 10px;
}

#customers td, #customers th {
  /* border: 1px solid #ddd; */
  padding: 8px;
  text-align: center;
}
#customers{
  overflow:scroll;
}

#customers tr:nth-child(odd){background-color: #f2f2f2;}

#customers tr:hover {background-color: #ddd;}

#customers th {
  padding-top: 12px;
  padding-bottom: 12px;
  text-align: center;
  background-color: #ced4da;
  color: white;
}

.swal-confirm {
    background-color: #28a745 !important; /* Green */
    color: white !important;
    padding: 10px 20px !important;
    margin: 10px !important; /* Adds space around the button */
}

.swal-cancel {
    background-color: #dc3545 !important; /* Red */
    color: white !important;
    padding: 10px 20px !important;
    margin: 10px !important;
}
#customers tr:hover input, #customers tr:hover select {
  background-color: white;
}
.form-control:focus {
  background-color: white;   /* background when clicked/focused */
  outline: none;             /* optional: remove blue outline */
}
.form-select:focus {
  background-color: white;   /* background when clicked/focused */
  outline: none;             /* optional: remove blue outline */
}

m_breadcrumbs{
    cursor:pointer;
    font-weight:500;
    font-size:16px;
}
m_breadcrumbs:hover {
  /* font-weight:700; */
  color:rgb(0, 23, 231) ;
}



.styled-table {
  border-collapse: collapse;
  margin: 20px 0;
  font-size: 16px;
  font-family: sans-serif;
  width: 100%;
  min-width: 400px;
  border: 1px solid #ccc;
}

.styled-table thead {
  background-color: #009879;
  color: white;
  text-align: left;
}


.styled-table th,
.styled-table td {
  padding: 12px 15px;
  border: 1px solid #ddd;
}

.styled-table tbody tr:nth-child(even) {
  background-color: #f3f3f3;
}

.styled-table tbody tr:hover {
  background-color: #f1f1f1;
}

.rgl-info-summary{display:flex;align-items:center;gap:8px;text-align:left;color:#65788c;font-size:.78rem;margin:4px 0 8px}.rgl-info-summary>span{font-size:10px;font-weight:700;letter-spacing:.05em}.rgl-info-summary>b{color:#173b67;font-size:13px}.rgl-info-summary>em{margin-left:auto;font-style:normal}.rgl-info-wrap{max-height:62vh;overflow:auto;border:1px solid #dfe6ee;border-radius:10px;background:#f7f9fc;padding:8px}.rgl-bank-group{overflow:hidden;margin-bottom:8px;border:1px solid #d9e3ef;border-radius:9px;background:#fff}.rgl-bank-group:last-child{margin-bottom:0}.rgl-bank-group summary{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;cursor:pointer;color:#1559a7;font-size:13px;font-weight:700;background:#edf5ff;user-select:none}.rgl-bank-group summary:hover{background:#e3f0ff}.rgl-function-name{display:flex;align-items:center;gap:8px}.rgl-function-name small{padding:2px 6px;border-radius:4px;background:#1769d2;color:#fff;font-size:9px;letter-spacing:.04em}.rgl-bank-count{padding:2px 8px;border-radius:12px;background:#fff;color:#65788c;font-size:10px;font-weight:600}.rgl-bank-table-wrap{overflow-x:auto}.rgl-info-table{margin:0;font-size:12px;min-width:680px}.rgl-info-table thead{position:sticky;top:0;z-index:2;background:#1769d2}.rgl-info-table th,.rgl-info-table td{padding:8px 10px;text-align:left}.rgl-info-table td:first-child{font-variant-numeric:tabular-nums;font-weight:700;color:#1559a7}.rgl-info-empty{padding:25px;color:#7a8795;text-align:center}


/* hide scroll bar */
.hide-scrollbar {
  overflow: auto;            /* or scroll */
  scrollbar-width: none;     /* Firefox */
  -ms-overflow-style: none;  /* IE and Edge */
}

.hide-scrollbar::-webkit-scrollbar {
  display: none;             /* Chrome, Safari, Opera */
}

.hide-scrollbar {
  overflow: auto;            /* or scroll */
  scrollbar-width: none;     /* Firefox */
  -ms-overflow-style: none;  /* IE and Edge */
}

.hide-scrollbar::-webkit-scrollbar {
  display: none;             /* Chrome, Safari, Opera */
}
</style>
