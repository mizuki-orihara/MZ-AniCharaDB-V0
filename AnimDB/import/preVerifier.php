<?php
/**
 * animdb:preVerifier (標準化・バリデーター)
 * Receiverが保存した一時データを検証し、正規のインポート候補(valid)へと昇華させる。
 * * I/O Specifications:
 * [IN]  import_cache/{~temp_*,^temp*}/*.json
 * [READ]  ../../API/task_master.json (ActiveJobs/Receiverフラグ監視)
 * [OUT]   valid_Inspected/[作品名]_[名前]_[日時]_[セグメント].json (正規化済)
 * [OUT]   validation_errors/ (構文/スキーマエラー、破損ファイル)
 * [OUT]   archive/[SID]/ (処理済み原本の移動先)
 * [OUT]   preVerifier_status.json (処理統計、実行履歴)
 * * Rules:
 * - Schema: MiZu_Character_Profile_vX.XXXX.XX 形式必須
 * - ID Normalization: UUID(32桁)またはID(16桁)を点検し、4-4-4-4形式のIDを強制生成
 * - Job Control: task_master['Receiver']がtrueの時のみ稼働
 * * ID仕様:
 * - UUID: 32桁(ハイフンなし含む)を正規の 8-4-4-4-12 形式へ整形して維持。
 * - 中間ID: (移行期のみ維持) 将来的に Dispatcher 側のハッシュ判定に置換。
 * * * 役割の明確化:
 * - データの「内容」を正規化（サニタイズ、表記揺れ修正）することに注力する。
 * - ここで名寄せが不完全だと、Dispatcher で別ハッシュとして判定されてしまうため、
 * もっとも「丁寧なデータ清掃」が求められる工程となる。
 
 
 
 
 
 
 */
$baseDir = __DIR__;
$importCacheDir = $baseDir . '/import_cache';//　仕掛品の読み込み先
$archiveRootDir = $baseDir . '/archive';
$inspectedDir   = $baseDir . '/valid/inspected'; // 点検済み送り先
$errorDir = $baseDir . '/valid/errors'; // 構文エラー等
$statusPath = $baseDir . '/processor_status.json';// 状態ファイルパス
$taskMasterPath = dirname($baseDir, 2) . '/API/task_master.json'; // task_master.json パス

// 1. ^temp で始まるディレクトリをスキャン
$dirs = glob($importCacheDir . '/{~temp_*,^temp*}', GLOB_BRACE);
if ($dirs === false) $dirs = [];

$results = ['processed' => 0, 'errors' => 0];

// --- 状態ファイル読み込み（存在するなら） ---
$statusData = [];
if (is_file($statusPath)) {
    $raw = file_get_contents($statusPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $statusData = $decoded;
    }
}
// 初期構造を保証
$statusData['history'] = $statusData['history'] ?? []; // 直近実行履歴（配列、最新が先頭）
$statusData['reports'] = $statusData['reports'] ?? []; // 将来の拡張用スペース

// --- 実行開始時のステータス書き込み（高精度タイム） ---
$runStartFloat = microtime(true);
$runStartIso = date('c', (int)$runStartFloat);

