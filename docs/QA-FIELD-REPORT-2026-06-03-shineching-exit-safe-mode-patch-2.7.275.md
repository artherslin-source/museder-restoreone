# shineching Exit Safe Mode 修補部署與驗證報告（2026-06-03）

## 摘要

| 項目 | 結果 |
|---|---|
| 部署範圍 | 僅 `shineching.com` docroot（未動 wp-config prefix） |
| 版本 | 2.7.275（4 檔 patch，非全量 zip） |
| Hash 驗證 | **PASS**（本地 = 主機） |
| 修補標記 | `wp_ajax_nopriv_*`、`backup_runtime_auth_files`、`verify_restore_progress_request` |
| 主機 table_prefix | `v4rg_`（未修改） |
| CLI 全量還原 E2E | **PASS**（job `rjb_20260603_163224_m3jtce`，7 slices） |
| post-complete token 驗證 | **PASS** |
| Exit Safe Mode grant 測試 | **PASS** |

---

## 部署檔案與 SHA256

| 檔案 | SHA256 |
|---|---|
| `assets/js/admin.js` | `b65795d1891a8d902cc3e47d69bb21f82154480eeb138011451d79cc3fb1c872` |
| `includes/class-restore-handler.php` | `1f63970f3a26fa7f757bd2593545ad454a663e06b639d7980c09090b5fae18b5` |
| `includes/class-restore-token.php` | `7f7241dd00a1c18820b4cc98ae94ddd512daa43d95fd2036285de465c488aa2f` |
| `includes/class-restore-service.php` | `64c23099ebfd580be86773aa5d9bf6a39c4d259a02ce848bf2c7af0ebc767c0a` |

---

## 驗證執行

腳本：`tools/qa/run-shineching-exit-safe-mode-patch-deploy.ps1`  
證據目錄：`docs/qa-evidence/shineching-exit-safe-mode-patch-20260603/`

### E2E 關鍵輸出

```
PASS	restore_prepared rjb_20260603_163224_m3jtce
PASS	restore_token_captured len=64
PASS	restore_completed loop=7 stage=done
PASS	verify_post_complete_read_ok
PASS	restore_post_complete_read_is_valid_ok
PASS	cli_exit_safe_mode_ok
exit_safe_mode_grant_test=PASS
SHINECHING_FIELD_E2E=PASS
```

---

## 請你做的最後一步（瀏覽器 UI）

伺服器 E2E 已在 grant 測試中清除 Safe Mode。請你在 wp-admin 再跑一次 **UI 還原**（Safe Mode 開啟），完成後點 **Exit Safe Mode**：

1. 預期：成功 toast + 頁面 reload，**不再**出現 `An unexpected error occurred`
2. 若仍失敗：DevTools → Network → `admin-ajax.php` POST，確認 body 含 `job_id` + `restore_token`，HTTP 應為 200 JSON success

---

## 相關文件

- 根因調查：[`docs/BUG-INVESTIGATION-2026-06-03-shineching-exit-safe-mode-manual-retest-2.7.275.md`](BUG-INVESTIGATION-2026-06-03-shineching-exit-safe-mode-manual-retest-2.7.275.md)
