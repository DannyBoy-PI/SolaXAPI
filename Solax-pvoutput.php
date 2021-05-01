<?php

//
// ***IMPORTANT*** PLEASE READ ALL THE FOLLOWING:-
//
// Connect to Solax RealTime API and Push to PVOutput
//
// It will pull the latest RealTime information about your inverter and store it in a datafile (No database needed).
// It will then push the defined range up to PVOutput from that datafile. If the SolaX API service is unavailable, your inverter is
// in an abnormal state or there is no internet connectivity, you will get data gaps. If and when SolaX release a full
// historical API, this code will need to be replaced/modified to pull a range from SolaX rather than just the RealTime data.
//
// This code assumes the data, log and executable files are all in the same directory.
// This code assumes you have the date/time/region set correctly.
// This code should be called from a BASH script in CRON and run once a minute (This is important as upload rate is set as below).
//
// It will attempt to upload data to PVOuput ONLY when the minute is 00, 15, 30 or 45.
//
//
//
// Written by D.C.Moore 17/04/2021
//
// Written for PHP 7.x with PHP CURL Extension installed and designed to run on a Raspberry PI, but should work on any system with PHP installed.
//
// This software is free and no warranty is given for the saftey and fitness of it for any given purpose.
//
//

$TEST="TRUE";  // If this is set to anything other than FALSE, data will NOT be uploaded to PVOutput, but will attempt a read from SolaX API

$solaxsn="";      // This is your SolaX Dongle Serial Number (e.g. SWGHDSGEHAS)
$solaxapi="";     // This is the Token ID you got from the SolaX Website API settings (e.g. 202102241645545324344)

$pvoutputsi="";   // This is your PV Output System ID (find it in "Settings" in PVOutput) (e.g. 60123)
$pvoutputapi="";  // This is your PV Output API Key (find/create it in "Settings" in PVOutput) (e.g. 2ea67a13b068ca08fabc534535abcd66234cd)

$hours=2; // This is the number of hours back from the datafile to update (e.g. If this is 2 and it is 10am now, upload entries from 8am)
          // Do not set this number too high or you could run out of upload attempts per hour - Ideally set it to 1 or 2....

$purgedays=63; // This is the age of data and log files to keep - Suggest this is at least two months plus one day (so 63);

$path = __DIR__."/"; // Get our directory path

if (isset($argv[1])) { // Running the script with an argument date will attempt to batch process that day.
$HISTORICAL="TRUE";    // e.g. "PHP Solax-pvoutput.php 2021-04-20" - This will attempt to upload data from 20th April 2021 datafile
} else {               // Note: Non PVOutput donators are limited to 14 days old data and 60 API calls per hour...
$HISTORICAL="FALSE";   // Donators can process data that is 90 days old and 300 API calls per hour...
}

if ($HISTORICAL == "FALSE") {

$curtime=date('Y-m-d H:i:s'); // Get the current date and time from date
$curmin=date('i'); // Get the current minute from date
$date=date('Y-m-d'); // Get current date
$datafile=$path."SolaX-".$date."-data.solaxapi"; // Data file for this day

TidyUpDataLogs($purgedays); // Purge old log and data files

} else { // Use the date provided in the argument variable

$date=$argv[1];
$curtime="$date 23:45:00";
$curmin="45";
$datafile=$path."SolaX-".$date."-data.solaxapi";
$hours=24;

GOTO JUMP;

}


//GOTO JUMP;  // Used to skip loading from SolaX API

// Connect to Solax API and pull the latest RealTime Information

$streamContext = stream_context_create(
    array('http'=>
        array(
            'timeout' => 5,  // Timeout of 5 seconds
        )
    )
);

$url = sprintf('https://www.eu.solaxcloud.com:9443/proxy/api/getRealtimeInfo.do?tokenId=%s&sn=%s',
       $solaxapi, $solaxsn);
$contents = file_get_contents($url, false, $streamContext);
$results = json_decode($contents);

$json_error=json_last_error();

if ($json_error <> 0 or $results->success == "") {
print "\nAn error has occured connecting to SolaX API, proceeding to upload.\n";
GOTO JUMP;

}

