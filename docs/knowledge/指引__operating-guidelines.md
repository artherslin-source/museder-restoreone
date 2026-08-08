# 操作指引（Operating Guidelines）— 現行準則

> **權威等級**：與本檔衝突的舊「修正指引」以 [`EVOLUTION-INDEX.md`](./EVOLUTION-INDEX.md) 標註的 **現行** 為準。  
> **對象**：後續開發 Agent／工程師。產品版本基線：**2.7.262**。

---

## A. 改程式前（強制）

1. 確認是否動到：路徑拼接、下載、chunk、備份 packing／finalize、REST／AJAX nonce、對外 HTTP、readme 承諾功能。  
2. 對照現行合規清單：`docs/wp-compliance-checklist.md` + 最近一輪未關閉的 P0（見 `docs/2026-05-06__v2.7.258*`／`259*`）。  
3. Free 版**不得**用付費鎖擋備份／還原／排程主路徑。

## B. 備份／還原工程紅線

| 規則 | 說明 |
|---|---|
| 路徑 | 使用者可控片段一律經 helper；根目錄判定用 `realpath` + **trailing-slash prefix**。 |
| ZipArchive vs PclZip | `pack_method === 'pclzip'` 時**禁止**對同一 archive 長時間持有 `ZipArchive` open handle。 |
| 驗證失敗重包 | 驗證要比對條目時容忍常見命名差異；避免假陰性觸發全量 repack。 |
| 進度語意 | 95% 附近是 finalizing；log 應能區分 packing／verify／repack。 |
| >2GB 單檔 | 略過並寫入 skip 統計（readme 已聲明）。 |

## C. Docker／功能測試

```bash
# 小站（乾淨站）
# 埠衝突時改 MR_FT_HOST_PORT + MR_FT_WP_URL
./tools/functional-test/run-clean-small-site-ft.sh

# 大站：同一套 8080 根 compose（務必 pin COMPOSE_FILE）
COMPOSE_PROJECT_NAME=museder-restoreone \
MR_FT_SKIP_SMALL=1 MR_FT_RUN_LARGE=1 \
./tools/functional-test/run-functional-test.sh
```

- 細節：`tools/functional-test/README.md`。  
- Plugin Check：`./tools/run-wp-plugin-check-docker.sh`。  
- 打包：`./create-package.sh` → `dist/museder-restoreone-<version>.zip`。

## D. 版號與文件同步（發行前）

每升一版至少同步：

1. `museder-restoreone.php`：`Version`、`MUSEDER_RESTOREONE_VERSION`、`MUSEDER_RESTOREONE_BUILD_ID`  
2. `readme.txt`：`Stable tag`、Changelog、Upgrade Notice  
3. `reports/museder-restoreone-<ver>-validation.md`（有跑測才寫）  
4. 本知識庫：`EVOLUTION-INDEX.md` 新增一列（重大變更時）

## E. 正式站除錯最小採集

1. 外掛 Logs 當日檔（或使用者提供的 `logs/*-debug/`）。  
2. 搜尋關鍵字：`Archive verify`、`repack`、`Closed ZipArchive`、`Time budget`、`cancelled`。  
3. 確認實際運行版本／build id（log 開頭 heartbeat）。  
4. 勿先假設「UI 卡住」；先對 stage／pack_method。

## F. Git／GitHub

- 知識庫與 Skill **應進版控**（本目錄 + `.cursor/skills/museder-restoreone-release-ops/`）。  
- Session 摘要放 `docs/knowledge/session-digests/`（**不要**用 `archive/` 目錄名——根 `.gitignore` 含 `archive/`）。  
- `logs/`、`*.zip`、`dist/` 巨型檔、`.worktrees/` 依 `.gitignore` 不推。  
- 勿 force-push `main`／`master`。
