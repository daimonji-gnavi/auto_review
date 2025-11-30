<?php
/**
 * レビュー管理表の指定レビュアー列のステータスを更新
 * Usage: php update_review_status.php <spreadsheetId> <gid> <番号> <レビュアー名> <ステータス>
 */

if ($argc < 6) {
    echo "Usage: php " . basename(__FILE__) . " <spreadsheetId> <gid> <番号> <レビュアー名> <ステータス>\n";
    echo "Example: php " . basename(__FILE__) . " 19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM 1895963193 1030 daimonji 完了\n";
    exit(1);
}

$spreadsheetId = $argv[1];
$gid = $argv[2];
$number = intval($argv[3]);
$reviewer = $argv[4];
$status = $argv[5];

// レビュアー名から列を特定
$reviewerColumns = [
    'daimonji' => 'U',
    '大文字亮' => 'U',
    '奥村治貴' => 'L',
    '齋藤栞' => 'M',
    '周健太郎' => 'N',
    '山本望羽' => 'O',
    '成沢大地' => 'P',
    '大木拓朗' => 'Q',
    '野崎洋平' => 'R',
    '佐野日奈子' => 'S',
    '横山博明' => 'T',
    '齋藤周平' => 'V'
];

if (!isset($reviewerColumns[$reviewer])) {
    echo "Error: Unknown reviewer '{$reviewer}'\n";
    exit(1);
}

$column = $reviewerColumns[$reviewer];

// シート全体を取得して実際の行番号を検索
$tokenPath = __DIR__ . '/../../.github/scripts/sheets_token_001.json';
$tokenData = json_decode(file_get_contents($tokenPath), true);
$accessToken = $tokenData['access_token'];

// シートデータ取得
$sheetName = 'レビュー管理表';
$rangeAll = urlencode("{$sheetName}");
$urlGet = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$rangeAll}";
$ch = curl_init($urlGet);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken
]);
$resultGet = curl_exec($ch);
curl_close($ch);
$sheetData = json_decode($resultGet, true);

// 番号から行を検索
$rowNumber = null;
foreach ($sheetData['values'] as $index => $row) {
    if (isset($row[0]) && $row[0] == $number) {
        $rowNumber = $index + 1; // スプレッドシートは1始まり
        break;
    }
}

if ($rowNumber === null) {
    echo "Error: Review number '{$number}' not found\n";
    exit(1);
}

$range = urlencode("{$sheetName}!{$column}{$rowNumber}");

// トークン取得(再利用)
$tokenPath = __DIR__ . '/../../.github/scripts/sheets_token_001.json';
$tokenData = json_decode(file_get_contents($tokenPath), true);
$accessToken = $tokenData['access_token'];

// API URL
$url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?valueInputOption=RAW";

// リクエストデータ
$data = json_encode(['values' => [[$status]]]);

// cURL実行
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json'
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo $result;
