# 請神：SEO神 — 設定說明

## 已安裝內容

| 位置 | 內容 |
|------|------|
| **GitHub / Cloud Agent** | `.cursor/skills/` 內 8 個 marketingskills + `seo-shen` |
| **產品上下文** | `.agents/product-marketing.md` |
| **Submodule** | `.cursor/marketingskills` |

## 本機全域（Windows）

在專案根目錄執行：

```powershell
powershell -ExecutionPolicy Bypass -File tools\cursor\setup-global-marketingskills-seo.ps1
```

或請 Agent 代執行上述指令。

成功後應存在：

`C:\Users\<你>\.cursor\skills-cursor\seo-shen\SKILL.md`

## 使用方式

1. **新開** Agent 對話
2. 送一行：`<請神：SEO神>`
3. 再描述需求，例如：「稽核 WordPress.org readme 的 SEO」

退出請神（與寫代碼相同）：`<退駕>`

## 建議加入 Cursor User Rules（可選）

若希望與「寫代碼請神」同級強制，將下列整段貼到 **Cursor Settings → Rules → User Rules**：

```markdown
## 請神：SEO神

- **進入**：使用者訊息完全相等於 `<請神：SEO神>`
- **退出**：使用者訊息完全相等於 `<退駕>`
- **開啟時**：撰寫/修改與 SEO、搜尋、排名、readme 文案、Schema、AI 搜尋能見度相關工作前，必須讀取並遵守 `$HOME/.cursor/skills-cursor/seo-shen/SKILL.md`（Windows: `%USERPROFILE%\.cursor\skills-cursor\seo-shen\SKILL.md`）
```

（repo 內 `.cursor/rules/seo-shen-mode.mdc` 為專案級輔助規則。）
