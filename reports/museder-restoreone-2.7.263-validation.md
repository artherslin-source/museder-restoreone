# Museder RestoreOne v2.7.263 — WordPress 7.0.3 驗證紀錄

## 變更

- **Tested up to: 7.0**（主檔 + `readme.txt`）。
- Docker：`wordpress:7.0-php8.2-apache`（根 compose、小站／Multisite／isolated FT）。
- FT helper：`ft_ensure_wp_core_version` 將 core pin 到 **7.0.3**（官方 image 當下為 7.0.2）。
- `tools/docker/setup.sh` 安裝後同樣會嘗試升到 7.0.3。

## 本機 Docker 證據

| 項目 | 指令 / 堆疊 | 結果 |
|------|-------------|------|
| 小站 | `COMPOSE_PROJECT_NAME=restoreone-ft-263 MR_FT_HOST_PORT=8290 MR_FT_WP_URL=http://localhost:8290 MR_FT_WP_CORE_VERSION=7.0.3 ./tools/functional-test/run-clean-small-site-ft.sh` | **通過**：core **7.0.3**、Plugin Check 0 errors、迴歸、chunk/REST/矩陣、mail、nginx、PclZip minimal、ZIP 乾淨安裝、Multisite uninstall。Log：`reports/ft-small-restoreone-ft-263-20260810-184758.log` |
| 大站 8080 | `setup.sh` 後 `MR_FT_SKIP_SMALL=1 MR_FT_RUN_LARGE=1` + pin 7.0.3 | **通過**：core **7.0.3**、Plugin Check 0 errors、端點矩陣、mail、`make-large-uploads`、>2GB skip。`wp-content` ≈ **3.3G**（本次 volume 為新建後重建；非先前 ~40G 舊卷）。Log：`reports/ft-large-20260810-190519.log` |

## 版號／封裝

- Plugin：**2.7.263** / build `2.7.263-1`
- ZIP：`dist/museder-restoreone-2.7.263.zip`

## 注意

- Docker Hub 截至測試當日**尚無** `wordpress:7.0.3-php8.2-apache` tag；以 `7.0-php8.2-apache` + `wp core update --version=7.0.3` 達成。
- 升級大站 image／重建 volume 時，既有大型資料卷可能被清空；正式站測試請另備份。
