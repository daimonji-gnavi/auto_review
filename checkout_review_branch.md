# レビュー対象URLから該当ブランチをチェックアウトする手順

## 概要
レビュー管理シートからレビュー対象情報を取得し、レビュー種別に応じた手順でレビューを実施する。

## 前提条件
- 環境変数 `GITLAB_API_TOKEN` が設定されていること
- Google Sheets API トークンが設定済みであること（`.github/scripts/refresh_token.php`）
- ワークスペースのルートディレクトリが `/Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos` であること

## レビュー管理表からの情報取得と分岐

### 1. レビュー管理表から対象行を取得

**1-1. レビュー管理表の構造**

レビュー管理シート（gid=1895963193）の主要カラム：
- A列: 番号
- B列: レビュー種別（「コード」または「テスト項目書」）
- C列: 対象（システム名・機能名）
- D列: 期日
- E列: レビュー対象URL
- U列: 状態（未確認/完了）

**1-2. レビュー管理表の取得と優先順位付け**

```bash
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/.github/scripts

# トークン更新
php refresh_token.php

# レビュー担当者大文字亮（U列）が「未確認」の項目を取得
# 期日昇順でソート済み
php get_daimonji_review.php > /tmp/daimonji_reviews.json

# 取得した件数を確認
REVIEW_COUNT=$(cat /tmp/daimonji_reviews.json | jq 'length')
echo "レビュー対象: ${REVIEW_COUNT}件"

# 先頭の項目（最も期日が近い）を表示
cat /tmp/daimonji_reviews.json | jq '.[0] | {番号, レビュー種別, 対象, 期日, 状態}'
```

**出力例:**
```json
{
  "番号": "1033",
  "レビュー種別": "テスト項目書",
  "対象": "クチコミ返信店舗用画面（Figmaデザイン版）",
  "期日": "2024-11-28",
  "状態": "未確認"
}
```

**1-3. レビュー対象の特定**

期日順に並んだリストから、対象を選択（通常は先頭の項目）：

```bash
# 先頭の項目を取得（最優先）
cat /tmp/daimonji_reviews.json | jq '.[0]' > /tmp/target_review.json

# または、番号を指定して取得
REVIEW_NUMBER="1033"
cat /tmp/daimonji_reviews.json | jq ".[] | select(.番号 == \"$REVIEW_NUMBER\")" > /tmp/target_review.json

# レビュー種別とURLを確認
REVIEW_TYPE=$(cat /tmp/target_review.json | jq -r '.["レビュー種別"]')
REVIEW_URL=$(cat /tmp/target_review.json | jq -r '.["レビュー対象URL"]')
TARGET_NAME=$(cat /tmp/target_review.json | jq -r '.["対象"]')
REVIEW_NUMBER=$(cat /tmp/target_review.json | jq -r '.["番号"]')

echo "番号: $REVIEW_NUMBER"
echo "レビュー種別: $REVIEW_TYPE"
echo "対象: $TARGET_NAME"
echo "URL: $REVIEW_URL"
```

### 2. レビュー種別による分岐

**2-1. 「コード」の場合**

レビュー対象URLがGitLab MRの場合、以下の手順で通常のコードレビューを実施：

```bash
if [ "$REVIEW_TYPE" = "コード" ]; then
    echo "=== コードレビュー手順を実施 ==="
    # → 下記「コードレビュー手順（MR）」セクションへ
fi
```

**レビュー対象URL例:**
```
https://gitlab101.gnavi.co.jp/rpa_dev/gmb_batch/-/merge_requests/434?diff_id=208371
```

**2-2. 「テスト項目書」の場合**

レビュー対象URLがGoogle Spreadsheetsの場合、以下の手順でテストカバレッジレビューを実施：

```bash
if [ "$REVIEW_TYPE" = "テスト項目書" ]; then
    echo "=== テスト項目書レビュー手順を実施 ==="
    # → 下記「テスト項目書（スプレッドシート）のレビュー手順」セクションへ
fi
```

**レビュー対象URL例:**
```
https://docs.google.com/spreadsheets/d/1mJ.../edit#gid=1704186406
```

### 3. レビュー完了後の状態更新

レビュー完了後、レビュー管理表のU列(状態)を更新:

**注意:** `update_review_status.php` は `temp/20251130review/` ディレクトリ配下にあります。

