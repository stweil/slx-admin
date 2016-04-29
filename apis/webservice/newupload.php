<?php
if(!isset($_SESSION['userid'])){
    echo json_encode(array(
        "errormsg"=>"Not logged in",
        "status" => "error",
        "msg" => ""));
    die();
}
if(!isset($_POST['nparts'])){
    echo json_encode(array(
        "errormsg"=>"Number of parts isn't set",
        "status" => "error",
        "msg" => ""));
    die();
}

function crypto_rand_secure($min, $max){
    $range = $max - $min;
    if ($range < 1) return $min; // not so random...
    $log = ceil(log($range, 2));
    $bytes = (int) ($log / 8) + 1; // length in bytes
    $bits = (int) $log + 1; // length in bits
    $filter = (int) (1 << $bits) - 1; // set all lower bits to 1
    do {
        $rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
        $rnd = $rnd & $filter; // discard irrelevant bits
    } while ($rnd >= $range);
    return $min + $rnd;
}

function getToken($length){
    $token = "";
    $codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
    $codeAlphabet.= "0123456789";
    $max = strlen($codeAlphabet) - 1;
    for ($i=0; $i < $length; $i++) {
        $token .= $codeAlphabet[crypto_rand_secure(0, $max)];
    }
    return $token;
}
$token=getToken(35);
while(Database::queryFirst("select * from upload where `token`=:token", array(
        "token" => $token))){
    $token = getToken(35);
}
$okay=Database::exec("INSERT INTO upload(`userid`, `nparts`, `nremaining`, `token`)".
   " values (:userid, :nparts, :nremaining, :token)", array(
        "userid"=>$_SESSION['userid'],
        "nparts"=>$_POST['nparts'],
        "nremaining"=>$_POST['nparts'],
        "token"=> $token
    ));
if($okay){
    echo json_encode(array(
        "uploadid"=>$token,
        "errormsg"=>"",
        "status" => "ok",
        "msg" => "New upload succesful"));
    mkdir($target_dir.$token."/",0755, true);
}else{
    echo json_encode(array(
        "errormsg"=>"Error when saving new upload, please retry",
        "status" => "error",
        "msg" => ""));
}

