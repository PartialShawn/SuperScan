<?php

//	INTRO
//
//	Introductory comments are provided in the ReadMe.txt file
//
//	End of Intro

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'vendor/PHPMailer/src/Exception.php';
require 'vendor/PHPMailer/src/PHPMailer.php';
require 'vendor/PHPMailer/src/SMTP.php';


//	CONFIGURE account for scan
require('configure.php');


/* ********************************* */
// #region INITIALIZE
/* ********************************* */

//	Initialization of scanner variables and tables



//	Clear and title the report variable before starting
$report = "SuperScan File Check for $acct\r\n\r\n";

//	Initialize the baseline array for the baseline table
$baseline = array();

//	Initialize the current array for the current file scan
$current = array();

//	Intitialize the differences arrays 
$added = array();
$altered = array();
$deleted = array();	


//	Connect to database
try {
	$scandb = mysqli_connect(SERVER,USER,PASS,DATABASE);
} catch (mysqli_sql_exception $e) {
	$e->getMessage();
	exit(1);
}

//	Limit first scan entries in history table

//	Get date and time of last scan for report
$stmt = $scandb->prepare("SELECT `scanned` FROM scanned WHERE `acct` = ? ORDER BY `scanned` DESC LIMIT 1");
$stmt->bind_param("s", $acct);
$stmt->execute();
$last_scanned_records = $stmt->get_result();
$stmt->close();

$count_baseline = 0;
if ($last_scanned_records->num_rows > 0)
{
	$firstscan = false;
	$firstscan_history_inserted = false;
	//	Get last timestamp
	$last_datetime = $last_scanned_records->fetch_assoc();
	$last_scanned = $last_datetime['scanned'];
} else {
	$firstscan = true;
	$firstscan_history_inserted = false;
}

//	Start timer (scan duration)
$start = microtime(true);

//	END OF INITIALIZE
// #endregion


/* ********************************* */
// #region Obtain baseline
/* ********************************* */

//	BASELINE
// 	Read from database to obtain file paths, hash values and 
//		last modified dates to compare against current files

$stmt = $scandb->prepare("SELECT `file_path`, `file_hash`, `file_last_mod` FROM baseline WHERE `acct` = ? ORDER BY `file_path` ASC");
$stmt->bind_param("s", $acct);
$stmt->execute();
$baseline_results = $stmt->get_result();
$stmt->close();


if ($baseline_results) 
{
	while ($baseline_files = $baseline_results->fetch_assoc())
	{
		$baseline[$baseline_files['file_path']] = array(
			'file_hash' => $baseline_files['file_hash'],
			'file_last_mod' => $baseline_files['file_last_mod']);
	}

	//	Output baseline array for testing
	/* if ($testing)
	{
		echo "<h2>SuperScan Test</h2>";
		echo "<pre>Baseline array (database files):<br />";
		print_r($baseline);
		echo "</pre><br />";
	} */

	//	Get the count of baseline records
	$count_baseline = count($baseline);

	if (0 == $count_baseline) 
	//	Prior scanned results but empty baseline table
	{
		//	Check for database hack by checking $firstscan
		if (!$firstscan)
		{
			$report .= "Empty baseline table!\r\nPROBABLE HACK ATTACK\r\n(ALL files are missing/deleted)!\r\n\r\n";	
		}
	}
	
	$report .= "$count_baseline baseline files extracted from database.\r\n";
	
	//	Output number of baseline files for testing
	if ($testing) echo "<p>$count_baseline baseline files extracted from database.</p>";
}
//	Baseline files read into baseline array and baseline_count made
// #endregion


/* ********************************* */
// #region SCAN
/* ********************************* */

//	Scan directories and generate hash values for current files
$dir = new RecursiveDirectoryIterator(SCAN_PATH);
$iter = new RecursiveIteratorIterator($dir);

