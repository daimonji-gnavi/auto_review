# Auto Review

GitLab マージリクエストの自動レビューワークフロー用スクリプトとドキュメント

## 概要

レビュー管理シート(Google Spreadsheet)から対象を取得し、GitLab MRのレビューを効率化するツール群です。

## 主要ファイル

### ドキュメント
- `checkout_review_branch.md` - レビューワークフロー完全ガイド
  - レビュー管理表からの情報取得
  - MRブランチのチェックアウト手順
  - テスト項目書レビュー手順
  - カバレッジ分析方法

### スクリプト
- `get_daimonji_review.php` - レビュー管理表から担当レビューを取得
- `update_review_status.php` - レビュー管理表のステータス更新

## 前提条件

- PHP 7.4+
- Google Sheets API トークン
- GitLab API トークン (環境変数 `GITLAB_API_TOKEN`)
- jq コマンド

## 使い方

詳細は [`checkout_review_branch.md`](./checkout_review_branch.md) を参照してください。

### クイックスタート

```bash
# 1. レビュー対象を取得
php get_daimonji_review.php > /tmp/reviews.json

# 2. レビュー実施（ドキュメント参照）

# 3. ステータス更新
php update_review_status.php \
  <spreadsheetId> \
  <gid> \
  <レビュー番号> \
  <レビュアー名> \
  <ステータス>
```

## ライセンス

MIT License
