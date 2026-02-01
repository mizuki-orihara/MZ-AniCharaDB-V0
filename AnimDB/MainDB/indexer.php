<?php
/**
 * animdb: Indexer (台帳管理・実体化)
 * * 役割:
 * 1. Register_Cache 内のファイルを MainDB へ正式登録 (ADD)
 * 2. MainDB 内の全ファイルを走査し、ID/ハッシュを再定義 (REBUILD)
 * * ID三階層構造:
 * - uuid: 作成者/オリジン由来 (既存維持)
 * - id: 中間処理用 (既存維持)
 * - hash: 内容に基づくメインID (MD5 / 今回生成)
 */

$baseDir = __DIR__;
$mainDbDir     = $baseDir . '/MainDB';
$registerCache = $mainDbDir . '/Register_Cache';
$indexFile     = $mainDbDir . '/index.json';
$statusFile    = $baseDir . '/indexer_status.json';

// 動作設定 (外部引数やフォーム等で切り替え想定)
$mode = $argv[1] ?? 'ADD'; // 'ADD' or 'REBUILD'

// -----------------------------
// 1. ハッシュ計算関数
// -----------------------------
/**
 * ヘッダ以外のデータからMD5ハッシュを生成
 */
function generateDataHash($data) {
    $target = $data;
    unset($target['header']);    // ヘッダを除外
    unset($target['processed_at']); // 処理時間も除外
    
    // データの正規化（並び順によるハッシュ変化を防ぐ）
    ksort($target);
    $jsonString = json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return md5($jsonString);
}

// -----------------------------
// 2. メイン処理ルーチン
// -----------------------------

$dbIndex = [];
if ($mode === 'ADD' && file_exists($indexFile)) {
    $dbIndex = json_decode(file_get_contents($indexFile), true) ?: [];
}

// 対象ディレクトリの決定
$targetDir = ($mode === 'REBUILD') ? $mainDbDir : $registerCache;
$files = glob($targetDir . '/*.json');

foreach ($files as $filePath) {
    if (basename($filePath) === 'index.json') continue;

    $json = json_decode(file_get_contents($filePath), true);
    if (!$json) continue;

    // A. ハッシュ生成とヘッダ更新
    $newHash = generateDataHash($json);
    $json['header']['hash'] = $newHash;
    
    // B. 新ファイル名の決定
    // 命名規則: 作品名_キャラ名_派生区分_ハッシュ.json
    $sanitize = fn($s) => preg_replace('/[\/\\?%*:|"<>]/', '-', $s);
    $work = $sanitize($json['work'] ?? 'Unknown');
    $name = $sanitize($json['name'] ?? 'Unknown');
    $variant = $sanitize($json['header']['variant'] ?? 'Default'); // 派生区分
    
    $newFilename = "{$work}_{$name}_{$variant}_{$newHash}.json";
    $newPath = $mainDbDir . '/' . $newFilename;

    // C. JSON保存と移動
    $jsonContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($mode === 'REBUILD') {
        // 同一フォルダ内でのリネーム（全数書き直し）
        file_put_contents($filePath, $jsonContent);
        rename($filePath, $newPath);
    } else {
        // キャッシュからMainDBへ移動
        file_put_contents($newPath, $jsonContent);
        unlink($filePath);
    }

    // D. インデックス(台帳)への登録
    $workKey = "{$work}_{$name}";
    if (!isset($dbIndex[$workKey])) $dbIndex[$workKey] = [];
    if (!in_array($newHash, $dbIndex[$workKey])) {
        $dbIndex[$workKey][] = $newHash;
    }
}

// -----------------------------
// 3. 台帳(index.json)の更新
// -----------------------------
ksort($dbIndex);
file_put_contents($indexFile, json_encode($dbIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Indexer finished. Mode: {$mode}\n";