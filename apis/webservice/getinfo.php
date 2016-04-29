<?php
if(isset($_SESSION['userid'])){
    $sql = "select user.login, user.fullname, user.email, cities.name from"
        ." `user` left join cities on user.city=cities.cityid"
        ." where user.userid= :userid";

    $user=Database::queryFirst($sql, array("userid"=> $_SESSION['userid']));
    $ret = array(
        "login"=>$user['login'],
        "name"=>$user['fullname'],
        "email"=>$user['email'],
        "city"=>$user['name'],
        "errormsg" => "",
        "status" => "ok",
        "msg" => "Get informations of user successful"
    );
    echo json_encode($ret);

}else{
    echo json_encode(array(
        "errormsg"=> "Not logged in",
        "status" => "error",
        "msg" => ""));
}

