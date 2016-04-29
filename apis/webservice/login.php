<?php
$login = $_POST['login'];
$sql = "select * from `user` WHERE login= :login";
$user=Database::queryFirst($sql, array("login"=> $login));
if($user){
    if(Crypto::verify($_POST['passwd'],$user['passwd'])){
        $_SESSION['userid']=$user['userid'];
        echo json_encode(array(
            "errormsg"=> "",
            "status" => "ok",
            "msg" => "Login successful"));
    }else{
        echo json_encode(array(
            "errormsg"=> "Wrong passwd",
            "status" => "error",
            "msg" => ""));
    }
}else{
    echo json_encode(array(
        "errormsg"=> "User not found",
        "status" => "error",
        "msg" => ""));
}