while ($iter->valid())
{
	// 	Not in Dot AND not in $skip (prohibited) directories
	if (!$iter->isDot() && !(in_array($iter->getSubPath(), $skip)))
	{
		//	Get or set file extension ('' vs null)
		if (is_null(pathinfo($iter->key(), PATHINFO_EXTENSION)))
		{
			$ext = '';
		} else {
			$ext = strtolower(pathinfo($iter->key(), PATHINFO_EXTENSION));
		}

		//	Check for allowed file extension OR
		//	$ext empty AND not excluded ext OR
		//	is not $extensionless (if prohibited)
		//	if ((!empty($ext_array)) || (empty($ext_array) && !in_array($ext, $excl_array, true)))
		if (
			(in_array($ext, $ext_array, true)) ||	
			// in allowed extension array
			(empty($ext_array) && !in_array($ext, $excl_array, true)) ||	
			// OR NOT in excluded extension array
			(empty($ext) && $extensionless) )	
			// OR extensionless AND extensionless is allowed
		{
			$file_path = $iter->key();
			//	Ensure $file_path without \'s
			$file_path = str_replace(chr(92),chr(47),$file_path);
			
			//	Handle addition to $current array
			$current[$file_path] = array('file_hash' => hash_file("sha1", $file_path), 'file_last_mod' => date("Y-m-d H:i:s", filemtime($file_path)));

			if (strlen($file_path) > MAX_PATH_LENGTH) {
				print(PHP_EOL."Error: file path is over the character limit `PATH_LENGTH` in configuration file.".PHP_EOL);
				print(strlen($file_path) . " characters in " . $file_path.PHP_EOL);
			}

			// echo "\nchecking: ".$file_path;
			//	IF file_path is not in baseline, file was ADDED
			if (!array_key_exists($file_path, $baseline))
			{
				$added[$file_path] = array('file_hash' => $current[$file_path]['file_hash'], 'file_last_mod' => $current[$file_path]['file_last_mod']);
			
				//	INSERT added record in baseline table
				$stmt = $scandb->prepare("INSERT INTO baseline SET `file_path` = ?, `file_hash` = ?, `file_last_mod` = ?, `acct` = ?");
				$stmt->bind_param("ssss", $file_path, $added[$file_path]['file_hash'], $added[$file_path]['file_last_mod'], $acct);
				try {
					$stmt->execute();
				} catch (mysqli_sql_exception $e) {
					echo("Failed:".$file_path.PHP_EOL);
					echo($e->getMessage());
					echo($e->getCode().PHP_EOL.$e->getMessage());
					exit(2);
				}
				$stmt->close();

				if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);

				//	INSERT added file record in history table
				//		EXCEPT if $firstscan (to prevent unnecessary records)
				if(!$firstscan) 
				{
					$stmt = $scandb->prepare("INSERT INTO history SET `stamp` = ?, `status` = 'Added', `file_path` = ?, `hash_org` = 'Not Applicable', `hash_new` = ?, `file_last_mod` = ?, `acct` = ?");
					$timestamp = date('Y-m-d h:i:s');
					$stmt->bind_param("sssss", $timestamp, $file_path, $added[$file_path]['file_hash'], $added[$file_path]['file_last_mod'], $acct);
					$stmt->execute();
					$stmt->close();

					if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);
				}  else if(!$firstscan_history_inserted) {
					//	First Scan entry into history table
					// This creates an entry for EVERY file but all using the same file_path, timestamp and account
					$timestamp = date('Y-m-d h:i:s');
					$stmt = $scandb->prepare("INSERT INTO history SET `stamp` = ?, `status` = 'Added', `file_path` = 'FIRST SCAN (file listings inhibited)', `hash_org` = 'Not Applicable', `hash_new` = 'Not Applicable', `file_last_mod` = 'Not Applicable', `acct` = ?");
					$stmt->bind_param("ss", $timestamp, $acct);
					$stmt->execute();
					$stmt->close();

					if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);
					$firstscan_history_inserted = true;
				}	//	End of handling $added array entry

			} else {

				//	IF file was ALTERED 
				if ($baseline[$file_path]['file_hash'] <> $current[$file_path]['file_hash'] || $baseline[$file_path]['file_last_mod'] <> $current[$file_path]['file_last_mod'])
				{
					$altered[$file_path] = array('hash_org' => $baseline[$file_path]['file_hash'], 'hash_new' => $current[$file_path]['file_hash'], 'file_last_mod' => $current[$file_path]['file_last_mod']);
				
					//	UPDATE altered record in baseline
					$stmt = $scandb->prepare("UPDATE baseline SET `file_hash` = ?, `file_last_mod` = ? WHERE `file_path` = ? AND `acct` = ?");
					$stmt->bind_param("ssss", $altered[$file_path]['hash_new'], $altered[$file_path]['file_last_mod'], $file_path, $acct);
					$stmt->execute();
					$stmt->close();

					if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);

					//	INSERT altered file info in history table
					$stmt = $scandb->prepare("INSERT INTO history SET `stamp` = ?, `status` = 'Altered', `file_path` = ?, `hash_org` = ?, `hash_new` = ?, `file_last_mod` = ?, `acct` = ?");
					
					$timestamp = date('Y-m-d h:i:s');
					$stmt->bind_param("ssssss", $timestamp, $file_path, $altered[$file_path]['hash_org'], $altered[$file_path]['hash_new'], $altered[$file_path]['file_last_mod'], $acct);
					$stmt->execute();
					$stmt->close();

					if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);
				}
			}
		}	//	End of handling $altered array entry
	}	// End of handling $current record entry
	$iter->next();
	usleep(CHECK_DELAY);
}

