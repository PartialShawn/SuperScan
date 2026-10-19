<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'vendor/PHPMailer/src/Exception.php';
require 'vendor/PHPMailer/src/PHPMailer.php';
require 'vendor/PHPMailer/src/SMTP.php';



// #region Initialize

//	CONFIGURE account for reporter
require('configure.php');

$report = "SuperScan Daily Report\r\n\r\n";

//	Prepare scan report
$yesterday = date("Y-m-d H:i:s", mktime(date('H'), date('i'), date('s'), date('n'), date('j')-1,date('Y')));

$report .= "SuperScan log report for $acct file changes since ".$yesterday.":\r\n\r\n";

// 	use define statements or enter values directly in the mysqli_connect
$scandb = mysqli_connect(SERVER,USER,PASS,DATABASE);

$results = mysqli_query($scandb,"SELECT stamp, status, file_path, file_last_mod FROM history WHERE acct = '$acct' AND stamp > '$yesterday'");
if (!$results)
{
	$report .="No log entries available!\r\n ";
} else {
	while($result=mysqli_fetch_array($results))
	{
		$report .= $result['stamp']." =>  ".strtoupper($result['status'])." =>  ".$result['file_path']."\r\n";
	}
}

// #endregion



// #region OUTPUT Report

if ($email_out)
{
	//Create an instance; passing `true` enables exceptions
	$mail = new PHPMailer(true);

	try {
		//Server settings
		$mail->SMTPDebug = SMTP::DEBUG_SERVER;                      //Enable verbose debug output
		$mail->isSMTP();                                            //Send using SMTP
		$mail->Host       = SMTP_HOST;                     //Set the SMTP server to send through
		$mail->SMTPAuth   = true;                                   //Enable SMTP authentication
		$mail->Username   = SMTP_USERNAME;                     //SMTP username
		$mail->Password   = SMTP_PASSWORD;                               //SMTP password
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            //Enable implicit TLS encryption
		$mail->Port       = 465;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

		//Recipients
		$mail->setFrom(SMTP_FROM, 'Integrity Monitor Log Report');
		foreach(REPORT_ADDRESSES as $email) {
			$mail->addAddress($email);
		}

		//Content
		$mail->isHTML(false);                                  //Set email format to HTML
		$mail->Subject = $acct . ' Integrity Monitor Log Report';
		$mail->Body    = $report;

		$mail->send();
		echo 'Message has been sent';
	} catch (Exception $e) {
		echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
	}

}

// To TEST this script, activate the following line on
if ($report_out) echo nl2br($report);

// #endregion

mysqli_close($scandb);