$statusData['inWorking'] = true;
$statusData['current_run'] = [
    'start_time' => $runStartIso,
    'start_time_float' => $runStartFloat, // 高精度値（必要ならレポートで使う）
    'end_time' => null,
    'end_time_float' => null,
    'processed' => 0,
    'errors' => 0,
    'duration_seconds' => null,
    'items_per_second' => null,
    'items_per_hour' => null,
    'avg_ms_per_item' => null
];
file_put_contents($statusPath, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// --- 追加: task_master.json を読み込み Receiver と ActiveJobs/last_update を扱うヘルパー関数 ---
function loadTaskMaster($path) {
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
function saveTaskMaster($path, $data) {
    // LOCK_EX で並列更新の競合を軽減
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function registerActiveJob(&$tm, $type = 'processor') {
    try {
        $rand = bin2hex(random_bytes(3));
    } catch (Exception $e) {
        $rand = uniqid();
    }
    $jobKey = "{$type}_" . getmypid() . "_{$rand}";
    $tm['ActiveJobs'] = $tm['ActiveJobs'] ?? [];
    $tm['ActiveJobs'][$jobKey] = [
        'type' => $type,
        'pid' => getmypid(),
        'start_time' => date('c'),
        'status' => 'running',
        'note' => null
    ];
    $tm['last_update'] = $tm['last_update'] ?? [];
    $tm['last_update'][$type] = [
        'time' => date('c'),
        'pid' => getmypid(),
        'processed' => 0,
        'errors' => 0,
        'active_job' => $jobKey
    ];
    return $jobKey;
}
function updateLastUpdate(&$tm, $type, $fields = []) {
    $tm['last_update'] = $tm['last_update'] ?? [];
    $tm['last_update'][$type] = $tm['last_update'][$type] ?? [
        'time' => date('c'),
        'pid' => getmypid()
    ];
    $tm['last_update'][$type]['time'] = date('c');
    foreach ($fields as $k => $v) $tm['last_update'][$type][$k] = $v;
}
function deregisterActiveJob(&$tm, $jobKey, $type = 'processor') {
    if (isset($tm['ActiveJobs'][$jobKey])) {
        $tm['ActiveJobs'][$jobKey]['end_time'] = date('c');
        $tm['ActiveJobs'][$jobKey]['status'] = 'finished';
        // 残す or 削除する方針はここで決められる。現在は履歴として残すが active からは除去する:
        unset($tm['ActiveJobs'][$jobKey]);
    }
    // 最終更新
    $tm['last_update'] = $tm['last_update'] ?? [];
    $tm['last_update'][$type] = $tm['last_update'][$type] ?? [];
    $tm['last_update'][$type]['last_finished'] = date('c');
    $tm['last_update'][$type]['pid'] = getmypid();
}

// --- task_master の存在チェックと Receiver フラグ確認 ---
// 存在しなければ警告ログだけ出して通常処理を続行（運用上の柔軟性確保）
$taskMaster = loadTaskMaster($taskMasterPath);
$registeredJobKey = null;
if (is_array($taskMaster)) {
    // Receiver が真でなければ処理を行わない（ユーザー指示に基づく）
    $receiverFlag = $taskMaster['Receiver'] ?? null;
    if ($receiverFlag !== true) {
        // Receiver が true でない -> 作業に入らない
        echo json_encode(['success' => false, 'reason' => 'Receiver not active in API/task_master.json']);
        // 状態を inWorking=false に戻して終了
        $statusData['inWorking'] = false;
        $statusData['status'] = 'idle';
        file_put_contents($statusPath, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        exit(0);
    }

    // ActiveJobs に自ジョブを登録し、task_master.json を保存
    $registeredJobKey = registerActiveJob($taskMaster, 'processor');
    saveTaskMaster($taskMasterPath, $taskMaster);
}

// ----- メイン処理ループ -----
foreach ($dirs as $sourceDir) {
    // セッションIDの抽出
    $sessionId = str_replace($importCacheDir . '/', '', $sourceDir);
    $sessionId = str_replace(['~temp_', '^temp'], '', $sessionId);
    
    $files = glob($sourceDir . '/*.json');

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        // 基本解析チェック
        if ($data && (isset($data['name']) || isset($data['header']['id']))) {
            try {
                // 点検・整形 (UUID/IDの正規化)
                $validatedData = validateAndReconstruct($data);
                
                // 命名規則に従った新ファイル名
                $newFilename = generateNewFilename($validatedData);

                // valid_Inspected フォルダがなければ作成
                if (!is_dir($inspectedDir)) mkdir($inspectedDir, 0777, true);

                // 点検済みエリアへ保存
                $savePath = $inspectedDir . '/' . $newFilename;
                if (file_put_contents($savePath, json_encode($validatedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    $results['processed']++;
                } else {
                    throw new Exception("Write failed to inspectedDir");
                }

            } catch (Exception $e) {
                // バリデーション失敗
                moveToError($filePath, $invalidDir, "err_" . $filename);
                $results['errors']++;
            }
        } else {
            // JSONとして壊れている
            moveToError($filePath, $invalidDir, $filename);
            $results['errors']++;
        }

        // --- (中略: ステータス更新処理) ---
    }

    // 4. セッション単位の原本アーカイブ
    $sessionArchiveDir = $archiveRootDir . '/' . $sessionId;
    if (!is_dir($sessionArchiveDir)) mkdir($sessionArchiveDir, 0777, true);
    
    foreach (glob($sourceDir . '/*') as $f) {
        if (is_file($f)) rename($f, $sessionArchiveDir . '/' . basename($f));
    }
    if (is_dir($sourceDir)) rmdir($sourceDir);
}
}

// --- 実行終了時のステータス更新（履歴に積む） ---
$runEndFloat = microtime(true);
$runEndIso = date('c', (int)$runEndFloat);
$duration = max(0.0, $runEndFloat - $runStartFloat);

$processed = $results['processed'];
$errors = $results['errors'];

// メトリクス算出（分母ゼロ回避）
$itemsPerSecond = $duration > 0 ? ($processed / $duration) : null;
$itemsPerHour = $itemsPerSecond !== null ? ($itemsPerSecond * 3600.0) : null;
$avgMsPerItem = ($processed > 0 && $duration > 0) ? ($duration * 1000.0 / $processed) : null;

$runEntry = [
    'start_time' => $runStartIso,
    'end_time' => $runEndIso,
    'start_time_float' => $runStartFloat,
    'end_time_float' => $runEndFloat,
    'duration_seconds' => $duration,
    'processed' => $processed,
    'errors' => $errors,
    'items_per_second' => $itemsPerSecond,
    'items_per_hour' => $itemsPerHour,
    'avg_ms_per_item' => $avgMsPerItem
];

// 履歴は先頭に追加して最大3件保持
array_unshift($statusData['history'], $runEntry);
$statusData['history'] = array_slice($statusData['history'], 0, 3);

// current_run を更新して inWorking をオフに
$statusData['current_run']['end_time'] = $runEndIso;
$statusData['current_run']['end_time_float'] = $runEndFloat;
$statusData['current_run']['processed'] = $processed;
$statusData['current_run']['errors'] = $errors;
$statusData['current_run']['duration_seconds'] = $duration;
$statusData['current_run']['items_per_second'] = $itemsPerSecond;
$statusData['current_run']['items_per_hour'] = $itemsPerHour;
$statusData['current_run']['avg_ms_per_item'] = $avgMsPerItem;
$statusData['inWorking'] = false;

// その他メタ
$statusData['last_run'] = $runEndIso;
$statusData['status'] = 'idle';
$statusData['last_results'] = $results;

// reports は将来の拡張用（ここでは空または既存維持）
$statusData['reports'] = $statusData['reports'] ?? [];

// 最終書き込み
file_put_contents($statusPath, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// --- 追加: task_master の ActiveJobs から削除と最終更新 ---
if (is_array($taskMaster) && $registeredJobKey !== null) {
    updateLastUpdate($taskMaster, 'processor', [
        'processed' => $processed,
        'errors' => $errors
    ]);
    deregisterActiveJob($taskMaster, $registeredJobKey, 'processor');
    saveTaskMaster($taskMasterPath, $taskMaster);
}

echo json_encode(['success' => true, 'results' => $results]);

// --- ヘルパー関数 ---

/**
 * バリデーション及び構造の正規化（UUID / ID の読み分けを含む）
 */
function validateAndReconstruct($data) {
    // 1. スキーマの抽出とチェック (第一必須事項)
    $inputSchema = $data['header']['schema'] ?? $data['schema'] ?? '';
    if (!preg_match('/^MiZu_Character_Profile_v\d\.\d{4}\.\d{2}$/', $inputSchema)) {
        throw new Exception("Invalid Schema Format");
    }

    // 2. UUID / ID の読み分け
    // 入力に uuid 表記と id 表記が混在する可能性があるため両方チェックする
    $rawUuid = trim($data['header']['uuid'] ?? $data['uuid'] ?? '');
    $rawId   = trim($data['header']['id']   ?? $data['id']   ?? '');

    $cleanUuid = preg_replace('/[^a-f0-9]/i', '', $rawUuid);
    $cleanId   = preg_replace('/[^a-f0-9]/i', '', $rawId);

    $finalUuid = null; // 正規の32桁UUIDがある場合に文字列を入れる。無ければ null（あとで空文字に）
    $finalId = null;   // 中間処理用 4-4-4-4 形式（常に最終的に存在させる）

    // ルール適用順序：
    // 1) uuid 表記が 32 桁ならそれを正規UUIDとして温存（header.uuid に格納）
    //    併せて中間IDはその先頭16桁で作る
    if (strlen($cleanUuid) === 32) {
        $finalUuid = $rawUuid; // もとの表記を温存
        $finalId = implode('-', str_split(substr($cleanUuid, 0, 16), 4));
    }
    // 2) uuid 表記が 16 桁なら中間処理用 TempID とみなし、UUID は未生成（空）
    elseif (strlen($cleanUuid) === 16) {
        $finalUuid = null;
        $finalId = implode('-', str_split($cleanUuid, 4));
    }
    // 3) id 表記が 32 桁ならそれを UUID に格上げ
    elseif (strlen($cleanId) === 32) {
        $finalUuid = $rawId; // 元の id 表記を UUID として扱う（温存）
        $finalId = implode('-', str_split(substr($cleanId, 0, 16), 4));
    }
    // 4) id 表記が 16 以上なら先頭16桁を中間IDに使う（UUIDは無し）
    elseif (strlen($cleanId) >= 16) {
        $finalUuid = null;
        $finalId = implode('-', str_split(substr($cleanId, 0, 16), 4));
    }
    // 5) どちらも無効または短い場合は中間IDを新規生成（16 hex -> 4x4）
    else {
        $finalUuid = null;
        $finalId = implode('-', str_split(bin2hex(random_bytes(8)), 4));
    }

    // header.uuid は存在しない場合は空文字にする（UUID未生成は空欄）
    $headerUuidValue = $finalUuid ?? '';

    // 3. 新構造（Headerセクション統一版）へ再構成
    return [
        'header' => [
            'schema' => $inputSchema,
            'uuid' => $headerUuidValue, // 正規UUIDがあれば入る、無ければ空文字
            'id' => $finalId,           // 中間処理用ID（必ず存在）
            'generated_at' => $data['header']['generated_at'] ?? $data['generated_at'] ?? date('c')
        ],
        'name' => $data['name'] ?? 'Unknown',
        'work' => $data['work'] ?? 'Unknown',
        'profile' => $data['profile'] ?? [],
        'rating' => $data['rating'] ?? [],
        'tags' => is_array($data['tags'] ?? null) ? $data['tags'] : [],
        '性格パラメーター' => $data['性格パラメーター'] ?? [],
        'processed_at' => date('c') // 処理通過ログ
    ];
}

/**
 * ファイル名生成
 * 末尾セグメントは header.id のクリーン hex から 13-16 桁目（1-based）を取り4文字に揃える。
 * 十分な長さが無ければランダム4桁(hex)でフォールバック。
 */
function generateNewFilename($data) {
    $sanitize = fn($s) => preg_replace('/[\/\\?%*:|"<>]/', '-', $s);
    $work = $sanitize($data['work']);
    $name = $sanitize($data['name']);
    $ts = date('ymd_His');

    // header.id は 4-4-4-4 形式 (ハイフンあり) で入っている想定なのでハイフンを除去して扱う
    $rawHeaderId = $data['header']['id'] ?? '';
    $cleanHeaderId = preg_replace('/[^a-f0-9]/i', '', $rawHeaderId);
    $segment = '';

    if (strlen($cleanHeaderId) >= 16) {
        // 1-based 13-16 => 0-based index 12..15
        $segment = substr($cleanHeaderId, 12, 4);
    } else {
        // 足りない場合はランダム4桁(hex)でフォールバック
        $segment = bin2hex(random_bytes(2));
    }

    $segment = strtolower(preg_replace('/[^a-f0-9]/', '', $segment));
    if ($segment === '') {
        $segment = bin2hex(random_bytes(2));
    }

    return "{$work}_{$name}_{$ts}_{$segment}.json";
}

function moveToError($path, $errorDir, $name) {
    if (!is_dir($errorDir)) mkdir($errorDir, 0777, true);
    rename($path, $errorDir . '/' . $name);
}