//	DELETED
//	Compare and generate $deleted array
//	$deleted contains records where file_path 
//		in $baseline but not in $current
$deleted = array_diff_key($baseline, $current);
// Replace forward slashes with backflashes for Windows
// This should be done on the strings within the array, not on the array.
// Creates "array to string conversion"
// Why do this at all? In case something is wrong on your local Windows install?
//$deleted = str_replace(chr(92),chr(47),$deleted);

foreach($deleted as $file_path => $value_ignored)
{
	//	Handle DELETED file
	//	DELETE file from baseline table

	$stmt = $scandb->prepare("DELETE FROM baseline WHERE `file_path` = ? LIMIT 1");
	$stmt->bind_param("s", $file_path);
	$stmt->execute();
	$stmt->close();

	if ($testing && mysqli_error($scandb)) 
	{
		echo mysqli_error($scandb);
	} else {
		if ($testing) echo "<p>Deleted " . $file_path . "'s baseline record.</p>";
	}

	//	Record deletion in history table
	$timestamp = date('Y-m-d h:i:s');

	$stmt = $scandb->prepare("INSERT INTO history SET `stamp` = ?, `status` = 'Deleted', `file_path` = ?, `hash_org` = ?, `hash_new` = 'Not Applicable', `file_last_mod` = ?, `acct` = ?");
	$stmt->bind_param("sssss", $timestamp, $file_path, $deleted[$file_path]['file_hash'], $deleted[$file_path]['file_last_mod'], $acct);
	$stmt->execute();
	$stmt->close();

	if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);
}
//	End of Deleted file handling
// #endregion


/* ********************************* */
// #region Prepare Report 
/* ********************************* */


//	Get scan duration
$elapsed = round(microtime(true) - $start, 5);
	
//	Add count summary to report
$count_current = count($current);
$report .= "$count_current files collected in scan.\r\n";
if (0 == $count_current)
{
	//	ALL files are gone!
	$report .= "\r\nThere are NO files in the specified location.\r\n";
	if (!$firstscan) $report .= "This indicates a possible HACK ATTACK\r\nOR an incorrect path to the account's files\r\n";
}

$count_added = count($added);
$report .= "$indent $count_added files ADDED to baseline.\r\n";
if (!$firstscan)
{
	foreach($added as $filename => $value_ignored) $report .= "$indent2 + " . substr($filename,$scan_path_length) . "\r\n";
}

$count_altered = count($altered);
$report .= "$indent $count_altered ALTERED files updated.\r\n";
foreach($altered as $filename => $value_ignored) $report .= "$indent2 " . chr(177) . " " . substr($filename,$scan_path_length) . "\r\n";