```bash
# レビュー番号とレビュアー名、ステータスを指定
REVIEW_NUMBER="1030"
REVIEWER="daimonji"  # または "大文字亮"
STATUS="返信待ち"     # または "完了"

php /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php \
  19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM \
  1895963193 \
  "$REVIEW_NUMBER" \
  "$REVIEWER" \
  "$STATUS"
```

**重要な修正点:**
- 旧版の行番号計算式 `4 + (番号 - 810)` は**誤り**でした
- 実際には番号とスプレッドシート行番号は線形関係ではありません
- 修正版では全シートデータを取得して該当番号の行を検索します

**行番号の対応例:**
- 番号 #810 → スプレッドシート4行目
- 番号 #1030 → スプレッドシート1033行目(224行目ではない!)

**ステータス値の種類:**
- `未確認`: レビュー未着手
- `返信待ち`: カバレッジ不足などで開発者への返信待ち
- `完了`: レビュー完了・承認済み

---

## コードレビュー手順（MR）

### 1. レビュー対象URLの確認
レビュー管理シートから取得したURL例：
```
https://gitlab101.gnavi.co.jp/rpa_dev/gmb_batch/-/merge_requests/434?diff_id=208371
```

このURLから以下の情報を抽出：
- GitLabプロジェクト: `rpa_dev/gmb_batch`
- マージリクエスト番号: `434`

### 2. リポジトリのローカルパス探索

#### 2-1. リポジトリ名の抽出
GitLabプロジェクトパス `rpa_dev/gmb_batch` から、リポジトリ名 `gmb_batch` を抽出。

#### 2-2. ワークスペース内でディレクトリ検索

**重要:** 必ずワークスペースルートディレクトリから実行すること

```bash
# ワークスペースルートに移動
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos

# リポジトリを検索
find . -type d -name "gmb_batch" 2>/dev/null
```

**プロジェクト構造の対応例:**
- `rpa_dev` プロジェクト → `gbp` 配下
  - 例: `gbp/gmb_batch`, `gbp/gmb_api`
- `gbp_review_reply` プロジェクト → `gbp/gbp_review_reply` 配下
  - 例: `gbp/gbp_review_reply/lambda_redmine`
- `consulting` プロジェクト → `consulting` 配下
- `restmail` プロジェクト → `restmail` 配下

#### 2-3. 候補ディレクトリの確認
```bash
ls -la gbp/gmb_batch/
```

`.git` ファイルまたはディレクトリが存在すればGitリポジトリと判断。

### 3. GitLab APIでブランチ名を取得

#### 3-1. マージリクエストの詳細を取得
```bash
curl -s -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/rpa_dev%2Fgmb_batch/merge_requests/434" \
  | jq -r '.source_branch'
```

**注意点:**
- プロジェクトパスの `/` は `%2F` にURLエンコードする
- 例: `rpa_dev/gmb_batch` → `rpa_dev%2Fgmb_batch`

#### 3-2. レスポンス例
```
work/fix_monitoring_ranking_report_oki-ta_PB-10849
```

### 4. ブランチのチェックアウト

#### 4-1. リポジトリディレクトリに移動

**重要:** ワークスペースルートからの相対パスで指定

```bash
# ワークスペースルートから移動
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos
cd gbp/gmb_batch

# または絶対パスで直接移動
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/gbp/gmb_batch
```

#### 4-2. リモートブランチの取得
```bash
git fetch origin work/fix_monitoring_ranking_report_oki-ta_PB-10849:work/fix_monitoring_ranking_report_oki-ta_PB-10849
```

または、全ブランチを取得する場合：
```bash
git fetch origin
```

#### 4-3. ブランチのチェックアウト
```bash
git checkout work/fix_monitoring_ranking_report_oki-ta_PB-10849
```

#### 4-4. 確認
```bash
git log --oneline -5
```

### 5. マージリクエストの承認とコメント追加

#### 5-1. マージリクエストを承認（Approve）
```bash
curl -s -X POST -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/rpa_dev%2Fgmb_batch/merge_requests/434/approve" \
  | jq '{user_has_approved, approved, approved_by}'
```

**レスポンス例:**
```json
{
  "user_has_approved": true,
  "approved": true,
  "approved_by": [
    {
      "user": {
        "name": "DAIMONJI Ryo",
        "username": "daimonji"
      }
    }
  ]
}
```

#### 5-2. レビュー管理表のステータス更新

