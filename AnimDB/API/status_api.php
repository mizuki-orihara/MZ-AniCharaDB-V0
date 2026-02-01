<?php
// API/status_api.php
header('Content-Type: application/json');

$base_dir = __DIR__;
$resever_dir = ../import/ 
$preVerifier_dir = ../varid/
$dispatcher_dir = ../dispatch/
$Add_indexer_dir = ../maindb/register_cache/



$target_phases = ['reseiver','preVerifier','dispatcher','Add_indexer'];
$response = [
	'system' =>'animDB' ,
	'timestamp' => date ('Y-m-d H:i:s'),
	'phases' => []
];
foreach ($target_phases as $phase) {
	$status_file = __DIR__ . "/../status/{$phase}_status.json";
	if (file_exists($status_file)) {
		$status_data = json_decode(file_get_contents($status_file), true);
		$response['phases'][$phase] = $status_data;
	} else {
		$response['phases'][$phase] = ['status' => 'unknown', 'message' => 'Status file not found'];
	}
}
echo json_encode($response, JSON_PRETTY_PRINT||JSON_UNESCAPED_UNICODE);