// If we are here, we got something back from SolaX API, so decode into variables.

$uploadtime=$results->result->uploadTime;
$acpower=$results->result->acpower;
$yieldtoday=$results->result->yieldtoday;
$yieldtotal=$results->result->yieldtotal;
$status=$results->result->inverterStatus;
$powerdca=$results->result->powerdc1;
$powerdcb=$results->result->powerdc2;
$success=$results->success;

print "\nCurrent Time: $curtime";
print "\nUpload Time: $uploadtime";
print "\nAC Power: $acpower";
print "\nYield Today: $yieldtoday";
print "\nYield Total: $yieldtotal";
print "\nPower DC 1: $powerdca";
print "\nPower DC 2: $powerdcb";
print "\nInverter Status: $status";
print "\nSuccess: $success\n";

if ($success <> 1) {
print "\nError in SolaX API pull, proceeding to upload.\n";
GOTO JUMP;
}

if ($status <> 102) {
print "\nInverter is not in NORMAL mode...\n";
if ($status == 100) {print "\nInverter is in WAIT mode, this is normal if it is not during daylight hours\n";}
if ($status == 101) {print "\nInverter is in CHECK mode, check inverter for errors !\n";}
if ($status == 103) {print "\nInverter is in FAULT mode, check inverter for errors !!\n";}
if ($status == 104) {print "\nInverter is in PERMANENT FAULT mode, check inverter for errors !!!\n";}
if ($status >= 105 and $status <= 113) {print "\nInverter is in an abnormal state, check inverter for errors !\n";}
print "\nProceeding to upload..\n";
GOTO JUMP;
}

// If we are here, the data from SolaX API appears good, so add to todays datafile.

$array="$curtime,$uploadtime,$acpower,$yieldtoday,$yieldtotal,$powerdca,$powerdcb,$status,$success";

$duplicate=CheckDuplicate($datafile,$uploadtime); // Check if this data string is already in todays datafile

if ($duplicate == "FALSE") {

print "\nNew data received from SolaX API, updating todays datafile...\n";

$handle=fopen("$datafile", "a");

fwrite($handle,"$array\n");

fclose($handle);

} else {

print "\nData from API already in todays datafile. Proceeding with Upload to PVOutput...\n";

}

JUMP:  // Used to skip read from SolaX API or processed an abnormal read from Solax API.

// If we are here we are ready to upload to PVOutput.

$UPLOAD="FALSE";

if ($curmin == 15 or $curmin == 30 or $curmin == 45 or $curmin == 00) { // Check the minute to see if we are going to upload
$UPLOAD="TRUE";
}

if ($UPLOAD == "FALSE") {
print "\nThe minute is not 00, 15, 30 or 45 - So not attempting upload at this time..\n";
exit;
}

if (!file_exists($datafile)) {
print "\nDatafile ($datafile) does not exist yet ! Exiting..\n";
exit;
}

$handle=fopen("$datafile", "r"); // Open datafile READ Only..

$uldata=""; // This will be the variable used to contain the upload string
$ectime=strtotime($curtime); // Convert current time to epoch
$pctime=strtotime("-$hours hours", $ectime); // Process data from this time only (see $hours at the top of this code)

//print "\n$ectime - $pctime\n";

$count=0;
$batch="FALSE";

while (($data=fgetcsv($handle)) !== FALSE) {

$uploadtime=$data[1];
$acpower=$data[2];
$yieldtoday=$data[3];
$yieldtotal=$data[4];
$powerdca=$data[5];
$powerdcb=$data[6];

$istime=strtotime($uploadtime); // Convert uploadtime to epoch

//print "\n$istime - $pctime\n"; Just displays the epoch times

if ($istime >= $pctime) { // The uploadtime falls within our upload range, so process it for upload to PVOutput.

$count=$count+1;

$pdateY=date('Y',strtotime($uploadtime));
$pdateM=date('m',strtotime($uploadtime));
$pdateD=date('d',strtotime($uploadtime));
$pdate="$pdateY"."$pdateM"."$pdateD";
$ptime=date('H:i',strtotime($uploadtime));
$yieldtoday=$yieldtoday*1000; // Convert yield today from kWh's to Wh's

$uldata=$uldata.";$pdate,$ptime,$yieldtoday,$acpower";

if ($count >= 25) {  // We have a batch of up to 25 reads, uploading batch

$count=0; // Reset batch count variable

BulkProcess($uldata,$TEST,$pvoutputapi,$pvoutputsi);

$batch="TRUE"; // Make a note that we have processed a batch
$uldata=""; // Reset batch data variable

}

}

}