**MRを承認した後、必ずレビュー管理表のステータスを更新してください。**

```bash
# カバレッジ不足などで開発者へ質問・返信を求める場合
php /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php \
  19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM \
  1895963193 \
  "$REVIEW_NUMBER" \
  daimonji \
  "返信待ち"

# レビュー完了・承認済みの場合
php /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php \
  19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM \
  1895963193 \
  "$REVIEW_NUMBER" \
  daimonji \
  "完了"
```

#### 5-3. マージリクエストにコメントを追加

**重要:** レビュー内容を詳細に記載すること

**基本コメント（簡潔版）:**
```bash
curl -s -X POST -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"body": "確認いたしました。ご対応ありがとうございます！"}' \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/rpa_dev%2Fgmb_batch/merge_requests/434/notes" \
  | jq '{id, body, created_at}'
```

**詳細レビューコメント（推奨）:**
```bash
curl -s -X POST -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "body": "## レビュー完了\n\n### 変更内容\n- [主な変更内容を箇条書き]\n\n### 主な機能\n1. [機能1の説明]\n2. [機能2の説明]\n\n### 評価\n- ✅ [良い点1]\n- ✅ [良い点2]\n\n**問題点:** なし（または具体的な指摘）\n\n承認いたしました。実装お疲れさまでした！"
  }' \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/rpa_dev%2Fgmb_batch/merge_requests/434/notes" \
  | jq '{id, created_at}'
```

**レビュー内容の記載項目:**
1. **変更内容**: 変更ファイル数、追加/削除行数、主な変更箇所
2. **主な機能**: 実装された機能の概要（1〜3項目）
3. **評価**: コードの品質、構成、エラーハンドリング等の良い点
4. **問題点**: 発見した問題（なければ「なし」と明記）
5. **総評**: 承認の可否と一言コメント

**レスポンス例:**
```json
{
  "id": 758974,
  "created_at": "2025-12-01T04:22:53.950+09:00"
}
```

**チャット表示用の要約:**
レビューコメントと同じ内容をチャットにも出力し、ユーザーに確認してもらうこと。



## 自動化スクリプト例

**重要:** スクリプト実行前に必ずワークスペースルートに移動すること

```bash
#!/bin/bash
# レビュー対象URLからブランチをチェックアウト

REVIEW_URL="$1"
WORKSPACE_ROOT="/Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos"

# URLからプロジェクトパスとMR番号を抽出
PROJECT_PATH=$(echo "$REVIEW_URL" | sed -n 's|https://gitlab101.gnavi.co.jp/\([^/]*/[^/]*\)/-/merge_requests/.*|\1|p')
MR_NUMBER=$(echo "$REVIEW_URL" | sed -n 's|.*/merge_requests/\([0-9]*\).*|\1|p')

echo "プロジェクト: $PROJECT_PATH"
echo "MR番号: $MR_NUMBER"

# リポジトリ名を抽出
REPO_NAME=$(basename "$PROJECT_PATH")
echo "リポジトリ名: $REPO_NAME"

# ワークスペース内でリポジトリを検索（必ずワークスペースルートから実行）
cd "$WORKSPACE_ROOT" || exit 1
REPO_PATH=$(find . -type d -name "$REPO_NAME" -print -quit 2>/dev/null)

if [ -z "$REPO_PATH" ]; then
    echo "エラー: リポジトリが見つかりません"
    echo "ワークスペースルート: $WORKSPACE_ROOT"
    echo "検索対象: $REPO_NAME"
    exit 1
fi

# 相対パスから絶対パスに変換
REPO_FULL_PATH="${WORKSPACE_ROOT}/${REPO_PATH#./}"
echo "ローカルパス: $REPO_FULL_PATH"

cd "$REPO_FULL_PATH" || exit 1

# GitLab APIでブランチ名を取得
PROJECT_PATH_ENCODED=$(echo "$PROJECT_PATH" | sed 's/\//%2F/g')
BRANCH_NAME=$(curl -s -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
    "https://gitlab101.gnavi.co.jp/api/v4/projects/${PROJECT_PATH_ENCODED}/merge_requests/${MR_NUMBER}" \
    | jq -r '.source_branch')

echo "ブランチ名: $BRANCH_NAME"

if [ -z "$BRANCH_NAME" ] || [ "$BRANCH_NAME" = "null" ]; then
    echo "エラー: ブランチ名の取得に失敗しました"
    exit 1
fi

# ブランチをfetchしてチェックアウト
echo "ブランチを取得中..."
git fetch origin "$BRANCH_NAME:$BRANCH_NAME"

echo "チェックアウト中..."
git checkout "$BRANCH_NAME"

echo "完了: $(git branch --show-current)"
git log --oneline -5
```

