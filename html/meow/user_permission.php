<?php
function mdb_user_permissions($username=null){$role=strtolower((string)($_SESSION['user_type']??''));if($role==='superadmin'||$role==='admin')return['displays'=>['*'],'functions'=>['*']];$username=$username??(string)($_SESSION['username']??'');$all=json_decode(@file_get_contents(__DIR__.'/config/user_permissions.json'),true);return is_array($all)&&isset($all[$username])?$all[$username]:['displays'=>['dashboard'],'functions'=>['report']];}
function mdb_can_display($page){$p=mdb_user_permissions();return in_array('*',$p['displays']??[],true)||in_array($page,$p['displays']??[],true);}
function mdb_can_function($name){$p=mdb_user_permissions();return in_array('*',$p['functions']??[],true)||in_array($name,$p['functions']??[],true);}
