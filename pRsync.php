<?php

$pTasks = 4; // # of parallel copies
$srcDir = '/var/log';
$globalRateLimit = 4000; // in KB/s
$taskFolder = '/tmp';



$pTasks=intval($pTasks); // basic sanity check
if ($pTasks < 1){
	die('Must have at least 1 process');
}



$iuid = uniqid();
$total = array();
$ratePerProc = floor($globalRateLimit/$pTasks);

for ($i=0; $i<$pTasks; $i++){
	$tList[$i]['fn'] = $l = $taskFolder.'/'.$iuid.'_'.$i.'.list';
	$tList[$i]['fh'] = fopen($l, 'w');
	$sizeBin[$i] = 0;
}

$list = popen('find ' . $srcDir, 'r');

while ($entry = trim(fgets($list))){
    $fHash = hexdec(substr(md5($entry), -6)) % $pTasks;
	asort($sizeBin);
	$emptiestBin = key($sizeBin);
	$eSize = filesize($entry);
	$total['JobSize']+= $eSize;
	$total['Files']++;
	$sizeBin[$emptiestBin]+=$eSize;
	fwrite($tList[$emptiestBin]['fh'], $entry."\n");
	echo "\n$entry\n  hash: $fHash, size: $eSize";
	
}
print_r($sizeBin);

print_r($total);
echo "\nFileLists are Done";

foreach ($tList as $tfid=>$transfer){
	$rsyncCommand = 'rsync --files-from=' . $transfer['fn'];
	/*
	you can dynamically control the rate limit on rsync so that the remaining processes can have more bandwidth after one is finished, like this:
	https://bugzilla.samba.org/show_bug.cgi?id=7120
	
			with pipe-viewer or similar pipe transfer visualization tools with throttling feature. 

			http://www.ivarch.com/programs/pv.shtml 

			first we need to create a little wrapper to put pv into the transfer chain:

			linux:/tmp # cat /tmp/pv-wrapper
			#!/bin/bash
			pv -L 1000 | "$@"

			example:

			linux:/tmp # rsync --rsh='/tmp/pv-wrapper ssh' -a /proc root@localhost:/tmp/test
			Password: 4 B 0:00:01 [3.68 B/s] [ <=> ]
			file has vanished: "/proc/10/exe"
			file has vanished: "/proc/10/task/10/exe"
			file has vanished: "/proc/11/exe"
			file has vanished: "/proc/11/task/11/exe"
			file has vanished: "/proc/12/exe"=> ]
			4.88kiB 0:00:05 [1002 B/s] [ <=> 

			You can even adjust the transfer rate at runtime, as pv can communicate with a running instance of itself - you will just need the appropriate PID. This would even make cron based tuning of transfer rates possible.

			pv -R $PIDOFPV -L RATE

	One could also adjust the rate limit per process proportionately based on the size of the files to be trasnferred in each process by the sizeBin array.

	*/
				
	$catCommand = 'cat ' . $transfer['fn'];
	$tList[$tfid]['ph'] = popen($catCommand, 'r'); // open the rsync process and store the process handle in the array
}

// loop through your handles
do{
	$alive = false;
	foreach ($tList as $tfid=>$transfer){
		if (!feof($transfer['ph'])){
			// process is done.
			$alive = true;
			$lastOutput = stream_get_contents($transfer['ph']);
			if (strlen($lastOutput)>0){
				echo "\n[$tfid]: \n" . $lastOutput;
			}
		}
	}
	usleep(10000);
}while($alive);


echo "\nDone rsync processes, cleaning up.";

// cleanup

for ($i=0; $i<$pTasks; $i++){
	fclose($tList[$i]['fh']);
	unlink($tList[$i]['fn']);
}


?>