### トラブルシューティング

#### レビュー管理表のステータス更新で行番号がずれる

**症状:** `update_review_status.php` で更新したセルが意図した番号と異なる行に反映される

**原因:** 旧版スクリプトの行番号計算式 `4 + (番号 - 810)` が誤り

**解決策:**
1. 最新版の `update_review_status.php` を使用(全シート検索版)
2. スクリプトの場所: `/Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php`

**確認方法:**
```bash
# 正しい行番号を確認(番号1030の例)
cat /tmp/sheet_full.json | jq -r 'to_entries[] | select(.value[0] == "1030") | "配列インデックス: \(.key), スプレッドシート行: \(.key + 1)"'
# 出力例: 配列インデックス: 1032, スプレッドシート行: 1033
```

#### レビュー管理表から取得したJSONが空または不正

**症状:** `get_daimonji_review.php` の出力が空、またはパースエラーが発生

**原因:** Google Sheets APIトークンの有効期限切れ

**解決策:**
```bash
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/.github/scripts
php refresh_token.php
# 出力: Token refreshed successfully
```

#### リポジトリが見つからない場合
1. ワークスペース構造を再確認
2. サブモジュールの可能性を確認
3. `.gitmodules` ファイルで管理されているか確認

### GitLab API認証エラー
- `GITLAB_API_TOKEN` 環境変数が設定されているか確認
- トークンの有効期限を確認
- トークンのスコープに `api` または `read_api` が含まれているか確認

### ブランチが存在しない
- MRがマージ済みまたはクローズ済みの可能性
- ブランチが削除されている可能性
- リモートリポジトリを最新化: `git fetch --all --prune`

## テスト項目書（スプレッドシート）のレビュー手順

### 概要
レビュー対象URLがテスト項目書（Google Spreadsheet）の場合、以下の手順でテストカバレッジ観点のレビューを実施します。

### 前提条件
- 環境変数 `GITLAB_API_TOKEN` が設定されていること
- `.github/scripts/spreadsheet_hyperlinks.php` が利用可能であること
- `.github/scripts/refresh_token.php` でGoogle Sheets APIトークンが更新済みであること

### 手順

#### 1. レビュー対象URLの確認
レビュー管理シートから取得したURL例：
```
https://docs.google.com/spreadsheets/d/1mJ.../edit#gid=1704186406
```

このURLから以下の情報を抽出：
- スプレッドシートID: `1mJ...`（`/d/` と `/edit` の間）
- シートGID: `1704186406`（`#gid=` の後）

#### 2. テスト項目書内のMRリンクを取得

**2-1. スプレッドシートのカラム構造を確認**

テスト項目書の典型的な構造：
- A列: No.
- B列: テスト観点
- C列: テスト項目
- D列: 結果
- E列: レビュー対象URL（MRリンクが含まれる）

**2-2. MRリンクが含まれるセルを特定**

通常、テスト項目書の上部（C5など）にMRリンクが記載されている場合が多い。
レビュー管理シートの該当行から取得したスプレッドシートの番号を基に行を特定。

**2-3. ハイパーリンク情報を抽出**

```bash
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/.github/scripts

# トークン更新
php refresh_token.php

# C5セルのハイパーリンクを取得（例）
php spreadsheet_hyperlinks.php "<spreadsheetId>" "<gid>" "5:5" | jq -r '.cells[] | select(.col == 3) | .hyperlink'
```

**出力例:**
```
https://gitlab101.gnavi.co.jp/gbp_review_reply/react/-/merge_requests/29/commits
```

**2-4. MR URLからプロジェクトとMR番号を抽出**

```bash
MR_URL="https://gitlab101.gnavi.co.jp/gbp_review_reply/react/-/merge_requests/29/commits"

# プロジェクトパスとMR番号を抽出
PROJECT_PATH=$(echo "$MR_URL" | sed -n 's|https://gitlab101.gnavi.co.jp/\([^/]*/[^/]*\)/-/merge_requests/.*|\1|p')
MR_NUMBER=$(echo "$MR_URL" | sed -n 's|.*/merge_requests/\([0-9]*\).*|\1|p')

echo "プロジェクト: $PROJECT_PATH"  # gbp_review_reply/react
echo "MR番号: $MR_NUMBER"           # 29
```

