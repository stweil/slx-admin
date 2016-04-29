<?php
// print results, insert id or affected row count
session_start();

if(!isset($_POST['request'])){
    echo json_encode(array(
        "errormsg"=>"Request not set, finishing session",
        "status" => "error",
        "msg" => ""));
    session_unset();
    session_destroy();
}else if($_POST['request']=='logout'){
    echo json_encode(array(
        "errormsg"=> "",
        "status" => "ok",
        "msg" => "Logout successful"));
    session_unset();
    session_destroy();

}else {
    $target_dir = "tmpUploads/";
    $requests = array("login","getinfo","upload","newupload");
    if( in_array($_POST['request'],$requests ))
        require("webservice/".$_POST['request'].".php");
    else{
        echo json_encode(array(
            "errormsg"=> "Request don't exist, finishing session",
            "status" => "error",
            "msg" => ""));
        session_unset();
        session_destroy();
    }
}
//TODO: analyze session unset/destroy