fclose($handle);

if ($uldata == "" and $batch == "FALSE") {
print "\nNo data to upload at this time..\n";
exit;
}

if ($uldata == "" and $batch == "TRUE") {
print "\nNo more data to upload at this time..\n";
exit;
}

// If we are here, we have some data to upload to PVOutput.

$uldata = substr($uldata, 1); // Remove leading ";" from the string as we don't need it

print "\n$uldata\n";  // Display the string we are going to upload to PVOutput

$uldata="data=".$uldata;

if ($TEST == "FALSE") {

$ch = curl_init();// curl handle to post data to PVOutput

curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_URL, 'https://pvoutput.org/service/r2/addbatchstatus.jsp');
curl_setopt($ch, CURLOPT_POSTFIELDS, "$uldata");
curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Pvoutput-Apikey:$pvoutputapi","X-Pvoutput-SystemId:$pvoutputsi"));
curl_setopt($ch, CURLOPT_POST, 1);
curl_exec  ($ch);
curl_close ($ch);

} else {

print "\n**INFORMATION** In test mode (No data will have been uploaded to PVOutput)\n";

}

print "\nUploaded to PVOutput...\n";

exit;

function BulkProcess($uldata,$TEST,$pvoutputapi,$pvoutputsi) {

// If we are here, we have a batch to upload to PVOutput.

$uldata = substr($uldata, 1); // Remove leading ";" from the string as we don't need it

print "\nBatch: $uldata\n";  // Display the string we are going to upload to PVOutput

$uldata="data=".$uldata;

if ($TEST == "FALSE") {

$ch = curl_init();// curl handle to post data to PVOutput

curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_URL, 'https://pvoutput.org/service/r2/addbatchstatus.jsp');
curl_setopt($ch, CURLOPT_POSTFIELDS, "$uldata");
curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Pvoutput-Apikey:$pvoutputapi","X-Pvoutput-SystemId:$pvoutputsi"));
curl_setopt($ch, CURLOPT_POST, 1);
curl_exec  ($ch);
curl_close ($ch);

} else {

print "\n**INFORMATION** In test mode (No data will have been uploaded to PVOutput)\n";

}

print "\nBatch uploaded to PVOutput...\n";

return;

}

function CheckDuplicate($datafile,$uploadtime) {

// Check to see if this data string is already in todays datafile

$duplicate="FALSE";

if (!file_exists($datafile)) {
return($duplicate); // File doesn't exist yet, so can't have data in it !
}

$handle=fopen("$datafile", "r");

while (($data=fgetcsv($handle)) !== FALSE) {

$dfuploadtime=$data[1];

if ($dfuploadtime == $uploadtime) {$duplicate="TRUE";} // Check to see if the uploadtime is already in the datafile

}

fclose($handle);

return($duplicate);

}

function TidyUpDataLogs($purgedays) {

print "\n*Data/Log File Housekeeping*\n";

$days = $purgedays;
$path = __DIR__."/";
$filetypes_to_delete = array("solaxapi");

print "\nPurging files with extension '.solaxapi' older than $purgedays days in $path...\n";

if ($handle = opendir($path))
{
    while (false !== ($file = readdir($handle)))
    {
        if (is_file($path.$file))
        {
            $file_info = pathinfo($path.$file);
            if (isset($file_info['extension']) && in_array(strtolower($file_info['extension']), $filetypes_to_delete))
            {
                if (filemtime($path.$file) < ( time() - ( $days * 24 * 60 * 60 ) ) )
                {
                    print "\nPurging file $path$file\n";
                    unlink($path.$file);
                }
            }
        }
    }
}

return;

}


?>
