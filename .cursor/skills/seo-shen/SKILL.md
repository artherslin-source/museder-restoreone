---
name: seo-shen
description: SEO神模式 — 請神：SEO神 時強制啟用。統籌 marketingskills 的 SEO 工作流（稽核、Schema、AI 搜尋、架構、內容策略）。WordPress 外掛專案須同時遵守 museder-wporg-compliance。
---

# SEO神（請神：SEO神）

當使用者訊息**完全相等**於 `<請神：SEO神>`，或後續對話處於 SEO神模式時，你必須依本 skill 與下列子 skill 執行，不得跳過。

## 啟動檢查（依序）

1. **WordPress.org 合規（本 repo）**  
   讀取並遵守 `.cursor/skills/museder-wporg-compliance/SKILL.md`（外掛 readme、官網文案、GPL、不可誤導排名承諾）。

2. **產品行銷上下文**  
   若存在則先讀：`.agents/product-marketing.md` → `.claude/product-marketing.md` → 專案內 `product-marketing` skill 指引。  
   若不存在，用 `product-marketing` skill 協助建立最小上下文（產品、受眾、關鍵字、競品、轉換目標）後再繼續。

3. **載入對應子 skill（必用 Skill 工具或讀取 SKILL.md）**  
   | 任務 | 子 skill |
   |------|----------|
   | 稽核、掉排名、技術/On-page SEO | `seo-audit` |
   | AI Overviews / ChatGPT / Perplexity 能見度 | `ai-seo` |
   | 大量模板頁、地區頁、比較頁 | `programmatic-seo` |
   | JSON-LD、Rich Results | `schema` |
   | 導覽、URL、內鏈架構 | `site-architecture` |
   | 內容主題與優先順序 | `content-strategy` |
   | 競品/替代頁 | `competitors` |

## 預設工作流（無其他指示時）

```
1. 釐清目標與範圍（站點/頁面/關鍵字）→ verify: 已寫入計畫
2. seo-audit（或針對 readme/外掛頁的精簡稽核）→ verify: 問題清單 + 優先級
3. 提出可執行修正（先 quick wins）→ verify: 每項對應檢查方式
4. 需要時：schema → ai-seo → content-strategy
5. 交付前：不得宣稱「已優化完成」除非有可驗證標準（工具、檢查清單、前後對照）
```

## Schema 偵測（強制）

不得僅用 `web_fetch`/`curl` 判定「沒有 schema」。WordPress 外掛站常用 Yoast/RankMath 等 JS 注入 JSON-LD — 依 `seo-audit` skill 使用瀏覽器、Rich Results Test 或實際 HTML 原始碼。

## 與其他模式的關係

- **`<請神：寫代碼>`**：啟用 `karpathy-guidelines`（寫碼紀律），與 SEO神可並存；寫碼時仍須遵守 wporg-compliance。
- **Superpowers**：功能開發流程；SEO神 專注搜尋與行銷可見度，不取代 brainstorming/TDD。

## 回覆使用者

進入 SEO神模式後，**第一句**簡短確認：「已進入 SEO神模式，將依 marketingskills + 外掛合規執行。」
