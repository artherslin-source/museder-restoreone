# Bug 調查報告（P2）：merge_wp_config_files 破壞含括號的 define 值

| 項目 | 內容 |
|------|------|
| **嚴重度** | **P2** — 不阻擋 shineching / GoDaddy 典型現場（字面量 `DB_*`） |
| **版本** | 2.7.274（wp-config 延後套用修復已合入） |
| **發現環境** | Docker qa-c1 離線 QA（`getenv_docker()` wp-config） |
| **調查日期** | 2026-05-31 |
| **相關修復** | P0 `should_skip_wp_config_in_zip` + populated-host merge（已 PASS） |

---

## 1. 摘要

`Museder_Restoreone_Restore_Preflight::merge_wp_config_files()` 以正則  
`define( 'DB_*', ([^)]+) )` 擷取 destination 的 define 值。當 destination `wp-config.php` 使用 **巢狀括號**（例如 Docker 官方 `getenv_docker('WORDPRESS_DB_NAME', 'wordpress')`）時，擷取會在**第一個 `)`** 截斷，合併後寫入的 `wp-config.php` 產生 **PHP Parse error**。

**shineching.com 現場（cPanel 字面量 `define( 'DB_NAME', 'i10269493_xsip1' );`）不受影響**；單元測試 `verify-wp-config-restore-policy.php` 已覆蓋字面量 merge。

---

## 2. 重現步驟（Docker）

1. 乾淨 WordPress Docker，`wp-config.php` 保留 `getenv_docker(...)` 形式（**不**執行 `materialize-wp-config-db-literals.php`）。
2. 全站還原 shineching 種子備份，`db_then_files` + `wp_config_mode=backup` + populated profile。
3. 還原完成後檢查 docroot `wp-config.php`：

```php
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress' );
// 缺少 getenv_docker 的 closing ')'
```

4. `php -l wp-config.php` → **Parse error**；HTTP 500。

---

## 3. 根因

`includes/class-restore-preflight.php` → `merge_wp_config_files()`：

```php
preg_match( "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*([^)]+)\)/i", $dest_body, $m )
```

`[^)]+` 無法匹配含 `)` 的函式呼叫參數列表。

---

## 4. 與 P0 修復的關係

| 項目 | P0（已修） | 本 P2 |
|------|------------|--------|
| 問題 | restore-files **中途**解壓 wp-config → 連錯 DB | **完成後** merge 寫壞含括號的 define |
| shineching 現場 | **是** | **否**（字面量 wp-config） |
| 離線 QA | E2E + materialize 步驟 **PASS** | 未 materialize 時 HTTP 500 |

---

## 5. 建議 dev 方向（不含實作）

- 以括號深度掃描或 `token_get_all()` 擷取完整 `define()` 第二參數，而非 `[^)]+`。
- 或 merge 時只替換**已知字面量** `DB_*` / `$table_prefix` 行，不解析複雜 expression。

---

## 6. 證據

- 容器內：`php -l /var/www/html/wp-config.php` Parse error（未 materialize 之 E2E run）
- 對照：`tools/qa/materialize-wp-config-db-literals.php` 後 E2E **SHINECHING_PROFILE_E2E=PASS**，wp-config 為 `define( 'DB_NAME', 'wordpress_c1' );`
