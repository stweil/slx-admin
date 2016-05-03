<?php

class Page_Support extends Page
{

	protected function doPreprocess(){
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
        }
        error_reporting(E_ALL);
        ini_set('display_errors','on');

        Session::get('token');


        //THIS IS NOT WORKING
        //Cant connect to ANY smtp server
        /*
        if (strpos($_SERVER['REQUEST_URI'], "action=send") !== false){
            require '/var/www/slx-admin/phpmailer/PHPMailerAutoload.php';

            $mail = new PHPMailer;
            $mail->SMTPDebug = 3;                               // Enable verbose debug output         
            $mail->isSMTP();                                      // Set mailer to use SMTP
            $mail->Host = 'mx.c3sl.ufpr.br';  // Specify main and backup SMTP servers
            $mail->SMTPAuth = true;                               // Enable SMTP authentication
            $mail->Username = 'xxx00@inf.ufpr.br';                 // SMTP username
            $mail->Password = '';                           // SMTP password
         // $mail->SMTPSecure = 'false';                            // Enable TLS encryption, `ssl` also accepted
            $mail->Port = 25;                                    // TCP port to connect to
            
            $mail->From = 'xxx00@inf.ufpr.br';
            $mail->FromName = 'Someone';
            $mail->addAddress('receiver@email.com', 'Another One');     // Add a recipient        
           // $mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
           // $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = 'Here is the subject';
            $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
            $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';
            
            if(!$mail->send()) {
                echo 'Message could not be sent.';
                echo 'Mailer Error: ' . $mail->ErrorInfo;
            } else {
                echo 'Message has been sent';
            } 
            */
//            $uploaddir = '/var/www/uploads/';
//            $uploadfile = $uploaddir . basename($_FILES['inp_file']['name']);
//            if(move_uploaded_file($_FILES['inp_file']['tmp_name'], $uploadfile))
//                Message::addSuccess('news-save-success');
//            else
//                Message::addError('news-empty');
//            mail($to,$_POST[assuntoEmail],$_POST[conteudoEmail],"-r".$from);
//            mail($to,$assunto,$content,$headers); 
         
   
        }
        


    protected function doRender(){
        error_reporting(E_ALL);
        ini_set('display_errors','on');
		if (strpos($_SERVER['REQUEST_URI'], "true") !== false){
            Render::addTemplate('page-faq',
                json_decode(file_get_contents("modules/support/faq.json"),true)
            );
        }
        else{
            Render::addTemplate('page-support',
                json_decode(file_get_contents("modules/support/faq.json"),true)
            );
        }
//        Render::addTemplate('page-support', array(
//            'token' => Session:get('token'));
	}


}
