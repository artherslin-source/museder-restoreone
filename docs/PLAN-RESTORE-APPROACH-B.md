# 還原做法 B — 產品規格（已決策）

**狀態：** B3 + B2 + B1 已落地（build `2.7.267-4`）  
**關聯：** `docs/PLAN-FULL-SITE-RESTORE.md`（ZIP 兩階段檔案，2.7.267 已部分落地）、`release/2.7.265` 還原修復（MU 隔離、token、safe plugins）

## 一句話

以 **「封存 + 目的地現況」** 決定還原策略；階段為 **檢查 → 檔案 → 設定 → 資料庫 → 收尾**；大還原 **以 WP-Cron loopback 切片** 推進，還原頁只顯示進度與必要時 nudge cron。

---

## 已鎖定決策（2026-05-25）

| # | 主題 | 決策 |
|---|------|------|
| 1 | 空 docroot | **不必**完全零檔案；允許主機已裝最小 WP（有殼）。真・零檔案列 Phase 2。 |
| 2 | 執行引擎 | **WP-Cron loopback 為主**；還原頁顯示進度、必要時 nudge cron；不要求使用者開頁等到完。 |
| 3 | 已有資料站 | **強制**還原前快照（auto backup 現站，不可略過）。 |
| 4 | 還原順序 | **Step 2 使用者可選（丙）**；**預設 C = 先 DB、後檔案**（`populated_wp`）。`fresh_wp` / 空殼可用 **先檔案、後 DB**。 |
| 5 | 新裝 WP 後蓋 DB | **接受**；UI 明確告知備份 DB 會覆寫 install 建立的資料庫。 |
| 6 | wp-config | 三種：**覆蓋備份（預設）** / **保留目的地** / **合併**（`DB_*` 用目的地，其餘用備份）。 |
| 7 | WP 版本差異 | **警告**，仍**允許**覆寫核心。 |
| 8 | 僅 wp-content 封存 | 目的地空或無核心時 **阻擋**；需全站封存或先安裝 WP + 明確選「僅 wp-content」。 |
| 9 | 實作優先序 | **B3 先行**（`populated_wp` + UI/策略），再 `fresh_wp`，再真・零檔案。 |
| 10 | MU 隔離 | **P1 開始** 至 **P4 結束** 才 `exit`（與 266 一致）。 |
| 11 | 外掛隔離 UX | **S1 + S4**：全程 MU/`active_plugins` 隔離 + Step 2 勾選「還原時暫停其它外掛（建議）」**預設勾**。 |
| 12 | 大檔逾時 | **維持 cron 切片**；不以還原頁 tick 為主要動力。 |

---

## 目的地設定檔（restore_profile）

P0 分析後寫入 job meta，驅動順序與 UI：

| profile | 判定（概念） | 預設順序 | 快照 |
|---------|----------------|----------|------|
| `populated_wp` | 已有內容（文章/外掛/上傳等） | **DB → 檔案**（Step 2 可改） | **強制** |
| `fresh_wp` | 最小 WP / 剛 install、幾乎無內容 | **檔案 → DB** | 建議（可沿用 auto backup 勾選） |
| `empty_shell` | 可寫目錄、無完整核心（過渡） | **檔案 → DB**；非全站 ZIP **阻擋** | 視 overwrite |
| `subsite_archive` | Multisite 子站包（無核心） | 僅 wp-content 路徑；不強制核心 | 依情境 |

---

## 階段管線

```
P0  preflight   封存結構、目的地 profile、阻擋規則、警告（版本、overwrite）
P1  files       ZIP/WPRESS 切片（wp-content → 核心+根目錄）；self-protect；MU 隔離 ON
P2  config      wp-config 三模式；.htaccess fallback
P3  database    NDJSON/SQL；prefix migrate（可與 P1 順序對調，見 options）
P4  finalize    search-replace、flush、exit 隔離、reapply safe plugins、history
```

**順序選項（Step 2，`restore_order`）：**

- `db_then_files`（**預設**）— 先 P3 再 P1（對已有資料站縮短半新半舊）
- `files_then_db` — 做法 B 標準（新站/空殼）

實作時以 stage 編排或 sub-stage 切換，checkpoint 需標 `restore_order`。

