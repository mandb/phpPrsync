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