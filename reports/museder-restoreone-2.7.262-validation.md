# Museder RestoreOne v2.7.262 — 驗證／修復紀錄

## 變更摘要

- **Backup／finalize**：PclZip 相容重包時，async job runner **不再**對同一 `.zip` 長時間持有 `ZipArchive` handle（避免超慢 `close()` 與中央目錄風險）。  
- **Backup／verify**：新增 `zip_archive_has_entry()`，降低關檔後驗證假陰性觸發全量 repack。  
- **知識庫歸檔**：`docs/knowledge/*` + 專案 Skill `.cursor/skills/museder-restoreone-release-ops/`。

## 觸發證據（正式站）

- Log 目錄：`logs/140514-debug/`（job `63ca4baf-bbc2-4ca5-a33b-207908cdfee8`）。  
- 現象：packing 完成 → verify fail → PclZip repack → UI 約 95% 長時間無感進度 → 使用者取消。

## 封裝

- `./create-package.sh` → `dist/museder-restoreone-2.7.262.zip`（掃描 OK）。  
- Build id：`2.7.262-1`。

## 建議複驗（部署後）

- [ ] 正式站安裝 2.7.262 後重跑大站完整備份至 **completed**。  
- [ ] Log 不應再出現「verify fail → 長時間 Opened ZipArchive during pclzip packing」模式。  
- [ ] 可選：`MR_FT_SKIP_SMALL=1 MR_FT_RUN_LARGE=1 ./tools/functional-test/run-functional-test.sh`（8080）。