---

## Step 2 UI（做法 B 擴充）

既有 + 新增：

| 控制項 | 預設 | 說明 |
|--------|------|------|
| 覆寫現有資料 | 使用者決定 | 已有資料站必讀警告 |
| **還原前快照** | **已有資料站強制 ON 且 disabled** | 對應決策 #3 |
| 還原 wp-config | 三選一 radio | 覆蓋 / 保留 / **合併** |
| **還原順序** | **先 DB 後檔案** | 進階；`fresh_wp` 可建議改先檔案後 DB |
| 暫停其它外掛（還原中） | **勾選** | S4；說明為暫時、結束後還原清單 |
| 還原模式 | 完整站點 / 僅 wp-content / 僅 DB | 與 profile 連動驗證 |

---

## wp-config 合併（決策 #6）

- 讀取**備份** `wp-config.php` 與**目的地**現有檔（若無則視同覆蓋備份）。
- **保留目的地：** `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_CHARSET`, `DB_COLLATE`（若存在）。
- **其餘常數與區塊：** 以備份為準（含 `AUTH_KEY` 等）；寫入前備份目的地檔至 temp（rollback 用）。
- 合併失敗 → 中止並 log，不寫半套。

---

## 阻擋與警告

| 條件 | 行為 |
|------|------|
| `empty_shell` + 封存無 `wp-admin`/`wp-includes` | **阻擋**（#8） |
| 備份 WP 版本與目的地差異大 | **警告**，允許繼續（#7） |
| `populated_wp` + 未勾 overwrite | 依產品規則：阻擋或僅檔案模式 |
| `populated_wp` | 未做還原前快照 → **不得開始**（#3） |

---

## 外掛隔離（S1 + S4）

- **S1：** `enter_mid_restore_plugin_isolation` 不晚於 **P1 開始**；`exit` 不早於 **P4**（含失敗/取消）。
- **S4：** Step 2 文案說明：還原過程暫不載入其它外掛，完成後依備份或安全啟用還原；取消勾選僅在進階情境（文件註明風險）。
- 與 WordPress.org：還原安全例外，非永久關閉他戶外掛；readme/FAQ 一句話說明。

---

## 實作 Phase（建議）

| Phase | 範圍 | 驗收 |
|-------|------|------|
| **B3-1** | P0 `restore_profile`、強制快照、`restore_order` 預設 db_then_files、僅 content 阻擋 | 已有資料站還原 E2E |
| **B3-2** | Step 2 全欄位、wp-config 三模式（含合併）、S4 勾選、版本警告 | UI + job options 寫入 meta |
| **B3-3** | stage 依 `restore_order` 編排；P1 起隔離 | Cron loopback PASS |
| **B2** | `fresh_wp` 預設 files_then_db、DB 覆寫提示、Step 2/JS preflight hints | 新站案例 |
| **B1** | `empty_shell` + 全站 ZIP：`museder-restoreone-restore-bootstrap.php`（複製到根目錄）、`class-restore-bootstrap.php`、handoff JSON、無 WP 時檔案切片 | 空 docroot + 外掛先行 |

---

## 技術備註

- 2.7.267 已具 ZIP 兩階段檔案、`restoreWpConfig`、265 隔離/token/reapply；做法 B 在此基礎改 **stage 順序與 P0/P2**。
- **B1 空 docroot：** 將外掛目錄內的 `museder-restoreone-restore-bootstrap.php` 複製到網站根目錄；備份 ZIP 放在 `wp-content/uploads/museder-restoreone/backups/`；以 bootstrap 頁啟動還原（`bootstrap-handoff.json` + loopback）直至 `wp-load.php` 可用，之後改由 WP-Cron 接手。
- 選項鍵建議：`restore_order`, `wp_config_mode` (`backup`|`keep`|`merge`), `pause_other_plugins` (bool)。
- 向後相容：`skip_config` / `restoreWpConfig` 映射到 `wp_config_mode`。

---

## 相關文件

- `docs/PLAN-FULL-SITE-RESTORE.md`
- `docs/BUG-INVESTIGATION-2026-05-25-restore-stall-76pct-2.7.264.md`
- `AGENTS.md` — 還原驗收 B（WP-Cron loopback）
