# Museder RestoreOne — 知識庫入口（心得／指引／Skill）

**歸檔日**：2026-08-08  
**對應產品版本**：Free **2.7.262**（build `2.7.262-1`）  
**定位**：開發倉庫內的「可複用決策記憶」；**不**進入 WordPress.org 發行 ZIP（`create-package.sh` 排除 `docs/`）。

---

## 讀哪個檔？

| 你想做的事 | 讀這個 |
|---|---|
| 快速對齊「現在該怎麼做」 | [`指引__operating-guidelines.md`](./指引__operating-guidelines.md) |
| 了解踩過的坑與取捨 | [`心得__lessons-learned.md`](./心得__lessons-learned.md) |
| 看版本／文件怎麼演化、有無衝突 | [`EVOLUTION-INDEX.md`](./EVOLUTION-INDEX.md) |
| 讓 Cursor Agent 自動套用作業流程 | [`.cursor/skills/museder-restoreone-release-ops/SKILL.md`](../../.cursor/skills/museder-restoreone-release-ops/SKILL.md) |
| 本輪對話／正式站卡關摘要 | [`session-digests/2026-08-08__v2.7.262__session-digest.md`](./session-digests/2026-08-08__v2.7.262__session-digest.md) |

---

## 與既有文件的關係（一句話）

- **不取代** `docs/wp-compliance-checklist.md`、各版「修正指引給開發 AI」、`tools/functional-test/README.md`。  
- **本目錄是索引＋濃縮操作層**：衝突時以 [`EVOLUTION-INDEX.md`](./EVOLUTION-INDEX.md) 的「現行準則」欄為準，舊文檔保留作歷史證據。  
- 目錄分層原則延續 [`docs/PROJECT-ORGANIZATION-PLAN.md`](../PROJECT-ORGANIZATION-PLAN.md)（核心程式不搬；`docs/`／`logs/`／`tools/` 為開發產物）。

---

## 維護規則

1. 每重大版本（尤其 WP.org 退審往返、正式站重大 bugfix）至少更新：`EVOLUTION-INDEX.md` 一列 + 必要時 `session-digests/` 一篇 digest。  
2. 若新決策**推翻**舊指引：在演化索引標「**supersedes**」，**不要刪舊檔**。  
3. Skill 只放「可執行檢查清單」；長篇背景放 `心得`／`session-digests`。  
4. **勿**使用目錄名 `archive/`（根 `.gitignore` 有 `archive/` 規則會擋版控）；session 摘要請放 `session-digests/`。
