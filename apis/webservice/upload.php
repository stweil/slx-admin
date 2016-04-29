<?php
if(!isset($_POST['uploadid'])){
    echo json_encode(array(
        "errormsg"=>"Not logged in",
        "status" => "error",
        "msg" => ""));
    die();
}elseif (!isset($_FILES['fileToUpload'])){
    echo json_encode(array(
        "errormsg"=>"No file received",
        "status" => "error",
        "msg" => ""));
    die();
}

$upload = Database::queryFirst("Select * from upload where token = :token",
             array( "token" => $_POST['uploadid']));
if($upload['userid']!= $_SESSION['userid']){
    echo json_encode(array(
        "errormsg"=>"Not same owner",
        "status" => "error",
        "msg" => ""));
    die();
}

$name = $_FILES["fileToUpload"]["name"];
$upload['nremaining'] = $upload['nremaining'] - 1;
if ($upload['nremaining'] < 0){
        echo json_encode(array(
            "errormsg"=>"Already received all the parts",
            "status" => "error",
            "msg" => ""));
        die();
}
$target_file = $target_dir.$_POST['uploadid']."/".$name;
if(move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)){
    $ret = Database::exec("UPDATE upload SET nremaining= :nremaining".
        " WHERE id=:id", array(
            "id"=>$upload['id'],
            "nremaining"=>$upload['nremaining']
    ));
    if ($upload['nremaining'] == 0) {
        echo json_encode(array(
            "errormsg"=>"",
            "status" => "ok",
            "msg" => "Upload successful, sending to taskmanager"));
        //passa pro taskmanager;
        die();
    }else{
        echo json_encode(array(
            "errormsg"=>"",
            "status" => "ok",
            "msg" => "Upload successful, waiting next part"));
        die();
    }
} else {
    echo json_encode(array(
        "errormsg"=>"",
        "status" => "error",
        "msg" => "Error on upload, please resend"));
}