#### 3. MRブランチのチェックアウト

通常のMRレビューと同じ手順（上記「4. ブランチのチェックアウト」参照）：

```bash
# リポジトリ名を抽出
REPO_NAME=$(basename "$PROJECT_PATH")  # react

# ワークスペース内でリポジトリを検索
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos
REPO_PATH=$(find . -type d -name "$REPO_NAME" -print -quit 2>/dev/null)

# リポジトリディレクトリに移動
cd "$REPO_PATH"

# GitLab APIでブランチ名を取得
PROJECT_PATH_ENCODED=$(echo "$PROJECT_PATH" | sed 's/\//%2F/g')
BRANCH_NAME=$(curl -s -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
    "https://gitlab101.gnavi.co.jp/api/v4/projects/${PROJECT_PATH_ENCODED}/merge_requests/${MR_NUMBER}" \
    | jq -r '.source_branch')

echo "ブランチ名: $BRANCH_NAME"

# ブランチをfetchしてチェックアウト
git fetch origin
git checkout "origin/$BRANCH_NAME"
```

#### 4. MR差分の取得

```bash
# masterブランチとの差分統計を取得
git diff origin/master..HEAD --stat

# 変更ファイル一覧を取得
git diff origin/master..HEAD --name-only

# 主要な実装ファイルを確認
find src -type f \( -name "*.tsx" -o -name "*.ts" -o -name "*.js" \) | head -20
```

#### 5. ソースコードの主要機能を分析

**5-1. コンポーネント・モジュール構造を把握**

```bash
# ディレクトリ構造を確認
ls -1 src/components/ src/pages/ src/hooks/ src/store/ src/utils/ 2>/dev/null

# package.jsonで使用技術スタックを確認
cat package.json | jq '{dependencies, devDependencies}'
```

**5-2. 主要ファイルの読み込み**

実装内容を理解するために、以下のファイルを優先的に読み込む：
- エントリーポイント: `src/main.tsx`, `src/App.tsx`
- 主要コンポーネント: `src/pages/`, `src/components/`
- ビジネスロジック: `src/hooks/`, `src/store/`, `src/utils/`
- 型定義: `src/types/`

**5-3. 実装機能の抽出**

以下の観点で機能を整理：
- **UI表示**: 画面一覧、レスポンシブ対応
- **ナビゲーション**: 画面遷移、前後移動
- **フォーム**: 入力、バリデーション、送信
- **API連携**: データ取得、更新、エラーハンドリング
- **状態管理**: ストア、キャッシュ、ローカルステート
- **セキュリティ**: XSS対策、認証、権限

#### 6. テスト項目書の内容を取得

**6-1. テスト項目書の全体構造を確認**

```bash
cd /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/.github/scripts

# テスト項目書の内容を取得
php spreadsheet_simple.php "<spreadsheetId>" "<gid>" > /tmp/test_items.json

# テスト項目数を確認
cat /tmp/test_items.json | jq 'length'

# 主要カラムを確認
cat /tmp/test_items.json | jq '.[0] | keys'
```

**6-2. テスト観点の分類**

テスト項目を以下の観点で分類して集計：
- UI表示テスト
- ナビゲーションテスト
- バリデーションテスト
- API連携テスト
- エラーハンドリングテスト
- セキュリティテスト
- レスポンシブテスト
- パフォーマンステスト

```bash
# テスト観点ごとの件数を集計
cat /tmp/test_items.json | jq -r '.[] | .["テスト観点"]' | sort | uniq -c
```

#### 7. カバレッジ分析（実装 vs テスト項目）

**7-1. 実装機能とテスト項目の対応表を作成**

| 実装機能 | ソースコード | テスト項目 | カバレッジ判定 |
|---------|-------------|-----------|---------------|
| UI表示 | ReviewsList.tsx, ReviewDetail.tsx | UI表示テスト 20件 | ✅ カバー済み |
| ナビゲーション | useNavigate, chevron | ナビゲーションテスト 15件 | ✅ カバー済み |
| バリデーション | buildCustomFields | バリデーションテスト 10件 | ✅ カバー済み |
| API連携 | useReviews, useIssue | API連携テスト 12件 | ⚠️ 境界値テスト不足 |

