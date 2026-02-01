<?php
/**
 * animdb: Receiver
 * 外部(JS/API)からのRAWデータを受信し、セッション別フォルダへ隔離保存する。
 * * I/O Specifications:
 * [IN]  HTTP POST (php://input) / X-FILE-NAME / ?sid=
 * [READ] ../task_master.json (Gate check)
 * [OUT]  receiver_status.json (Status log)
 * [OUT]  import_cache/~temp_[sid]/[filename]_[seq].[ext]
 * * Rules:
 * - Max size: 32,767 bytes / file
 * - Gate Control: task_master['gates']['receiver']
 */
// 1. 設定とパスの定義
$base_dir = __DIR__;
$import_cache_dir = $base_dir . '/import_cache';
$task_master_path = $base_dir . '/../task_master.json'; 
$status_path = $base_dir . '/receiver_status.json';
$max_size_limit = 32767;
// --------------------

// 2. ゲート開閉チェック
$gate_open = true;
if (file_exists($task_master_path)) {
    $master = json_decode(file_get_contents($task_master_path), true);
    $gate_open = $master['gates']['receiver'] ?? $master['control']['gate_open'] ?? true;
}

if (!$gate_open) {
    update_receiver_status($status_path, "error", "System gate is closed. Entry rejected.");
    http_response_code(503);
    exit("Receiver gate is closed.\n");
}

// 3. データ受信処理とサイズバリデーション
$json_data = file_get_contents('php://input');

// データが空の場合
if (empty($json_data)) {
    update_receiver_status($status_path, "standby", "Awaiting data input.");
    exit("Ready to receive.\n");
}

// 【重要】サイズチェック：32KBを超えている場合は即座に破棄
$data_size = strlen($json_data);
if ($data_size > $max_size_limit) {
    update_receiver_status($status_path, "error", "File too large: {$data_size} bytes (Limit: {$max_size_limit})");
    http_response_code(413); // Payload Too Large
    exit("Error: Data size exceeds the 32KB limit.\n");
}

// 4. 作業セッションフォルダの準備
$sid = $_REQUEST['sid'] ?? bin2hex(random_bytes(8)); 
$session_dir = $import_cache_dir . '/~temp_' . $sid;

if (!is_dir($session_dir)) {
    if (!mkdir($session_dir, 0755, true)) {
        update_receiver_status($status_path, "error", "Failed to create session directory: ~temp_$sid");
        exit("Directory error.\n");
    }
}

// 5. 保存と状態更新
// セッションフォルダ内の既存ファイル（. と .. を除く）をカウントして連番を生成
$files = scandir($session_dir);
$file_count = count(array_diff($files, array('.', '..')));
$seq = $file_count + 1;

$raw_filename = $_SERVER['HTTP_X_FILE_NAME'] ?? '';

if (!empty($raw_filename)) {
    // ファイル名と拡張子を分離
    $original_name = basename(urldecode($raw_filename));
    $info = pathinfo($original_name);
    $filename_only = $info['filename'];
    $extension = $info['extension'] ?? 'json';

    // 形式：元のファイル名 _ 連番 . 拡張子 (例: data_1.json)
    $file_name = $filename_only . '_' . $seq . '.' . $extension;
} else {
    // ファイル名が指定されていない場合のフォールバック
    $file_name = 'received_' . date('Ymd_His') . '_' . $seq . '.json';
}

$save_path = $session_dir . '/' . $file_name;

if (file_put_contents($save_path, $json_data)) {
    update_receiver_status($status_path, "success", "Received: $file_name", $sid);
    // JS側がSIDを認識できるよう、この文字列は維持
    echo "Data accepted. Session: ~temp_$sid ($file_name)\n";
} else {
    http_response_code(500);
    exit("Write failed.");
}

/**
 * 補助関数: receiver_status.json の更新
 */
function update_receiver_status($path, $status_code, $message, $sid = null) {
    $current_status = [
        "control" => [
            "phase" => "receiver",
            "gate_open" => true
        ],
        "result" => [
            "last_update" => date('Y-m-d H:i:s'),
            "status" => $status_code,
            "message" => $message
        ],
        "current_session" => $sid ? "~temp_$sid" : null
    ];
    file_put_contents($path, json_encode($current_status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
