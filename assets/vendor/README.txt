Third-party libraries (bundled)
================================

This directory ships **vendored** front-end libraries used only from local plugin URLs (`plugins/museder-restoreone/assets/vendor/…`). They are **not** loaded from third-party CDNs in the distributed build.

Chart.js v4.5.1 (MIT)
---------------------

* **Project / license:** https://github.com/chartjs/Chart.js — MIT License (see upstream `LICENSE.md`).
* **Files in this plugin:**
  * `chart.4.5.1.min.js` — UMD **minified** build (Dashboard charts).
  * `chart.4.5.1.js` — UMD **unminified** build (readable source / diff reference for reviewers).
* **Upstream distribution URLs used when refreshing these files:**
  * https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js
  * https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js

Toastify JS v1.12.0 (MIT)
-------------------------

* **Project / license:** https://github.com/apvarun/toastify-js — MIT License.
* **Files in this plugin:** `toastify.min.js`, `toastify.min.css`
* **Notes:** Minified distribution as obtained from the upstream project / npm package; keep versions in sync when updating.