**7-2. テストカバレッジ不足の抽出**

以下の観点で不足を確認：

**🔴 重大な不足（必ず追加すべき）:**
1. **境界値テスト**
   - ページネーション処理（0件、1件、limit件、limit+1件）
   - 配列の端での検索（最初、最後、存在しない）
   - 空文字 vs null vs undefined の区別

2. **異常系テスト**
   - 不正なパラメータ（存在しないID、不正な文字列）
   - カスタムフィールド未発見エラー
   - API 4xx/5xx エラー

3. **状態遷移テスト**
   - キャッシュ無効化（forceRefetch）
   - ステータス更新の即時UI反映
   - 複数画面間のデータ同期

**🟡 中程度の不足（推奨追加）:**
4. **エッジケーステスト**
   - 特殊文字を含むデータ
   - 極端に長い文字列
   - 同時実行・競合状態

5. **パフォーマンステスト**
   - 大量データ表示
   - メモ化の効果検証
   - 不要な再レンダリング

**7-3. レビュー結果のまとめとレビュー管理表の更新**

テストカバレッジレビュー結果をMarkdown形式で整理:

**重要:** レビュー結果に応じてレビュー管理表のステータスを更新してください。

```bash
# カバレッジ不足があり、開発者へ追加テスト依頼する場合
php /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php \
  19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM \
  1895963193 \
  "$REVIEW_NUMBER" \
  daimonji \
  "返信待ち"

# テストカバレッジが十分で、承認する場合
php /Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos/temp/20251130review/update_review_status.php \
  19br2ZMGjh986ko_HdFMxetkJVcYPd6-Wg6FMv8hd9hM \
  1895963193 \
  "$REVIEW_NUMBER" \
  daimonji \
  "完了"
```

```markdown
## MR#XX テストカバレッジ分析結果

### 実装されている主要機能
- [機能1]: [ソースファイル名]
- [機能2]: [ソースファイル名]

### テスト項目書の構成
- 総テスト項目数: XX件
- 正常系: XX件
- 異常系: XX件
- 境界値: XX件

### カバレッジ分析
| テスト観点 | 実装確認 | カバレッジ判定 |
|---|---|---|
| UI表示 | ✅ | ✅ カバー済み |
| API連携 | ✅ | ⚠️ 境界値テスト不足 |

### テストカバレッジ不足の指摘

#### 🔴 重大な不足（必ず追加すべき）
1. **[機能名]の境界値テスト**
   - 実装箇所: [ファイル名:行番号]
   - 不足内容: [具体的なテストケース]
   - 追加推奨: [テスト項目の具体例]

#### 🟡 中程度の不足（推奨追加）
2. **[機能名]のエラーハンドリング**
   - 実装箇所: [ファイル名:行番号]
   - 不足内容: [具体的なテストケース]
   - 追加推奨: [テスト項目の具体例]

### 総括
既存テスト項目XX件は基本機能を十分カバーしていますが、
以下のX項目の追加を推奨します：
- 境界値テスト: X項目
- 異常系テスト: X項目
- 状態遷移テスト: X項目

**推奨追加テスト数: X項目**

**結論**: [総評]
```

#### 8. レビュー完了処理

通常のMRレビューと同様に、承認とコメントを実施：

```bash
# MRを承認
curl -s -X POST -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/${PROJECT_PATH_ENCODED}/merge_requests/${MR_NUMBER}/approve"

# テストカバレッジレビュー結果をコメント
curl -s -X POST -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "body": "## テストカバレッジレビュー完了\n\n[上記の分析結果を貼り付け]"
  }' \
  "https://gitlab101.gnavi.co.jp/api/v4/projects/${PROJECT_PATH_ENCODED}/merge_requests/${MR_NUMBER}/notes"
```

### テスト項目書レビュー自動化スクリプト例

