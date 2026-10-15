<?php

//	CONFIGURE account for reporter
require('configure.php');

$report = "SuperScan Daily Report\r\n\r\n";

//	Prepare scan report
$yesterday = date("Y-m-d H:i:s", mktime(date('H'), date('i'), date('s'), date('n'), date('j')-1,date('Y')));

$report .= "SuperScan log report for $acct file changes since ".$yesterday.":\r\n\r\n";

// 	use define statements or enter values directly in the mysqli_connect
include('scandb.php');

//	$scandb = mysqli_connect(SERVER,USER,PASS,DATABASE);

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

// OUTPUT Report
if ($email_out)
{
	$to = (count($addresses)>1) ? implode(", ", $addresses): $addresses[0];
	$mailed = mail($to, $acct . ' Integrity Monitor Log Report',$report); 
}

// To TEST this script, activate the following line on
if ($report_out) echo nl2br($report);

mysqli_close($scandb);
?>
