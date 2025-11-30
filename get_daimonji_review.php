<?php
/**
 * 大文字亮の未確認レビュー行を取得するスクリプト
 * - 大文字亮列が「未確認」の行を抽出
 * - 第1ソート: I列（希望期日）昇順
 * - 第2ソート: A列（#）昇順
 */

// 解析しやすいようにDeprecated等の警告は抑制
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require __DIR__ . '/../../.github/scripts/google_api_library/vendor/autoload.php';

// スプレッドシートデータを取得
$spreadsheetId = '19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM';
$sheetGid = 1895963193;

$client = new Google_Client();
$clientSecretFile = 'client_secret_001.json';
$clientSecretPath = __DIR__ . '/../../.github/scripts/' . $clientSecretFile;
if (!is_file($clientSecretPath)) {
    throw new Exception("Client secret not found: $clientSecretPath");
}
$client->setAuthConfig($clientSecretPath);
$client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);

// 保存済みトークン読み込み
$tokenBaseName = str_replace('client_secret_', 'sheets_token_', basename($clientSecretFile, '.json'));
$tokenPath = __DIR__ . '/../../.github/scripts/' . $tokenBaseName . '.json';
if (!file_exists($tokenPath)) {
    throw new Exception("Token file not found: $tokenPath");
}
$raw = json_decode(file_get_contents($tokenPath), true);
if (!is_array($raw)) {
    throw new Exception("Token JSON parse error: $tokenPath");
}
// ラップ形式 { created_at, client:{}, token:{...} } に対応
if (isset($raw['token']) && is_array($raw['token']) && isset($raw['token']['access_token'])) {
    $token = $raw['token'];
} else {
    $token = $raw; // 従来形式
}
if (!isset($token['access_token'])) {
    throw new InvalidArgumentException('Invalid token format: access_token missing');
}
$client->setAccessToken($token);

// トークン期限切れならリフレッシュ
if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
}

// Sheets サービス
$service = new Google_Service_Sheets($client);
// シートタイトルを取得
$meta = $service->spreadsheets->get($spreadsheetId);
$sheetTitle = '';
foreach ($meta->getSheets() as $sheet) {
    $props = $sheet->getProperties();
    if ($props->getSheetId() === $sheetGid) {
        $sheetTitle = $props->getTitle();
        break;
    }
}
if ($sheetTitle === '') {
    throw new Exception("指定したgid({$sheetGid})のシートが見つかりません");
}

// 全行取得（A1:V2000で十分な範囲を確保）
$range = "{$sheetTitle}!A1:V2000";
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$data = $response->getValues();

if (!$data || !is_array($data)) {
    echo "エラー: スプレッドシートデータの取得に失敗しました\n";
    exit(1);
}

// ヘッダー行を取得
$header = $data[1] ?? [];

// 大文字亮の列インデックスを探す
$daimonjiColIndex = array_search('大文字亮', $header);
if ($daimonjiColIndex === false) {
    echo "エラー: '大文字亮'列が見つかりません\n";
    exit(1);
}

echo "大文字亮の列インデックス: {$daimonjiColIndex}\n";

// データ行を抽出（ヘッダー行を除く、3行目以降）
$dataRows = [];
foreach ($data as $index => $row) {
    // 0行目はルール、1行目はヘッダー、2行目はタスクステータス
    if ($index < 3) {
        continue;
    }
    
    // A列（#）が空または「ー」の場合はスキップ
    $rowNumber = $row[0] ?? '';
    if (empty($rowNumber) || $rowNumber === 'ー') {
        continue;
    }
    
    // 大文字亮の列が「未確認」の場合のみ抽出
    $daimonjiStatus = $row[$daimonjiColIndex] ?? '';
    if ($daimonjiStatus === '未確認') {
        $dataRows[] = [
            'original_index' => $index,
            'row_data' => $row,
            'col_a' => $rowNumber, // A列: #
            'col_i' => $row[8] ?? '', // I列: 希望期日（インデックス8）
        ];
    }
}

// ソート: 第1ソートI列昇順、第2ソートA列昇順
usort($dataRows, function($a, $b) {
    // I列（希望期日）で比較
    $dateCompare = strcmp($a['col_i'], $b['col_i']);
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    
    // A列（#）で比較（数値として）
    $numA = is_numeric($a['col_a']) ? intval($a['col_a']) : 0;
    $numB = is_numeric($b['col_a']) ? intval($b['col_a']) : 0;
    return $numA - $numB;
});

// 結果を表示
echo "\n=== 大文字亮 未確認レビュー一覧 ===\n";
echo "抽出件数: " . count($dataRows) . "件\n\n";

if (empty($dataRows)) {
    echo "未確認のレビューはありません。\n";
    exit(0);
}

// ヘッダー表示
echo str_pad("#", 5) . " | ";
echo str_pad("種別", 12) . " | ";
echo str_pad("プロダクト", 12) . " | ";
echo str_pad("概要", 50) . " | ";
echo str_pad("レビュイー", 12) . " | ";
echo str_pad("依頼日", 8) . " | ";
echo str_pad("希望期日", 8) . " | ";
echo str_pad("ステータス", 12) . " | ";
echo str_pad("大文字亮", 12);
echo "\n";
echo str_repeat("-", 150) . "\n";

// データ表示
foreach ($dataRows as $item) {
    $row = $item['row_data'];
    echo str_pad($row[0] ?? '', 5) . " | "; // #
    echo str_pad(mb_substr($row[1] ?? '', 0, 10), 12) . " | "; // レビュー種別
    echo str_pad(mb_substr($row[2] ?? '', 0, 10), 12) . " | "; // プロダクト
    echo str_pad(mb_substr($row[3] ?? '', 0, 45), 50) . " | "; // 概要
    echo str_pad(mb_substr($row[6] ?? '', 0, 10), 12) . " | "; // レビュイー
    echo str_pad($row[7] ?? '', 8) . " | "; // 依頼日
    echo str_pad($row[8] ?? '', 8) . " | "; // 希望期日
    echo str_pad(mb_substr($row[10] ?? '', 0, 10), 12) . " | "; // ステータス
    echo str_pad($row[$daimonjiColIndex] ?? '', 12); // 大文字亮
    echo "\n";
}

echo "\n";

// 詳細情報をJSON形式で出力（オプション）
$detailFile = __DIR__ . '/daimonji_review_detail.json';
file_put_contents($detailFile, json_encode([
    'extract_date' => date('Y-m-d H:i:s'),
    'total_count' => count($dataRows),
    'header' => $header,
    'daimonji_col_index' => $daimonjiColIndex,
    'rows' => array_map(function($item) use ($header) {
        $row = $item['row_data'];
        $result = [];
        foreach ($header as $idx => $colName) {
            $result[$colName] = $row[$idx] ?? '';
        }
        return $result;
    }, $dataRows)
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "詳細データを保存しました: {$detailFile}\n";