```bash
#!/bin/bash
# テスト項目書からMRリンクを抽出してカバレッジレビュー

SPREADSHEET_ID="$1"
SHEET_GID="$2"
LINK_CELL_RANGE="$3"  # 例: "5:5"（C5セル）

WORKSPACE_ROOT="/Users/daimonji/Library/CloudStorage/GoogleDrive-daimonji@gnavi.co.jp/マイドライブ/10Git4win/repos"
SCRIPTS_DIR="$WORKSPACE_ROOT/.github/scripts"

cd "$SCRIPTS_DIR" || exit 1

# 1. トークン更新
echo "=== Google Sheets APIトークン更新 ==="
php refresh_token.php

# 2. MRリンクを取得
echo "=== MRリンク取得 ==="
MR_URL=$(php spreadsheet_hyperlinks.php "$SPREADSHEET_ID" "$SHEET_GID" "$LINK_CELL_RANGE" \
  | jq -r '.cells[] | select(.hyperlink != null) | .hyperlink' | head -1)

if [ -z "$MR_URL" ]; then
    echo "エラー: MRリンクが見つかりません"
    exit 1
fi

echo "MR URL: $MR_URL"

# 3. プロジェクトパスとMR番号を抽出
PROJECT_PATH=$(echo "$MR_URL" | sed -n 's|https://gitlab101.gnavi.co.jp/\([^/]*/[^/]*\)/-/merge_requests/.*|\1|p')
MR_NUMBER=$(echo "$MR_URL" | sed -n 's|.*/merge_requests/\([0-9]*\).*|\1|p')

echo "プロジェクト: $PROJECT_PATH"
echo "MR番号: $MR_NUMBER"

# 4. リポジトリを検索してチェックアウト
REPO_NAME=$(basename "$PROJECT_PATH")
cd "$WORKSPACE_ROOT" || exit 1
REPO_PATH=$(find . -type d -name "$REPO_NAME" -print -quit 2>/dev/null)

if [ -z "$REPO_PATH" ]; then
    echo "エラー: リポジトリが見つかりません: $REPO_NAME"
    exit 1
fi

cd "$REPO_PATH" || exit 1

# 5. ブランチ取得とチェックアウト
PROJECT_PATH_ENCODED=$(echo "$PROJECT_PATH" | sed 's/\//%2F/g')
BRANCH_NAME=$(curl -s -H "PRIVATE-TOKEN: ${GITLAB_API_TOKEN}" \
    "https://gitlab101.gnavi.co.jp/api/v4/projects/${PROJECT_PATH_ENCODED}/merge_requests/${MR_NUMBER}" \
    | jq -r '.source_branch')

echo "ブランチ名: $BRANCH_NAME"

git fetch origin
git checkout "origin/$BRANCH_NAME"

# 6. 差分統計
echo "=== MR差分統計 ==="
git diff origin/master..HEAD --stat

# 7. テスト項目書を取得
echo "=== テスト項目書取得 ==="
php "$SCRIPTS_DIR/spreadsheet_simple.php" "$SPREADSHEET_ID" "$SHEET_GID" > /tmp/test_items.json
TEST_COUNT=$(cat /tmp/test_items.json | jq 'length')
echo "テスト項目数: $TEST_COUNT"

# 8. カバレッジ分析（手動で実施）
echo "=== カバレッジ分析を実施してください ==="
echo "1. ソースコードの主要機能を分析"
echo "2. テスト項目との対応を確認"
echo "3. 不足しているテスト項目を特定"
echo "4. レビュー結果をMarkdownで作成"
```

### トラブルシューティング

#### MRリンクが取得できない
- セル範囲の指定を確認（例: "5:5" は5行目全体）
- ハイパーリンクが正しく設定されているか確認
- Google Sheets APIトークンの有効期限を確認

#### テスト項目書のシートGIDが見つからない
- スプレッドシートIDとシートGIDの組み合わせを確認
- シートが削除されていないか確認
- アクセス権限を確認

#### カバレッジ分析の粒度
- テスト項目が多い場合は、テスト観点ごとにグルーピング
- 実装ファイルが多い場合は、主要コンポーネントに絞って分析
- 境界値テストと異常系テストを優先的に確認

## 参考情報

### GitLab API エンドポイント
- マージリクエスト詳細: `GET /api/v4/projects/:id/merge_requests/:merge_request_iid`
- ドキュメント: https://docs.gitlab.com/ee/api/merge_requests.html

### プロジェクトパスとローカルパスの対応例
| GitLabプロジェクト | ローカルパス |
|-------------------|-------------|
| `rpa_dev/gmb_batch` | `gbp/gmb_batch` |
| `rpa_dev/consulting_report` | `consulting/consulting_report` |
| `restmail/r` | `restmail/r` |
| `plan_dev_manage/auto_exec` | `plan_dev_manage/auto_exec` |
| `gbp_review_reply/react` | `gbp/gbp_review_reply/react` |
