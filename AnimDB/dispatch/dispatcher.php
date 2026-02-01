<?php
/**
 * animdb: Dispatcher (現状整理とリファクタリング案)
 * * [現状の I/O 仕様]
 * ├（入力）valid_Inspected / [作品名]_[名前]_[日時]_[セグメント].json
 * ├（参照）MainDB / index.json
 * ├（参照）task_master.json (ゲート監視)
 * └（出力/振分先）
 * ├ 新規登録: MainDB / Register_Cache
 * ├ 競合確認: manualMerge
 * └ 一時退避: ~TrashTemp_[SID] (※事故復旧用の暫定退避バッファ)
 * * [今後(After)の書き換え方針]
 * 1. パス管理の集約:
 * - dirname($baseDir, 2) 等の相対計算を廃止。
 * - task_master.json 内の 'paths' セクション、またはそこから参照する .env より絶対パスを取得。
 * 2. 名称の正常化:
 * - 「~TrashTemp_」 → 「~Discard_Buffer_」へ変更（役割を「ゴミ箱」から「一時退避」へ明確化）。
 * 3. 判定ロジックの安定化:
 * - ファイル名の explode 依存から、JSONヘッダー（header.id）照合へのシフト。
 * 4. クリーンアップ:
 * - 処理成功かつエラー0の場合、~Discard_Buffer_ の自動削除を検討。
 
 
 * * ID管理仕様の変更:
 * - 中間ID(4-4-4-4形式)の廃止方針。
 * - [UUID] + [HASH] の2層構造へ集約。
 * * * 振分ロジック (Hash-Based):
 * 1. Processor通過済みのデータから Hash を生成。
 * 2. MainDB/index.json 内の当該作品/キャラのハッシュリストと照合。
 * 3. 既知のハッシュであれば「~Discard_Buffer_」へ退避。
 * 4. 未知のハッシュであれば「Register_Cache」へ送る。
 *
 * * メリット:
 * - 同一内容の別個体（ID違い）を「重複」として正確に弾ける。
 * - 名寄せ（Processor）の成果を最大限に活かした照合が可能。
 
 
 */
 
 
 

$baseDir = __DIR__;
statusFile      = $baseDir . '/dispatcher_status.json';
$taskMasterFile  = dirname($baseDir, 2) . '/API/task_master.json';
$lockFile        = $baseDir . '/dispatcher.lock';

$inspectedDir    = $baseDir . '/valid/Inspected';
$manualMergeDir  = $baseDir . '/manualMerge';
$Discard_Buffer    = $baseDir . '/~Discard_Buffer_'; // 後ほどSIDを付与
$mainDbDir       = dirname($baseDir, 1) . '/MainDB'; 
$indexFile       = $mainDbDir . '/index.json';
$registerCache   = $mainDbDir . '/Register_Cache';


// -----------------------------
// 1. 事前チェック (Gate & Lock)
// -----------------------------
if (file_exists($taskMasterFile)) {
    $tm = json_decode(@file_get_contents($taskMasterFile), true);
    if (isset($tm['gate_open']['dispatcher']) && $tm['gate_open']['dispatcher'] !== true) {
        error_log("dispatcher: gate closed.");
        exit(0);
    }
}

if (file_exists($lockFile)) {
    error_log("dispatcher: locked.");
    exit(0);
}
file_put_contents($lockFile, getmypid());

// -----------------------------
// 2. index.json の読み込み
// -----------------------------
// index.json 構造想定: { "Work_Name": ["segment1", "segment2"], ... }
$dbIndex = [];
if (file_exists($indexFile)) {
    $dbIndex = json_decode(@file_get_contents($indexFile), true) ?: [];
}

// -----------------------------
// 3. 処理開始ステータス更新
// -----------------------------
$status = ['inWorking' => true, 'history' => []];
if (file_exists($statusFile)) {
    $oldStatus = json_decode(@file_get_contents($statusFile), true);
    if (isset($oldStatus['history'])) $status['history'] = $oldStatus['history'];
}
$newEntry = [
    'started_at' => date('c'),
    'counts' => ['total' => 0, 'new' => 0, 'review' => 0, 'discard' => 0]
];
array_unshift($status['history'], $newEntry);
$status['history'] = array_slice($status['history'], 0, 3);
saveStatus($statusFile, $status);

// -----------------------------
// 4. メインループ
// -----------------------------
$files = glob($inspectedDir . '/*.json');
foreach ($files as $filePath) {
    $filename = basename($filePath);
    // ファイル名形式: {Work}_{Name}_{Timestamp}_{Segment}.json
    $parts = explode('_', basename($filename, '.json'));
    
    if (count($parts) < 4) continue;

    $workName  = "{$parts[0]}_{$parts[1]}";
    $timestamp = $parts[2];
    $segment   = $parts[3];
    
    $status['history'][0]['counts']['total']++;

    // 振り分け判定
    if (!isset($dbIndex[$workName])) {
        // --- A. 全く新しい作品/キャラの場合 ---
        $destDir = $registerCache;
        $status['history'][0]['counts']['new']++;
    } else {
        if (in_array($segment, $dbIndex[$workName], true)) {
            // --- B. 同一セグメント(ID)が既に存在する場合 (重複) ---
            // セッションIDが不明な場合は timestamp を代用
            $destDir = $Discard_Buffer . ($timestamp ?: 'default');
            $status['history'][0]['counts']['discard']++;
        } else {
            // --- C. 作品名は一致するが、個体(ID)が異なる場合 (要確認) ---
            $destDir = $manualMergeDir;
            $status['history'][0]['counts']['review']++;
        }
    }

    moveTo($filePath, $destDir, $filename);
}

// -----------------------------
// 5. 終了処理
// -----------------------------
$status['history'][0]['ended_at'] = date('c');
$status['inWorking'] = false;
saveStatus($statusFile, $status);
@unlink($lockFile);

/**
 * ユーティリティ関数
 */
function moveTo($src, $dir, $name) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $dest = $dir . '/' . $name;
    return @rename($src, $dest) ?: (@copy($src, $dest) && @unlink($src));
}

function saveStatus($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}