$count_deleted = count($deleted);
$report .= "$indent $count_deleted files DELETED from baseline.\r\n";
foreach($deleted as $filename => $value_ignored) $report .= "$indent2 - " . substr($filename,$scan_path_length) . "\r\n";

echo "\r\n";

$count_changes = $count_added + $count_altered + $count_deleted;
	
//	Completed update of history table for Unchanged

if (0 == $count_changes)
{  
    $path = "File structure is unchanged since last scan, script execution time $elapsed seconds.<br>The baseline contains $count_current files.\r\n";

	//	Update history table
	$timestamp = date('Y-m-d h:i:s');

	$stmt = $scandb->prepare("INSERT INTO history SET `stamp` = ?, `status` = 'Unchanged', `file_path` = ?, `hash_org` = 'Not Applicable', `hash_new` = 'Not Applicable', `file_last_mod` = 'Not Applicable', `acct` = ?");
	$stmt->bind_param("sss", $timestamp, $path, $acct);
	$stmt->execute();
	$stmt->close();

	if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);

	// update scanned table
	$timestamp = date('Y-m-d h:i:s');
	
    $stmt = $scandb->prepare("INSERT INTO scanned SET `scanned` = ?, `changes` = ?, `acct` = ?");
    $stmt->bind_param("sss", $timestamp, $count_changes, $acct);
    $stmt->execute();
    $stmt->close();

	if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);

	$report .= "File structure is unchanged since last scan.\r\n\r\nThe baseline now contains $count_current files.\r\n\r\nScan executed in $elapsed seconds.";
	
} else {
	$timestamp = date('Y-m-d h:i:s');
    $stmt = $scandb->prepare("INSERT INTO scanned SET `scanned` = ?, `changes` = ?, `acct` = ?");

    $stmt->bind_param("sss", $timestamp, $count_changes, $acct);
    $stmt->execute();
    $stmt->close();

	if ($testing && mysqli_error($scandb)) echo mysqli_error($scandb);

	$report .= "\r\n\r\nSummary:\r\n
Baseline start: $count_baseline
Current Baseline: $count_current
Changes to baseline: $count_changes\r\n
$indent Added: $count_added
$indent Altered: $count_altered
$indent Deleted: $count_deleted.\r\n
Scan executed in $elapsed seconds.";
	if (0 < $count_changes) $report .= "\r\n\r\nIf you did not makes these changes, examine your files closely\r\nfor evidence of embedded hacker code or added hacker files.\r\n(WinMerge provides excellent comparisons)";
}

//	Clean-up history table and scanned table by deleting entries over 30 days old
@mysqli_query($scandb,"DELETE FROM history WHERE `stamp` < DATE_SUB(NOW(), INTERVAL 30 DAY)");
if ($testing && mysqli_error($scandb)) echo "History table clean-up problem: " . mysqli_error($scandb) . "<br />";

@mysqli_query($scandb,"DELETE FROM scanned WHERE `scanned` < DATE_SUB(NOW(), INTERVAL 30 DAY)");
if ($testing && mysqli_error($scandb)) echo "Scanned table clean-up problem: " . mysqli_error($scandb) . "<br />";

//	End of Report preparation and clean-up
// #endregion


/* ********************************* */
// #region Output Report
/* ********************************* */

//	E-mail Report
if ($email_out && 0 < $count_changes)
{
	
}

//	Output Report for testing
if ($testing || $report_out)
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
		$mail->setFrom(SMTP_FROM, 'Integrity Monitor Scan Report');
		foreach(REPORT_ADDRESSES as $email) {
			$mail->addAddress($email);
		}

		//Content
		$mail->isHTML(false);                                  //Set email format to HTML
		$mail->Subject = "SuperScan Report for {$acct}";
		$mail->Body    = $report;

		$mail->send();
		echo 'Message has been sent';
	} catch (Exception $e) {
		echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
	}


	
}

// #endregion


//	Destroy tables (release to memory)
$baseline = $current = $added = $altered = $deleted = array();

//	Close database
mysqli_close($scandb);