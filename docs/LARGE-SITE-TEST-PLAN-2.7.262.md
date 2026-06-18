# Museder RestoreOne 2.7.262 — WP 大站（>2GB）完整備份/還原測試計畫

> **版本**：museder-restoreone-2.7.262.zip  
> **前次參考**：[2.7.229 測試報告](../logs/MUSEDER_RESTOREONE_2.7.229_TEST_REPORT_2026-01-13.md)  
> **建立日期**：2026-05-19

---

## 目錄

1. [測試目標與範圍](#1-測試目標與範圍)
2. [測試環境準備](#2-測試環境準備)
3. [測試資料準備](#3-測試資料準備)
4. [測試案例總覽](#4-測試案例總覽)
5. [Phase A — 環境基線驗證](#5-phase-a--環境基線驗證)
6. [Phase B — 大站備份測試](#6-phase-b--大站備份測試)
7. [Phase C — 本機還原測試（同站）](#7-phase-c--本機還原測試同站)
8. [Phase D — 分段上傳還原測試（模擬跨站遷移）](#8-phase-d--分段上傳還原測試模擬跨站遷移)
9. [Phase E — 邊界與負面測試](#9-phase-e--邊界與負面測試)
10. [Phase F — 效能與穩定性測試](#10-phase-f--效能與穩定性測試)
11. [Phase G — 排程備份大站測試](#11-phase-g--排程備份大站測試)
12. [驗證方法與工具](#12-驗證方法與工具)
13. [測試記錄範本](#13-測試記錄範本)
14. [已知限制與注意事項](#14-已知限制與注意事項)

---

## 1. 測試目標與範圍

### 1.1 主要目標

驗證 RestoreOne 2.7.262 在 **WordPress 大站（總檔案量 >2GB）** 環境下，以下功能的正確性、完整性與穩定性：

| 功能 | 驗證重點 |
|------|---------|
| **異步備份** | >2GB 站點能完整打包（DB + wp-content），無遺漏、無靜默失敗 |
| **本機還原** | 從已有備份 ZIP 直接還原，DB 與檔案均正確復原 |
| **分段上傳還原** | 透過 Chunk v2（2MB 分段）上傳 >2GB ZIP 並還原，SHA1 校驗通過 |
| **單檔 >2GB 跳過** | 確認單一檔案超過 2GB 時被正確跳過並在 UI/log 中顯示 |
| **中斷恢復** | 備份/上傳中途中斷後可正確續傳/續做 |
| **排程備份** | WP-Cron 排程可處理大站備份 |

### 1.2 測試範圍

- **IN SCOPE**：備份、還原（本機 + 上傳）、排程、分段上傳、日誌、進度顯示、錯誤處理
- **OUT OF SCOPE**：PRO 功能（S3 雲端儲存、AI 分析）、WPRESS 格式（WP.org 版已停用）

---

## 2. 測試環境準備

### 2.1 Docker 環境規格

```yaml
# docker-compose.yml 已有的設定
services:
  db:      mariadb:10.11
  wordpress: wordpress:6.6.2-php8.2-apache  (port 8080)
```

### 2.2 PHP 限制確認（tools/docker/php.ini）

| 參數 | 值 | 說明 |
|------|-----|------|
| `upload_max_filesize` | 2048M | 分段上傳不受此限制（每段 2MB） |
| `post_max_size` | 2048M | 同上 |
| `memory_limit` | 1024M | 需足夠處理大批次 |
| `max_execution_time` | 600 | 異步處理每次 <30s，不依賴此值 |

### 2.3 磁碟空間需求

```
估算：
  測試資料檔案          ~2.7 GB
  備份 ZIP 產出         ~2.7 GB（CM_STORE 無壓縮大檔）
  分段上傳暫存          ~2.7 GB（chunk 暫存 + final.zip）
  還原解壓暫存          ~2.7 GB
  ─────────────────────
  最低需求              ~12 GB 可用空間
```

**環境啟動前確認磁碟空間**：
```bash
docker compose exec -T wordpress df -h /var/www/html
# 確認可用 > 15GB
```

### 2.4 外掛安裝

```bash
# 方法 A：從 ZIP 安裝（推薦，模擬真實用戶流程）
# 1. 將 museder-restoreone-2.7.262.zip 複製到容器
docker cp museder-restoreone-2.7.262.zip workspace-wordpress-1:/tmp/

# 2. 透過 WP-CLI 安裝
docker compose run --rm wpcli plugin install /tmp/museder-restoreone-2.7.262.zip --activate --force

# 方法 B：從 repo sync（開發模式）
docker compose exec -T wordpress bash -lc '
  src=/tmp/museder-restoreone-src
  dst=/var/www/html/wp-content/plugins/museder-restoreone
  rm -rf "$dst" && mkdir -p "$dst"
  tar -C "$src" --exclude=./logs --exclude=./docs --exclude=./dist \
    --exclude=./release --exclude=./.git --exclude=./.cursor \
    -cf - . | tar -C "$dst" -xf -
'
docker compose run --rm wpcli plugin activate museder-restoreone
```

### 2.5 版本確認

```bash
docker compose run --rm wpcli plugin get museder-restoreone --field=version
# 預期輸出：2.7.262
```

---

## 3. 測試資料準備

### 3.1 大檔案產生（總量 >2GB、每檔 <2GB）

使用 `tools/docker/make-large-uploads.sh` 的改良版本，產生更具代表性的測試資料：

```bash
docker compose exec -T wordpress bash -lc '
  set -e
  BASE="/var/www/html/wp-content/uploads"

  # ─── 區塊 A：模擬 uploads 大量圖片（混合大小）───
  mkdir -p "$BASE/test-large/media"
  for i in $(seq 1 5); do
    test -f "$BASE/test-large/media/photo-album-${i}.bin" || \
      dd if=/dev/urandom of="$BASE/test-large/media/photo-album-${i}.bin" bs=1M count=400 status=none
  done
  # 5 × 400MB = 2.0 GB

  # ─── 區塊 B：模擬 WooCommerce 產品資料 ───
  mkdir -p "$BASE/test-large/woocommerce"
  test -f "$BASE/test-large/woocommerce/product-exports.bin" || \
    dd if=/dev/urandom of="$BASE/test-large/woocommerce/product-exports.bin" bs=1M count=500 status=none
  # 1 × 500MB

  # ─── 區塊 C：模擬 Elementor 快取 ───
  mkdir -p "$BASE/test-large/elementor-cache"
  for i in $(seq 1 10); do
    test -f "$BASE/test-large/elementor-cache/cache-${i}.bin" || \
      dd if=/dev/urandom of="$BASE/test-large/elementor-cache/cache-${i}.bin" bs=1M count=30 status=none
  done
  # 10 × 30MB = 300MB

  echo "=== 測試資料摘要 ==="
  du -sh "$BASE/test-large/"
  du -sh "$BASE/test-large/media/"
  du -sh "$BASE/test-large/woocommerce/"
  du -sh "$BASE/test-large/elementor-cache/"
  find "$BASE/test-large/" -type f | wc -l
'
```

**預期結果**：總計 ~2.8GB，16 個檔案，模擬真實大站的 uploads 結構。

### 3.2 資料庫標記植入

用於備份/還原前後的 DB 一致性驗證：

```bash
docker compose run --rm wpcli option update blogname "BEFORE_BACKUP_27262"
docker compose run --rm wpcli option update museder_test_marker "before_backup_27262"
docker compose run --rm wpcli option update museder_test_timestamp "$(date +%s)"

# 新增測試用 posts（模擬大量內容站點）
for i in $(seq 1 50); do
  docker compose run --rm wpcli post create \
    --post_title="Test Post $i for 2.7.262" \
    --post_content="This is test content for large site backup validation. Post number $i." \
    --post_status=publish
done

# 記錄 baseline 統計
docker compose run --rm wpcli db query "SELECT COUNT(*) as post_count FROM wp_posts WHERE post_status='publish';"
docker compose run --rm wpcli db query "SELECT COUNT(*) as total_rows FROM wp_options;"
```

### 3.3 負面測試用：超大單檔（>2GB）

```bash
docker compose exec -T wordpress bash -lc '
  mkdir -p /var/www/html/wp-content/uploads/test-large/oversized
  # 產生一個 2.3GB 檔案（預期會被備份跳過）
  dd if=/dev/zero of=/var/www/html/wp-content/uploads/test-large/oversized/too-large-2300mb.bin \
    bs=1M count=2300 status=progress
'
```

---

## 4. 測試案例總覽

| # | Phase | 案例 | 優先級 | 預期結果 |
|---|-------|------|--------|---------|
| A1 | 基線 | 環境容量與 PHP 限制確認 | P0 | 磁碟 >15GB，memory_limit=1024M |
| A2 | 基線 | 外掛版本與 Dashboard 可存取 | P0 | 版本 2.7.262 顯示正確 |
| A3 | 基線 | Env Compatibility 面板無紅色警告 | P0 | ZipArchive 可用 |
| B1 | 備份 | 大站完整備份（>2GB 資料、每檔 <2GB） | P0 | ZIP 產出 >2GB，無跳過檔案 |
| B2 | 備份 | 備份 ZIP 內容驗證（database.ndjson 格式、meta.json、檔案完整性） | P0 | NDJSON 格式正確 |
| B3 | 備份 | 備份期間進度條顯示與更新 | P1 | 進度從 0→100%，無卡頓 >30s |
| B4 | 備份 | 備份 Log 完整性 | P1 | 記錄所有階段與耗時 |
| C1 | 還原 | 從 Available Backups 直接還原 | P0 | DB markers 回復、檔案 SHA1 一致 |
| C2 | 還原 | 還原後 WordPress 正常運作 | P0 | 前後台可存取、外掛清單正確 |
| C3 | 還原 | Restore History 記錄正確 | P1 | 時間戳與 log 一致 |
| D1 | 上傳 | 分段上傳 >2GB ZIP（Chunk v2 REST） | P0 | 上傳完成、SHA1 通過 |
| D2 | 上傳 | 上傳後自動還原完成 | P0 | DB + 檔案均回復正確 |
| D3 | 上傳 | 上傳進度：速度/ETA 顯示 | P1 | 顯示合理值 |
| D4 | 上傳 | 上傳中斷後續傳 | P1 | 重新整理後可接續上傳 |
| E1 | 邊界 | 單檔 >2GB 備份時自動跳過 | P0 | 備份完成但該檔不在 ZIP 中 |
| E2 | 邊界 | 跳過檔案的 UI 提示與 log 記錄 | P1 | 完成對話框/log 列出被跳過的檔案 |
| E3 | 邊界 | 磁碟空間不足時的錯誤處理 | P2 | 不會靜默失敗，顯示明確錯誤 |
| E4 | 邊界 | 備份中途取消 | P1 | 可正常取消，暫存檔清理 |
| E5 | 邊界 | 備份中途網頁重新整理 | P1 | 回到頁面後作業自動恢復 |
| F1 | 效能 | 備份耗時基準 | P1 | 記錄各階段耗時供回歸參考 |
| F2 | 效能 | 還原耗時基準 | P1 | 記錄各階段耗時 |
| F3 | 效能 | 記憶體峰值觀察 | P2 | 不超過 memory_limit |
| G1 | 排程 | WP-Cron 排程備份大站 | P1 | 背景完成備份，結果與手動一致 |

---

## 5. Phase A — 環境基線驗證

### A1：環境容量與 PHP 限制確認

```bash
# 磁碟空間
docker compose exec -T wordpress df -h /var/www/html

# PHP 限制
docker compose exec -T wordpress php -r "
  echo 'memory_limit: ' . ini_get('memory_limit') . PHP_EOL;
  echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . PHP_EOL;
  echo 'post_max_size: ' . ini_get('post_max_size') . PHP_EOL;
  echo 'max_execution_time: ' . ini_get('max_execution_time') . PHP_EOL;
  echo 'ZipArchive: ' . (class_exists('ZipArchive') ? 'YES' : 'NO') . PHP_EOL;
  echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
"

# WordPress 版本
docker compose run --rm wpcli core version
```

**通過條件**：
- [x] 磁碟可用 > 15GB
- [x] memory_limit = 1024M
- [x] ZipArchive = YES
- [x] PHP >= 7.4

### A2：外掛版本與 Dashboard

```bash
docker compose run --rm wpcli plugin get museder-restoreone --field=version
```

**UI 驗證**：
1. 登入 `http://localhost:8080/wp-admin/`（admin/admin）
2. 進入「Museder RestoreOne → Dashboard」
3. 確認版本號顯示 `2.7.262`

### A3：Env Compatibility 面板

**UI 驗證**：
1. Dashboard 的「Environment Compatibility」面板
2. 確認無紅色錯誤圖示
3. 確認顯示「ZipArchive available」

---

## 6. Phase B — 大站備份測試

### B1：大站完整備份（>2GB 資料，每檔 <2GB）

**前置條件**：
- 已完成 §3.1 測試資料準備（~2.8GB 測試檔案）
- 已完成 §3.2 DB 標記植入

**步驟**：
1. 進入「Museder RestoreOne → Backups」
2. 點擊「Backup Site」
3. 觀察進度條（記錄開始時間）
4. 等待完成（預計 3–15 分鐘，視磁碟 I/O）
5. 記錄完成時間與 ZIP 大小

**驗證**：
```bash
# ZIP 檔案大小確認
docker compose exec -T wordpress bash -lc '
  BACKUP_DIR=$(find /var/www/html/wp-content/uploads/backup-lite-backups -name "*.zip" -type f | sort -t- -k2 -r | head -1)
  echo "Backup file: $BACKUP_DIR"
  ls -lh "$BACKUP_DIR"
  # 預期 > 2GB
'

# ZIP 內容抽樣檢查
docker compose exec -T wordpress bash -lc '
  BACKUP_ZIP=$(find /var/www/html/wp-content/uploads/backup-lite-backups -name "*.zip" -type f | sort -t- -k2 -r | head -1)

  php -r "
    \$z = new ZipArchive();
    \$z->open(\"$BACKUP_ZIP\", ZipArchive::RDONLY);
    echo \"Total entries: \" . \$z->numFiles . PHP_EOL;

    // 確認必要檔案存在
    \$required = [\"database.ndjson\", \"meta.json\"];
    foreach (\$required as \$f) {
      \$idx = \$z->locateName(\$f);
      echo \"\$f: \" . (\$idx !== false ? \"FOUND (\" . \$z->statIndex(\$idx)[\"size\"] . \" bytes)\" : \"MISSING\") . PHP_EOL;
    }

    // 確認測試資料檔案存在
    \$test_files = [
      \"wp-content/uploads/test-large/media/photo-album-1.bin\",
      \"wp-content/uploads/test-large/media/photo-album-5.bin\",
      \"wp-content/uploads/test-large/woocommerce/product-exports.bin\",
      \"wp-content/uploads/test-large/elementor-cache/cache-1.bin\",
    ];
    foreach (\$test_files as \$f) {
      \$idx = \$z->locateName(\$f);
      echo \"\$f: \" . (\$idx !== false ? \"FOUND\" : \"MISSING\") . PHP_EOL;
    }
    \$z->close();
  "
'
```

**通過條件**：
- [ ] ZIP 大小 > 2GB
- [ ] `database.ndjson` 存在且大小 > 0
- [ ] `meta.json` 存在
- [ ] 所有 16 個測試檔案都在 ZIP 中
- [ ] 無 `skipped_files` 在非 oversized 檔案中

### B2：database.ndjson 格式驗證

```bash
docker compose exec -T wordpress bash -lc '
  BACKUP_ZIP=$(find /var/www/html/wp-content/uploads/backup-lite-backups -name "*.zip" -type f | sort -t- -k2 -r | head -1)

  php -r "
    \$z = new ZipArchive();
    \$z->open(\"$BACKUP_ZIP\", ZipArchive::RDONLY);
    \$stream = \$z->getStream(\"database.ndjson\");
    \$lines = 0;
    \$has_meta = false;
    \$has_schema = false;
    \$has_row = false;
    while ((\$line = fgets(\$stream)) !== false && \$lines < 100) {
      \$obj = json_decode(trim(\$line), true);
      if (\$obj) {
        if (isset(\$obj[\"type\"])) {
          if (\$obj[\"type\"] === \"meta\") \$has_meta = true;
          if (\$obj[\"type\"] === \"schema\") \$has_schema = true;
          if (\$obj[\"type\"] === \"row\") \$has_row = true;
        }
      }
      \$lines++;
    }
    fclose(\$stream);
    \$z->close();
    echo \"Lines sampled: \$lines\" . PHP_EOL;
    echo \"Has meta: \" . (\$has_meta ? \"YES\" : \"NO\") . PHP_EOL;
    echo \"Has schema: \" . (\$has_schema ? \"YES\" : \"NO\") . PHP_EOL;
    echo \"Has row: \" . (\$has_row ? \"YES\" : \"NO\") . PHP_EOL;
  "
'
```

**通過條件**：
- [ ] NDJSON 第一行能 json_decode
- [ ] 包含 `meta` / `schema` / `row` 三種 type
- [ ] 無純 SQL 內容（2.7.229 P0 修正確認）

### B3：進度條觀察（UI 測試）

**手動 UI 測試步驟**：
1. 開始備份
2. 觀察並記錄：
   - 進度百分比是否從 0% 起步
   - 是否有「Preparing...」→「Packing...」→「Finalizing...」階段轉換
   - 已處理檔案數 / 總檔案數 是否顯示
   - 已處理大小 / 總大小 是否顯示
   - 進度是否有持續更新（無 >30s 卡頓）
3. 截圖留存

**通過條件**：
- [ ] 進度從 ~0% 遞增到 100%
- [ ] 階段名稱正確轉換
- [ ] 無超過 30 秒的進度卡頓
- [ ] 完成對話框顯示正確的檔案大小與耗時

### B4：備份 Log 驗證

```bash
docker compose exec -T wordpress bash -lc '
  LOG_DIR=/var/www/html/wp-content/uploads/backup-lite-logs
  LATEST_LOG=$(ls -t "$LOG_DIR"/*.log 2>/dev/null | head -1)
  echo "Latest log: $LATEST_LOG"
  tail -50 "$LATEST_LOG"
'
```

**通過條件**：
- [ ] Log 包含「Backup started」記錄
- [ ] Log 包含 preparing / packing / finalizing 階段記錄
- [ ] Log 包含「Backup completed」記錄
- [ ] Log 包含最終 ZIP 大小與耗時
- [ ] Log 時間戳與 WordPress 時區一致

---

## 7. Phase C — 本機還原測試（同站）

### C1：從 Available Backups 直接還原

**前置條件**：Phase B 已產出一份 >2GB 備份

**步驟**：

1. **修改 DB 標記**（模擬資料變更）：
```bash
docker compose run --rm wpcli option update blogname "CHANGED_AFTER_BACKUP_27262"
docker compose run --rm wpcli option update museder_test_marker "after_backup_changed"

# 刪除部分測試檔案（模擬資料遺失）
docker compose exec -T wordpress rm -rf /var/www/html/wp-content/uploads/test-large/media/photo-album-3.bin
docker compose exec -T wordpress rm -rf /var/www/html/wp-content/uploads/test-large/woocommerce/

# 記錄修改後狀態
docker compose run --rm wpcli option get blogname
docker compose run --rm wpcli option get museder_test_marker
```

2. **執行還原**：
   - 進入「Museder RestoreOne → Restore」
   - 在 Restore Center 或 Available Backups 中選擇 Phase B 的備份
   - 點擊「Start Restore」
   - 觀察進度條直到完成

3. **驗證 DB 回復**：
```bash
docker compose run --rm wpcli option get blogname
# 預期：BEFORE_BACKUP_27262

docker compose run --rm wpcli option get museder_test_marker
# 預期：before_backup_27262

docker compose run --rm wpcli option get museder_test_timestamp
# 預期：備份時記錄的 timestamp
```

4. **驗證檔案回復**：
```bash
docker compose exec -T wordpress bash -lc '
  BASE=/var/www/html/wp-content/uploads/test-large

  echo "=== 檔案存在性檢查 ==="
  for f in media/photo-album-{1..5}.bin woocommerce/product-exports.bin elementor-cache/cache-{1..10}.bin; do
    if [ -f "$BASE/$f" ]; then
      echo "✅ $f EXISTS ($(stat -c%s "$BASE/$f") bytes)"
    else
      echo "❌ $f MISSING"
    fi
  done
'
```

5. **SHA1 抽樣驗證**（備份前需先記錄）：
```bash
# 備份前（在 §3.1 建立資料後立即執行並記錄）：
docker compose exec -T wordpress sha1sum \
  /var/www/html/wp-content/uploads/test-large/media/photo-album-1.bin \
  /var/www/html/wp-content/uploads/test-large/media/photo-album-5.bin \
  /var/www/html/wp-content/uploads/test-large/woocommerce/product-exports.bin

# 還原後（同樣命令）比對輸出
```

**通過條件**：
- [ ] `blogname` 回復為 `BEFORE_BACKUP_27262`
- [ ] `museder_test_marker` 回復為 `before_backup_27262`
- [ ] 所有 16 個測試檔案存在
- [ ] SHA1 抽樣 100% 一致

### C2：還原後 WordPress 正常運作

```bash
# 前台
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/
# 預期：200

# 後台
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-login.php
# 預期：200

# 外掛狀態
docker compose run --rm wpcli plugin list --status=active
# 預期：museder-restoreone 在列表中且為 active

# 文章數量
docker compose run --rm wpcli post list --post_status=publish --format=count
# 預期：與備份前記錄一致
```

**通過條件**：
- [ ] 前台 HTTP 200
- [ ] 後台可登入
- [ ] 外掛仍為 active
- [ ] 文章數量一致

### C3：Restore History 驗證

**UI 驗證**：
1. 進入「Museder RestoreOne → Restore」
2. 查看 Restore History 最新一筆
3. 確認時間戳與 log 中「Restore completed」時間一致

**Log 驗證**：
```bash
docker compose exec -T wordpress bash -lc '
  LOG_DIR=/var/www/html/wp-content/uploads/backup-lite-logs
  LATEST_LOG=$(ls -t "$LOG_DIR"/*.log 2>/dev/null | head -1)
  grep -i "restore" "$LATEST_LOG" | tail -10
'
```

---

## 8. Phase D — 分段上傳還原測試（模擬跨站遷移）

### D1：分段上傳 >2GB ZIP

**前置條件**：
- Phase B 產出的 >2GB 備份 ZIP 已下載到本機
- 或可從容器內取得路徑

**步驟**：

1. **修改 DB 標記**（同 C1 步驟 1）

2. **透過 Restore Center 上傳**：
   - 進入「Museder RestoreOne → Restore」
   - 在 Restore Center 的上傳區域選擇 Phase B 的 ZIP 檔案
   - 觀察分段上傳過程：
     - 進度百分比
     - 上傳速度（MB/s）
     - 預估剩餘時間（ETA）
     - 段落進度（chunk X/Y）

3. **記錄上傳行為**：
   - 總段落數（預期 >2GB ÷ 2MB = >1000 個段落）
   - 上傳耗時
   - 是否有 chunk 重傳
   - SHA1 校驗結果

### D2：上傳後自動還原

**步驟**：
1. 上傳完成後，系統應自動進入還原流程
2. 觀察還原進度直到完成
3. 執行與 C1 相同的 DB 和檔案驗證

**通過條件**：
- [ ] 所有 chunks 上傳完成（無遺失）
- [ ] SHA1 校驗通過
- [ ] 還原自動開始
- [ ] DB markers 回復正確
- [ ] 檔案 SHA1 抽樣一致

### D3：上傳進度 UI 驗證

**截圖/錄影記錄**：
- [ ] 顯示上傳速度（數值合理，非 0 非 NaN）
- [ ] 顯示 ETA（數值合理）
- [ ] 進度條連續遞增
- [ ] 段落計數器遞增

### D4：上傳中斷續傳

**步驟**：
1. 開始上傳 >2GB ZIP
2. 等待進度到 ~30-50%
3. **重新整理瀏覽器頁面**（F5）
4. 回到 Restore 頁面
5. 檢查是否可從中斷處續傳：
   - 查看 Status API 回傳已收到的 chunks
   - 重新選擇同一檔案上傳
   - 觀察是否跳過已上傳的 chunks

**通過條件**：
- [ ] Status API 回傳正確的 `received` chunks
- [ ] 重新上傳時跳過已有 chunks（或從 `next_missing` 開始）
- [ ] 最終上傳完成且 SHA1 通過

---

## 9. Phase E — 邊界與負面測試

### E1：單檔 >2GB 自動跳過

**前置條件**：已建立 §3.3 的 2.3GB 測試檔案

**步驟**：
1. 執行備份
2. 備份完成後檢查 ZIP 內容

```bash
docker compose exec -T wordpress bash -lc '
  BACKUP_ZIP=$(find /var/www/html/wp-content/uploads/backup-lite-backups -name "*.zip" -type f | sort -t- -k2 -r | head -1)

  php -r "
    \$z = new ZipArchive();
    \$z->open(\"$BACKUP_ZIP\", ZipArchive::RDONLY);
    \$found = \$z->locateName(\"wp-content/uploads/test-large/oversized/too-large-2300mb.bin\");
    echo \"Oversized file in ZIP: \" . (\$found !== false ? \"FOUND (BUG!)\" : \"CORRECTLY SKIPPED\") . PHP_EOL;
    \$z->close();
  "
'
```

**通過條件**：
- [ ] 備份完成（非失敗）
- [ ] 2.3GB 檔案不在 ZIP 中
- [ ] 其餘所有 <2GB 檔案仍在 ZIP 中

### E2：跳過檔案的 UI/Log 提示

**UI 驗證**：
1. 備份完成對話框中是否提及被跳過的檔案
2. 或在備份結果的詳細資訊中可見

**Log 驗證**：
```bash
docker compose exec -T wordpress bash -lc '
  LOG_DIR=/var/www/html/wp-content/uploads/backup-lite-logs
  LATEST_LOG=$(ls -t "$LOG_DIR"/*.log 2>/dev/null | head -1)
  grep -i "skip\|too_large\|oversized\|2GB" "$LATEST_LOG"
'
```

**Job payload 驗證**：
```bash
docker compose exec -T wordpress bash -lc '
  JOB_DIR=/var/www/html/wp-content/uploads/backup-lite-jobs
  LATEST_JOB=$(ls -t "$JOB_DIR"/*.json 2>/dev/null | head -1)
  if [ -n "$LATEST_JOB" ]; then
    php -r "
      \$job = json_decode(file_get_contents(\"$LATEST_JOB\"), true);
      echo \"Skipped files: \" . json_encode(\$job[\"skipped_files\"] ?? [], JSON_PRETTY_PRINT) . PHP_EOL;
      echo \"Diagnostic samples: \" . json_encode(\$job[\"diagnostic_samples\"] ?? [], JSON_PRETTY_PRINT) . PHP_EOL;
    "
  fi
'
```

**通過條件**：
- [ ] Log 或 Job payload 記錄了被跳過的檔案名稱
- [ ] 跳過原因標示為 `too_large` 或類似

### E3：磁碟空間不足測試（P2）

**步驟**：
1. 填滿磁碟至僅剩 ~500MB
2. 執行備份
3. 觀察是否有明確錯誤訊息

```bash
# 填滿磁碟（小心操作）
docker compose exec -T wordpress bash -lc '
  dd if=/dev/zero of=/tmp/fill-disk.bin bs=1M count=99999 status=progress 2>&1 || true
  df -h /var/www/html
'

# 嘗試備份...（略，手動操作）

# 清理
docker compose exec -T wordpress rm -f /tmp/fill-disk.bin
```

**通過條件**：
- [ ] 不會靜默產出損壞的 ZIP
- [ ] 顯示明確的「磁碟空間不足」錯誤
- [ ] 或在 log 中記錄錯誤

### E4：備份中途取消

**步驟**：
1. 開始大站備份
2. 在 packing 階段（進度 10~50%）點擊「Cancel Backup」
3. 確認取消成功

**通過條件**：
- [ ] 取消後狀態顯示為 cancelled
- [ ] 暫存檔案已清理（或在下次備份時清理）
- [ ] 可重新啟動新的備份

### E5：備份中途重新整理

**步驟**：
1. 開始大站備份
2. 在 packing 階段重新整理瀏覽器（F5）
3. 回到 Backups 頁面

**驗證**：
- [ ] 頁面顯示正在進行的備份作業
- [ ] 進度條從中斷處繼續
- [ ] 備份最終正常完成

---

## 10. Phase F — 效能與穩定性測試

### F1：備份耗時基準

記錄各階段耗時，建立效能基準供未來回歸對比：

```
測試環境：Docker / PHP 8.2 / MariaDB 10.11 / 1024M memory_limit
測試資料：~2.8GB, 16 files + DB (50 posts)

| 階段 | 開始時間 | 結束時間 | 耗時 |
|------|---------|---------|------|
| Preparing (DB export) | | | |
| Preparing (manifest) | | | |
| Packing | | | |
| Finalizing | | | |
| **Total** | | | |
```

### F2：還原耗時基準

```
| 階段 | 開始時間 | 結束時間 | 耗時 |
|------|---------|---------|------|
| Extract DB | | | |
| Import DB | | | |
| Restore files | | | |
| Search-replace | | | |
| Cleanup | | | |
| **Total** | | | |
```

### F3：記憶體峰值觀察

```bash
# 備份期間觀察 PHP 程序記憶體
docker compose exec -T wordpress bash -lc '
  while true; do
    ps aux | grep php | grep -v grep | awk "{print \$5, \$6}" | head -3
    sleep 5
  done
'
# 在另一個終端中斷（Ctrl+C）
```

---

## 11. Phase G — 排程備份大站測試

### G1：WP-Cron 排程備份

**步驟**：

1. **設定排程**：
   - 進入「Museder RestoreOne → Schedules」
   - 建立每日排程（或手動觸發測試排程）

2. **手動觸發 Cron**：
```bash
docker compose run --rm wpcli cron event run --all
# 或指定排程 hook
docker compose run --rm wpcli cron event run backup_lite_scheduled_backup
```

3. **等待完成並驗證**：
   - 檢查新的 ZIP 產出
   - 驗證 ZIP 大小與內容（同 B1/B2 方法）

**通過條件**：
- [ ] Cron 觸發後備份自動執行
- [ ] 產出的 ZIP 與手動備份品質一致
- [ ] Log 記錄完整

---

## 12. 驗證方法與工具

### 12.1 SHA1 完整性驗證腳本

完整版的 SHA1 驗證，備份前記錄 → 還原後比對：

```bash
#!/usr/bin/env bash
# save as: tools/docker/verify-restore-integrity.sh

set -euo pipefail

ACTION="${1:-record}"  # record | verify
HASH_FILE="/tmp/test-large-sha1-baseline.txt"
BASE="/var/www/html/wp-content/uploads/test-large"

case "$ACTION" in
  record)
    echo "[SHA1] Recording baseline hashes..."
    docker compose exec -T wordpress bash -lc "
      find $BASE -type f -name '*.bin' | sort | while read f; do
        sha1sum \"\$f\"
      done
    " | tee "$HASH_FILE"
    echo "[SHA1] Saved to $HASH_FILE ($(wc -l < "$HASH_FILE") files)"
    ;;
  verify)
    echo "[SHA1] Verifying against baseline..."
    PASS=0; FAIL=0; MISSING=0
    while IFS= read -r line; do
      expected_hash=$(echo "$line" | awk '{print $1}')
      file_path=$(echo "$line" | awk '{print $2}')
      actual=$(docker compose exec -T wordpress sha1sum "$file_path" 2>/dev/null | awk '{print $1}')
      if [ -z "$actual" ]; then
        echo "❌ MISSING: $file_path"
        MISSING=$((MISSING + 1))
      elif [ "$expected_hash" = "$actual" ]; then
        echo "✅ MATCH: $file_path"
        PASS=$((PASS + 1))
      else
        echo "❌ MISMATCH: $file_path (expected $expected_hash, got $actual)"
        FAIL=$((FAIL + 1))
      fi
    done < "$HASH_FILE"
    echo ""
    echo "=== SHA1 結果 ==="
    echo "PASS: $PASS, FAIL: $FAIL, MISSING: $MISSING"
    [ "$FAIL" -eq 0 ] && [ "$MISSING" -eq 0 ] && echo "✅ ALL PASSED" || echo "❌ FAILURES DETECTED"
    ;;
esac
```

### 12.2 自動化驗證腳本

```bash
#!/usr/bin/env bash
# save as: tools/docker/verify-db-markers.sh

set -euo pipefail

echo "[DB] Verifying database markers after restore..."

blogname=$(docker compose run --rm wpcli option get blogname 2>/dev/null | tr -d '\r')
marker=$(docker compose run --rm wpcli option get museder_test_marker 2>/dev/null | tr -d '\r')
timestamp=$(docker compose run --rm wpcli option get museder_test_timestamp 2>/dev/null | tr -d '\r')

echo "blogname: $blogname"
echo "museder_test_marker: $marker"
echo "museder_test_timestamp: $timestamp"

PASS=true

if [ "$blogname" = "BEFORE_BACKUP_27262" ]; then
  echo "✅ blogname correct"
else
  echo "❌ blogname mismatch (expected: BEFORE_BACKUP_27262, got: $blogname)"
  PASS=false
fi

if [ "$marker" = "before_backup_27262" ]; then
  echo "✅ museder_test_marker correct"
else
  echo "❌ museder_test_marker mismatch (expected: before_backup_27262, got: $marker)"
  PASS=false
fi

if [ -n "$timestamp" ]; then
  echo "✅ museder_test_timestamp exists ($timestamp)"
else
  echo "❌ museder_test_timestamp missing"
  PASS=false
fi

$PASS && echo "✅ ALL DB MARKERS VERIFIED" || echo "❌ DB MARKER VERIFICATION FAILED"
```

---

## 13. 測試記錄範本

```markdown
# Museder RestoreOne 2.7.262 大站測試記錄

## 測試資訊
- **日期**：2026-XX-XX
- **測試者**：
- **環境**：WordPress X.X / PHP 8.2 / MariaDB 10.11 / Docker
- **外掛版本**：2.7.262
- **測試資料**：~2.8GB (16 files) + 50 posts

## Phase A — 環境基線
- [ ] A1：磁碟 >15GB，memory_limit=1024M — ✅/❌
- [ ] A2：版本 2.7.262 正確 — ✅/❌
- [ ] A3：Env Compatibility 無紅色警告 — ✅/❌

## Phase B — 大站備份
- [ ] B1：ZIP 大小 ___GB，含 ___ entries — ✅/❌
  - 備份耗時：___分___秒
- [ ] B2：database.ndjson 格式正確 (meta/schema/row) — ✅/❌
- [ ] B3：進度條正常（截圖：___） — ✅/❌
- [ ] B4：Log 完整 — ✅/❌

## Phase C — 本機還原
- [ ] C1：DB markers 回復正確 — ✅/❌
  - blogname: ___
  - museder_test_marker: ___
  - SHA1 抽樣：___/___  pass
  - 還原耗時：___分___秒
- [ ] C2：WordPress 正常運作 — ✅/❌
  - 前台 HTTP: ___
  - 外掛 active: ___
  - 文章數量: ___
- [ ] C3：Restore History 時間一致 — ✅/❌

## Phase D — 分段上傳還原
- [ ] D1：上傳完成，___ chunks，SHA1 通過 — ✅/❌
  - 上傳耗時：___分___秒
- [ ] D2：還原正確（DB + files） — ✅/❌
- [ ] D3：進度 UI 正常 — ✅/❌
- [ ] D4：中斷續傳測試 — ✅/❌

## Phase E — 邊界測試
- [ ] E1：2.3GB 單檔跳過 — ✅/❌
- [ ] E2：跳過提示/Log — ✅/❌
- [ ] E3：磁碟空間不足 — ✅/❌/跳過
- [ ] E4：取消備份 — ✅/❌
- [ ] E5：重新整理後恢復 — ✅/❌

## Phase F — 效能基準
- 備份總耗時：___
- 還原總耗時：___
- 記憶體峰值：___

## Phase G — 排程備份
- [ ] G1：Cron 排程備份 — ✅/❌

## 問題記錄
| # | 描述 | 嚴重度 | 截圖/Log |
|---|------|--------|---------|
| 1 | | P0/P1/P2 | |

## 結論
- 備份：✅通過 / ❌失敗
- 還原（本機）：✅通過 / ❌失敗
- 還原（上傳）：✅通過 / ❌失敗
- 邊界測試：✅通過 / ❌失敗
- 排程：✅通過 / ❌失敗
```

---

## 14. 已知限制與注意事項

### 14.1 外掛層面

| 限制 | 說明 | 影響 |
|------|------|------|
| **單檔 >2GB 跳過** | `class-backup.php` 硬限制 `$max_file_size = 2147483648` | 超大 media/log 檔案不會被備份 |
| **上傳 4GB 上限** | Chunk v1 `MAX_FILE_SIZE = 4294967296` | 總 ZIP >4GB 需 v2 或分卷 |
| **Client-side SHA1** | `chunk-upload-v2.js` 載入整檔到 `arrayBuffer` 計算 SHA1 | 多 GB 檔案可能造成瀏覽器記憶體壓力 |
| **WP.org 版無 WPRESS** | `extract_archive()` 回傳 `wpress_not_supported` | 只能還原 ZIP 格式 |

### 14.2 環境層面

| 限制 | 說明 | 緩解 |
|------|------|------|
| **PHP memory_limit** | 預設 128M 不足，需 ≥512M | `php.ini` 已設 1024M |
| **磁碟空間** | 備份 + 還原暫存需 3–4× 資料量 | 確保 >15GB 可用 |
| **PHP max_execution_time** | 異步處理不依賴，但同步路徑受限 | `php.ini` 已設 600s |
| **MariaDB max_allowed_packet** | 大 INSERT 可能被截斷 | 使用 NDJSON 逐行匯入，不受影響 |

### 14.3 測試注意事項

1. **產生 SHA1 基線要在備份前**：一旦備份開始，不要修改任何測試檔案
2. **還原會覆蓋 wp_options**：還原後 admin 密碼會回到備份時的狀態（admin/admin）
3. **外掛自我保護**：還原不會覆蓋 `wp-content/plugins/museder-restoreone/` 目錄本身
4. **大檔案測試耗時**：預估完整跑完所有 Phase 需要 1–2 小時
5. **Docker 磁碟**：Docker volume 是持久的，重複測試前可能需要清理
