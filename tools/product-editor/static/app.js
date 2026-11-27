(() => {
  "use strict";

  const state = {
    file: "",
    filename: "",
    headers: [],
    products: [],
    categories: [],
    tags: [],
    attribute_tokens: [],
    attribute_groups: {},
    variation_headers: [],
    variations: [],
    lists: [],
    filebird: [],
    paths: {},
    images_root: "",
    dirty: false,
    filter: "all",
    search: "",
    lastFileBird: "",
    localImages: { groups: {}, total: 0 },
    errorsByRow: new Map(), // rowIdx -> Map(colIdx -> [msg])
    warningsByRow: new Map(), // rowIdx -> Map(colIdx -> [msg]) (actionable warnings)
    warnBuckets: {}, // bucketKey -> Set(rowIdx)
    sharedImageGroups: [], // [{ key, token, label, rows: [rIdx, ...] }]
    warnTypeFilter: null, // optional specific bucketKey when clicked from Warnings modal
    autoFixableCount: 0,
    sortCol: -1,
    sortDir: 0, // 0 none, 1 asc, -1 desc
    colFilters: new Map(), // colIdx -> Set(allowed values)
    selAnchor: null, // { visRow, col }
    selEnd: null, // { visRow, col }
    selectedCells: new Set(), // "visRow:col" keys (supports Ctrl+Click multi-select/unselect + drag + Shift+Click)
    isDraggingSel: false,
    dragBaseCells: new Set(),
    visibleRows: [], // array of real row indices currently displayed
    undoStack: [],
    redoStack: [],
    frozenCols: (() => {
      try {
        const raw = localStorage.getItem("odella_frozen_cols");
        if (raw) {
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) return parsed.map((x) => String(x).toLowerCase());
        }
      } catch {}
      return ["id", "sku", "name"];
    })(),
    colWidths: new Map(), // colIdx -> px
    rowHeights: new Map(), // realRowIdx -> px
    frozenRows: new Set(), // realRowIdx pinned to top (ignores search/filters)
    frozenRowsFile: "",
    hiddenCols: new Set(), // lowercased header names cosmetically hidden
    deferredValidationActive: false,
    ratioBadRows: new Set(), // realRowIdx
    langView: "all", // all | ka | en
    category_en_map: {}, // ka_lower -> en
    settings: {
      theme: "dark",
      ui_lang: "en",
      font_size: 13,
      zoom: 100,
      row_density: "normal",
      extra_blank_rows: 50,
      auto_backup: true,
      gemini_api_key: "",
      gemini_model: "gemini-3.8-flash",
      gemini_batch_size: 12,
      gemini_prompt: "",
      update_repo: "NMeJa/odella-sheet-editor-releases",
    },
    varSaleDiscounts: {},
    baselineVariations: new Map(),
    appInfo: { version: "1.1.0", is_frozen: false, update_repo: "NMeJa/odella-sheet-editor-releases" },
    updateInfo: null,
    defaultPrompt: "",
    translateCancelled: false,
  };

  const UI_I18N = {
    en: {
      import: "Import",
      reload: "Reload",
      add_product: "+ Add",
      warnings: "Warnings Summary",
      errors: "Errors",
      translate: "✨ Translate",
      auto_match: "Match Images",
      local_images: "Local Images",
      fix_zip: "Fix Naming & Zip",
      save_as: "Save As",
      save: "Save",
      settings_title: "Settings",
    },
    ka: {
      import: "იმპორტი",
      reload: "განახლება",
      add_product: "+ დამატება",
      warnings: "გაფრთხილებები",
      errors: "შეცდომები",
      translate: "✨ თარგმნა",
      auto_match: "ფოტოების მიბმა",
      local_images: "ლოკალური ფოტოები",
      fix_zip: "სახელები & Zip",
      save_as: "შენახვა როგორც",
      save: "შენახვა",
      settings_title: "პარამეტრები",
    },
  };

  // Optional WooCommerce fields — rendered as smart controls ONLY when present in the opened file
  const WC_DROPDOWNS = {
    "stock status": ["", "instock", "outofstock", "onbackorder"],
    "manage stock": ["", "yes", "no"],
    "backorders": ["", "no", "notify", "yes"],
    "tax status": ["", "taxable", "shipping", "none"],
    "sold individually": ["", "yes", "no"],
    "virtual": ["", "yes", "no"],
    "downloadable": ["", "yes", "no"],
  };

  function isRowEmpty(row) {
    return !row || !row.some((c) => String(c || "").trim() !== "");
  }

  function ensureScratchRows() {
    const want = Math.max(0, Number(state.settings.extra_blank_rows ?? 50));
    if (!state.headers.length || want === 0) return;
    let trailingEmpty = 0;
    for (let i = state.products.length - 1; i >= 0; i--) {
      if (isRowEmpty(state.products[i])) trailingEmpty++;
      else break;
    }
    const need = want - trailingEmpty;
    for (let k = 0; k < need; k++) {
      state.products.push(new Array(state.headers.length).fill(""));
    }
  }

  function applySettingsToUI() {
    const s = state.settings;
    document.documentElement.setAttribute("data-theme", s.theme === "light" ? "light" : "dark");
    document.body.setAttribute("data-density", s.row_density || "normal");
    document.body.style.fontSize = `${Number(s.font_size || 13)}px`;
    const tbl = $("sheetTable");
    const zoomPct = Number(s.zoom || 100);
    if (tbl) tbl.style.zoom = `${zoomPct}%`;
    const zBtn = $("btnZoomReset");
    if (zBtn) zBtn.textContent = `${zoomPct}%`;
    $("btnTheme").textContent = s.theme === "light" ? "🌙" : "☀";
    const dict = UI_I18N[s.ui_lang] || UI_I18N.en;
    document.querySelectorAll("[data-i18n]").forEach((el) => {
      const k = el.getAttribute("data-i18n");
      if (dict[k]) el.textContent = dict[k];
    });
  }

  const KA_ONLY_COLS = new Set(["name", "categories", "short description", "description"]);
  const EN_ONLY_COLS = new Set([
    "name (en)", "name_en",
    "categories (en)", "categories_en",
    "short description (en)", "short description_en",
    "description (en)", "description_en",
  ]);
  const RICH_TEXT_COLS = new Set([
    "short description", "description",
    "short description (en)", "short description_en",
    "description (en)", "description_en",
  ]);

  function isColHiddenByLang(header) {
    const h = String(header || "").trim().toLowerCase();
    if (state.hiddenCols && state.hiddenCols.has(h)) return true;
    if (state.langView === "ka" && EN_ONLY_COLS.has(h)) return true;
    if (state.langView === "en" && KA_ONLY_COLS.has(h)) return true;
    return false;
  }

  function updateColsVisibilityButtons() {
    const btnEdit = $("btnEditCols");
    const btnReset = $("btnResetCols");
    const n = state.hiddenCols ? state.hiddenCols.size : 0;
    if (btnEdit) {
      btnEdit.textContent = n > 0 ? `Columns (${n} hidden)` : "Columns";
    }
    if (btnReset) {
      btnReset.classList.toggle("hidden", n === 0);
    }
  }

  function renderEditColsList() {
    const wrap = $("editColsList");
    if (!wrap) return;
    const q = ($("editColsSearch")?.value || "").trim().toLowerCase();
    wrap.innerHTML = "";
    const headers = (state.headers || []).filter((h) => !q || String(h).toLowerCase().includes(q));
    if (!headers.length) {
      wrap.innerHTML = `<div class="muted-sm" style="grid-column:1/-1;text-align:center;padding:12px;">No matching columns.</div>`;
      return;
    }
    headers.forEach((h) => {
      const hLow = String(h).trim().toLowerCase();
      const isHidden = state.hiddenCols.has(hLow);
      const lbl = document.createElement("label");
      lbl.className = "edit-col-toggle" + (isHidden ? " is-hidden-col" : "");
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = !isHidden;
      cb.addEventListener("change", () => {
        if (cb.checked) state.hiddenCols.delete(hLow);
        else state.hiddenCols.add(hLow);
        lbl.classList.toggle("is-hidden-col", !cb.checked);
        updateColsVisibilityButtons();
        renderGrid();
      });
      const sp = document.createElement("span");
      sp.className = "edit-col-name";
      sp.textContent = h;
      sp.title = h;
      lbl.appendChild(cb);
      lbl.appendChild(sp);
      wrap.appendChild(lbl);
    });
  }

  function openEditColsModal() {
    if (!state.headers.length) {
      toast("Open or import a spreadsheet first.", true);
      return;
    }
    if ($("editColsSearch")) $("editColsSearch").value = "";
    renderEditColsList();
    $("editColsModal").classList.remove("hidden");
    setTimeout(() => $("editColsSearch")?.focus(), 20);
  }

  function resetHiddenCols() {
    if (!state.hiddenCols.size) return;
    state.hiddenCols.clear();
    updateColsVisibilityButtons();
    renderEditColsList();
    renderGrid();
    toast("Restored all columns.");
  }

  function hideAllCols() {
    (state.headers || []).forEach((h) => {
      const hLow = String(h).trim().toLowerCase();
      if (hLow) state.hiddenCols.add(hLow);
    });
    updateColsVisibilityButtons();
    renderEditColsList();
    renderGrid();
    toast("Cleared all columns — check the ones you want to see.");
  }

  function translateCategoriesToEn(kaCell) {
    const parts = String(kaCell || "").split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
    const out = [];
    parts.forEach((p) => {
      const en = state.category_en_map[p.toLowerCase()];
      if (en && !out.includes(en)) out.push(en);
    });
    return out.join("; ");
  }

  function syncCategoriesEn() {
    const catC = colIdx("Categories");
    const catEnC = state.headers.findIndex((h) => ["categories (en)", "categories_en"].includes(h.trim().toLowerCase()));
    if (catC < 0 || catEnC < 0) return;
    state.products.forEach((row) => {
      row[catEnC] = translateCategoriesToEn(row[catC] || "");
    });
  }

  // Odella website product image standard: 2:3 portrait (Odella Product Card = 600x900, ideal 1000x1500)
  const TARGET_RATIO = 2 / 3; // 0.6667
  const RATIO_TOLERANCE = 0.05; // ±5% (0.633 .. 0.700)
  const MIN_IMG_W = 600;
  const MIN_IMG_H = 900;

  // Live Odella location-based pricing rules (odella-pricing.php + NBG rates)
  // Georgia (GE) = base GEL; Eurozone (EU) = EUR; Global (rest of world) = USD
  const ODELLA_RATES = { EUR: 0.350300, USD: 0.385609, feePercent: 1 };

  function calcAutoZonePrice(gelRaw, currency) {
    const gel = parseFloat(String(gelRaw || "").replace(",", "."));
    if (isNaN(gel) || gel <= 0) return "";
    const rate = ODELLA_RATES[currency];
    if (!rate) return "";
    const withFee = gel * rate * (1 + ODELLA_RATES.feePercent / 100);
    // Round up to a number ending in 9 (matches odella_round_up_9)
    const rounded = withFee <= 9 ? 9 : Math.ceil((withFee - 9 - 1e-7) / 10) * 10 + 9;
    return String(rounded);
  }

  // ---------- Sale Discount (% vs GEL units less) Helpers ----------
  function cleanNumStr(n) {
    if (!Number.isFinite(n)) return "";
    if (Math.abs(n - Math.round(n)) < 1e-6) return String(Math.round(n));
    return n.toFixed(2).replace(/\.?0+$/, "");
  }

  function parseSaleDiscount(raw) {
    const s = String(raw || "").trim();
    if (!s || s.toLowerCase() === "(clear)") {
      return { kind: "blank", value: 0, normalized: "", message: "" };
    }
    const m = s.match(/^\s*(\d+(?:[.,]\d+)?)\s*(%?)\s*$/);
    if (!m) {
      return { kind: "invalid", value: NaN, normalized: "", message: "Use xx% (percentage off) or plain number (GEL units off), e.g. 20% or 40." };
    }
    const num = parseFloat(m[1].replace(",", "."));
    if (isNaN(num) || num <= 0) {
      return { kind: "invalid", value: NaN, normalized: "", message: "Discount must be greater than 0." };
    }
    const isPct = Boolean(m[2]);
    const clean = cleanNumStr(num);
    if (isPct) {
      if (num >= 100) {
        return { kind: "invalid", value: NaN, normalized: "", message: "Percentage discount must be less than 100%." };
      }
      return { kind: "pct", value: num, normalized: `${clean}%`, message: "" };
    }
    return { kind: "unit", value: num, normalized: clean, message: "" };
  }

  function computeSalePriceFromDiscount(regRaw, discRaw) {
    const parsed = parseSaleDiscount(discRaw);
    if (parsed.kind === "blank") {
      return { ok: true, saleStr: "", normalized: "", kind: "blank", error: "" };
    }
    if (parsed.kind === "invalid") {
      return { ok: false, saleStr: "", normalized: "", kind: "invalid", error: parsed.message };
    }
    const reg = parseFloat(String(regRaw || "").trim().replace(",", "."));
    if (isNaN(reg) || reg <= 0) {
      return { ok: false, saleStr: "", normalized: parsed.normalized, kind: parsed.kind, error: "Set Regular price (GEL) first." };
    }
    let sale;
    if (parsed.kind === "pct") {
      sale = Math.round(reg * (1 - parsed.value / 100));
    } else {
      sale = reg - parsed.value;
    }
    if (sale <= 0) {
      return { ok: false, saleStr: "", normalized: parsed.normalized, kind: parsed.kind, error: "Discount makes Sale price 0 or negative." };
    }
    if (sale >= reg) {
      return { ok: false, saleStr: "", normalized: parsed.normalized, kind: parsed.kind, error: "Discount must lower Sale price below Regular price." };
    }
    return { ok: true, saleStr: cleanNumStr(sale), normalized: parsed.normalized, kind: parsed.kind, error: "" };
  }

  function deriveUnitDiscount(regRaw, saleRaw) {
    const reg = parseFloat(String(regRaw || "").trim().replace(",", "."));
    const sale = parseFloat(String(saleRaw || "").trim().replace(",", "."));
    if (isNaN(reg) || isNaN(sale) || reg <= 0 || sale <= 0 || sale >= reg) return "";
    return cleanNumStr(reg - sale);
  }

  function toggleDiscountModeKeepNumber(discRaw, targetMode) {
    const parsed = parseSaleDiscount(discRaw);
    if (parsed.kind !== "pct" && parsed.kind !== "unit") return null;
    if (parsed.kind === targetMode) return parsed.normalized;
    const numStr = cleanNumStr(parsed.value);
    if (targetMode === "pct") {
      if (parsed.value >= 100) return null;
      return `${numStr}%`;
    }
    return numStr;
  }

  function getVarDiscountKey(vrow) {
    if (!vrow) return "";
    const vidC = varCol("Variation ID");
    const vskuC = varCol("SKU");
    const vid = vidC >= 0 ? String(vrow[vidC] || "").trim().replace(/\.0+$/, "") : "";
    if (vid) return `vid:${vid}`;
    const vsku = vskuC >= 0 ? String(vrow[vskuC] || "").trim().toLowerCase() : "";
    if (vsku) return `vsku:${vsku}`;
    return "";
  }

  function getStoredOrDerivedVarDiscount(vrow) {
    const regC = varCol("Regular price (GEL)");
    const saleC = varCol("Sale price (GEL)");
    const reg = regC >= 0 ? String(vrow[regC] || "").trim() : "";
    const sale = saleC >= 0 ? String(vrow[saleC] || "").trim() : "";
    if (!sale || sale.toLowerCase() === "(clear)") return "";
    const key = getVarDiscountKey(vrow);
    const stored = key && state.varSaleDiscounts ? String(state.varSaleDiscounts[key] || "").trim() : "";
    if (stored) {
      const calc = computeSalePriceFromDiscount(reg, stored);
      const curF = parseFloat(sale.replace(",", "."));
      const expF = calc.ok ? parseFloat(calc.saleStr) : NaN;
      if (calc.ok && !isNaN(curF) && !isNaN(expF) && Math.abs(curF - expF) < 0.01) {
        return calc.normalized;
      }
    }
    return deriveUnitDiscount(reg, sale);
  }

  function syncProductRowSaleFromEdit(rIdx, editedColLow) {
    const row = state.products[rIdx];
    if (!row) return;
    const regC = colIdx("Regular price (GEL)");
    const saleC = colIdx("Sale price (GEL)");
    const discC = colIdx("Sale Discount");
    if (regC < 0 || saleC < 0 || discC < 0) return;

    const reg = String(row[regC] || "").trim();
    const sale = String(row[saleC] || "").trim();
    const disc = String(row[discC] || "").trim();

    if (editedColLow === "sale discount") {
      if (!disc || disc.toLowerCase() === "(clear)") {
        row[discC] = "";
        row[saleC] = "";
        return;
      }
      const res = computeSalePriceFromDiscount(reg, disc);
      if (res.ok) {
        row[discC] = res.normalized;
        row[saleC] = res.saleStr;
      }
    } else if (editedColLow === "regular price (gel)") {
      if (disc) {
        const res = computeSalePriceFromDiscount(reg, disc);
        if (res.ok) {
          row[discC] = res.normalized;
          row[saleC] = res.saleStr;
        }
      } else if (sale && sale.toLowerCase() !== "(clear)") {
        row[discC] = deriveUnitDiscount(reg, sale);
      }
    } else if (editedColLow === "sale price (gel)") {
      if (!sale || sale.toLowerCase() === "(clear)") {
        row[discC] = "";
      } else {
        row[discC] = deriveUnitDiscount(reg, sale);
      }
    }
  }

  function collectSaleDiscountsMap() {
    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const discC = colIdx("Sale Discount");
    const map = {};
    if (discC < 0) return map;
    state.products.forEach((row) => {
      if (isRowEmpty(row)) return;
      const d = String(row[discC] || "").trim();
      if (!d) return;
      const parsed = parseSaleDiscount(d);
      const val = parsed.kind === "pct" || parsed.kind === "unit" ? parsed.normalized : d;
      const pid = idC >= 0 ? String(row[idC] || "").trim().replace(/\.0+$/, "") : "";
      const sku = skuC >= 0 ? String(row[skuC] || "").trim().toLowerCase() : "";
      if (pid) map[`id:${pid}`] = val;
      if (sku) map[`sku:${sku}`] = val;
    });
    return map;
  }

  const IMAGE_COLS = new Set(["hover image", "featured image", "gallery images"]);
  const MULTI_COLS = new Set(["categories", "tags", "attributes"]);

  const $ = (id) => document.getElementById(id);

  // Prevent browser from opening dropped files outside dropzones
  window.addEventListener("dragover", (e) => e.preventDefault());
  window.addEventListener("drop", (e) => e.preventDefault());

  function toast(msg, isError = false) {
    const el = $("toast");
    el.textContent = msg;
    el.style.borderColor = isError ? "#ef4444" : "#10b981";
    el.classList.remove("hidden");
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add("hidden"), 4500);
  }

  function setDirty(flag = true) {
    state.dirty = flag;
    const badge = $("statusBadge");
    badge.textContent = flag ? "Unsaved changes" : "Saved";
    badge.classList.toggle("dirty", flag);
  }

  function colIdx(name) {
    const low = name.toLowerCase();
    return state.headers.findIndex((h) => h.trim().toLowerCase() === low);
  }

  function splitPictures(cell) {
    return String(cell || "")
      .replace(/\r\n/g, "\n")
      .split(/\s*(?:;|\||\n)\s*|,\s*(?=https?:\/\/)/i)
      .map((s) => s.trim())
      .filter(Boolean);
  }

  function parsePictureToken(token) {
    const t = String(token || "").trim();
    if (t.toLowerCase() === "(clear)") return { kind: "clear", folder: "", file: "" };
    if (/^https?:\/\//i.test(t)) return { kind: "url", folder: "", file: t };
    if (t.includes("/")) {
      const idx = t.lastIndexOf("/");
      return { kind: "file", folder: t.slice(0, idx).replace(/^\/+|\/+$/g, ""), file: t.slice(idx + 1).trim() };
    }
    return { kind: "file", folder: "", file: t };
  }

  function imagePreviewSrc(token) {
    const p = parsePictureToken(token);
    if (p.kind === "url") return p.file;
    if (p.kind !== "file") return "";
    const mapped = state.paths[token] || state.paths[p.file] || "";
    const qs = new URLSearchParams();
    if (mapped) qs.set("path", mapped);
    qs.set("token", token);
    if (state.images_root) qs.set("root", state.images_root);
    return "/api/local-image?" + qs.toString();
  }

  // ---------- Preflight Validation & Client Warnings ----------
  function expectedVariationCount(attrCell) {
    const parts = String(attrCell || "").split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
    const groups = new Map();
    parts.forEach((p) => {
      const idx = p.indexOf(":");
      if (idx < 0) return;
      const g = p.slice(0, idx).trim().toLowerCase();
      const v = p.slice(idx + 1).trim().toLowerCase();
      if (!g || !v) return;
      if (!groups.has(g)) groups.set(g, new Set());
      groups.get(g).add(v);
    });
    if (groups.size === 0) return 0;
    let total = 1;
    for (const set of groups.values()) total *= set.size;
    return total;
  }

  function runValidation() {
    const errorsByRow = new Map();
    const warningsByRow = new Map();
    const warnBuckets = {
      missing_price: new Set(),
      eur_usd_auto: new Set(),
      missing_featured: new Set(),
      draft: new Set(),
      bad_vars: new Set(),        // BIG warning: duplicates, missing attrs, missing variation prices, or 0 variations
      incomplete_vars: new Set(), // Minor info: fewer variations than full attribute combos (often intentional)
      missing_category: new Set(),
      missing_desc: new Set(),
      missing_en: new Set(),
      dup_sku: new Set(),
      dup_name_ka: new Set(),
      dup_name_en: new Set(),
      missing_hover: new Set(),
      shared_images: new Set(),
    };

    const catSet = new Set(state.categories.map((c) => c.toLowerCase()));
    const tagSet = new Set(state.tags.map((t) => t.toLowerCase()));
    const attrSet = new Set(state.attribute_tokens.map((a) => a.toLowerCase()));

    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const nameEnC = state.headers.findIndex((h) => ["name (en)", "name_en"].includes(h.trim().toLowerCase()));
    const catC = colIdx("Categories");
    const tagC = colIdx("Tags");
    const attrC = colIdx("Attributes");
    const typeC = colIdx("Product type");
    const statusC = colIdx("Status");
    const regC = colIdx("Regular price (GEL)");
    const saleC = colIdx("Sale price (GEL)");
    const discC = colIdx("Sale Discount");
    const eurRegC = colIdx("EUR regular price");
    const usdRegC = colIdx("USD regular price");
    const featC = colIdx("Featured image");
    const hovC = colIdx("Hover image");
    const galC = colIdx("Gallery images");
    const sdC = colIdx("Short description");
    const dC = colIdx("Description");
    const dEnC = state.headers.findIndex((h) => ["description (en)", "description_en"].includes(h.trim().toLowerCase()));

    const seenSku = new Map();
    const seenNameKa = new Map();
    const seenNameEn = new Map();
    const imageUsageMap = new Map(); // normKey -> { key, token, label, rows: Set<rIdx> }
    let autoFixable = 0;

    const addErr = (r, c, msg) => {
      if (c < 0) return;
      if (!errorsByRow.has(r)) errorsByRow.set(r, new Map());
      const m = errorsByRow.get(r);
      if (!m.has(c)) m.set(c, []);
      m.get(c).push(msg);
    };

    const addWarn = (r, c, bucket, msg, markActionable = true) => {
      if (bucket && warnBuckets[bucket]) warnBuckets[bucket].add(r);
      if (!markActionable || c < 0) return;
      if (!warningsByRow.has(r)) warningsByRow.set(r, new Map());
      const m = warningsByRow.get(r);
      if (!m.has(c)) m.set(c, []);
      m.get(c).push(msg);
    };

    const varRegC = state.variation_headers.findIndex((h) => h.trim().toLowerCase() === "regular price (gel)");
    const varSkuC = state.variation_headers.findIndex((h) => h.trim().toLowerCase() === "sku");
    const varAttrCols = Object.keys(state.attribute_groups)
      .map((g) => state.variation_headers.findIndex((h) => h.trim().toLowerCase() === g.toLowerCase()))
      .filter((i) => i >= 0);

    state.products.forEach((row, r) => {
      if (isRowEmpty(row)) return;
      const id = (row[idC] || "").trim();
      const sku = (row[skuC] || "").trim();
      const name = (row[nameC] || "").trim();
      const isNew = id === "";
      const pType = (typeC >= 0 ? row[typeC] || "simple" : "simple").trim().toLowerCase() || "simple";
      const status = (statusC >= 0 ? row[statusC] || "" : "").trim().toLowerCase();

      if (isNew && !sku) addErr(r, skuC, "New product needs a SKU.");
      if (sku) {
        const k = sku.toLowerCase();
        if (seenSku.has(k)) {
          const prevR = seenSku.get(k);
          addErr(r, skuC, `Duplicate SKU (also on row ${prevR + 2}).`);
          addErr(prevR, skuC, `Duplicate SKU (also on row ${r + 2}).`);
          addWarn(r, skuC, "dup_sku", `Duplicate SKU "${sku}" (also on row ${prevR + 2}).`);
          addWarn(prevR, skuC, "dup_sku", `Duplicate SKU "${sku}" (also on row ${r + 2}).`);
        } else {
          seenSku.set(k, r);
        }
      }
      if (isNew && !name) addErr(r, nameC, "New product needs a Name.");
      if (name) {
        const nk = name.toLowerCase();
        if (seenNameKa.has(nk)) {
          const prevR = seenNameKa.get(nk);
          addWarn(r, nameC, "dup_name_ka", `Duplicate Georgian Name (also on row ${prevR + 2}).`);
          addWarn(prevR, nameC, "dup_name_ka", `Duplicate Georgian Name (also on row ${r + 2}).`);
        } else {
          seenNameKa.set(nk, r);
        }
      }
      const nameEn = nameEnC >= 0 ? (row[nameEnC] || "").trim() : "";
      if (nameEn) {
        const nek = nameEn.toLowerCase();
        if (seenNameEn.has(nek)) {
          const prevR = seenNameEn.get(nek);
          addWarn(r, nameEnC, "dup_name_en", `Duplicate English Name (also on row ${prevR + 2}).`);
          addWarn(prevR, nameEnC, "dup_name_en", `Duplicate English Name (also on row ${r + 2}).`);
        } else {
          seenNameEn.set(nek, r);
        }
      }

      const catRaw = catC >= 0 ? (row[catC] || "").trim() : "";
      let rowFixable = false;
      if (!catRaw) {
        addWarn(r, catC, "missing_category", "Category is empty.");
      } else if (catRaw.toLowerCase() !== "(clear)") {
        if (catRaw.includes(",") || /(^|;\s*)კაცი->/.test(catRaw)) rowFixable = true;
        const parts = catRaw.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
        parts.forEach((part) => {
          if (!catSet.has(part.toLowerCase())) {
            addErr(r, catC, `Unknown category: "${part}"`);
          }
        });
      }

      if (tagC >= 0 && row[tagC] && row[tagC].trim() !== "" && row[tagC].trim().toLowerCase() !== "(clear)") {
        if (row[tagC].includes(",")) rowFixable = true;
        const parts = row[tagC].trim().split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
        parts.forEach((part) => {
          if (tagSet.size && !tagSet.has(part.toLowerCase())) addErr(r, tagC, `Unknown tag: "${part}"`);
        });
      }

      if (attrC >= 0 && row[attrC] && row[attrC].trim() !== "" && row[attrC].trim().toLowerCase() !== "(clear)") {
        if (row[attrC].includes(",")) rowFixable = true;
        const parts = row[attrC].trim().split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
        parts.forEach((part) => {
          if (attrSet.size && !attrSet.has(part.toLowerCase())) addErr(r, attrC, `Unknown attribute: "${part}"`);
        });
      }
      if (galC >= 0 && row[galC] && (row[galC].includes("|") || row[galC].includes(","))) {
        rowFixable = true;
      }
      if (rowFixable) autoFixable++;

      if (typeC >= 0 && row[typeC] && !["simple", "variable", "grouped", "external"].includes(pType)) {
        addErr(r, typeC, "Must be simple, variable, grouped, or external.");
      }

      if (statusC >= 0 && row[statusC] && !["publish", "draft"].includes(status)) {
        addErr(r, statusC, "Must be publish or draft.");
      } else if (status === "draft" || status === "") {
        addWarn(r, statusC, "draft", status === "draft" ? "In Draft (hidden from customers)." : "Status empty (defaults to Draft).");
      }

      const reg = regC >= 0 ? (row[regC] || "").trim() : "";
      const sale = saleC >= 0 ? (row[saleC] || "").trim() : "";
      const disc = discC >= 0 ? (row[discC] || "").trim() : "";
      if (reg && isNaN(Number(reg.replace(",", ".")))) addErr(r, regC, "Invalid number.");
      if (sale && sale.toLowerCase() !== "(clear)" && isNaN(Number(sale.replace(",", ".")))) addErr(r, saleC, "Invalid number.");
      if (reg && sale && !isNaN(Number(reg)) && !isNaN(Number(sale)) && Number(sale) >= Number(reg)) {
        addErr(r, saleC, "Sale price must be lower than Regular price.");
      }
      if (discC >= 0 && disc) {
        const dp = parseSaleDiscount(disc);
        if (dp.kind === "invalid") {
          addErr(r, discC, dp.message);
        } else if (dp.kind === "pct" || dp.kind === "unit") {
          const calc = computeSalePriceFromDiscount(reg, disc);
          if (!calc.ok) addErr(r, discC, calc.error);
        }
      }

      // Price & Variation warnings (split into BIG bad_vars vs MINOR incomplete_vars)
      if (pType === "variable") {
        const vars = getVariationsForRow(r);
        const expected = expectedVariationCount(attrC >= 0 ? row[attrC] : "");
        const unpricedVars = vars.filter((v) => varRegC < 0 || !(v[varRegC] || "").trim());
        const attrlessVars = vars.filter((v) => varAttrCols.every((c) => !(v[c] || "").trim()));
        // Check duplicate variation SKUs or duplicate attribute combos
        const seenVarSku = new Set();
        const seenVarAttr = new Set();
        let dupCount = 0;
        vars.forEach((v) => {
          const vs = varSkuC >= 0 ? (v[varSkuC] || "").trim().toLowerCase() : "";
          const va = varAttrCols.map((c) => (v[c] || "").trim().toLowerCase()).join("|");
          const hasA = varAttrCols.some((c) => (v[c] || "").trim() !== "");
          if ((vs && seenVarSku.has(vs)) || (hasA && seenVarAttr.has(va))) dupCount++;
          if (vs) seenVarSku.add(vs);
          if (hasA) seenVarAttr.add(va);
        });

        if (vars.length === 0) {
          addWarn(r, typeC, "bad_vars", "Variable product has 0 variations.");
          addWarn(r, typeC, "missing_price", "No priced variations (product will be hidden).");
        } else {
          if (dupCount > 0) {
            addWarn(r, typeC, "bad_vars", `${dupCount} duplicate variation(s) — click Clean Variations.`);
          }
          if (attrlessVars.length > 0) {
            addWarn(r, typeC, "bad_vars", `${attrlessVars.length} variation(s) missing attributes.`);
          }
          if (unpricedVars.length === vars.length) {
            addWarn(r, typeC, "missing_price", "No priced variations (product will be hidden).");
            addWarn(r, typeC, "bad_vars", `All ${unpricedVars.length} variation(s) missing GEL price.`);
          } else if (unpricedVars.length > 0) {
            addWarn(r, typeC, "bad_vars", `${unpricedVars.length} variation(s) missing GEL price.`);
          }
          // Minor info: fewer valid variations than attribute combinations (often intentional!)
          const validCount = vars.length - attrlessVars.length - dupCount;
          if (expected > 0 && validCount < expected) {
            warnBuckets.incomplete_vars.add(r);
          }
        }
      } else if (pType !== "grouped") {
        if (!reg) {
          addWarn(r, regC, "missing_price", "Missing GEL price (product will be hidden/Draft).");
        }
        const eurVal = eurRegC >= 0 ? (row[eurRegC] || "").trim() : "";
        const usdVal = usdRegC >= 0 ? (row[usdRegC] || "").trim() : "";
        if (!eurVal || !usdVal) {
          // Track in eur_usd_auto bucket (non-intrusive cell placeholder already shows Auto price)
          warnBuckets.eur_usd_auto.add(r);
        }
      }

      // Image warnings
      if (featC >= 0 && !(row[featC] || "").trim()) {
        addWarn(r, featC, "missing_featured", "Missing Featured image (hidden on shop).");
      }
      if (hovC >= 0 && !(row[hovC] || "").trim()) {
        warnBuckets.missing_hover.add(r);
      }

      // Collect image usage across Featured, Hover & Gallery for Info — Shared Images
      [featC, hovC, galC].forEach((ic) => {
        if (ic < 0) return;
        const rawImg = (row[ic] || "").trim();
        if (!rawImg || rawImg.toLowerCase() === "(clear)") return;
        splitPictures(rawImg).forEach((tok) => {
          const p = parsePictureToken(tok);
          if (p.kind !== "url" && p.kind !== "file") return;
          let normKey = "";
          let label = "";
          if (p.kind === "url") {
            const cleanUrl = p.file.split("?")[0];
            normKey = "url:" + cleanUrl.toLowerCase();
            try {
              label = decodeURIComponent(cleanUrl.split("/").pop() || cleanUrl);
            } catch {
              label = cleanUrl.split("/").pop() || cleanUrl;
            }
          } else {
            const realP = state.paths[tok] || state.paths[p.file] || "";
            normKey = "file:" + (realP ? realP.toLowerCase() : p.file.toLowerCase());
            label = p.file;
          }
          if (!imageUsageMap.has(normKey)) {
            imageUsageMap.set(normKey, { key: normKey, token: tok, label, rows: new Set() });
          }
          imageUsageMap.get(normKey).rows.add(r);
        });
      });

      // Description & Translation warnings
      const hasSd = sdC >= 0 && (row[sdC] || "").trim() !== "";
      const hasD = dC >= 0 && (row[dC] || "").trim() !== "";
      if (!hasSd && !hasD) {
        addWarn(r, dC >= 0 ? dC : sdC, "missing_desc", "Both Short description and Description are empty.");
      }
      if (name && nameEnC >= 0 && !(row[nameEnC] || "").trim()) {
        addWarn(r, nameEnC, "missing_en", "Missing English Name.");
      } else if (hasD && dEnC >= 0 && !(row[dEnC] || "").trim()) {
        addWarn(r, dEnC, "missing_en", "Missing English Description.");
      }
    });

    // Build Shared Images Info groups (images used by 2+ distinct product rows)
    const sharedGroups = [];
    imageUsageMap.forEach((entry) => {
      if (entry.rows.size >= 2) {
        const rowArr = Array.from(entry.rows).sort((a, b) => a - b);
        rowArr.forEach((ri) => warnBuckets.shared_images.add(ri));
        sharedGroups.push({
          key: entry.key,
          token: entry.token,
          label: entry.label,
          rows: rowArr,
        });
      }
    });
    sharedGroups.sort((a, b) => (b.rows.length - a.rows.length) || a.label.localeCompare(b.label));

    state.errorsByRow = errorsByRow;
    state.warningsByRow = warningsByRow;
    state.warnBuckets = warnBuckets;
    state.sharedImageGroups = sharedGroups;
    state.autoFixableCount = autoFixable;

    const totalErrRows = errorsByRow.size;
    const totalWarnRows = warningsByRow.size;
    const nonEmpty = state.products.filter((r) => !isRowEmpty(r));
    const newRows = nonEmpty.filter((r) => !(r[idC] || "").trim()).length;
    const varRows = nonEmpty.filter((r) => (r[typeC] || "").trim().toLowerCase() === "variable").length;

    $("errorCount").textContent = String(totalErrRows);
    $("warnCount").textContent = String(totalWarnRows);
    $("countAll").textContent = String(nonEmpty.length);
    $("countNew").textContent = String(newRows);
    $("countErr").textContent = String(totalErrRows);
    $("countWarn").textContent = String(totalWarnRows);
    $("countVar").textContent = String(varRows);

    // Update split counts directly inside Warning Type dropdown
    const wsel = $("warnTypeSelect");
    if (wsel) {
      const labelMap = {
        "": `All Big Warnings (${totalWarnRows})`,
        missing_price: `Price: Missing GEL (${warnBuckets.missing_price.size})`,
        missing_featured: `Featured Image: Missing (${warnBuckets.missing_featured.size})`,
        bad_vars: `Variations: Duplicates / Empty Price (${warnBuckets.bad_vars.size})`,
        draft: `Status: In Draft / Empty (${warnBuckets.draft.size})`,
        missing_category: `Categories: Missing (${warnBuckets.missing_category.size})`,
        missing_desc: `Description: Empty (${warnBuckets.missing_desc.size})`,
        missing_en: `English (EN): Missing (${warnBuckets.missing_en.size})`,
        dup_sku: `Duplicates: SKU (${warnBuckets.dup_sku.size})`,
        dup_name_ka: `Duplicates: Name (KA) (${warnBuckets.dup_name_ka.size})`,
        dup_name_en: `Duplicates: Name (EN) (${warnBuckets.dup_name_en.size})`,
        incomplete_vars: `Info — Variations: Fewer Combos (${warnBuckets.incomplete_vars.size})`,
        missing_hover: `Info — Hover Image: Missing (${warnBuckets.missing_hover.size})`,
        eur_usd_auto: `Info — EUR / USD: Auto NBG Rate (${warnBuckets.eur_usd_auto.size})`,
        shared_images: `Info — Shared Images (${warnBuckets.shared_images.size} rows · ${sharedGroups.length} imgs)`,
      };
      Array.from(wsel.options).forEach((opt) => {
        if (labelMap[opt.value]) opt.textContent = labelMap[opt.value];
      });
    }

    const autoBtn = $("btnAutoFix");
    if (autoFixable > 0) {
      $("autoFixCount").textContent = String(autoFixable);
      autoBtn.classList.remove("hidden");
    } else {
      autoBtn.classList.add("hidden");
    }
  }

  function computeAutoFixChanges() {
    const skuC = colIdx("SKU");
    const catC = colIdx("Categories");
    const tagC = colIdx("Tags");
    const attrC = colIdx("Attributes");
    const galC = colIdx("Gallery images");
    const changes = [];

    state.products.forEach((row, rIdx) => {
      const sku = skuC >= 0 ? (row[skuC] || "").trim() : "";
      if (catC >= 0) {
        const raw = (row[catC] || "").trim();
        if (raw && raw.toLowerCase() !== "(clear)") {
          const next = raw
            .replace(/\s*,\s*/g, "; ")
            .replace(/;\s*$/, "")
            .replace(/(^|;\s*)კაცი->/g, "$1მამაკაცი->")
            .replace(/\s*;\s*/g, "; ")
            .trim();
          if (next !== raw) {
            changes.push({ rIdx, ci: catC, colName: state.headers[catC], sku, before: raw, after: next });
          }
        }
      }
      [tagC, attrC].forEach((c) => {
        if (c < 0) return;
        const raw = (row[c] || "").trim();
        if (!raw || raw.toLowerCase() === "(clear)") return;
        const next = raw
          .replace(/\s*,\s*/g, "; ")
          .replace(/;\s*$/, "")
          .replace(/\s*;\s*/g, "; ")
          .trim();
        if (next !== raw) {
          changes.push({ rIdx, ci: c, colName: state.headers[c], sku, before: raw, after: next });
        }
      });
      if (galC >= 0) {
        const raw = (row[galC] || "").trim();
        if (raw && raw.toLowerCase() !== "(clear)") {
          const next = raw
            .replace(/\s*(?:\||,)\s*/g, "; ")
            .replace(/;\s*$/, "")
            .replace(/\s*;\s*/g, "; ")
            .trim();
          if (next !== raw) {
            changes.push({ rIdx, ci: galC, colName: state.headers[galC], sku, before: raw, after: next });
          }
        }
      }
    });
    return changes;
  }

  function openAutoFixModal() {
    const changes = computeAutoFixChanges();
    if (!changes.length) {
      toast("Nothing to auto-fix.");
      return;
    }
    const affectedRows = new Set(changes.map((c) => c.rIdx)).size;
    $("autoFixSummary").textContent = `${changes.length} cell(s) across ${affectedRows} product row(s) will be updated:`;
    const body = $("autoFixPreviewBody");
    body.innerHTML = "";
    const shown = changes.slice(0, 150);
    shown.forEach((ch) => {
      const tr = document.createElement("tr");
      const trunc = (s) => (s.length > 90 ? s.slice(0, 87) + "..." : s);
      tr.innerHTML = `
        <td>${ch.rIdx + 2}</td>
        <td class="mono">${esc(ch.sku || "—")}</td>
        <td>${esc(ch.colName)}</td>
        <td class="mono" style="color:#fca5a5;" title="${esc(ch.before)}">${esc(trunc(ch.before))}</td>
        <td class="mono" style="color:#86efac;" title="${esc(ch.after)}">${esc(trunc(ch.after))}</td>
      `;
      body.appendChild(tr);
    });
    if (changes.length > shown.length) {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td colspan="5" class="muted-sm" style="text-align:center;padding:8px;">...and ${changes.length - shown.length} more cell(s).</td>`;
      body.appendChild(tr);
    }
    $("autoFixModal").classList.remove("hidden");
  }

  function applyAutoFix() {
    const changes = computeAutoFixChanges();
    $("autoFixModal").classList.add("hidden");
    if (!changes.length) return;
    pushUndo();
    const affectedRows = new Set();
    changes.forEach((ch) => {
      state.products[ch.rIdx][ch.ci] = ch.after;
      affectedRows.add(ch.rIdx);
    });
    setDirty(true);
    renderGrid();
    toast(`Auto-fixed ${changes.length} cell(s) across ${affectedRows.size} row(s).`);
  }

  // ---------- Variations Helpers ----------
  function getVariationsForRow(rowIdx) {
    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const pid = (state.products[rowIdx][idC] || "").trim();
    const psku = (state.products[rowIdx][skuC] || "").trim();
    const pidIdx = state.variation_headers.findIndex((h) => h.trim().toLowerCase() === "parent id");
    const pskuIdx = state.variation_headers.findIndex((h) => h.trim().toLowerCase() === "parent sku");
    return state.variations.filter((v) => {
      const vpid = pidIdx >= 0 ? (v[pidIdx] || "").trim() : "";
      const vpsku = pskuIdx >= 0 ? (v[pskuIdx] || "").trim() : "";
      if (pid && vpid === pid) return true;
      if (!pid && psku && vpsku && vpsku.toLowerCase() === psku.toLowerCase()) return true;
      return false;
    });
  }

  // ---------- Undo / Redo History ----------
  function snapshotSheetState() {
    return {
      products: state.products.map((r) => [...r]),
      variations: (state.variations || []).map((r) => [...r]),
      varSaleDiscounts: { ...(state.varSaleDiscounts || {}) },
    };
  }

  function updateUndoRedoButtons() {
    const u = $("btnUndo");
    const r = $("btnRedo");
    if (u) u.disabled = !state.undoStack.length;
    if (r) r.disabled = !state.redoStack.length;
  }

  function pushUndo() {
    state.undoStack.push(snapshotSheetState());
    if (state.undoStack.length > 40) state.undoStack.shift();
    state.redoStack = [];
    updateUndoRedoButtons();
  }

  function performUndo() {
    if (!state.undoStack.length) {
      toast("Nothing to undo.");
      return;
    }
    state.redoStack.push(snapshotSheetState());
    if (state.redoStack.length > 40) state.redoStack.shift();
    const prev = state.undoStack.pop();
    state.products = prev.products;
    state.variations = prev.variations;
    if (prev.varSaleDiscounts) state.varSaleDiscounts = { ...prev.varSaleDiscounts };
    setDirty(true);
    updateUndoRedoButtons();
    renderGrid();
    toast("Undid last change.");
  }

  function performRedo() {
    if (!state.redoStack.length) {
      toast("Nothing to redo.");
      return;
    }
    state.undoStack.push(snapshotSheetState());
    if (state.undoStack.length > 40) state.undoStack.shift();
    const next = state.redoStack.pop();
    state.products = next.products;
    state.variations = next.variations;
    if (next.varSaleDiscounts) state.varSaleDiscounts = { ...next.varSaleDiscounts };
    setDirty(true);
    updateUndoRedoButtons();
    renderGrid();
    toast("Redid change.");
  }

  // ---------- Selection & Excel TSV Copy / Paste / Fill Down ----------
  function cellKey(visRow, col) {
    return `${visRow}:${col}`;
  }

  function addRectToSet(set, a, b) {
    const r1 = Math.min(a.visRow, b.visRow);
    const r2 = Math.max(a.visRow, b.visRow);
    const c1 = Math.min(a.col, b.col);
    const c2 = Math.max(a.col, b.col);
    for (let vr = r1; vr <= r2; vr++) {
      for (let c = c1; c <= c2; c++) {
        set.add(cellKey(vr, c));
      }
    }
  }

  function getSelectionBounds() {
    if (state.selectedCells.size === 0) {
      if (!state.selAnchor) return null;
      const a = state.selAnchor;
      const b = state.selEnd || a;
      return {
        r1: Math.min(a.visRow, b.visRow),
        r2: Math.max(a.visRow, b.visRow),
        c1: Math.min(a.col, b.col),
        c2: Math.max(a.col, b.col),
      };
    }
    let r1 = Infinity, r2 = -1, c1 = Infinity, c2 = -1;
    state.selectedCells.forEach((k) => {
      const [vr, c] = k.split(":").map(Number);
      if (vr < r1) r1 = vr;
      if (vr > r2) r2 = vr;
      if (c < c1) c1 = c;
      if (c > c2) c2 = c;
    });
    return r2 >= 0 ? { r1, r2, c1, c2 } : null;
  }

  function getActiveRealRowIndex() {
    if (state.selAnchor) {
      const rr = state.visibleRows[state.selAnchor.visRow];
      if (rr !== undefined) return rr;
    }
    const sel = getSelectedRealRowIndices();
    if (sel.length) return sel[0];
    return state.visibleRows.length ? state.visibleRows[0] : -1;
  }

  function getGridRowEl(visIdx) {
    const tbody = $("sheetBody");
    if (!tbody) return null;
    return tbody.querySelector(`tr[data-vis-row="${visIdx}"]`) || tbody.rows[visIdx] || null;
  }

  function updateSelectionClasses() {
    const tbody = $("sheetBody");
    if (!tbody) return;
    let checkedRows = 0;
    let totalRows = 0;
    Array.from(tbody.rows).forEach((tr) => {
      if (tr.dataset.visRow === undefined) return;
      const vIdx = Number(tr.dataset.visRow);
      let rowHasSel = false;
      for (let i = 1; i < tr.cells.length; i++) {
        const td = tr.cells[i];
        const ci = Number(td.dataset.ci);
        if (Number.isNaN(ci)) continue;
        const inSel = state.selectedCells.has(cellKey(vIdx, ci));
        if (inSel) rowHasSel = true;
        const isAnchor = state.selAnchor && vIdx === state.selAnchor.visRow && ci === state.selAnchor.col;
        td.classList.toggle("cell-selected", inSel);
        td.classList.toggle("cell-anchor", !!isAnchor);
      }
      const chk = tr.cells[0]?.querySelector(".row-chk");
      const isScratch = tr.classList.contains("scratch-row");
      if (chk) {
        chk.checked = rowHasSel;
        if (!isScratch) {
          totalRows++;
          if (rowHasSel) checkedRows++;
        }
      }
    });
    const allChk = $("chkSelectAllRows");
    const allSelected = totalRows > 0 && checkedRows === totalRows;
    if (allChk) {
      allChk.checked = allSelected;
      allChk.indeterminate = checkedRows > 0 && checkedRows < totalRows;
    }
    const selVisBtn = $("btnSelectAllVisible");
    if (selVisBtn) {
      selVisBtn.disabled = totalRows === 0;
      selVisBtn.classList.toggle("active", allSelected);
      selVisBtn.textContent = allSelected
        ? `☐ Deselect Visible (${totalRows})`
        : `☑ Select Visible (${totalRows})`;
    }
    updateBulkBar();
    if (!$("rowInspector").classList.contains("hidden")) {
      renderRowInspector();
    }
  }

  function toggleSelectAllVisible(forceSelect = null) {
    const visibleNonEmpty = [];
    for (let v = 0; v < state.visibleRows.length; v++) {
      const rr = state.visibleRows[v];
      if (rr !== undefined && !isRowEmpty(state.products[rr])) {
        visibleNonEmpty.push(v);
      }
    }
    if (!visibleNonEmpty.length) {
      state.selectedCells.clear();
      updateSelectionClasses();
      return;
    }
    let currentlyAllSelected = true;
    for (const v of visibleNonEmpty) {
      let rowSel = false;
      for (let c = 1; c < state.headers.length; c++) {
        if (state.selectedCells.has(cellKey(v, c))) {
          rowSel = true;
          break;
        }
      }
      if (!rowSel) {
        currentlyAllSelected = false;
        break;
      }
    }
    const shouldSelect = forceSelect !== null ? Boolean(forceSelect) : !currentlyAllSelected;
    state.selectedCells.clear();
    if (shouldSelect) {
      for (const v of visibleNonEmpty) {
        for (let c = 1; c < state.headers.length; c++) {
          state.selectedCells.add(cellKey(v, c));
        }
      }
      state.selAnchor = { visRow: visibleNonEmpty[0], col: 1 };
      state.selEnd = { visRow: visibleNonEmpty[visibleNonEmpty.length - 1], col: Math.max(1, state.headers.length - 1) };
    }
    updateSelectionClasses();
  }

  // ---------- Column Freeze Helpers ----------
  const DEFAULT_COL_WIDTHS = { id: 68, sku: 105, name: 200 };

  function saveFrozenCols() {
    try {
      localStorage.setItem("odella_frozen_cols", JSON.stringify(state.frozenCols));
    } catch {}
    apiPost("/api/settings", { frozen_cols: state.frozenCols }).catch(() => {});
  }

  function getTempFrozenColsForFilter() {
    if (state.filter === "warnings" && state.warnTypeFilter) {
      const map = {
        missing_featured: ["featured image"],
        missing_hover: ["hover image"],
        missing_price: ["regular price (gel)"],
        eur_usd_auto: ["regular price (gel)", "eur regular price", "usd regular price"],
        bad_vars: ["product type", "attributes"],
        incomplete_vars: ["product type", "attributes"],
        draft: ["status"],
        missing_category: ["categories"],
        missing_desc: ["short description", "description"],
        missing_en: ["name (en)", "description (en)"],
        dup_sku: ["sku"],
        dup_name_ka: ["name"],
        dup_name_en: ["name (en)"],
        shared_images: ["featured image", "hover image", "gallery images"],
      };
      return map[state.warnTypeFilter] || [];
    }
    if (state.filter === "ratio") {
      return ["featured image", "hover image", "gallery images"];
    }
    return [];
  }

  function getDisplayColumnOrder() {
    const frozenOrder = [];
    const frozenSet = new Set();
    const tempFrozenSet = new Set();
    const hLowMap = new Map();
    state.headers.forEach((h, ci) => hLowMap.set(h.trim().toLowerCase(), ci));
    (state.frozenCols || []).forEach((nameLow) => {
      const ci = hLowMap.get(nameLow);
      if (ci !== undefined && !frozenSet.has(ci)) {
        frozenSet.add(ci);
        frozenOrder.push(ci);
      }
    });
    getTempFrozenColsForFilter().forEach((nameLow) => {
      const ci = hLowMap.get(nameLow);
      if (ci !== undefined && !frozenSet.has(ci)) {
        frozenSet.add(ci);
        tempFrozenSet.add(ci);
        frozenOrder.push(ci);
      }
    });
    const rest = [];
    state.headers.forEach((_, ci) => {
      if (!frozenSet.has(ci)) rest.push(ci);
    });
    return { order: [...frozenOrder, ...rest], frozenSet, tempFrozenSet };
  }

  function toggleFreezeColumn(ci) {
    const h = state.headers[ci];
    if (!h) return;
    const low = h.trim().toLowerCase();
    const idx = state.frozenCols.indexOf(low);
    if (idx >= 0) {
      state.frozenCols.splice(idx, 1);
      toast(`Unfroze "${h}"`);
    } else {
      state.frozenCols.push(low);
      toast(`Froze "${h}" to front`);
    }
    saveFrozenCols();
    renderGrid();
  }

  function resetDefaultFreeze() {
    state.frozenCols = ["id", "sku", "name"];
    saveFrozenCols();
    renderGrid();
    toast("Reset frozen columns to default (ID, SKU, Name).");
  }

  function unfreezeAllColumns() {
    state.frozenCols = [];
    saveFrozenCols();
    renderGrid();
    toast("Unfroze all columns.");
  }

  // ---------- Row Freeze Helpers ----------
  function getValidFrozenRows() {
    const out = [];
    Array.from(state.frozenRows || [])
      .sort((a, b) => a - b)
      .forEach((rIdx) => {
        if (rIdx >= 0 && rIdx < state.products.length && !isRowEmpty(state.products[rIdx])) {
          out.push(rIdx);
        }
      });
    return out;
  }

  function updateFrozenRowsBadge() {
    const btn = $("btnUnfreezeRows");
    const cnt = $("frozenRowsCount");
    const valid = getValidFrozenRows();
    if (cnt) cnt.textContent = String(valid.length);
    if (btn) btn.classList.toggle("hidden", valid.length === 0);
  }

  function toggleFreezeRow(rIdx) {
    if (rIdx < 0 || rIdx >= state.products.length || isRowEmpty(state.products[rIdx])) return;
    if (state.frozenRows.has(rIdx)) {
      state.frozenRows.delete(rIdx);
      toast(`Unfroze row ${rIdx + 2}.`);
    } else {
      state.frozenRows.add(rIdx);
      toast(`Froze row ${rIdx + 2} to top.`);
    }
    renderGrid();
  }

  function freezeRowsByIndices(indices) {
    let added = 0;
    (indices || []).forEach((rIdx) => {
      if (rIdx >= 0 && rIdx < state.products.length && !isRowEmpty(state.products[rIdx]) && !state.frozenRows.has(rIdx)) {
        state.frozenRows.add(rIdx);
        added++;
      }
    });
    if (added) {
      renderGrid();
      toast(`Froze ${added} row(s) to top.`);
    }
  }

  function unfreezeAllRows() {
    if (!state.frozenRows.size) return;
    state.frozenRows.clear();
    renderGrid();
    toast("Unfroze all rows.");
  }

  function getSelectedRealRowIndices() {
    const rowSet = new Set();
    state.selectedCells.forEach((k) => {
      const vr = Number(k.split(":")[0]);
      const realIdx = state.visibleRows[vr];
      if (realIdx !== undefined && !isRowEmpty(state.products[realIdx])) {
        rowSet.add(realIdx);
      }
    });
    return Array.from(rowSet).sort((a, b) => a - b);
  }

  function updateBulkBar() {
    const bar = $("bulkBar");
    if (!bar) return;
    const rows = getSelectedRealRowIndices();
    if (rows.length < 2) {
      bar.classList.add("hidden");
      return;
    }
    $("bulkCount").textContent = `${rows.length} rows selected`;
    // Auto-select anchor column in Bulk Edit dropdown if editable
    const colSel = $("bulkColSel");
    if (colSel && state.selAnchor) {
      const ci = state.selAnchor.col;
      if (Array.from(colSel.options).some((o) => Number(o.value) === ci)) {
        colSel.value = String(ci);
      }
    }
    syncBulkValueControl();
    bar.classList.remove("hidden");
  }

  function getDropdownOptionsForHeader(hLow) {
    if (hLow === "status") return ["publish", "draft", "(clear)"];
    if (hLow === "product type") return ["simple", "variable", "grouped", "external"];
    if (WC_DROPDOWNS[hLow]) return [...WC_DROPDOWNS[hLow].filter(Boolean), "(clear)"];
    return null;
  }

  function syncBulkValueControl() {
    const colSel = $("bulkColSel");
    const valSel = $("bulkColSelect");
    const valInp = $("bulkColVal");
    const pickBtn = $("btnBulkPick");
    if (!colSel || !valSel || !valInp || !pickBtn) return;
    const ci = Number(colSel.value);
    const hLow = (state.headers[ci] || "").trim().toLowerCase();
    const opts = getDropdownOptionsForHeader(hLow);
    if (opts) {
      valSel.innerHTML = "";
      opts.forEach((opt) => {
        const o = document.createElement("option");
        o.value = opt;
        o.textContent = opt;
        valSel.appendChild(o);
      });
      valSel.classList.remove("hidden");
      valInp.classList.add("hidden");
      pickBtn.classList.add("hidden");
    } else if (MULTI_COLS.has(hLow)) {
      valSel.classList.add("hidden");
      valInp.classList.remove("hidden");
      pickBtn.classList.remove("hidden");
    } else {
      valSel.classList.add("hidden");
      valInp.classList.remove("hidden");
      pickBtn.classList.add("hidden");
    }
  }

  function parseTSV(text) {
    // Handles Excel quoted multi-line cells as well as plain tab/newline TSV
    const rows = [];
    let row = [];
    let cell = "";
    let inQuotes = false;
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (inQuotes) {
        if (ch === '"' && text[i + 1] === '"') {
          cell += '"';
          i++;
        } else if (ch === '"') {
          inQuotes = false;
        } else {
          cell += ch;
        }
      } else if (ch === '"' && cell === "") {
        inQuotes = true;
      } else if (ch === "\t") {
        row.push(cell);
        cell = "";
      } else if (ch === "\r" && text[i + 1] === "\n") {
        row.push(cell);
        rows.push(row);
        row = [];
        cell = "";
        i++;
      } else if (ch === "\n" || ch === "\r") {
        row.push(cell);
        rows.push(row);
        row = [];
        cell = "";
      } else {
        cell += ch;
      }
    }
    if (cell !== "" || row.length > 0) {
      row.push(cell);
      rows.push(row);
    }
    // Remove trailing empty line Excel often appends
    if (rows.length > 1 && rows[rows.length - 1].length === 1 && rows[rows.length - 1][0] === "") {
      rows.pop();
    }
    return rows;
  }

  function serializeTSV(bounds) {
    const lines = [];
    for (let vr = bounds.r1; vr <= bounds.r2; vr++) {
      const realIdx = state.visibleRows[vr];
      if (realIdx === undefined) continue;
      const vals = [];
      for (let c = bounds.c1; c <= bounds.c2; c++) {
        const v = String(state.products[realIdx][c] || "");
        vals.push(/[\t\n\r"]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
      }
      lines.push(vals.join("\t"));
    }
    return lines.join("\n");
  }

  function deleteRowsByIndices(indices) {
    const uniq = Array.from(new Set(indices)).filter((i) => i >= 0 && i < state.products.length && !isRowEmpty(state.products[i]));
    if (!uniq.length) return;
    if (uniq.length > 1 && !confirm(`Delete ${uniq.length} selected row(s) from the spreadsheet? (You can press Ctrl+Z to undo.)`)) {
      return;
    }
    pushUndo();
    const toRemove = new Set(uniq);
    state.products = state.products.filter((_, idx) => !toRemove.has(idx));
    state.selAnchor = null;
    state.selEnd = null;
    state.selectedCells.clear();
    setDirty(true);
    renderGrid();
    toast(`Deleted ${uniq.length} row(s). Press Ctrl+Z to undo.`);
  }

  function applyFillDown() {
    const b = getSelectionBounds();
    if (!b || b.r2 <= b.r1) return;
    const srcReal = state.visibleRows[b.r1];
    if (srcReal === undefined) return;
    pushUndo();
    const idC = colIdx("ID");
    let count = 0;
    for (let vr = b.r1 + 1; vr <= b.r2; vr++) {
      const dstReal = state.visibleRows[vr];
      if (dstReal === undefined) continue;
      for (let c = b.c1; c <= b.c2; c++) {
        if (c === idC) continue;
        state.products[dstReal][c] = state.products[srcReal][c];
        const hLow = (state.headers[c] || "").trim().toLowerCase();
        if (hLow === "sale discount" || hLow === "regular price (gel)" || hLow === "sale price (gel)") {
          syncProductRowSaleFromEdit(dstReal, hLow);
        }
        count++;
      }
    }
    if (count) {
      setDirty(true);
      renderGrid();
      toast(`Filled down ${count} cell(s).`);
    }
  }

  // ---------- Column Filter Popover ----------
  let colFilterCtx = null;

  function rowMatchesActiveFilters(rIdx, excludeColIdx = -1) {
    const row = state.products[rIdx];
    if (!row || isRowEmpty(row)) return false;
    const idC = colIdx("ID");
    const typeC = colIdx("Product type");
    if (state.filter === "new" && (row[idC] || "").trim() !== "") return false;
    if (state.filter === "errors" && !state.errorsByRow.has(rIdx)) return false;
    if (state.filter === "warnings") {
      if (state.warnTypeFilter) {
        const b = state.warnBuckets[state.warnTypeFilter];
        if (!b || !b.has(rIdx)) return false;
      } else if (!state.warningsByRow.has(rIdx)) {
        return false;
      }
    }
    if (state.filter === "ratio" && !state.ratioBadRows.has(rIdx)) return false;
    if (state.filter === "variable" && (row[typeC] || "").trim().toLowerCase() !== "variable") return false;
    for (const [fci, allowed] of state.colFilters.entries()) {
      if (fci === excludeColIdx) continue;
      const v = (row[fci] || "").trim();
      const key = v === "" ? "(Blanks)" : v;
      if (!allowed.has(key)) return false;
    }
    const q = state.search.trim().toLowerCase();
    if (q) {
      const hay = row.join(" ").toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  }

  function updateColFiltersBadge() {
    const btn = $("btnClearColFilters");
    const cnt = $("colFiltersCount");
    const n = state.colFilters ? state.colFilters.size : 0;
    if (cnt) cnt.textContent = String(n);
    if (btn) btn.classList.toggle("hidden", n === 0);
  }

  function openColFilterPopover(anchorEl, ci) {
    const uniqMap = new Map(); // normalized -> count
    state.products.forEach((r, rIdx) => {
      if (!rowMatchesActiveFilters(rIdx, ci)) return;
      const v = (r[ci] || "").trim();
      const key = v === "" ? "(Blanks)" : v;
      uniqMap.set(key, (uniqMap.get(key) || 0) + 1);
    });
    const allOpts = Array.from(uniqMap.keys()).sort((a, b) => {
      if (a === "(Blanks)") return -1;
      if (b === "(Blanks)") return 1;
      return a.localeCompare(b, undefined, { numeric: true });
    });
    const existing = state.colFilters.get(ci);
    const selected = new Set(existing ? allOpts.filter((o) => existing.has(o)) : allOpts);

    // Reuse the multiPopover UI cleanly in filter mode!
    multiCtx = null;
    colFilterCtx = { ci, options: allOpts, counts: uniqMap, selected };
    $("multiTitle").textContent = `Filter: ${state.headers[ci]}`;
    $("multiSearch").value = "";
    renderColFilterPopover();

    const pop = $("multiPopover");
    pop.classList.remove("hidden");
    const rect = anchorEl.getBoundingClientRect();
    const top = Math.min(window.innerHeight - 470, Math.max(10, rect.bottom + 4));
    const left = Math.min(window.innerWidth - 375, Math.max(10, rect.left));
    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
    $("multiSearch").focus();
  }

  function renderColFilterPopover() {
    if (!colFilterCtx) return;
    const q = $("multiSearch").value.trim().toLowerCase();
    const selDiv = $("multiSelected");
    selDiv.innerHTML = `<span class="muted-sm">${colFilterCtx.selected.size} / ${colFilterCtx.options.length} values shown</span>`;

    const listDiv = $("multiList");
    listDiv.innerHTML = "";
    const filtered = colFilterCtx.options.filter((o) => !q || o.toLowerCase().includes(q));
    if (!filtered.length) {
      listDiv.innerHTML = `<div class="muted-sm" style="padding:10px;text-align:center;">No matching values under current filters.</div>`;
      return;
    }

    // Select all visible toggle
    const allLbl = document.createElement("label");
    allLbl.className = "popover-option";
    allLbl.style.borderBottom = "1px solid #334155";
    const allCb = document.createElement("input");
    allCb.type = "checkbox";
    allCb.checked = filtered.length > 0 && filtered.every((o) => colFilterCtx.selected.has(o));
    allCb.addEventListener("change", () => {
      filtered.forEach((o) => {
        if (allCb.checked) colFilterCtx.selected.add(o);
        else colFilterCtx.selected.delete(o);
      });
      renderColFilterPopover();
    });
    allLbl.appendChild(allCb);
    allLbl.appendChild(document.createTextNode(q ? `Select all matching (${filtered.length})` : "Select all"));
    listDiv.appendChild(allLbl);

    filtered.slice(0, 300).forEach((opt) => {
      const lbl = document.createElement("label");
      lbl.className = "popover-option";
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = colFilterCtx.selected.has(opt);
      cb.addEventListener("change", () => {
        if (cb.checked) colFilterCtx.selected.add(opt);
        else colFilterCtx.selected.delete(opt);
        renderColFilterPopover();
      });
      lbl.appendChild(cb);
      lbl.appendChild(document.createTextNode(`${opt} (${colFilterCtx.counts.get(opt)})`));
      listDiv.appendChild(lbl);
    });
  }

  // ---------- Rendering Grid ----------
  function getFilteredRowIndices() {
    const idC = colIdx("ID");
    const typeC = colIdx("Product type");
    const q = state.search.trim().toLowerCase();
    const frozenList = getValidFrozenRows();
    const frozenSet = new Set(frozenList);
    const indices = [];
    for (let i = 0; i < state.products.length; i++) {
      if (frozenSet.has(i)) continue;
      const row = state.products[i];
      const empty = isRowEmpty(row);
      if (empty) {
        if ((state.filter === "all" || state.filter === "new") && !q && state.colFilters.size === 0) {
          indices.push(i);
        }
        continue;
      }
      if (state.filter === "new" && (row[idC] || "").trim() !== "") continue;
      if (state.filter === "errors" && !state.errorsByRow.has(i)) continue;
      if (state.filter === "warnings") {
        if (state.warnTypeFilter) {
          const b = state.warnBuckets[state.warnTypeFilter];
          if (!b || !b.has(i)) continue;
        } else if (!state.warningsByRow.has(i)) {
          continue;
        }
      }
      if (state.filter === "ratio" && !state.ratioBadRows.has(i)) continue;
      if (state.filter === "variable" && (row[typeC] || "").trim().toLowerCase() !== "variable") continue;
      // Per-column Excel filters
      let colOk = true;
      for (const [ci, allowed] of state.colFilters.entries()) {
        const v = (row[ci] || "").trim();
        const key = v === "" ? "(Blanks)" : v;
        if (!allowed.has(key)) {
          colOk = false;
          break;
        }
      }
      if (!colOk) continue;
      if (q) {
        const hay = row.join(" ").toLowerCase();
        if (!hay.includes(q)) continue;
      }
      indices.push(i);
    }

    // Sorting (only non-frozen rows; frozen rows stay pinned at the top)
    if (state.sortCol >= 0 && state.sortDir !== 0) {
      const sc = state.sortCol;
      const dir = state.sortDir;
      const hLow = (state.headers[sc] || "").toLowerCase();
      const isNum = hLow === "id" || hLow.includes("price");
      indices.sort((a, b) => {
        const va = (state.products[a][sc] || "").trim();
        const vb = (state.products[b][sc] || "").trim();
        if (va === vb) return a - b;
        if (va === "") return 1;
        if (vb === "") return -1;
        if (isNum) {
          const na = parseFloat(va.replace(",", "."));
          const nb = parseFloat(vb.replace(",", "."));
          if (!isNaN(na) && !isNaN(nb)) return (na - nb) * dir;
        }
        return va.localeCompare(vb, undefined, { numeric: true, sensitivity: "base" }) * dir;
      });
    }

    const combined = [...frozenList, ...indices];
    state.visibleRows = combined;
    return combined;
  }

  function isValidationFilterActive() {
    return state.filter === "warnings" || state.filter === "errors" || state.filter === "ratio";
  }

  function updateDeferredWarnBadge() {
    const b = $("btnDeferredWarnBadge");
    if (!b) return;
    b.classList.toggle("hidden", !state.deferredValidationActive);
  }

  function buildSharedImageDisplay(frozenList) {
    const q = state.search.trim().toLowerCase();
    const visible = [...frozenList];
    const groupHeaders = new Map(); // visIdx -> { token, label, rows, matchedRows }
    const groups = state.sharedImageGroups || [];
    for (const grp of groups) {
      const matched = [];
      for (const rIdx of grp.rows) {
        const row = state.products[rIdx];
        if (!row || isRowEmpty(row)) continue;
        let colOk = true;
        for (const [ci, allowed] of state.colFilters.entries()) {
          const v = (row[ci] || "").trim();
          const key = v === "" ? "(Blanks)" : v;
          if (!allowed.has(key)) {
            colOk = false;
            break;
          }
        }
        if (!colOk) continue;
        if (q) {
          const hay = (row.join(" ") + " " + grp.label + " " + grp.token).toLowerCase();
          if (!hay.includes(q)) continue;
        }
        matched.push(rIdx);
      }
      if (matched.length > 0) {
        groupHeaders.set(visible.length, { ...grp, matchedRows: matched });
        matched.forEach((ri) => visible.push(ri));
      }
    }
    state.visibleRows = visible;
    return { visible, groupHeaders };
  }

  function renderGrid(forceRevalidate = false) {
    if (state.frozenRowsFile !== state.file) {
      state.frozenRows.clear();
      state.frozenRowsFile = state.file;
      forceRevalidate = true;
    }
    ensureScratchRows();
    syncCategoriesEn();
    if (forceRevalidate || !isValidationFilterActive() || !state.dirty) {
      runValidation();
      state.deferredValidationActive = false;
    } else {
      state.deferredValidationActive = true;
    }
    updateDeferredWarnBadge();
    updateFrozenRowsBadge();
    updateColFiltersBadge();
    updateColsVisibilityButtons();

    const { order: colOrder, frozenSet, tempFrozenSet } = getDisplayColumnOrder();

    // Header
    const thead = $("sheetHead");
    thead.innerHTML = "";
    const trH = document.createElement("tr");
    const thNum = document.createElement("th");
    thNum.className = "sticky-0";
    thNum.innerHTML = `<div class="row-num-wrap"><input type="checkbox" class="row-chk" id="chkSelectAllRows" title="Select / deselect all visible rows"><span>#</span></div>`;
    const allChkEl = thNum.querySelector("#chkSelectAllRows");
    allChkEl.addEventListener("change", () => {
      toggleSelectAllVisible(allChkEl.checked);
    });
    trH.appendChild(thNum);

    colOrder.forEach((ci) => {
      const h = state.headers[ci];
      const hLow = h.trim().toLowerCase();
      const isFrozen = frozenSet.has(ci);
      const th = document.createElement("th");
      th.dataset.ci = String(ci);
      if (isFrozen) th.classList.add("frozen-col");
      if (isColHiddenByLang(h)) th.classList.add("hidden");
      const colW = state.colWidths.has(ci) ? state.colWidths.get(ci) : DEFAULT_COL_WIDTHS[hLow];
      if (colW) {
        th.style.width = `${colW}px`;
        th.style.minWidth = `${colW}px`;
        th.style.maxWidth = `${colW}px`;
      }

      const inner = document.createElement("div");
      inner.className = "th-inner";

      const sortSpan = document.createElement("span");
      sortSpan.className = "th-sort";
      const arrow = state.sortCol === ci ? (state.sortDir === 1 ? " ▲" : " ▼") : "";
      sortSpan.textContent = h + arrow;
      sortSpan.title = "Click to sort · Right-click for column options";
      sortSpan.addEventListener("click", () => {
        if (state.sortCol !== ci) {
          state.sortCol = ci;
          state.sortDir = 1;
        } else if (state.sortDir === 1) {
          state.sortDir = -1;
        } else {
          state.sortCol = -1;
          state.sortDir = 0;
        }
        renderGrid();
      });

      const pinBtn = document.createElement("button");
      pinBtn.type = "button";
      const isTempFrozen = tempFrozenSet && tempFrozenSet.has(ci);
      pinBtn.className = "col-pin-btn" + (isFrozen ? " pinned" : "");
      pinBtn.textContent = "📌";
      pinBtn.title = isTempFrozen
        ? `Temporarily frozen for active filter (click to pin "${h}" permanently)`
        : (isFrozen ? `Unfreeze "${h}"` : `Freeze "${h}" to front`);
      pinBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleFreezeColumn(ci);
      });

      const filterBtn = document.createElement("button");
      filterBtn.className = "th-filter-btn" + (state.colFilters.has(ci) ? " active" : "");
      filterBtn.textContent = "▾";
      filterBtn.title = "Filter column";
      filterBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        openColFilterPopover(filterBtn, ci);
      });

      th.addEventListener("contextmenu", (e) => {
        e.preventDefault();
        e.stopPropagation();
        showColumnContextMenu(e.clientX, e.clientY, ci, filterBtn);
      });

      inner.appendChild(sortSpan);
      inner.appendChild(pinBtn);
      inner.appendChild(filterBtn);
      th.appendChild(inner);

      // Column drag resizer (double-click to auto-fit)
      const resizer = document.createElement("div");
      resizer.className = "col-resizer";
      resizer.title = "Drag to resize · Double-click to auto-fit";
      resizer.addEventListener("dblclick", (e) => {
        e.preventDefault();
        e.stopPropagation();
        let maxLen = h.length;
        const sampleRows = state.visibleRows.slice(0, 60);
        for (const rr of sampleRows) {
          const v = String((state.products[rr] && state.products[rr][ci]) || "");
          if (v.length > maxLen) maxLen = v.length;
        }
        const bestW = Math.max(80, Math.min(420, Math.round(maxLen * 7.6 + 42)));
        state.colWidths.set(ci, bestW);
        applyColumnWidth(ci, bestW);
      });
      resizer.addEventListener("mousedown", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const startX = e.clientX;
        const startW = th.getBoundingClientRect().width;
        resizer.classList.add("active");
        const onMove = (ev) => {
          const nextW = Math.max(55, Math.round(startW + (ev.clientX - startX)));
          state.colWidths.set(ci, nextW);
          applyColumnWidth(ci, nextW);
        };
        const onUp = () => {
          resizer.classList.remove("active");
          window.removeEventListener("mousemove", onMove);
          window.removeEventListener("mouseup", onUp);
        };
        window.addEventListener("mousemove", onMove);
        window.addEventListener("mouseup", onUp);
      });
      th.appendChild(resizer);
      trH.appendChild(th);
    });
    thead.appendChild(trH);

    // Body
    const tbody = $("sheetBody");
    tbody.innerHTML = "";
    const isSharedGroupView = state.filter === "warnings" && state.warnTypeFilter === "shared_images";
    const frozenList = getValidFrozenRows();
    const frozenRowSet = new Set(frozenList);
    const lastFrozenVisIdx = frozenList.length > 0 ? frozenList.length - 1 : -1;
    let visible = [];
    let sharedGroupHeaders = new Map();
    if (isSharedGroupView) {
      const built = buildSharedImageDisplay(frozenList);
      visible = built.visible;
      sharedGroupHeaders = built.groupHeaders;
    } else {
      visible = getFilteredRowIndices();
    }
    const visibleColCount = 1 + colOrder.filter((ci) => !isColHiddenByLang(state.headers[ci])).length;
    const skuColForGroup = colIdx("SKU");
    const frag = document.createDocumentFragment();

    visible.forEach((rIdx, visIdx) => {
      if (sharedGroupHeaders.has(visIdx)) {
        const grp = sharedGroupHeaders.get(visIdx);
        const trGrp = document.createElement("tr");
        trGrp.className = "shared-img-group-row";
        const tdGrp = document.createElement("td");
        tdGrp.colSpan = Math.max(1, visibleColCount);
        const banner = document.createElement("div");
        banner.className = "shared-img-group-banner";
        const thumb = document.createElement("img");
        thumb.className = "shared-img-group-thumb";
        thumb.referrerPolicy = "no-referrer";
        thumb.src = imagePreviewSrc(grp.token);
        thumb.title = "Click to preview shared image";
        thumb.addEventListener("click", () => openLightbox(thumb.src, grp.token));
        banner.appendChild(thumb);

        const meta = document.createElement("div");
        meta.className = "shared-img-group-meta";
        const title = document.createElement("div");
        title.className = "shared-img-group-title mono";
        title.textContent = grp.label;
        title.title = grp.token;
        meta.appendChild(title);

        const sub = document.createElement("div");
        sub.className = "shared-img-group-sub";
        const badge = document.createElement("span");
        badge.className = "shared-img-group-badge";
        badge.textContent = `Shared by ${grp.rows.length} products`;
        sub.appendChild(badge);
        if (skuColForGroup >= 0) {
          const skus = grp.rows
            .map((ri) => (state.products[ri] && state.products[ri][skuColForGroup] || "").trim() || `Row ${ri + 2}`)
            .slice(0, 8)
            .join(", ");
          const skuSpan = document.createElement("span");
          skuSpan.textContent = `SKUs: ${skus}${grp.rows.length > 8 ? "…" : ""}`;
          sub.appendChild(skuSpan);
        }
        meta.appendChild(sub);
        banner.appendChild(meta);
        tdGrp.appendChild(banner);
        trGrp.appendChild(tdGrp);
        frag.appendChild(trGrp);
      }

      const row = state.products[rIdx];
      const rowErrs = state.errorsByRow.get(rIdx);
      const rowWarns = state.warningsByRow.get(rIdx);
      const isFrozenRow = visIdx <= lastFrozenVisIdx && frozenRowSet.has(rIdx);
      const tr = document.createElement("tr");
      tr.dataset.visRow = String(visIdx);
      if (isRowEmpty(row)) tr.classList.add("scratch-row");
      if (isFrozenRow) tr.classList.add("frozen-row");
      if (isFrozenRow && visIdx === lastFrozenVisIdx) tr.classList.add("frozen-row-last");
      if (state.rowHeights.has(rIdx)) {
        tr.style.height = `${state.rowHeights.get(rIdx)}px`;
      }

      const tdNum = document.createElement("td");
      tdNum.className = "sticky-0";
      tdNum.style.cursor = "pointer";
      tdNum.title = "Click checkbox or row # to select · 📌 to freeze row to top · Ctrl+Click to toggle";
      const numWrap = document.createElement("div");
      numWrap.className = "row-num-wrap";
      const rowChk = document.createElement("input");
      rowChk.type = "checkbox";
      rowChk.className = "row-chk";
      rowChk.title = "Select row";
      rowChk.addEventListener("click", (e) => e.stopPropagation());
      rowChk.addEventListener("change", () => {
        const firstVisCol = 1;
        const lastCol = state.headers.length - 1;
        for (let c = firstVisCol; c <= lastCol; c++) {
          if (rowChk.checked) state.selectedCells.add(cellKey(visIdx, c));
          else state.selectedCells.delete(cellKey(visIdx, c));
        }
        state.selAnchor = { visRow: visIdx, col: firstVisCol };
        state.selEnd = { visRow: visIdx, col: lastCol };
        updateSelectionClasses();
      });
      const numSpan = document.createElement("span");
      numSpan.textContent = String(rIdx + 2);
      numWrap.appendChild(rowChk);
      numWrap.appendChild(numSpan);
      if (!isRowEmpty(row)) {
        const pinRowBtn = document.createElement("button");
        pinRowBtn.type = "button";
        pinRowBtn.className = "row-pin-btn" + (isFrozenRow ? " pinned" : "");
        pinRowBtn.textContent = "📌";
        pinRowBtn.title = isFrozenRow ? `Unfreeze row ${rIdx + 2}` : `Freeze row ${rIdx + 2} to top (stays visible during search/filter)`;
        pinRowBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          toggleFreezeRow(rIdx);
        });
        numWrap.appendChild(pinRowBtn);
      }
      tdNum.appendChild(numWrap);
      tdNum.addEventListener("mousedown", (e) => {
        if (e.button !== 0 || e.target.closest(".row-del-btn, .row-resizer, .row-chk, .row-pin-btn")) return;
        e.preventDefault();
        window.getSelection()?.removeAllRanges();
        if (document.activeElement && ["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) {
          document.activeElement.blur();
        }
        const ctrl = e.ctrlKey || e.metaKey;
        const firstVisCol = 1;
        const lastCol = state.headers.length - 1;
        if (e.shiftKey && state.selAnchor) {
          if (!ctrl) state.selectedCells.clear();
          addRectToSet(state.selectedCells, { visRow: state.selAnchor.visRow, col: firstVisCol }, { visRow: visIdx, col: lastCol });
          state.selEnd = { visRow: visIdx, col: lastCol };
        } else if (ctrl) {
          const already = state.selectedCells.has(cellKey(visIdx, firstVisCol));
          for (let c = firstVisCol; c <= lastCol; c++) {
            if (already) state.selectedCells.delete(cellKey(visIdx, c));
            else state.selectedCells.add(cellKey(visIdx, c));
          }
          state.selAnchor = { visRow: visIdx, col: firstVisCol };
          state.selEnd = { visRow: visIdx, col: lastCol };
        } else {
          state.selectedCells.clear();
          addRectToSet(state.selectedCells, { visRow: visIdx, col: firstVisCol }, { visRow: visIdx, col: lastCol });
          state.selAnchor = { visRow: visIdx, col: firstVisCol };
          state.selEnd = { visRow: visIdx, col: lastCol };
          state.dragBaseCells = new Set();
          state.isDraggingSel = true;
          $("sheetTable").classList.add("no-select");
        }
        updateSelectionClasses();
      });
      if (!isRowEmpty(row)) {
        const delBtn = document.createElement("button");
        delBtn.type = "button";
        delBtn.className = "row-del-btn";
        delBtn.textContent = "×";
        delBtn.title = "Delete row (Ctrl+Z to undo)";
        delBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          deleteRowsByIndices([rIdx]);
        });
        numWrap.appendChild(delBtn);
      }
      // Row drag resizer
      const rowResizer = document.createElement("div");
      rowResizer.className = "row-resizer";
      rowResizer.title = "Drag to resize row height";
      rowResizer.addEventListener("mousedown", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const startY = e.clientY;
        const startH = tr.getBoundingClientRect().height;
        rowResizer.classList.add("active");
        const onMove = (ev) => {
          const nextH = Math.max(28, Math.round(startH + (ev.clientY - startY)));
          state.rowHeights.set(rIdx, nextH);
          tr.style.height = `${nextH}px`;
          if (tr.classList.contains("frozen-row")) updateStickyOffsets();
        };
        const onUp = () => {
          rowResizer.classList.remove("active");
          window.removeEventListener("mousemove", onMove);
          window.removeEventListener("mouseup", onUp);
        };
        window.addEventListener("mousemove", onMove);
        window.addEventListener("mouseup", onUp);
      });
      tdNum.appendChild(rowResizer);
      tr.appendChild(tdNum);

      colOrder.forEach((ci) => {
        const header = state.headers[ci];
        const hLow = header.trim().toLowerCase();
        const td = document.createElement("td");
        td.dataset.ci = String(ci);
        if (frozenSet.has(ci)) td.classList.add("frozen-col");
        if (isColHiddenByLang(header)) td.classList.add("hidden");
        const colW = state.colWidths.has(ci) ? state.colWidths.get(ci) : DEFAULT_COL_WIDTHS[hLow];
        if (colW) {
          td.style.width = `${colW}px`;
          td.style.minWidth = `${colW}px`;
          td.style.maxWidth = `${colW}px`;
        }
        td.addEventListener("contextmenu", (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (!state.selectedCells.has(cellKey(visIdx, ci))) {
            state.selectedCells.clear();
            state.selectedCells.add(cellKey(visIdx, ci));
            state.selAnchor = { visRow: visIdx, col: ci };
            state.selEnd = { visRow: visIdx, col: ci };
            updateSelectionClasses();
          }
          showCellContextMenu(e.clientX, e.clientY, rIdx, visIdx, ci, hLow, td);
        });

        td.addEventListener("mousedown", (e) => {
          if (e.button !== 0) return;
          const ctrl = e.ctrlKey || e.metaKey;
          if (ctrl) {
            e.preventDefault();
            window.getSelection()?.removeAllRanges();
            if (document.activeElement && ["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) {
              document.activeElement.blur();
            }
            const k = cellKey(visIdx, ci);
            if (state.selectedCells.has(k)) {
              state.selectedCells.delete(k);
            } else {
              state.selectedCells.add(k);
            }
            state.selAnchor = { visRow: visIdx, col: ci };
            state.selEnd = { visRow: visIdx, col: ci };
            state.dragBaseCells = new Set(state.selectedCells);
            state.isDraggingSel = true;
            $("sheetTable").classList.add("no-select");
            updateSelectionClasses();
            return;
          }
          if (e.shiftKey && state.selAnchor) {
            e.preventDefault();
            window.getSelection()?.removeAllRanges();
            if (document.activeElement && ["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) {
              document.activeElement.blur();
            }
            state.selectedCells.clear();
            addRectToSet(state.selectedCells, state.selAnchor, { visRow: visIdx, col: ci });
            state.selEnd = { visRow: visIdx, col: ci };
            updateSelectionClasses();
            return;
          }
          state.selectedCells.clear();
          state.selectedCells.add(cellKey(visIdx, ci));
          state.selAnchor = { visRow: visIdx, col: ci };
          state.selEnd = { visRow: visIdx, col: ci };
          state.dragBaseCells = new Set();
          state.isDraggingSel = true;
          updateSelectionClasses();
        });
        td.addEventListener("mouseenter", () => {
          if (!state.isDraggingSel || !state.selAnchor) return;
          if (state.selAnchor.visRow !== visIdx || state.selAnchor.col !== ci) {
            $("sheetTable").classList.add("no-select");
            window.getSelection()?.removeAllRanges();
            if (document.activeElement && ["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) {
              document.activeElement.blur();
            }
          }
          state.selectedCells = new Set(state.dragBaseCells);
          addRectToSet(state.selectedCells, state.selAnchor, { visRow: visIdx, col: ci });
          state.selEnd = { visRow: visIdx, col: ci };
          updateSelectionClasses();
        });

        const cellErrs = rowErrs ? rowErrs.get(ci) : null;
        const cellWarns = rowWarns ? rowWarns.get(ci) : null;
        if (cellErrs && cellErrs.length) td.classList.add("cell-error");
        else if (cellWarns && cellWarns.length) td.classList.add("cell-warn");

        const val = row[ci] || "";

        if (hLow === "id") {
          const ro = document.createElement("div");
          ro.className = "cell-readonly mono";
          ro.textContent = val;
          ro.title = "WooCommerce Product ID (read-only)";
          td.appendChild(ro);
        } else if (IMAGE_COLS.has(hLow)) {
          renderImageCell(td, rIdx, ci, hLow, val);
        } else if (hLow === "categories (en)" || hLow === "categories_en") {
          const box = document.createElement("div");
          box.className = "multi-cell";
          box.style.cursor = "default";
          box.title = "Auto-mapped from Georgian Categories via Polylang";
          const parts = val.trim() ? val.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean) : [];
          if (!parts.length) {
            const ph = document.createElement("span");
            ph.className = "multi-placeholder";
            ph.textContent = "Auto from Geo...";
            box.appendChild(ph);
          } else {
            parts.forEach((p) => {
              const pill = document.createElement("span");
              pill.className = "multi-pill";
              pill.style.borderColor = "#10b981";
              pill.style.color = "#a7f3d0";
              pill.textContent = p;
              box.appendChild(pill);
            });
          }
          td.appendChild(box);
        } else if (MULTI_COLS.has(hLow)) {
          renderMultiCell(td, rIdx, ci, hLow, val);
        } else if (hLow === "product type") {
          renderProductTypeCell(td, rIdx, ci, val);
        } else if (hLow === "status") {
          const sel = document.createElement("select");
          sel.className = "cell-select";
          ["", "publish", "draft"].forEach((opt) => {
            const o = document.createElement("option");
            o.value = opt;
            o.textContent = opt || "(blank)";
            if (opt === val.trim().toLowerCase()) o.selected = true;
            sel.appendChild(o);
          });
          sel.addEventListener("change", () => {
            pushUndo();
            state.products[rIdx][ci] = sel.value;
            setDirty(true);
            renderGrid();
          });
          td.appendChild(sel);
        } else if (WC_DROPDOWNS[hLow]) {
          const sel = document.createElement("select");
          sel.className = "cell-select";
          const opts = WC_DROPDOWNS[hLow];
          const cur = val.trim().toLowerCase();
          opts.forEach((opt) => {
            const o = document.createElement("option");
            o.value = opt;
            o.textContent = opt || "(blank)";
            if (opt === cur) o.selected = true;
            sel.appendChild(o);
          });
          if (cur && !opts.includes(cur)) {
            const o = document.createElement("option");
            o.value = val;
            o.textContent = val;
            o.selected = true;
            sel.appendChild(o);
          }
          sel.addEventListener("change", () => {
            pushUndo();
            state.products[rIdx][ci] = sel.value;
            setDirty(true);
            renderGrid();
          });
          td.appendChild(sel);
        } else if (RICH_TEXT_COLS.has(hLow)) {
          const ta = document.createElement("textarea");
          ta.className = "cell-input";
          ta.value = val;
          ta.title = "Double-click to open Rich Text Editor (bold, italic, bullets, etc.)";
          ta.addEventListener("change", () => {
            pushUndo();
            state.products[rIdx][ci] = ta.value;
            setDirty(true);
          });
          ta.addEventListener("dblclick", (e) => {
            e.preventDefault();
            e.stopPropagation();
            openRichModal(rIdx, ci);
          });
          td.appendChild(ta);
        } else {
          const inp = document.createElement("input");
          inp.className = "cell-input";
          inp.value = val;
          if (hLow === "id") inp.readOnly = true;
          if (hLow === "sale discount") {
            inp.classList.add("cell-discount");
            inp.placeholder = "20% or 40 off";
            const dp = parseSaleDiscount(val);
            if (dp.kind === "pct") {
              inp.title = `${dp.normalized} off Regular price (GEL), rounded to nearest GEL. Type plain number for GEL units off, or leave blank to clear sale.`;
            } else if (dp.kind === "unit") {
              inp.title = `${dp.normalized} GEL less than Regular price (GEL). Type xx% for percentage off, or leave blank to clear sale.`;
            } else {
              inp.title = "Type xx% for percentage off (e.g. 20%), or a plain number for GEL units less (e.g. 40).";
            }
          }
          if (hLow === "eur regular price" || hLow === "eur sale price" || hLow === "usd regular price" || hLow === "usd sale price") {
            const isEur = hLow.startsWith("eur");
            const isSale = hLow.includes("sale");
            const gelCol = colIdx(isSale ? "Sale price (GEL)" : "Regular price (GEL)");
            const gelVal = gelCol >= 0 ? row[gelCol] : "";
            const autoVal = calcAutoZonePrice(gelVal, isEur ? "EUR" : "USD");
            const sym = isEur ? "€" : "$";
            const zoneLabel = isEur ? "Eurozone (EU)" : "Global (Non-EU)";
            if (autoVal) {
              inp.placeholder = `Auto: ${autoVal} ${sym}`;
            } else {
              inp.placeholder = `Auto (${isEur ? "EUR" : "USD"})`;
            }
            inp.title = val.trim()
              ? `${zoneLabel} MANUAL override (${val} ${sym}). Clear cell to restore automatic NBG + 1% (end in 9).`
              : `${zoneLabel} AUTOMATIC price${autoVal ? ` = ${autoVal} ${sym}` : ""} (from GEL via NBG + 1%, rounded to 9). Leave blank for auto, or type a number to manually override.`;
          }
          inp.addEventListener("change", () => {
            pushUndo();
            state.products[rIdx][ci] = inp.value;
            if (hLow === "sale discount" || hLow === "regular price (gel)" || hLow === "sale price (gel)") {
              syncProductRowSaleFromEdit(rIdx, hLow);
            }
            setDirty(true);
            renderGrid();
          });
          td.appendChild(inp);
        }

        if (cellErrs && cellErrs.length) {
          const tip = document.createElement("span");
          tip.className = "error-tip";
          tip.textContent = cellErrs.join(" ");
          td.appendChild(tip);
        } else if (cellWarns && cellWarns.length) {
          const tip = document.createElement("span");
          tip.className = "warn-tip";
          tip.textContent = "⚠ " + cellWarns.join(" ");
          td.appendChild(tip);
        }
        tr.appendChild(td);
      });
      frag.appendChild(tr);
    });
    tbody.appendChild(frag);
    updateStickyOffsets();
    updateSelectionClasses();
    collectWebImages();
    if (drawerMode === "web" && !$("imagesDrawer").classList.contains("hidden")) {
      populateWebDrawerFolders();
      renderDrawerGrid();
    }
  }

  function applyColumnWidth(ci, widthPx) {
    const tbl = $("sheetTable");
    if (!tbl) return;
    tbl.querySelectorAll(`[data-ci="${ci}"]`).forEach((el) => {
      el.style.width = `${widthPx}px`;
      el.style.minWidth = `${widthPx}px`;
      el.style.maxWidth = `${widthPx}px`;
    });
    updateStickyOffsets();
  }

  function updateStickyOffsets() {
    const tbl = $("sheetTable");
    if (!tbl || !tbl.tHead?.rows[0]) return;
    tbl.querySelectorAll(".frozen-last").forEach((el) => el.classList.remove("frozen-last"));
    const headerCells = Array.from(tbl.tHead.rows[0].cells);
    let left = 0;
    let lastFrozenCi = null;
    for (const th of headerCells) {
      if (th.classList.contains("hidden")) continue;
      if (th.classList.contains("sticky-0")) {
        left += th.offsetWidth || 42;
        continue;
      }
      if (!th.classList.contains("frozen-col")) break;
      const ci = th.dataset.ci;
      const w = th.offsetWidth || th.getBoundingClientRect().width;
      tbl.querySelectorAll(`[data-ci="${ci}"]`).forEach((el) => {
        el.style.left = `${left}px`;
      });
      left += w;
      lastFrozenCi = ci;
    }
    if (lastFrozenCi !== null) {
      tbl.querySelectorAll(`[data-ci="${lastFrozenCi}"]`).forEach((el) => el.classList.add("frozen-last"));
    }

    // Sticky top offsets for frozen rows (stacked right under thead)
    const headH = tbl.tHead.getBoundingClientRect().height || 34;
    let topOffset = headH;
    const frozenTrs = tbl.tBodies[0] ? Array.from(tbl.tBodies[0].querySelectorAll("tr.frozen-row")) : [];
    for (const tr of frozenTrs) {
      const cells = Array.from(tr.cells);
      for (const td of cells) {
        td.style.top = `${topOffset}px`;
      }
      topOffset += tr.getBoundingClientRect().height || 40;
    }
  }

  function renderProductTypeCell(td, rIdx, ci, val) {
    const sel = document.createElement("select");
    sel.className = "cell-select";
    const cur = val.trim().toLowerCase() || "simple";
    ["simple", "variable", "grouped", "external"].forEach((opt) => {
      const o = document.createElement("option");
      o.value = opt;
      o.textContent = opt;
      if (opt === cur) o.selected = true;
      sel.appendChild(o);
    });
    sel.addEventListener("change", () => {
      pushUndo();
      const prev = (state.products[rIdx][ci] || "simple").trim().toLowerCase();
      state.products[rIdx][ci] = sel.value;
      setDirty(true);
      renderGrid();
      if (sel.value === "variable" && prev !== "variable") {
        openVariationModal(rIdx, true);
      }
    });
    td.appendChild(sel);

    if (cur === "variable") {
      const count = getVariationsForRow(rIdx).length;
      const btn = document.createElement("button");
      btn.className = "var-btn";
      btn.textContent = `Variations (${count})`;
      btn.addEventListener("click", () => openVariationModal(rIdx, false));
      td.appendChild(btn);
    }
  }

  function renderMultiCell(td, rIdx, ci, hLow, val) {
    const box = document.createElement("div");
    box.className = "multi-cell";
    box.title = "Double-click to open multi-select";
    const parts = val.trim() ? val.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean) : [];
    const validSet =
      hLow === "categories"
        ? new Set(state.categories.map((x) => x.toLowerCase()))
        : hLow === "tags"
        ? new Set(state.tags.map((x) => x.toLowerCase()))
        : new Set(state.attribute_tokens.map((x) => x.toLowerCase()));

    if (parts.length === 0) {
      const ph = document.createElement("span");
      ph.className = "multi-placeholder";
      ph.textContent = "Double-click to pick...";
      box.appendChild(ph);
    } else {
      parts.forEach((p) => {
        const pill = document.createElement("span");
        pill.className = "multi-pill";
        if (validSet.size && !validSet.has(p.toLowerCase()) && p.toLowerCase() !== "(clear)") {
          pill.classList.add("invalid");
        }
        pill.textContent = p;
        box.appendChild(pill);
      });
    }
    box.addEventListener("dblclick", (e) => {
      e.stopPropagation();
      openMultiPopover(td, rIdx, ci, hLow);
    });
    td.appendChild(box);
  }

  function renderImageCell(td, rIdx, ci, hLow, val) {
    td.classList.add("image-cell");
    const isGallery = hLow === "gallery images";
    const tokens = splitPictures(val);

    if (tokens.length > 0) {
      const list = document.createElement("div");
      list.className = "img-list";
      tokens.forEach((tok, idx) => {
        const parsed = parsePictureToken(tok);
        const card = document.createElement("div");
        card.className = "img-card";
        card.title = tok;
        card.draggable = true;
        card.addEventListener("dragstart", (e) => {
          e.stopPropagation();
          e.dataTransfer.setData(
            "application/x-odella-cell-image",
            JSON.stringify({
              token: tok,
              realPath: state.paths[tok] || state.paths[parsed.file] || "",
              kind: parsed.kind,
            })
          );
        });

        const img = document.createElement("img");
        img.referrerPolicy = "no-referrer";
        img.src = imagePreviewSrc(tok);
        img.loading = "lazy";
        img.onload = () => {
          const w = img.naturalWidth;
          const h = img.naturalHeight;
          if (!w || !h) return;
          const ratio = w / h;
          const diffPct = Math.abs(ratio - TARGET_RATIO) / TARGET_RATIO;
          const badRatio = diffPct > RATIO_TOLERANCE;
          const lowRes = w < MIN_IMG_W || h < MIN_IMG_H;
          const info = `${w}×${h} (${ratio.toFixed(2)})`;
          if (badRatio || lowRes) {
            card.classList.add("ratio-bad");
            const badge = document.createElement("div");
            badge.className = "img-ratio-badge";
            badge.textContent = badRatio ? `${ratio.toFixed(2)} !` : `${w}×${h}`;
            badge.style.cursor = "pointer";
            badge.title = "Click to open Crop / Rotate / Resize Studio";
            badge.addEventListener("click", (e) => {
              e.stopPropagation();
              openImageStudio([makeStudioItemFromCell(rIdx, ci, hLow, tok, idx)]);
            });
            card.appendChild(badge);
            const reasons = [];
            if (badRatio) reasons.push(`ratio ${ratio.toFixed(2)} differs by ${Math.round(diffPct * 100)}% from Odella 2:3 (0.67)`);
            if (lowRes) reasons.push(`smaller than ${MIN_IMG_W}×${MIN_IMG_H}`);
            card.title = `${tok}\n⚠ ${info} — ${reasons.join("; ")}`;
            img.dataset.caption = `${tok} — ⚠ ${info} (${reasons.join("; ")})`;
            if (!state.ratioBadRows.has(rIdx)) {
              state.ratioBadRows.add(rIdx);
              $("countRatio").textContent = String(state.ratioBadRows.size);
            }
          } else {
            card.title = `${tok}\n✓ ${info} (fits 2:3)`;
            img.dataset.caption = `${tok} — ✓ ${info}`;
          }
        };
        img.onerror = () => {
          img.remove();
          card.classList.add("missing");
          card.textContent = parsed.file.slice(0, 14) || "missing";
        };
        const openThisLightbox = (e) => {
          if (e) e.stopPropagation();
          const lbItems = tokens.map((t, ti) =>
            makeCellLightboxItem(rIdx, ci, hLow, t, ti, ti === idx ? (img.dataset.caption || t) : t)
          );
          openLightbox(img.src, img.dataset.caption || tok, lbItems, idx);
        };
        img.addEventListener("click", openThisLightbox);
        card.addEventListener("click", (e) => {
          if (e.target.closest(".img-mini-btn")) return;
          openThisLightbox(e);
        });
        card.appendChild(img);

        if (parsed.folder) {
          const badge = document.createElement("div");
          badge.className = "img-tag-badge";
          badge.textContent = parsed.folder.split("/").pop();
          badge.title = "FileBird: " + parsed.folder;
          card.appendChild(badge);
        }

        // Top corners: ✂ (left) and × (right) so bottom bar only holds ← / → and never overflows
        const topActs = document.createElement("div");
        topActs.className = "img-card-top-actions";
        const bEdit = document.createElement("button");
        bEdit.className = "img-mini-btn";
        bEdit.textContent = "✂";
        bEdit.title = "Crop / Rotate / Resize image";
        bEdit.addEventListener("click", (e) => {
          e.stopPropagation();
          openImageStudio([makeStudioItemFromCell(rIdx, ci, hLow, tok, idx)]);
        });
        topActs.appendChild(bEdit);

        const bDel = document.createElement("button");
        bDel.className = "img-mini-btn del";
        bDel.textContent = "×";
        bDel.title = "Remove";
        bDel.addEventListener("click", (e) => {
          e.stopPropagation();
          pushUndo();
          tokens.splice(idx, 1);
          state.products[rIdx][ci] = isGallery ? tokens.join("; ") : (tokens[0] || "");
          setDirty(true);
          renderGrid();
        });
        topActs.appendChild(bDel);
        card.appendChild(topActs);

        if (isGallery && tokens.length > 1) {
          const actions = document.createElement("div");
          actions.className = "img-card-actions";
          if (idx > 0) {
            const bL = document.createElement("button");
            bL.className = "img-mini-btn";
            bL.textContent = "←";
            bL.title = "Move left";
            bL.addEventListener("click", (e) => {
              e.stopPropagation();
              pushUndo();
              [tokens[idx - 1], tokens[idx]] = [tokens[idx], tokens[idx - 1]];
              state.products[rIdx][ci] = tokens.join("; ");
              setDirty(true);
              renderGrid();
            });
            actions.appendChild(bL);
          }
          if (idx < tokens.length - 1) {
            const bR = document.createElement("button");
            bR.className = "img-mini-btn";
            bR.textContent = "→";
            bR.title = "Move right";
            bR.addEventListener("click", (e) => {
              e.stopPropagation();
              pushUndo();
              [tokens[idx + 1], tokens[idx]] = [tokens[idx], tokens[idx + 1]];
              state.products[rIdx][ci] = tokens.join("; ");
              setDirty(true);
              renderGrid();
            });
            actions.appendChild(bR);
          }
          card.appendChild(actions);
        }
        list.appendChild(card);
      });
      const addTile = document.createElement("button");
      addTile.type = "button";
      addTile.className = "img-add-tile";
      addTile.textContent = "+";
      addTile.title = isGallery ? "Choose image(s) to add to gallery" : "Choose a new image (replaces current)";
      addTile.addEventListener("click", (e) => {
        e.stopPropagation();
        pickImageFiles(rIdx, ci, hLow, isGallery);
      });
      list.appendChild(addTile);
      td.appendChild(list);
    } else {
      const hint = document.createElement("div");
      hint.className = "drop-hint";
      hint.textContent = isGallery ? "+ Choose or drop images" : "+ Choose or drop 1 image";
      hint.title = isGallery ? "Click to choose one or more images" : "Click to choose 1 image";
      hint.addEventListener("click", (e) => {
        e.stopPropagation();
        pickImageFiles(rIdx, ci, hLow, isGallery);
      });
      td.appendChild(hint);
    }

    // Raw text input for pasting https:// URLs or editing
    const inp = document.createElement("input");
    inp.className = "img-raw-input";
    inp.placeholder = "Paste https:// URL or drop file";
    inp.value = val;
    inp.addEventListener("change", () => {
      pushUndo();
      state.products[rIdx][ci] = inp.value.trim();
      setDirty(true);
      renderGrid();
    });
    td.appendChild(inp);

    // Drag & Drop strictly on image cells
    td.addEventListener("dragover", (e) => {
      e.preventDefault();
      e.stopPropagation();
      td.classList.add("drag-over");
    });
    td.addEventListener("dragleave", () => td.classList.remove("drag-over"));
    td.addEventListener("drop", async (e) => {
      e.preventDefault();
      e.stopPropagation();
      td.classList.remove("drag-over");

      // Case 0: Dragged from another image cell (Gallery <-> Featured <-> Hover, local or web)
      const cellImgData = e.dataTransfer.getData("application/x-odella-cell-image");
      if (cellImgData) {
        const info = JSON.parse(cellImgData);
        if (info && info.token) {
          applyTokenToCell(rIdx, ci, hLow, info.token, info.realPath || "");
          toast(`Added image to ${state.headers[ci]}.`);
        }
        return;
      }

      // Case 1: Dragged from in-app Web Images Drawer
      const webData = e.dataTransfer.getData("application/x-odella-web-image");
      if (webData) {
        const info = JSON.parse(webData);
        if (info && info.url) {
          applyTokenToCell(rIdx, ci, hLow, info.url);
          toast(`Added web image to ${state.headers[ci]}.`);
        }
        return;
      }

      // Case 2: Dragged from in-app Local Images Drawer
      const drawerData = e.dataTransfer.getData("application/x-odella-local-image");
      if (drawerData) {
        const info = JSON.parse(drawerData);
        const matchedUrl = await matchLocalToWebUrl(info.path);
        if (matchedUrl) {
          applyTokenToCell(rIdx, ci, hLow, matchedUrl);
          toast("Same image already on web — used existing web URL!");
          return;
        }
        openDropModal(rIdx, ci, hLow, info.name, info.path);
        return;
      }

      // Case 3: Dragged from Windows Explorer
      await handleLocalImageFiles(e.dataTransfer.files, rIdx, ci, hLow, isGallery);
    });
  }

  function applyTokenToCell(rIdx, ci, hLow, token, realPath = "") {
    if (!token || !state.products[rIdx]) return;
    const isGallery = hLow === "gallery images";
    if (realPath) {
      const p = parsePictureToken(token);
      state.paths[token] = realPath;
      if (p.file) state.paths[p.file] = realPath;
    }
    pushUndo();
    if (isGallery) {
      const cur = splitPictures(state.products[rIdx][ci] || "");
      if (!cur.some((t) => t.toLowerCase() === token.toLowerCase())) {
        cur.push(token);
      }
      state.products[rIdx][ci] = cur.join("; ");
    } else {
      state.products[rIdx][ci] = token;
    }
    setDirty(true);
    renderGrid();
  }

  function getAllWorkbookWebUrls() {
    const imgCols = [colIdx("Featured image"), colIdx("Hover image"), colIdx("Gallery images")].filter((c) => c >= 0);
    const urls = [];
    const seen = new Set();
    state.products.forEach((row) => {
      if (isRowEmpty(row)) return;
      imgCols.forEach((c) => {
        splitPictures(row[c] || "").forEach((tok) => {
          const p = parsePictureToken(tok);
          if (p.kind === "url" && !seen.has(p.file)) {
            seen.add(p.file);
            urls.push(p.file);
          }
        });
      });
    });
    return urls;
  }

  async function matchLocalToWebUrl(localPath) {
    if (!localPath) return "";
    const webUrls = getAllWorkbookWebUrls();
    if (!webUrls.length) return "";
    const res = await apiPost("/api/match-web-image", { local_path: localPath, web_urls: webUrls });
    return res && res.ok && res.matched && res.url ? res.url : "";
  }

  function pickImageFiles(rIdx, ci, hLow, isGallery) {
    const picker = document.createElement("input");
    picker.type = "file";
    picker.accept = ".jpg,.jpeg,.png,.gif,.webp,image/*";
    picker.multiple = isGallery;
    picker.addEventListener("change", async () => {
      if (picker.files && picker.files.length) {
        await handleLocalImageFiles(picker.files, rIdx, ci, hLow, isGallery);
      }
    });
    picker.click();
  }

  async function handleLocalImageFiles(fileList, rIdx, ci, hLow, isGallery) {
    const files = Array.from(fileList || []).filter((f) => /\.(jpe?g|png|gif|webp)$/i.test(f.name));
    if (!files.length) {
      toast("Please choose an image file (.jpg, .jpeg, .png, .gif, .webp).", true);
      return;
    }
    const targetFiles = isGallery ? files : [files[0]];
    for (const file of targetFiles) {
      const b64 = await fileToBase64(file);
      const res = await apiPost("/api/upload-drop", {
        filename: file.name,
        data_base64: b64,
        images_root: state.images_root,
      });
      if (res.ok) {
        const matchedUrl = await matchLocalToWebUrl(res.path);
        if (matchedUrl) {
          applyTokenToCell(rIdx, ci, hLow, matchedUrl);
          toast(`"${file.name}" is already on web (unchanged) — used existing web URL!`);
        } else {
          await openDropModalAsync(rIdx, ci, hLow, res.filename, res.path);
        }
      } else {
        toast(res.error || `Failed to load ${file.name}`, true);
      }
    }
  }

  function fileToBase64(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        const str = String(reader.result || "");
        resolve(str.includes(",") ? str.split(",")[1] : str);
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  // ---------- Multi-select Popover ----------
  let multiCtx = null;

  function openMultiPopover(anchorEl, rIdx, ci, hLow) {
    const options =
      hLow === "categories"
        ? state.categories
        : hLow === "tags"
        ? state.tags
        : state.attribute_tokens;

    const current = (state.products[rIdx][ci] || "")
      .split(/\s*;\s*/)
      .map((s) => s.trim())
      .filter(Boolean);

    multiCtx = { rIdx, ci, hLow, options, selected: new Set(current) };
    $("multiTitle").textContent = state.headers[ci];
    $("multiSearch").value = "";
    renderMultiPopover();

    const pop = $("multiPopover");
    pop.classList.remove("hidden");
    const rect = anchorEl.getBoundingClientRect();
    const top = Math.min(window.innerHeight - 470, Math.max(10, rect.bottom + 4));
    const left = Math.min(window.innerWidth - 375, Math.max(10, rect.left));
    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
    $("multiSearch").focus();
  }

  function renderMultiPopover() {
    if (!multiCtx) return;
    const q = $("multiSearch").value.trim().toLowerCase();
    const selDiv = $("multiSelected");
    selDiv.innerHTML = "";
    Array.from(multiCtx.selected).forEach((val) => {
      const chip = document.createElement("span");
      chip.className = "sel-chip";
      chip.textContent = val;
      const x = document.createElement("button");
      x.textContent = "×";
      x.addEventListener("click", () => {
        multiCtx.selected.delete(val);
        renderMultiPopover();
      });
      chip.appendChild(x);
      selDiv.appendChild(chip);
    });

    const listDiv = $("multiList");
    listDiv.innerHTML = "";
    multiCtx.options
      .filter((o) => !q || o.toLowerCase().includes(q))
      .forEach((opt) => {
        const lbl = document.createElement("label");
        lbl.className = "popover-option";
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.checked = multiCtx.selected.has(opt);
        cb.addEventListener("change", () => {
          if (cb.checked) multiCtx.selected.add(opt);
          else multiCtx.selected.delete(opt);
          renderMultiPopover();
        });
        lbl.appendChild(cb);
        lbl.appendChild(document.createTextNode(opt));
        listDiv.appendChild(lbl);
      });
  }

  function closeMultiPopover() {
    $("multiPopover").classList.add("hidden");
    multiCtx = null;
  }

  // ---------- Drop / FileBird Modal ----------
  let dropResolve = null;

  function openDropModalAsync(rIdx, ci, hLow, filename, realPath) {
    return new Promise((resolve) => {
      dropResolve = resolve;
      openDropModal(rIdx, ci, hLow, filename, realPath);
    });
  }

  function suggestFileBirdFolderForRow(rIdx) {
    const folders = state.filebird || [];
    if (!folders.length) return "";
    const findFolder = (target) => {
      const low = target.toLowerCase();
      return folders.find((f) => f.toLowerCase() === low) || "";
    };

    const row = state.products[rIdx] || [];
    const catC = colIdx("Categories");
    const catEnC = state.headers.findIndex((h) => ["categories (en)", "categories_en"].includes(h.trim().toLowerCase()));
    const kaRaw = catC >= 0 ? String(row[catC] || "") : "";
    const enRaw = catEnC >= 0 ? String(row[catEnC] || "") : "";

    const enParts = enRaw
      .split(/\s*[;,]\s*/)
      .map((s) => s.trim())
      .filter(Boolean);
    const kaParts = kaRaw
      .split(/\s*[;,]\s*/)
      .map((s) => s.trim())
      .filter(Boolean);

    // Also derive EN categories via Polylang KA->EN map if EN column is empty
    const kaMap = state.category_en_map || {};
    const kaMapLow = new Map(Object.entries(kaMap).map(([k, v]) => [k.toLowerCase(), v]));
    kaParts.forEach((kp) => {
      const mapped = kaMapLow.get(kp.toLowerCase());
      if (mapped && !enParts.includes(mapped)) enParts.push(mapped);
    });

    const allCats = [...enParts, ...kaParts];
    const joinedLow = allCats.join(" ; ").toLowerCase();

    // 1. Specific Odella category -> FileBird folder rules (most specific first)
    const rules = [
      { test: /long\s+double[\s-]*sided|გრძელი\s+ორმხრივი/i, folder: "Products/Long Double-sided Scarf" },
      { test: /kimono->short|მოსაცმელი->მოკლე/i, folder: "Products/Short Kimono" },
      { test: /kimono->long|მოსაცმელი->გრძელი/i, folder: "Products/Long Kimono" },
      { test: /beach\s*bag|beachwear->bag|bag->beach|ზღვის\s+სამოსი->ჩანთა|ჩანთა->ზღვის/i, folder: "Products/Beach Bag" },
      { test: /beach\s*dress|ზღვის\s+სამოსი->კაბა/i, folder: "Products/Beach Dress" },
      { test: /beach\s*mat|სეზონური->მატი/i, folder: "Products/Beach Mat" },
      { test: /kaftan|კაფტანი/i, folder: "Products/Kaftan" },
      { test: /pareo|პარეო/i, folder: "Products/Pareo" },
      { test: /coat|პალტო/i, folder: "Products/Coat" },
      { test: /gift\s*set|სასაჩუქრე\s+ნაკრები/i, folder: "Products/Gift Set" },
      { test: /\bvest\b|ჟილეტი/i, folder: "Products/Vest" },
      { test: /scarf|თავსაბურავი/i, folder: "Products/Silk Scarf" },
      { test: /kimono|მოსაცმელი/i, folder: "Products/Long Kimono" },
    ];
    for (const rule of rules) {
      if (rule.test.test(joinedLow)) {
        const hit = findFolder(rule.folder);
        if (hit) return hit;
      }
    }

    // 2. Dynamic leaf-name match against any workbook FileBird folder (singular/plural tolerant)
    const norm = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, " ").trim().replace(/s$/, "");
    for (const cat of enParts) {
      const leaf = norm(cat.split("->").pop() || "");
      if (!leaf) continue;
      const hit = folders.find((f) => {
        const fLeaf = norm(f.split("/").pop() || "");
        return fLeaf && (fLeaf === leaf || fLeaf.includes(leaf) || leaf.includes(fLeaf));
      });
      if (hit) return hit;
    }

    // 3. Fallback to "Products" folder if available, else last chosen
    return findFolder("Products") || state.lastFileBird || "";
  }

  function openDropModal(rIdx, ci, hLow, filename, realPath) {
    const isGallery = hLow === "gallery images";
    const existing = splitPictures(state.products[rIdx][ci] || "");
    const willOverride = !isGallery && existing.length > 0;

    $("dropModalTitle").textContent = isGallery
      ? `Add to Gallery — Row ${rIdx + 2}`
      : `${willOverride ? "Replace" : "Set"} ${state.headers[ci]} — Row ${rIdx + 2}`;
    $("dropOverrideWarn").classList.toggle("hidden", !willOverride);
    $("dropFileName").textContent = filename;
    $("dropRealPath").textContent = realPath;
    $("dropPreviewImg").src = "/api/local-image?path=" + encodeURIComponent(realPath);

    const defaultFb = suggestFileBirdFolderForRow(rIdx);
    const sel = $("filebirdSelect");
    sel.innerHTML = "";
    const noneOpt = document.createElement("option");
    noneOpt.value = "";
    noneOpt.textContent = "(No FileBird folder)";
    sel.appendChild(noneOpt);
    state.filebird.forEach((fb) => {
      const o = document.createElement("option");
      o.value = fb;
      o.textContent = fb;
      if (fb === defaultFb) o.selected = true;
      sel.appendChild(o);
    });

    const updatePreview = () => {
      const fb = sel.value;
      $("dropCellPreview").textContent = fb ? `${fb}/${filename}` : filename;
    };
    sel.onchange = updatePreview;
    updatePreview();

    $("btnDropConfirm").onclick = () => {
      pushUndo();
      const fb = sel.value;
      state.lastFileBird = fb;
      const token = fb ? `${fb}/${filename}` : filename;
      state.paths[token] = realPath;
      state.paths[filename] = realPath;

      if (isGallery) {
        const cur = splitPictures(state.products[rIdx][ci] || "");
        cur.push(token);
        state.products[rIdx][ci] = cur.join("; ");
      } else {
        state.products[rIdx][ci] = token;
      }
      setDirty(true);
      $("dropModal").classList.add("hidden");
      renderGrid();
      if (dropResolve) {
        const r = dropResolve;
        dropResolve = null;
        r();
      }
    };

    $("dropModal").classList.remove("hidden");
  }

  // ---------- Variation Generator Modal ----------
  let varCtx = null;

  function openVariationModal(rIdx, justSwitched) {
    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const attrC = colIdx("Attributes");

    const row = state.products[rIdx];
    const pid = (row[idC] || "").trim();
    const psku = (row[skuC] || "").trim();
    const pname = (row[nameC] || "").trim();

    $("varModalTitle").textContent = `Variations — ${pname || psku || "Row " + (rIdx + 2)}`;
    $("varModalSub").textContent = `Parent ID: ${pid || "(new product)"} · Parent SKU: ${psku || "(none)"}`;
    $("chkClearParentPrice").checked = justSwitched;

    // Parse current product Attributes cell into selected Set("Group: Value")
    const selectedAttrs = new Set(
      (attrC >= 0 ? row[attrC] || "" : "")
        .split(/\s*;\s*/)
        .map((s) => s.trim())
        .filter(Boolean)
    );

    const existingVars = getVariationsForRow(rIdx).map((v) => [...v]);
    // Also seed selectedAttrs from existing variation rows so checkboxes reflect actual variations
    const attrGroupNames = Object.keys(state.attribute_groups);
    existingVars.forEach((v) => {
      attrGroupNames.forEach((g) => {
        const gi = varCol(g);
        const val = gi >= 0 ? (v[gi] || "").trim() : "";
        if (val) selectedAttrs.add(`${g}: ${val}`);
      });
    });
    varCtx = { rIdx, pid, psku, selectedAttrs, rows: existingVars };

    renderVarAttrGroups();
    renderVarTable();
    $("varModal").classList.remove("hidden");
  }

  function renderVarAttrGroups() {
    const wrap = $("varAttrGroups");
    wrap.innerHTML = "";
    Object.entries(state.attribute_groups).forEach(([group, values]) => {
      const box = document.createElement("div");
      box.className = "var-attr-group";
      const title = document.createElement("div");
      title.className = "var-attr-group-title";
      title.textContent = group;
      box.appendChild(title);

      const chips = document.createElement("div");
      chips.className = "var-attr-chips";
      values.forEach((v) => {
        const token = `${group}: ${v}`;
        const lbl = document.createElement("label");
        lbl.className = "var-attr-chip";
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.checked = varCtx.selectedAttrs.has(token);
        cb.addEventListener("change", () => {
          if (cb.checked) varCtx.selectedAttrs.add(token);
          else varCtx.selectedAttrs.delete(token);
        });
        lbl.appendChild(cb);
        lbl.appendChild(document.createTextNode(v));
        chips.appendChild(lbl);
      });
      box.appendChild(chips);
      wrap.appendChild(box);
    });
  }

  function varCol(name) {
    return state.variation_headers.findIndex((h) => h.trim().toLowerCase() === name.toLowerCase());
  }

  function renderVarTable() {
    const head = $("varTableHead");
    const body = $("varTableBody");
    head.innerHTML = "";
    body.innerHTML = "";

    const attrGroupNames = Object.keys(state.attribute_groups);
    const trH = document.createElement("tr");
    ["Var ID", "SKU", ...attrGroupNames, "Regular (GEL)", "Sale (GEL)", "Discount", "Status", ""].forEach((t) => {
      const th = document.createElement("th");
      th.textContent = t;
      trH.appendChild(th);
    });
    head.appendChild(trH);

    $("varCount").textContent = String(varCtx.rows.length);

    const vidC = varCol("Variation ID");
    const skuC = varCol("SKU");
    const regC = varCol("Regular price (GEL)");
    const saleC = varCol("Sale price (GEL)");
    const stC = varCol("Status");

    if (!varCtx.discounts) varCtx.discounts = new WeakMap();

    varCtx.rows.forEach((vrow, idx) => {
      const tr = document.createElement("tr");
      if (!varCtx.discounts.has(vrow)) {
        varCtx.discounts.set(vrow, getStoredOrDerivedVarDiscount(vrow));
      }

      // Var ID (read-only)
      const tdId = document.createElement("td");
      tdId.className = "muted-sm mono";
      tdId.textContent = vrow[vidC] || "(new)";
      tr.appendChild(tdId);

      // SKU
      const tdSku = document.createElement("td");
      const inpSku = document.createElement("input");
      inpSku.className = "input";
      inpSku.value = vrow[skuC] || "";
      inpSku.oninput = () => (vrow[skuC] = inpSku.value);
      tdSku.appendChild(inpSku);
      tr.appendChild(tdSku);

      // Attribute columns
      attrGroupNames.forEach((g) => {
        const gIdx = varCol(g);
        const td = document.createElement("td");
        const sel = document.createElement("select");
        sel.className = "select";
        const blank = document.createElement("option");
        blank.value = "";
        blank.textContent = "";
        sel.appendChild(blank);
        (state.attribute_groups[g] || []).forEach((val) => {
          const o = document.createElement("option");
          o.value = val;
          o.textContent = val;
          if ((vrow[gIdx] || "").trim() === val) o.selected = true;
          sel.appendChild(o);
        });
        sel.onchange = () => {
          if (gIdx >= 0) vrow[gIdx] = sel.value;
        };
        td.appendChild(sel);
        tr.appendChild(td);
      });

      // Regular price
      const tdReg = document.createElement("td");
      const inpReg = document.createElement("input");
      inpReg.className = "input";
      inpReg.value = regC >= 0 ? vrow[regC] || "" : "";
      tdReg.appendChild(inpReg);
      tr.appendChild(tdReg);

      // Sale price
      const tdSale = document.createElement("td");
      const inpSale = document.createElement("input");
      inpSale.className = "input";
      inpSale.value = saleC >= 0 ? vrow[saleC] || "" : "";
      tdSale.appendChild(inpSale);
      tr.appendChild(tdSale);

      // Sale Discount (% or GEL off)
      const tdDisc = document.createElement("td");
      const inpDisc = document.createElement("input");
      inpDisc.className = "input mono";
      inpDisc.placeholder = "20% or 40";
      inpDisc.value = varCtx.discounts.get(vrow) || "";
      tdDisc.appendChild(inpDisc);
      tr.appendChild(tdDisc);

      inpReg.oninput = () => {
        if (regC >= 0) vrow[regC] = inpReg.value;
        const dVal = inpDisc.value.trim();
        if (dVal) {
          const calc = computeSalePriceFromDiscount(inpReg.value, dVal);
          if (calc.ok && saleC >= 0) {
            vrow[saleC] = calc.saleStr;
            inpSale.value = calc.saleStr;
          }
        } else if (inpSale.value.trim()) {
          const d = deriveUnitDiscount(inpReg.value, inpSale.value);
          inpDisc.value = d;
          varCtx.discounts.set(vrow, d);
        }
      };

      inpSale.oninput = () => {
        if (saleC >= 0) vrow[saleC] = inpSale.value;
        const d = deriveUnitDiscount(inpReg.value, inpSale.value);
        inpDisc.value = d;
        varCtx.discounts.set(vrow, d);
      };

      inpDisc.oninput = () => {
        const raw = inpDisc.value.trim();
        varCtx.discounts.set(vrow, raw);
        if (!raw || raw.toLowerCase() === "(clear)") {
          if (saleC >= 0) vrow[saleC] = "";
          inpSale.value = "";
          return;
        }
        const calc = computeSalePriceFromDiscount(inpReg.value, raw);
        if (calc.ok && saleC >= 0) {
          vrow[saleC] = calc.saleStr;
          inpSale.value = calc.saleStr;
          varCtx.discounts.set(vrow, calc.normalized);
        }
      };

      // Status
      const tdSt = document.createElement("td");
      const selSt = document.createElement("select");
      selSt.className = "select";
      ["publish", "private", "draft"].forEach((s) => {
        const o = document.createElement("option");
        o.value = s;
        o.textContent = s;
        if ((vrow[stC] || "publish").trim().toLowerCase() === s) o.selected = true;
        selSt.appendChild(o);
      });
      selSt.onchange = () => {
        if (stC >= 0) vrow[stC] = selSt.value;
      };
      tdSt.appendChild(selSt);
      tr.appendChild(tdSt);

      // Delete button
      const tdDel = document.createElement("td");
      const bDel = document.createElement("button");
      bDel.className = "icon-btn";
      bDel.textContent = "×";
      bDel.onclick = () => {
        varCtx.rows.splice(idx, 1);
        renderVarTable();
      };
      tdDel.appendChild(bDel);
      tr.appendChild(tdDel);

      body.appendChild(tr);
    });
  }

  function generateCombinations() {
    if (!varCtx) return;
    const byGroup = [];
    Object.keys(state.attribute_groups).forEach((g) => {
      const vals = [];
      state.attribute_groups[g].forEach((v) => {
        if (varCtx.selectedAttrs.has(`${g}: ${v}`)) vals.push(v);
      });
      if (vals.length) byGroup.push({ group: g, values: vals });
    });
    if (!byGroup.length) {
      toast("Select at least one attribute value first.", true);
      return;
    }

    // Cartesian product
    let combos = [{}];
    byGroup.forEach(({ group, values }) => {
      const next = [];
      combos.forEach((c) => {
        values.forEach((v) => next.push({ ...c, [group]: v }));
      });
      combos = next;
    });

    const pidC = varCol("Parent ID");
    const pskuC = varCol("Parent SKU");
    const skuC = varCol("SKU");
    const stC = varCol("Status");

    const comboKey = (obj) =>
      Object.keys(state.attribute_groups)
        .map((g) => `${g}=${(obj[g] || "").toLowerCase()}`)
        .join("|");

    const existingKeys = new Set(
      varCtx.rows.map((r) => {
        const obj = {};
        Object.keys(state.attribute_groups).forEach((g) => {
          const gi = varCol(g);
          obj[g] = gi >= 0 ? r[gi] || "" : "";
        });
        return comboKey(obj);
      })
    );

    let added = 0;
    combos.forEach((combo) => {
      if (existingKeys.has(comboKey(combo))) return;
      const nr = new Array(state.variation_headers.length).fill("");
      if (pidC >= 0) nr[pidC] = varCtx.pid;
      if (pskuC >= 0) nr[pskuC] = varCtx.psku;
      if (stC >= 0) nr[stC] = "publish";
      Object.entries(combo).forEach(([g, val]) => {
        const gi = varCol(g);
        if (gi >= 0) nr[gi] = val;
      });
      varCtx.rows.push(nr);
      added++;
    });

    autoFillVariationSkus(false);
    renderVarTable();
    toast(added ? `Generated ${added} new variation row(s).` : "All selected combinations already exist.");
  }

  function cleanVariationList(rows) {
    const pidC = varCol("Parent ID");
    const pskuC = varCol("Parent SKU");
    const vidC = varCol("Variation ID");
    const skuC = varCol("SKU");
    const regC = varCol("Regular price (GEL)");
    const attrCols = Object.keys(state.attribute_groups).map((g) => varCol(g)).filter((i) => i >= 0);

    const scoreRow = (vr) => {
      const attrs = attrCols.map((c) => (vr[c] || "").trim());
      const attrCount = attrs.filter(Boolean).length;
      const reg = regC >= 0 ? (vr[regC] || "").trim() : "";
      const hasPrice = reg !== "" && !isNaN(Number(reg.replace(",", "."))) && Number(reg.replace(",", ".")) > 0;
      const hasVid = vidC >= 0 && (vr[vidC] || "").trim() !== "";
      return {
        attrCount,
        attrKey: attrs.map((a) => a.toLowerCase()).join("|"),
        hasPrice,
        sku: skuC >= 0 ? (vr[skuC] || "").trim().toLowerCase() : "",
        score: (attrCount > 0 ? 100 : 0) + (hasPrice ? 50 : 0) + attrCount * 10 + (hasVid ? 1 : 0),
      };
    };

    // Group by parent key
    const groups = new Map();
    rows.forEach((vr) => {
      const pid = pidC >= 0 ? (vr[pidC] || "").trim() : "";
      const psku = pskuC >= 0 ? (vr[pskuC] || "").trim().toLowerCase() : "";
      const pkey = pid ? `id:${pid}` : psku ? `sku:${psku}` : "__orphan__";
      if (!groups.has(pkey)) groups.set(pkey, []);
      groups.get(pkey).push(vr);
    });

    const cleaned = [];
    let removed = 0;

    for (const list of groups.values()) {
      const scored = list.map((vr) => ({ vr, info: scoreRow(vr) }));
      const hasGoodRow = scored.some((x) => x.info.attrCount > 0 && x.info.hasPrice);

      // Step 1: drop completely empty rows, or attr-less ghost rows when parent has valid variations
      const kept1 = [];
      scored.forEach((item) => {
        if (item.info.attrCount === 0 && !item.info.hasPrice) {
          removed++;
          return;
        }
        if (hasGoodRow && item.info.attrCount === 0) {
          removed++;
          return;
        }
        kept1.push(item);
      });

      // Step 2: deduplicate by SKU (keep highest-scoring row with valid attribute + price)
      kept1.sort((a, b) => b.info.score - a.info.score);
      const seenSku = new Set();
      const seenAttr = new Set();
      kept1.forEach((item) => {
        if (item.info.sku && seenSku.has(item.info.sku)) {
          removed++;
          return;
        }
        if (item.info.attrCount > 0 && seenAttr.has(item.info.attrKey)) {
          removed++;
          return;
        }
        if (item.info.sku) seenSku.add(item.info.sku);
        if (item.info.attrCount > 0) seenAttr.add(item.info.attrKey);
        cleaned.push(item.vr);
      });
    }
    return { cleaned, removed };
  }

  function syncProductAttributesFromVariations(rowIndices = null) {
    const attrC = colIdx("Attributes");
    if (attrC < 0) return;
    const attrGroupNames = Object.keys(state.attribute_groups);
    if (!attrGroupNames.length) return;
    const indices = rowIndices || state.products.map((_, i) => i);
    indices.forEach((rIdx) => {
      const row = state.products[rIdx];
      if (!row || isRowEmpty(row)) return;
      const vars = getVariationsForRow(rIdx);
      if (!vars.length) return;
      const usedGroups = new Set();
      const varTokens = [];
      const seenLow = new Set();
      vars.forEach((v) => {
        attrGroupNames.forEach((g) => {
          const gi = varCol(g);
          const val = gi >= 0 ? (v[gi] || "").trim() : "";
          if (!val) return;
          usedGroups.add(g.toLowerCase());
          const tok = `${g}: ${val}`;
          if (!seenLow.has(tok.toLowerCase())) {
            seenLow.add(tok.toLowerCase());
            varTokens.push(tok);
          }
        });
      });
      if (!varTokens.length) return;
      const existing = (row[attrC] || "")
        .split(/\s*;\s*/)
        .map((s) => s.trim())
        .filter(Boolean);
      const merged = [];
      const mergedLow = new Set();
      existing.forEach((t) => {
        const g = t.includes(":") ? t.split(":", 1)[0].trim().toLowerCase() : "";
        if (g && usedGroups.has(g)) return;
        if (!mergedLow.has(t.toLowerCase())) {
          mergedLow.add(t.toLowerCase());
          merged.push(t);
        }
      });
      varTokens.forEach((t) => {
        if (!mergedLow.has(t.toLowerCase())) {
          mergedLow.add(t.toLowerCase());
          merged.push(t);
        }
      });
      row[attrC] = merged.join("; ");
    });
  }

  function cleanSelectedVariations() {
    const selectedRows = getSelectedRealRowIndices();
    if (!selectedRows.length) return;
    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const pidC = varCol("Parent ID");
    const pskuC = varCol("Parent SKU");

    const targetIds = new Set();
    const targetSkus = new Set();
    selectedRows.forEach((r) => {
      const pid = idC >= 0 ? (state.products[r][idC] || "").trim() : "";
      const sku = skuC >= 0 ? (state.products[r][skuC] || "").trim().toLowerCase() : "";
      if (pid) targetIds.add(pid);
      if (sku) targetSkus.add(sku);
    });

    const matchesSelected = (vr) => {
      const vpid = pidC >= 0 ? (vr[pidC] || "").trim() : "";
      const vpsku = pskuC >= 0 ? (vr[pskuC] || "").trim().toLowerCase() : "";
      if (vpid && targetIds.has(vpid)) return true;
      if (!vpid && vpsku && targetSkus.has(vpsku)) return true;
      return false;
    };

    const selectedVars = state.variations.filter(matchesSelected);
    const otherVars = state.variations.filter((vr) => !matchesSelected(vr));
    const { cleaned, removed } = cleanVariationList(selectedVars);
    if (removed === 0) {
      toast(`No duplicate or empty variations found across the ${selectedRows.length} selected product(s).`);
      return;
    }
    state.variations = [...otherVars, ...cleaned];
    syncProductAttributesFromVariations(selectedRows);
    setDirty(true);
    renderGrid();
    toast(`Cleaned ${removed} duplicate/empty variation(s) across ${selectedRows.length} selected product(s).`);
  }

  function cleanAllWorkbookVariations() {
    const { cleaned, removed } = cleanVariationList(state.variations);
    if (removed === 0) {
      toast("No duplicate or empty variations found — everything is clean!");
      return;
    }
    state.variations = cleaned;
    syncProductAttributesFromVariations();
    setDirty(true);
    renderGrid();
    toast(`Cleaned ${removed} duplicate/empty variation(s). (${cleaned.length} valid variations kept.)`);
  }

  function autoFillVariationSkus(overwrite = true) {
    if (!varCtx) return;
    const skuC = varCol("SKU");
    if (skuC < 0) return;
    const baseSku = varCtx.psku;
    const isNumeric = /^\d+$/.test(baseSku);
    const startNum = isNumeric ? BigInt(baseSku) : null;

    varCtx.rows.forEach((vrow, idx) => {
      if (!overwrite && (vrow[skuC] || "").trim() !== "") return;
      if (startNum !== null) {
        vrow[skuC] = String(startNum + BigInt(idx));
      } else if (baseSku) {
        vrow[skuC] = `${baseSku}-${idx + 1}`;
      }
    });
    renderVarTable();
  }

  function saveVariationModal() {
    if (!varCtx) return;
    const { rIdx, pid, psku, selectedAttrs, rows } = varCtx;
    const attrC = colIdx("Attributes");
    const regC = colIdx("Regular price (GEL)");
    const saleC = colIdx("Sale price (GEL)");

    if (attrC >= 0) {
      // Union selected checkboxes with every attribute value actually present in the variation rows
      const finalAttrs = new Set(selectedAttrs);
      Object.keys(state.attribute_groups).forEach((g) => {
        const gi = varCol(g);
        if (gi < 0) return;
        rows.forEach((v) => {
          const val = (v[gi] || "").trim();
          if (val) finalAttrs.add(`${g}: ${val}`);
        });
      });
      state.products[rIdx][attrC] = Array.from(finalAttrs).join("; ");
    }
    if ($("chkClearParentPrice").checked) {
      if (regC >= 0) state.products[rIdx][regC] = "";
      if (saleC >= 0) state.products[rIdx][saleC] = "";
      const discC = colIdx("Sale Discount");
      if (discC >= 0) state.products[rIdx][discC] = "";
    }

    // Replace this product's variation rows in state.variations
    const pidIdx = varCol("Parent ID");
    const pskuIdx = varCol("Parent SKU");
    const kept = state.variations.filter((v) => {
      const vpid = pidIdx >= 0 ? (v[pidIdx] || "").trim() : "";
      const vpsku = pskuIdx >= 0 ? (v[pskuIdx] || "").trim() : "";
      if (pid && vpid === pid) return false;
      if (!pid && psku && vpsku && vpsku.toLowerCase() === psku.toLowerCase()) return false;
      return true;
    });

    if (!state.varSaleDiscounts) state.varSaleDiscounts = {};
    rows.forEach((v) => {
      if (pidIdx >= 0) v[pidIdx] = pid;
      if (pskuIdx >= 0) v[pskuIdx] = psku;
      const key = getVarDiscountKey(v);
      const d = varCtx.discounts ? String(varCtx.discounts.get(v) || "").trim() : "";
      if (key) {
        const dp = parseSaleDiscount(d);
        if (dp.kind === "pct" || dp.kind === "unit") {
          state.varSaleDiscounts[key] = dp.normalized;
        } else {
          delete state.varSaleDiscounts[key];
        }
      }
    });
    state.variations = [...kept, ...rows];

    setDirty(true);
    $("varModal").classList.add("hidden");
    varCtx = null;
    renderGrid();
    toast(`Saved ${rows.length} variation(s).`);
  }

  // ---------- Rename Web Images Modal ----------
  const webRenameCtx = {
    scope: "all", // "all" | "selected"
    selectedRows: [],
    items: [], // [{ url, current_filename, new_filename, row, sku, name, field, shared_count, shared_skus, checked }]
    running: false,
  };

  function updateWebRenameScopeButtons() {
    const bAll = $("btnWebRenameScopeAll");
    const bSel = $("btnWebRenameScopeSel");
    if (bSel) {
      bSel.textContent = `Selected Rows (${webRenameCtx.selectedRows.length})`;
      bSel.disabled = webRenameCtx.selectedRows.length === 0;
    }
    if (bAll) {
      bAll.className = "btn btn-sm " + (webRenameCtx.scope === "all" ? "btn-primary" : "btn-secondary");
    }
    if (bSel) {
      bSel.className = "btn btn-sm " + (webRenameCtx.scope === "selected" ? "btn-primary" : "btn-secondary");
    }
  }

  function showWebRenameStep(step) {
    const s1 = $("webRenameStep1");
    const s2 = $("webRenameStep2");
    const bBack = $("btnWebRenameBack");
    const bReview = $("btnRunWebRename");
    const bNow = $("btnConfirmWebRenameNow");
    if (step === 2) {
      s1.classList.add("hidden");
      s2.classList.remove("hidden");
      bBack.classList.remove("hidden");
      bReview.classList.add("hidden");
      bNow.classList.remove("hidden");
    } else {
      s1.classList.remove("hidden");
      s2.classList.add("hidden");
      bBack.classList.add("hidden");
      bReview.classList.remove("hidden");
      bNow.classList.add("hidden");
    }
  }

  function openWebRenameConfirmStep() {
    const chosen = webRenameCtx.items.filter((x) => x.checked && x.new_filename);
    if (!chosen.length) {
      toast("Select at least one image to rename.", true);
      return;
    }
    const purgeOn = $("chkWebRenamePurge")?.checked;
    $("webRenameConfirmSummary").textContent =
      `Ready to rename ${chosen.length} web image(s) on odella.ge` +
      (purgeOn ? " and then open Purge & Speed." : ".");
    $("btnConfirmWebRenameNow").textContent = `Yes, Rename ${chosen.length} Image(s) on odella.ge`;
    $("btnConfirmWebRenameNow").disabled = false;
    $("webRenameProgressWrap").classList.add("hidden");

    const body = $("webRenameConfirmBody");
    body.innerHTML = "";
    chosen.forEach((it) => {
      const tr = document.createElement("tr");
      const tdRow = document.createElement("td");
      tdRow.className = "mono";
      tdRow.style.fontSize = "11.5px";
      tdRow.textContent = `#${it.row} · ${it.sku}`;
      tr.appendChild(tdRow);

      const tdOld = document.createElement("td");
      tdOld.className = "mono";
      tdOld.style.cssText = "font-size:11.5px;word-break:break-all;color:var(--muted);";
      tdOld.textContent = it.current_filename;
      tr.appendChild(tdOld);

      const tdNew = document.createElement("td");
      tdNew.className = "mono";
      tdNew.style.cssText = "font-size:11.5px;word-break:break-all;color:var(--accent);font-weight:600;";
      tdNew.textContent = it.new_filename;
      tr.appendChild(tdNew);

      body.appendChild(tr);
    });
    showWebRenameStep(2);
  }

  async function openWebRenameModal(defaultScope = "all", explicitRows = null) {
    if (!state.headers.length) {
      toast("Open or import a spreadsheet first.", true);
      return;
    }
    if (!state.settings.wp_username || !state.settings.wp_app_password) {
      toast("Please configure your odella.ge WordPress Username & Application Password in Settings first.", true);
      openSettingsModal();
      return;
    }
    webRenameCtx.selectedRows = Array.isArray(explicitRows) && explicitRows.length
      ? [...explicitRows]
      : getSelectedRealRowIndices();
    webRenameCtx.scope = (defaultScope === "selected" && webRenameCtx.selectedRows.length > 0) ? "selected" : "all";
    webRenameCtx.running = false;
    $("webRenameProgressWrap").classList.add("hidden");
    $("btnRunWebRename").disabled = false;
    showWebRenameStep(1);
    updateWebRenameScopeButtons();
    $("webRenameModal").classList.remove("hidden");
    await loadWebRenamePlan();
  }

  async function loadWebRenamePlan() {
    $("webRenameSummary").textContent = "Scanning web images...";
    $("webRenameBody").innerHTML = "";
    const rowIndices = webRenameCtx.scope === "selected" ? webRenameCtx.selectedRows : null;
    const res = await apiPost("/api/plan-web-rename", {
      headers: state.headers,
      products: state.products,
      row_indices: rowIndices,
    });
    if (!res.ok) {
      $("webRenameSummary").textContent = res.error || "Failed to scan web images.";
      return;
    }
    webRenameCtx.items = (res.items || []).map((it) => ({ ...it, checked: true }));
    const scopeText = webRenameCtx.scope === "selected" ? `${webRenameCtx.selectedRows.length} selected row(s)` : "all rows";
    $("webRenameSummary").textContent = webRenameCtx.items.length
      ? `${webRenameCtx.items.length} web image(s) need renaming in ${scopeText} (${res.skipped_matching || 0} already match SKU & Name).`
      : `All ${res.total_web_images || 0} web image(s) in ${scopeText} already match their SKU & Name!`;
    $("chkWebRenameAll").checked = webRenameCtx.items.length > 0;
    $("btnRunWebRename").disabled = webRenameCtx.items.length === 0;
    renderWebRenameTable();
  }

  function renderWebRenameTable() {
    const body = $("webRenameBody");
    body.innerHTML = "";
    if (!webRenameCtx.items.length) {
      body.innerHTML = `<tr><td colspan="6" class="muted-sm" style="text-align:center;padding:18px;">Nothing to rename — all web images already follow {SKU}.{Name}_{Idx}.{ext}.</td></tr>`;
      return;
    }
    webRenameCtx.items.forEach((it) => {
      const tr = document.createElement("tr");

      const tdChk = document.createElement("td");
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = !!it.checked;
      cb.addEventListener("change", () => {
        it.checked = cb.checked;
        const checkedCount = webRenameCtx.items.filter((x) => x.checked).length;
        $("btnRunWebRename").disabled = checkedCount === 0;
        $("chkWebRenameAll").checked = checkedCount === webRenameCtx.items.length;
      });
      tdChk.appendChild(cb);
      tr.appendChild(tdChk);

      const tdImg = document.createElement("td");
      const img = document.createElement("img");
      img.src = it.url;
      img.referrerPolicy = "no-referrer";
      img.style.cssText = "width:34px;height:44px;object-fit:cover;border-radius:4px;cursor:pointer;display:block;";
      img.addEventListener("click", () => openLightbox(it.url, it.current_filename));
      tdImg.appendChild(img);
      tr.appendChild(tdImg);

      const tdRow = document.createElement("td");
      tdRow.innerHTML = `<div class="mono" style="font-size:11.5px;">#${it.row} · ${escapeHtml(it.sku)}</div><div class="muted-sm" style="font-size:10.5px;">${escapeHtml(it.field)}</div>`;
      tr.appendChild(tdRow);

      const tdOld = document.createElement("td");
      tdOld.className = "mono";
      tdOld.style.cssText = "font-size:11.5px;word-break:break-all;color:var(--muted);";
      tdOld.textContent = it.current_filename;
      tdOld.title = it.url;
      tr.appendChild(tdOld);

      const tdNew = document.createElement("td");
      const inp = document.createElement("input");
      inp.type = "text";
      inp.className = "input input-sm mono";
      inp.value = it.new_filename;
      inp.addEventListener("input", () => {
        it.new_filename = inp.value.trim();
      });
      tdNew.appendChild(inp);
      tr.appendChild(tdNew);

      const tdSh = document.createElement("td");
      tdSh.className = "muted-sm";
      tdSh.textContent = it.shared_count > 1 ? `${it.shared_count} prods` : "1 prod";
      if (it.shared_count > 1) tdSh.title = `Shared across SKUs: ${(it.shared_skus || []).join(", ")}`;
      tr.appendChild(tdSh);

      body.appendChild(tr);
    });
  }

  async function runWebRename() {
    if (webRenameCtx.running) return;
    const chosen = webRenameCtx.items.filter((x) => x.checked && x.new_filename);
    if (!chosen.length) {
      toast("Select at least one image to rename.", true);
      return;
    }
    webRenameCtx.running = true;
    $("btnConfirmWebRenameNow").disabled = true;
    $("btnWebRenameBack").disabled = true;
    $("webRenameProgressWrap").classList.remove("hidden");
    const fill = $("webRenameProgressFill");
    const txt = $("webRenameProgressText");

    const urlMap = new Map(); // old_url -> new_url
    let totalRenamed = 0;
    let totalUnchanged = 0;
    let totalErrors = 0;
    const errorMsgs = [];
    const batchSize = 15;

    for (let i = 0; i < chosen.length; i += batchSize) {
      const slice = chosen.slice(i, i + batchSize);
      const pct = Math.round((i / chosen.length) * 100);
      fill.style.width = `${pct}%`;
      txt.textContent = `Renaming batch ${Math.floor(i / batchSize) + 1} of ${Math.ceil(chosen.length / batchSize)} (${i}/${chosen.length})...`;
      const res = await apiPost("/api/wp/rename-web-images", {
        items: slice.map((x) => ({ url: x.url, new_filename: x.new_filename })),
      });
      if (!res.ok) {
        totalErrors += slice.length;
        errorMsgs.push(res.error || "Request failed");
        continue;
      }
      totalRenamed += res.renamed || 0;
      totalUnchanged += res.unchanged || 0;
      totalErrors += res.errors || 0;
      (res.results || []).forEach((r) => {
        if ((r.status === "renamed" || r.status === "unchanged") && r.old_url && r.new_url && r.old_url !== r.new_url) {
          urlMap.set(r.old_url, r.new_url);
        } else if (r.status === "error" && r.error) {
          errorMsgs.push(`${r.old_url?.split("/").pop() || "image"}: ${r.error}`);
        }
      });
    }

    fill.style.width = "100%";
    txt.textContent = `Done: ${totalRenamed} renamed, ${totalUnchanged} unchanged, ${totalErrors} error(s).`;

    if (urlMap.size > 0) {
      pushUndo();
      const imgCols = [colIdx("Featured image"), colIdx("Hover image"), colIdx("Gallery images")].filter((c) => c >= 0);
      state.products.forEach((row) => {
        imgCols.forEach((c) => {
          const cell = String(row[c] || "").trim();
          if (!cell) return;
          const toks = splitPictures(cell);
          let changed = false;
          const nextToks = toks.map((t) => {
            if (urlMap.has(t)) {
              changed = true;
              return urlMap.get(t);
            }
            return t;
          });
          if (changed) {
            const isGal = (state.headers[c] || "").trim().toLowerCase() === "gallery images";
            row[c] = isGal ? nextToks.join("; ") : (nextToks[0] || "");
          }
        });
      });
      setDirty(true);
      renderGrid();
      if (state.drawerTab === "web" && !$("imagesDrawer").classList.contains("hidden")) {
        renderWebImagesDrawer();
      }
    }

    webRenameCtx.running = false;
    $("btnConfirmWebRenameNow").disabled = false;
    $("btnWebRenameBack").disabled = false;

    if (totalErrors > 0) {
      toast(`Renamed ${totalRenamed} web image(s), ${totalErrors} failed (${errorMsgs[0] || "check log"}).`, true);
    } else {
      toast(`Renamed ${totalRenamed} web image(s) on odella.ge and updated sheet URLs!`);
    }

    if (totalRenamed > 0) {
      $("webRenameModal").classList.add("hidden");
      if ($("chkWebRenamePurge")?.checked) {
        openPurgeModal();
      }
    }
  }

  // ---------- Fix Naming & Zip Modal ----------
  async function openZipModal() {
    const res = await apiPost("/api/fix-and-zip", {
      file: state.file,
      headers: state.headers,
      products: state.products,
      paths: state.paths,
      images_root: state.images_root,
      preview: true,
    });
    if (!res.ok) {
      toast(res.error || "Failed to preview zip", true);
      return;
    }

    $("zipSummary").textContent = `${res.total} unique local image(s) will be renamed as {SKU}.{PRODUCT_NAME}_{INDEX}.{ext} and packed into an upload-ready .zip.`;

    const missBox = $("zipMissingBox");
    if (res.missing && res.missing.length) {
      missBox.classList.remove("hidden");
      missBox.innerHTML =
        `<b>${res.missing.length} local image(s) could not be found on disk:</b><br>` +
        res.missing.map((m) => `Row ${m.row} (${m.sku} · ${m.field}): <code>${m.token}</code>`).join("<br>");
    } else {
      missBox.classList.add("hidden");
    }

    const body = $("zipPreviewBody");
    body.innerHTML = "";
    if (!res.items.length) {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td colspan="5" class="muted-sm" style="padding:14px;text-align:center;">No local images found in the sheet yet. Drop local images onto Hover / Featured / Gallery first.</td>`;
      body.appendChild(tr);
    } else {
      res.items.forEach((it) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${it.row}</td>
          <td class="mono">${esc(it.sku)}</td>
          <td>${esc(it.field)}</td>
          <td class="mono muted-sm">${esc(it.old_token)}</td>
          <td class="mono" style="color:#6ee7b7;">${esc(it.new_token)}</td>
        `;
        body.appendChild(tr);
      });
    }
    $("btnRunZip").disabled = res.items.length === 0;
    $("chkRenameOriginals").checked = false;
    $("zipModal").classList.remove("hidden");
  }

  async function executeFixAndZip() {
    const renameOriginals = $("chkRenameOriginals").checked;
    const res = await apiPost("/api/fix-and-zip", {
      file: state.file,
      headers: state.headers,
      products: state.products,
      paths: state.paths,
      images_root: state.images_root,
      preview: false,
      rename_originals: renameOriginals,
    });
    if (!res.ok) {
      toast(res.error || "Zip failed", true);
      return;
    }
    state.products = res.products;
    state.paths = res.paths;
    setDirty(true);
    $("zipModal").classList.add("hidden");
    renderGrid();
    await saveWorkbook(null);
    if (renameOriginals) loadLocalImages();
    toast(`Created ${res.zip_name} (${res.total} images${res.renamed_on_disk ? `, ${res.renamed_on_disk} originals renamed` : ""}) and saved sheet!`);
  }

  // ---------- Local & Web Images Drawer ----------
  const DRAWER_BATCH = 60;
  let drawerMode = "local"; // "local" | "web"
  let drawerFilteredItems = [];
  let drawerRenderedCount = 0;
  let selectedDrawerItem = null; // { name, folder, path, url? }

  function collectWebImages() {
    const imgCols = [colIdx("Featured image"), colIdx("Hover image"), colIdx("Gallery images")].filter((c) => c >= 0);
    const catC = colIdx("Categories");
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const byUrl = new Map();

    state.products.forEach((row) => {
      if (isRowEmpty(row)) return;
      const catRaw = catC >= 0 ? String(row[catC] || "").split(/\s*;\s*/)[0].trim() : "";
      const folder = catRaw || "(Uncategorized)";
      const sku = skuC >= 0 ? String(row[skuC] || "").trim() : "";
      const pname = nameC >= 0 ? String(row[nameC] || "").trim() : "";
      imgCols.forEach((c) => {
        splitPictures(row[c] || "").forEach((tok) => {
          const p = parsePictureToken(tok);
          if (p.kind !== "url") return;
          if (!byUrl.has(p.file)) {
            let fname = p.file;
            try {
              fname = decodeURIComponent(new URL(p.file).pathname.split("/").pop() || p.file);
            } catch {}
            byUrl.set(p.file, {
              kind: "web",
              url: p.file,
              name: fname,
              folder,
              searchMeta: `${fname} ${sku} ${pname} ${folder}`.toLowerCase(),
            });
          }
        });
      });
    });

    const groups = {};
    byUrl.forEach((it) => {
      if (!groups[it.folder]) groups[it.folder] = [];
      groups[it.folder].push(it);
    });
    const countEl = $("webImgCount");
    if (countEl) countEl.textContent = String(byUrl.size);
    return { total: byUrl.size, groups };
  }

  function setDrawerMode(mode) {
    drawerMode = mode === "web" ? "web" : "local";
    const isWeb = drawerMode === "web";
    $("tabDrawerLocal").classList.toggle("active", !isWeb);
    $("tabDrawerWeb").classList.toggle("active", isWeb);
    $("drawerTitle").textContent = isWeb ? "Web Images" : "Local Images";
    $("localRootWrap").classList.toggle("hidden", isWeb);
    $("drawerHint").innerHTML = isWeb
      ? "Drag onto <b>Hover</b>, <b>Featured</b>, or <b>Gallery</b>, or click <b>F / H / +G</b> to reuse existing web URLs."
      : "Drag onto <b>Hover</b>, <b>Featured</b>, or <b>Gallery</b>. Select an image &amp; press <b>F2</b> to rename original file.";
    if (isWeb) {
      populateWebDrawerFolders();
      renderDrawerGrid();
    } else {
      loadLocalImages();
    }
  }

  function populateWebDrawerFolders() {
    const webData = collectWebImages();
    state.webImages = webData;
    const sel = $("drawerFolderSelect");
    const prev = sel.value;
    sel.innerHTML = "";
    const allOpt = document.createElement("option");
    allOpt.value = "__all__";
    allOpt.textContent = `All categories (${webData.total || 0})`;
    sel.appendChild(allOpt);
    Object.keys(webData.groups)
      .sort((a, b) => a.localeCompare(b))
      .forEach((folder) => {
        const items = webData.groups[folder];
        const o = document.createElement("option");
        o.value = folder;
        o.textContent = `${folder} (${items.length})`;
        if (folder === prev) o.selected = true;
        sel.appendChild(o);
      });
  }

  let browsingFolder = false;

  async function browseRootFolder() {
    if (browsingFolder) return;
    browsingFolder = true;
    const btn = $("btnBrowseRoot");
    if (btn) btn.disabled = true;
    try {
      const initial = $("imagesRootInput").value.trim() || state.images_root || "";
      const res = await apiGet("/api/browse-folder?initial=" + encodeURIComponent(initial));
      if (res.cancelled || res.busy) return;
      if (!res.ok || !res.path) {
        toast(res.error || "Could not open folder browser.", true);
        return;
      }
      $("imagesRootInput").value = res.path;
      state.images_root = res.path;
      await loadLocalImages();
      toast(`Local images folder set to: ${res.path}`);
    } finally {
      browsingFolder = false;
      if (btn) btn.disabled = false;
    }
  }

  function readDirectoryEntrySamples(dirEntry) {
    return new Promise((resolve) => {
      try {
        const reader = dirEntry.createReader();
        reader.readEntries(
          (entries) => resolve((entries || []).slice(0, 10).map((e) => e.name)),
          () => resolve([])
        );
      } catch {
        resolve([]);
      }
    });
  }

  async function handleRootFolderDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    $("rootFolderDropZone").classList.remove("drag-over");

    // 1. Plain text path dropped
    const textPath = (e.dataTransfer.getData("text/plain") || "").trim().replace(/^["']|["']$/g, "");
    if (textPath && (/^[a-zA-Z]:[\\/]/.test(textPath) || textPath.startsWith("\\\\"))) {
      $("imagesRootInput").value = textPath;
      state.images_root = textPath;
      await loadLocalImages();
      return;
    }

    // 2. Folder dragged from Windows Explorer via DataTransferItem.webkitGetAsEntry
    const items = Array.from(e.dataTransfer.items || []);
    for (const item of items) {
      const entry = item.webkitGetAsEntry && item.webkitGetAsEntry();
      if (entry && entry.isDirectory) {
        const sampleFiles = await readDirectoryEntrySamples(entry);
        const res = await apiPost("/api/resolve-folder", {
          folder_name: entry.name,
          sample_files: sampleFiles,
          hints: [state.images_root, state.file].filter(Boolean),
        });
        if (res.ok && res.path) {
          $("imagesRootInput").value = res.path;
          state.images_root = res.path;
          await loadLocalImages();
          toast(`Local images folder set to: ${res.path}`);
        } else {
          toast(res.error || "Could not resolve dropped folder. Click Browse to pick it.", true);
        }
        return;
      }
    }

    toast("Drop a folder from Explorer or click Browse to select the root folder.", true);
  }

  async function loadLocalImages() {
    const root = $("imagesRootInput").value.trim() || state.images_root;
    state.images_root = root;
    const res = await apiGet("/api/local-images?root=" + encodeURIComponent(root));
    if (res.error) {
      toast(res.error, true);
      return;
    }
    state.localImages = res;
    const sel = $("drawerFolderSelect");
    const prev = sel.value;
    sel.innerHTML = "";
    const allOpt = document.createElement("option");
    allOpt.value = "__all__";
    allOpt.textContent = `All folders (${res.total || 0})`;
    sel.appendChild(allOpt);
    Object.entries(res.groups || {}).forEach(([folder, items]) => {
      const o = document.createElement("option");
      o.value = folder;
      o.textContent = `${folder} (${items.length})`;
      if (folder === prev) o.selected = true;
      sel.appendChild(o);
    });
    renderDrawerGrid();
  }

  async function assignLocalImageToActiveRow(it, colName) {
    const isWeb = it.kind === "web" && it.url;
    const matchedUrl = isWeb ? it.url : await matchLocalToWebUrl(it.path);

    // If the +Add New Product modal is open, assign directly into it!
    if (!$("addProductModal").classList.contains("hidden")) {
      const entry = matchedUrl ? { name: it.name, url: matchedUrl, path: "" } : { name: it.name, path: it.path };
      const low = colName.toLowerCase();
      if (low === "featured image") addProdState.feat = entry;
      else if (low === "hover image") addProdState.hover = entry;
      else addProdState.gallery.push(entry);
      renderAddProductImages();
      toast(
        matchedUrl && !isWeb
          ? `"${it.name}" already on web — used web URL in New Product form!`
          : `Added "${it.name}" to ${colName} in New Product form.`
      );
      return;
    }
    const rIdx = getActiveRealRowIndex();
    if (rIdx < 0 || !state.products[rIdx]) {
      toast("Select a product row first.", true);
      return;
    }
    const ci = colIdx(colName);
    if (ci < 0) {
      toast(`Column "${colName}" not found.`, true);
      return;
    }
    if (matchedUrl) {
      applyTokenToCell(rIdx, ci, colName.toLowerCase(), matchedUrl);
      toast(isWeb ? `Added web image to ${colName}.` : `Same image already on web — used existing web URL!`);
      return;
    }
    openDropModal(rIdx, ci, colName.toLowerCase(), it.name, it.path);
  }

  function createDrawerCard(it) {
    const isWeb = it.kind === "web" && it.url;
    const previewSrc = isWeb ? it.url : "/api/local-image?path=" + encodeURIComponent(it.path);
    const caption = isWeb ? it.url : it.path;
    const div = document.createElement("div");
    div.className = "drawer-item";
    div.tabIndex = 0;
    div.draggable = true;
    div.dataset.path = caption;
    div.title = isWeb
      ? `${it.url}\nClick F/H/+G or drag onto cell • Double-click or 🔍 to preview`
      : `${it.path}\nClick to select • F2 to rename • Double-click or 🔍 to preview • F/H/+G to assign`;
    if (selectedDrawerItem && (selectedDrawerItem.path === it.path || (isWeb && selectedDrawerItem.url === it.url))) {
      div.classList.add("selected");
    }
    div.innerHTML = `
      <img src="${esc(previewSrc)}" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="">
      <button type="button" class="drawer-peek-btn" title="Peek / Preview image">🔍</button>
      <div class="drawer-assign-bar">
        <button type="button" class="drawer-assign-btn" data-assign="Featured image" title="Set as Featured image">F</button>
        <button type="button" class="drawer-assign-btn" data-assign="Hover image" title="Set as Hover image">H</button>
        <button type="button" class="drawer-assign-btn" data-assign="Gallery images" title="Add to Gallery">+G</button>
        <button type="button" class="drawer-assign-btn drawer-edit-btn" title="Crop / Rotate / Resize image">✂</button>
      </div>
      <div class="drawer-item-name">${esc(it.name)}</div>
      <div class="drawer-item-folder">${esc(it.folder)}</div>
    `;
    div.querySelectorAll(".drawer-assign-btn[data-assign]").forEach((ab) => {
      ab.addEventListener("click", (e) => {
        e.stopPropagation();
        assignLocalImageToActiveRow(it, ab.dataset.assign);
      });
    });
    const dEditBtn = div.querySelector(".drawer-edit-btn");
    if (dEditBtn) {
      dEditBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        openImageStudio([{
          label: it.name,
          src: isWeb ? "/api/proxy-image?url=" + encodeURIComponent(it.url) : previewSrc,
          originalPath: isWeb ? "" : it.path,
          filename: it.name,
          rIdx: -1,
          ci: -1,
          hLow: "",
          tokenIdx: -1,
          folder: "",
        }]);
      });
    }
    const openPeek = (e) => {
      if (e) e.stopPropagation();
      const lbItems = drawerFilteredItems.map((x) => makeDrawerLightboxItem(x));
      const startIdx = drawerFilteredItems.findIndex((x) => (isWeb ? x.url === it.url : x.path === it.path));
      openLightbox(previewSrc, caption, lbItems, Math.max(0, startIdx));
    };
    div.querySelector(".drawer-peek-btn").addEventListener("click", openPeek);
    div.addEventListener("click", (e) => {
      e.stopPropagation();
      selectDrawerItem(it, div);
    });
    div.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      selectDrawerItem(it, div);
      showDrawerContextMenu(e.clientX, e.clientY, it);
    });
    div.addEventListener("dragstart", (e) => {
      if (isWeb) {
        e.dataTransfer.setData("application/x-odella-web-image", JSON.stringify({ url: it.url, name: it.name }));
      } else {
        e.dataTransfer.setData("application/x-odella-local-image", JSON.stringify({ name: it.name, path: it.path }));
      }
    });
    div.addEventListener("dblclick", openPeek);
    return div;
  }

  function selectDrawerItem(it, cardEl) {
    selectedDrawerItem = it;
    $("drawerGrid").querySelectorAll(".drawer-item.selected").forEach((el) => el.classList.remove("selected"));
    if (cardEl) {
      cardEl.classList.add("selected");
      cardEl.focus({ preventScroll: true });
    }
  }

  function appendNextDrawerBatch() {
    const grid = $("drawerGrid");
    const oldMore = grid.querySelector(".drawer-load-more");
    if (oldMore) oldMore.remove();

    const nextSlice = drawerFilteredItems.slice(drawerRenderedCount, drawerRenderedCount + DRAWER_BATCH);
    if (!nextSlice.length) return;
    const frag = document.createDocumentFragment();
    nextSlice.forEach((it) => frag.appendChild(createDrawerCard(it)));
    drawerRenderedCount += nextSlice.length;

    const remaining = drawerFilteredItems.length - drawerRenderedCount;
    if (remaining > 0) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn btn-sm btn-secondary drawer-load-more";
      btn.textContent = `Load more (${remaining} remaining)`;
      btn.addEventListener("click", appendNextDrawerBatch);
      frag.appendChild(btn);
    }
    grid.appendChild(frag);
  }

  function renderDrawerGrid() {
    const grid = $("drawerGrid");
    grid.innerHTML = "";
    const folder = $("drawerFolderSelect").value || "__all__";
    const q = $("drawerSearch").value.trim().toLowerCase();
    const sourceGroups = drawerMode === "web" ? (state.webImages?.groups || {}) : (state.localImages?.groups || {});
    const items = [];
    Object.entries(sourceGroups).forEach(([f, arr]) => {
      if (folder !== "__all__" && f !== folder) return;
      arr.forEach((it) => {
        const hay = it.searchMeta || `${it.name} ${f}`.toLowerCase();
        if (!q || hay.includes(q)) {
          items.push(it);
        }
      });
    });

    drawerFilteredItems = items;
    drawerRenderedCount = 0;

    if (!items.length) {
      selectedDrawerItem = null;
      grid.innerHTML = `<div class="muted-sm" style="grid-column:1/-1;padding:12px;">No images found.</div>`;
      return;
    }

    appendNextDrawerBatch();
  }

  function openRenameImageModal(item) {
    if (!item || !item.path) return;
    const dotIdx = item.name.lastIndexOf(".");
    const stem = dotIdx > 0 ? item.name.slice(0, dotIdx) : item.name;
    const ext = dotIdx > 0 ? item.name.slice(dotIdx) : "";
    $("renameImageInput").value = stem;
    $("renameImageExt").textContent = ext;
    $("renameImagePath").textContent = item.path;
    $("renameImageModal").classList.remove("hidden");
    setTimeout(() => {
      const inp = $("renameImageInput");
      inp.focus();
      inp.select();
    }, 20);
  }

  async function executeRenameLocalImage() {
    if (!selectedDrawerItem) return;
    const stem = $("renameImageInput").value.trim();
    const ext = $("renameImageExt").textContent || "";
    if (!stem) {
      toast("Please enter a filename.", true);
      return;
    }
    const newName = stem.toLowerCase().endsWith(ext.toLowerCase()) ? stem : stem + ext;
    const res = await apiPost("/api/rename-local-image", {
      old_path: selectedDrawerItem.path,
      new_name: newName,
    });
    if (!res.ok) {
      toast(res.error || "Rename failed.", true);
      return;
    }
    $("renameImageModal").classList.add("hidden");

    const oldNameLow = res.old_name.toLowerCase();
    const oldPathLow = res.old_path.toLowerCase();

    // Update path map entries
    const nextPaths = {};
    Object.entries(state.paths || {}).forEach(([k, v]) => {
      if (String(v || "").toLowerCase() === oldPathLow) {
        const parsed = parsePictureToken(k);
        const newKey = parsed.folder ? `${parsed.folder}/${res.new_name}` : res.new_name;
        nextPaths[newKey] = res.new_path;
      } else {
        nextPaths[k] = v;
      }
    });
    nextPaths[res.new_name] = res.new_path;
    state.paths = nextPaths;

    // Update any product image cells referencing the old local filename
    const imgCols = [colIdx("Hover image"), colIdx("Featured image"), colIdx("Gallery images")].filter((c) => c >= 0);
    let updatedCells = 0;
    state.products.forEach((row) => {
      imgCols.forEach((c) => {
        const cell = (row[c] || "").trim();
        if (!cell) return;
        const isGal = c === colIdx("Gallery images");
        let changed = false;
        const parts = splitPictures(cell).map((tok) => {
          const p = parsePictureToken(tok);
          if (p.kind === "file" && p.file.toLowerCase() === oldNameLow) {
            changed = true;
            return p.folder ? `${p.folder}/${res.new_name}` : res.new_name;
          }
          return tok;
        });
        if (changed) {
          row[c] = isGal ? parts.join("; ") : (parts[0] || "");
          updatedCells++;
        }
      });
    });

    selectedDrawerItem = { ...selectedDrawerItem, name: res.new_name, path: res.new_path };
    await loadLocalImages();
    if (updatedCells > 0) {
      setDirty(true);
      renderGrid();
    }
    toast(`Renamed "${res.old_name}" → "${res.new_name}"${updatedCells ? ` (updated ${updatedCells} sheet cell(s))` : ""}`);
  }

  // ---------- Rich Text Editor Modal ----------
  let richCtx = null;

  function textToEditorHtml(raw) {
    const s = String(raw || "");
    if (/<(p|br|ul|ol|li|strong|b|em|i|u|a|div|h[1-6])\b/i.test(s)) return s;
    return esc(s).replace(/\r\n|\r|\n/g, "<br>");
  }

  function cleanEditorHtml(html) {
    // Normalize <b>/<i> to <strong>/<em> and strip empty trailing breaks
    return String(html || "")
      .replace(/<b>/gi, "<strong>")
      .replace(/<\/b>/gi, "</strong>")
      .replace(/<i>/gi, "<em>")
      .replace(/<\/i>/gi, "</em>")
      .replace(/(<br\s*\/?>\s*)+$/i, "")
      .trim();
  }

  function openRichModal(rIdx, ci) {
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const row = state.products[rIdx];
    richCtx = { rIdx, ci, htmlMode: false };
    $("richModalTitle").textContent = state.headers[ci];
    $("richModalSub").textContent = `Row ${rIdx + 2} · ${(row[skuC] || "").trim()} · ${(row[nameC] || "").trim()}`;
    $("richEditor").innerHTML = textToEditorHtml(row[ci] || "");
    $("richHtmlArea").value = row[ci] || "";
    $("richEditor").classList.remove("hidden");
    $("richHtmlArea").classList.add("hidden");
    $("btnRichHtmlToggle").textContent = "HTML";
    $("richModal").classList.remove("hidden");
    $("richEditor").focus();
  }

  function openWarningsModal() {
    runValidation();
    const defs = [
      { key: "missing_price", title: "Missing GEL Price", desc: "Simple products without GEL price (or variable products with no priced variations) are hidden / kept as Draft on odella.ge." },
      { key: "missing_featured", title: "Missing Featured Image", desc: "Published products without a Featured image are automatically hidden from the catalog." },
      { key: "draft", title: "In Draft Status", desc: "Products set to Draft (or empty status) will not be visible to customers until published." },
      { key: "bad_vars", title: "Variations: Duplicates / Empty Prices", desc: "Big warning — duplicate SKUs/attributes, missing attributes, unpriced variations, or 0 variations. Use Clean Variations to fix." },
      { key: "missing_category", title: "Missing Category", desc: "Products with no category won't appear on shop category pages." },
      { key: "missing_desc", title: "Missing Descriptions", desc: "Both Short description and Description are empty." },
      { key: "missing_en", title: "Missing English Translation", desc: "Missing Name (EN) or Description (EN) — won't show properly on the English storefront." },
      { key: "dup_sku", title: "Duplicate SKUs", desc: "Two or more rows share the same SKU — fix before pushing to odella.ge." },
      { key: "dup_name_ka", title: "Duplicate Georgian Names", desc: "Two or more products have the exact same Georgian Name." },
      { key: "dup_name_en", title: "Duplicate English Names", desc: "Two or more products have the exact same English Name." },
      { key: "incomplete_vars", title: "Variations: Fewer Than Attribute Combos", desc: "Minor info — product has fewer variations than its full attribute combinations (often intentional, e.g. only 2 sizes).", info: true },
      { key: "missing_hover", title: "Missing Hover Image", desc: "Product card won't swap to a second photo on mouse hover.", info: true },
      { key: "eur_usd_auto", title: "Using Default Auto EUR / USD Rate", desc: "EUR or USD cell is blank — odella-pricing.php will auto-calculate from GEL (NBG + 1%, rounded up to 9). Type a number only to manually override.", info: true },
      { key: "shared_images", title: "Shared / Same Images (Grouped)", desc: `Minor info — ${(state.sharedImageGroups || []).length} image(s) used by 2 or more products (Featured, Hover, or Gallery). Click to view rows grouped by image.`, info: true },
    ];
    const wrap = $("warnSummaryCards");
    wrap.innerHTML = "";
    defs.forEach((d) => {
      const count = state.warnBuckets[d.key] ? state.warnBuckets[d.key].size : 0;
      const card = document.createElement("div");
      card.className = "warn-card" + (count === 0 ? " ok" : d.info ? " info" : "");
      card.innerHTML = `
        <div class="warn-card-head">
          <span>${esc(d.title)}</span>
          <span class="warn-card-count">${count}</span>
        </div>
        <div class="warn-card-desc">${esc(d.desc)}</div>
      `;
      card.addEventListener("click", () => {
        if (count === 0) return;
        state.warnTypeFilter = d.key;
        $("warnModal").classList.add("hidden");
        setFilter("warnings", true);
        toast(`Filtered to: ${d.title} (${count} row(s))`);
      });
      wrap.appendChild(card);
    });
    $("warnModal").classList.remove("hidden");
  }

  // ---------- Lightbox ----------
  let lightboxState = { items: [], idx: 0 };

  function makeDrawerLightboxItem(x) {
    const isWeb = x.kind === "web" && x.url;
    return {
      src: isWeb ? x.url : "/api/local-image?path=" + encodeURIComponent(x.path),
      caption: isWeb ? x.url : x.path,
      source: "drawer",
      drawerItem: x,
    };
  }

  function makeCellLightboxItem(rIdx, ci, hLow, tok, tokenIdx, captionOverride = "") {
    return {
      src: imagePreviewSrc(tok),
      caption: captionOverride || tok,
      source: "cell",
      rIdx,
      ci,
      hLow: String(hLow || "").toLowerCase(),
      token: tok,
      tokenIdx,
    };
  }

  function copyCellTokenToSlot(rIdx, token, targetColName) {
    const targetCi = colIdx(targetColName);
    if (targetCi < 0 || !state.products[rIdx]) return;
    const isGal = targetColName.toLowerCase() === "gallery images";
    pushUndo();
    if (isGal) {
      const existing = splitPictures(state.products[rIdx][targetCi] || "");
      if (!existing.includes(token)) existing.push(token);
      state.products[rIdx][targetCi] = existing.join("; ");
    } else {
      state.products[rIdx][targetCi] = token;
    }
    setDirty(true);
    renderGrid();
    toast(isGal ? "Added to Gallery." : `Set as ${targetColName}.`);
  }

  function removeCellTokenFromLightbox(it) {
    if (!state.products[it.rIdx] || it.ci < 0) return;
    const isGal = it.hLow === "gallery images";
    pushUndo();
    if (isGal) {
      const toks = splitPictures(state.products[it.rIdx][it.ci] || "");
      const rmIdx = toks.indexOf(it.token);
      if (rmIdx >= 0) toks.splice(rmIdx, 1);
      else if (it.tokenIdx >= 0 && it.tokenIdx < toks.length) toks.splice(it.tokenIdx, 1);
      state.products[it.rIdx][it.ci] = toks.join("; ");
    } else {
      state.products[it.rIdx][it.ci] = "";
    }
    setDirty(true);
    renderGrid();
    const slotLabel = it.hLow === "featured image" ? "Featured" : it.hLow === "hover image" ? "Hover" : "Gallery";
    toast(`Removed from ${slotLabel}.`);

    lightboxState.items.splice(lightboxState.idx, 1);
    // Keep remaining tokenIdx values aligned if gallery
    if (isGal) {
      lightboxState.items.forEach((x, i) => {
        if (x.source === "cell" && x.rIdx === it.rIdx && x.ci === it.ci) x.tokenIdx = i;
      });
    }
    if (!lightboxState.items.length) {
      $("lightboxModal").classList.add("hidden");
    } else {
      lightboxState.idx = Math.min(lightboxState.idx, lightboxState.items.length - 1);
      renderLightboxCurrent();
    }
  }

  function renderLightboxActions(it) {
    const bar = $("lightboxActions");
    if (!bar) return;
    bar.innerHTML = "";
    const addBtn = (label, cls, onClick, title = "") => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = `btn btn-sm ${cls}`;
      b.textContent = label;
      if (title) b.title = title;
      b.addEventListener("click", (e) => {
        e.stopPropagation();
        onClick();
      });
      bar.appendChild(b);
    };

    if (it.source === "drawer" && it.drawerItem) {
      const d = it.drawerItem;
      const isWeb = d.kind === "web" && d.url;
      addBtn("Set Featured (F)", "btn-primary", () => assignLocalImageToActiveRow(d, "Featured image"));
      addBtn("Set Hover (H)", "btn-secondary", () => assignLocalImageToActiveRow(d, "Hover image"));
      addBtn("+ Add Gallery (+G)", "btn-secondary", () => assignLocalImageToActiveRow(d, "Gallery images"));
      addBtn("✂ Edit", "btn-accent", () => {
        $("lightboxModal").classList.add("hidden");
        openImageStudio([{
          label: d.name,
          src: isWeb ? "/api/proxy-image?url=" + encodeURIComponent(d.url) : "/api/local-image?path=" + encodeURIComponent(d.path),
          originalPath: isWeb ? "" : d.path,
          filename: d.name,
          rIdx: -1,
          ci: -1,
          hLow: "",
          tokenIdx: -1,
          folder: "",
        }]);
      }, "Crop / Rotate / Resize image");
    } else if (it.source === "cell") {
      if (it.hLow !== "featured image") {
        addBtn("Set Featured", "btn-primary", () => copyCellTokenToSlot(it.rIdx, it.token, "Featured image"));
      }
      if (it.hLow !== "hover image") {
        addBtn("Set Hover", "btn-secondary", () => copyCellTokenToSlot(it.rIdx, it.token, "Hover image"));
      }
      if (it.hLow !== "gallery images") {
        addBtn("+ Add Gallery", "btn-secondary", () => copyCellTokenToSlot(it.rIdx, it.token, "Gallery images"));
      }
      addBtn("✂ Edit", "btn-accent", () => {
        $("lightboxModal").classList.add("hidden");
        openImageStudio([makeStudioItemFromCell(it.rIdx, it.ci, it.hLow, it.token, it.tokenIdx)]);
      }, "Crop / Rotate / Resize image");
      const slotLabel = it.hLow === "featured image" ? "Featured" : it.hLow === "hover image" ? "Hover" : "Gallery";
      addBtn(`🗑 Remove from ${slotLabel}`, "btn-danger-soft", () => removeCellTokenFromLightbox(it));
    } else if (it.source === "addModal" && it.addItem) {
      const a = it.addItem;
      if (it.slot !== "feat") {
        addBtn("Set Featured", "btn-primary", () => {
          addProdState.feat = { ...a };
          renderAddProductImages();
          toast("Set as Featured image.");
        });
      }
      if (it.slot !== "hover") {
        addBtn("Set Hover", "btn-secondary", () => {
          addProdState.hover = { ...a };
          renderAddProductImages();
          toast("Set as Hover image.");
        });
      }
      if (it.slot !== "gallery") {
        addBtn("+ Add Gallery", "btn-secondary", () => {
          addProdState.gallery.push({ ...a });
          renderAddProductImages();
          toast("Added to Gallery.");
        });
      }
      addBtn("✂ Edit", "btn-accent", () => {
        $("lightboxModal").classList.add("hidden");
        openImageStudio([{
          label: a.name,
          src: a.url ? "/api/proxy-image?url=" + encodeURIComponent(a.url) : "/api/local-image?path=" + encodeURIComponent(a.path),
          originalPath: a.url ? "" : a.path,
          filename: a.name,
          rIdx: -1,
          ci: -1,
          hLow: "",
          tokenIdx: -1,
          folder: "",
        }]);
      });
    }

    bar.classList.toggle("hidden", bar.children.length === 0);
  }

  function renderLightboxCurrent() {
    const it = lightboxState.items[lightboxState.idx];
    if (!it) return;
    $("lightboxImg").src = it.src;
    $("lightboxCaption").textContent = it.caption || "";
    const multi = lightboxState.items.length > 1;
    $("lightboxPrev").classList.toggle("hidden", !multi);
    $("lightboxNext").classList.toggle("hidden", !multi);
    const counter = $("lightboxCounter");
    counter.classList.toggle("hidden", !multi);
    if (multi) {
      counter.textContent = `${lightboxState.idx + 1} / ${lightboxState.items.length}`;
    }
    renderLightboxActions(it);
  }

  function stepLightbox(delta) {
    if (lightboxState.items.length <= 1) return;
    lightboxState.idx = (lightboxState.idx + delta + lightboxState.items.length) % lightboxState.items.length;
    renderLightboxCurrent();
  }

  function openLightbox(src, caption, items = null, startIdx = 0) {
    if (Array.isArray(items) && items.length > 0) {
      lightboxState = { items, idx: Math.max(0, Math.min(startIdx, items.length - 1)) };
    } else {
      lightboxState = { items: [{ src, caption: caption || "" }], idx: 0 };
    }
    renderLightboxCurrent();
    $("lightboxModal").classList.remove("hidden");
  }

  // ---------- Custom Right-Click Context Menu ----------
  function hideContextMenu() {
    const menu = $("contextMenu");
    if (menu) menu.classList.add("hidden");
  }

  function showContextMenu(x, y, entries) {
    const menu = $("contextMenu");
    if (!menu) return;
    menu.innerHTML = "";
    entries.forEach((entry) => {
      if (entry.sep) {
        const sep = document.createElement("div");
        sep.className = "ctx-sep";
        menu.appendChild(sep);
        return;
      }
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "ctx-item" + (entry.danger ? " danger" : "");
      if (entry.disabled) btn.disabled = true;
      btn.innerHTML = `<span>${esc(entry.label)}</span>${entry.shortcut ? `<span class="ctx-shortcut">${esc(entry.shortcut)}</span>` : ""}`;
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        hideContextMenu();
        if (entry.action) entry.action();
      });
      menu.appendChild(btn);
    });
    menu.classList.remove("hidden");
    const rect = menu.getBoundingClientRect();
    const left = Math.min(window.innerWidth - rect.width - 8, Math.max(8, x));
    const top = Math.min(window.innerHeight - rect.height - 8, Math.max(8, y));
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
  }

  function showColumnContextMenu(x, y, ci, filterBtn) {
    const h = state.headers[ci] || "";
    const low = h.trim().toLowerCase();
    const isFrozen = state.frozenCols.includes(low);
    showContextMenu(x, y, [
      {
        label: isFrozen ? `Unfreeze "${h}"` : `Freeze "${h}" to Front`,
        shortcut: "📌",
        action: () => toggleFreezeColumn(ci),
      },
      {
        label: "Sort A → Z",
        action: () => {
          state.sortCol = ci;
          state.sortDir = 1;
          renderGrid();
        },
      },
      {
        label: "Sort Z → A",
        action: () => {
          state.sortCol = ci;
          state.sortDir = -1;
          renderGrid();
        },
      },
      {
        label: "Clear Sort",
        disabled: state.sortCol !== ci,
        action: () => {
          state.sortCol = -1;
          state.sortDir = 0;
          renderGrid();
        },
      },
      { sep: true },
      {
        label: "Filter Column...",
        action: () => openColFilterPopover(filterBtn, ci),
      },
      {
        label: "Clear Column Filter",
        disabled: !state.colFilters.has(ci),
        action: () => {
          state.colFilters.delete(ci);
          renderGrid();
        },
      },
      { sep: true },
      { label: "Reset Default Freeze (ID, SKU, Name)", action: resetDefaultFreeze },
      { label: "Unfreeze All Columns", disabled: !state.frozenCols.length, action: unfreezeAllColumns },
    ]);
  }

  function showCellContextMenu(x, y, rIdx, visIdx, ci, hLow, td) {
    const row = state.products[rIdx] || [];
    const val = String(row[ci] || "");
    const selRows = getSelectedRealRowIndices();
    const items = [];

    // Context-specific actions first
    if (IMAGE_COLS.has(hLow)) {
      const isGallery = hLow === "gallery images";
      const tokens = splitPictures(val);
      if (tokens.length) {
        items.push({
          label: "🔍 Peek / Preview Image",
          action: () => {
            const lbItems = tokens.map((t, ti) => makeCellLightboxItem(rIdx, ci, hLow, t, ti));
            openLightbox(lbItems[0].src, lbItems[0].caption, lbItems, 0);
          },
        });
        items.push({
          label: "✂ Crop / Rotate / Resize...",
          action: () => {
            openImageStudio(tokens.map((t, ti) => makeStudioItemFromCell(rIdx, ci, hLow, t, ti)));
          },
        });
      }
      items.push({
        label: isGallery ? "Choose Image(s)..." : "Choose Image...",
        action: () => pickImageFiles(rIdx, ci, hLow, isGallery),
      });
      if (tokens.length) {
        items.push({
          label: "Clear Image(s)",
          danger: true,
          action: () => {
            pushUndo();
            state.products[rIdx][ci] = "";
            setDirty(true);
            renderGrid();
          },
        });
      }
      items.push({ sep: true });
    } else if (MULTI_COLS.has(hLow)) {
      items.push({
        label: `Pick ${state.headers[ci]}...`,
        action: () => openMultiPopover(td, rIdx, ci, hLow),
      });
      items.push({ sep: true });
    } else if (["short description", "description", "short description (en)", "description (en)", "short_description_en", "description_en"].includes(hLow)) {
      items.push({
        label: "Open Rich Text Editor...",
        action: () => openRichModal(rIdx, ci),
      });
      items.push({ sep: true });
    }

    const typeC = colIdx("Product type");
    if (typeC >= 0 && (row[typeC] || "").trim().toLowerCase() === "variable") {
      items.push({
        label: "Edit Variations...",
        action: () => openVariationModal(rIdx, false),
      });
      items.push({ sep: true });
    }

    items.push(
      {
        label: "Copy Cell Value",
        shortcut: "Ctrl+C",
        action: async () => {
          try {
            await navigator.clipboard.writeText(val);
            toast("Copied to clipboard.");
          } catch {
            toast("Use Ctrl+C to copy.", true);
          }
        },
      },
      {
        label: "Paste into Cell",
        shortcut: "Ctrl+V",
        disabled: hLow === "id",
        action: async () => {
          if (hLow === "id") return;
          try {
            const text = await navigator.clipboard.readText();
            if (text !== undefined) {
              pushUndo();
              state.products[rIdx][ci] = text;
              setDirty(true);
              renderGrid();
            }
          } catch {
            toast("Use Ctrl+V to paste.", true);
          }
        },
      },
      {
        label: "Clear Selected Cell(s)",
        shortcut: "Del",
        disabled: hLow === "id" && state.selectedCells.size <= 1,
        action: () => {
          const idC = colIdx("ID");
          pushUndo();
          let cleared = 0;
          state.selectedCells.forEach((k) => {
            const [vr, c] = k.split(":").map(Number);
            const rr = state.visibleRows[vr];
            if (rr !== undefined && c !== idC) {
              state.products[rr][c] = "";
              cleared++;
            }
          });
          if (cleared) {
            setDirty(true);
            renderGrid();
          }
        },
      },
      {
        label: "Fill Down",
        shortcut: "Ctrl+D",
        disabled: selRows.length < 2,
        action: applyFillDown,
      },
      { sep: true },
      { label: "Undo", shortcut: "Ctrl+Z", disabled: !state.undoStack.length, action: performUndo },
      { label: "Redo", shortcut: "Ctrl+Y", disabled: !state.redoStack.length, action: performRedo },
      { sep: true },
      {
        label: state.frozenRows.has(rIdx)
          ? `📌 Unfreeze Row ${rIdx + 2}`
          : (selRows.length > 1 ? `📌 Freeze ${selRows.length} Selected Rows` : `📌 Freeze Row ${rIdx + 2} to Top`),
        action: () => {
          if (state.frozenRows.has(rIdx)) toggleFreezeRow(rIdx);
          else if (selRows.length > 1) freezeRowsByIndices(selRows);
          else toggleFreezeRow(rIdx);
        },
      },
      {
        label: "Unfreeze All Rows",
        disabled: !getValidFrozenRows().length,
        action: unfreezeAllRows,
      },
      { sep: true },
      {
        label: "✨ Translate Row(s)",
        action: () => openTranslateModal("selected"),
      },
      {
        label: "🌐 Rename Web Images for Row(s)...",
        action: () => openWebRenameModal("selected", selRows.length ? selRows : [rIdx]),
      },
      {
        label: "Duplicate Row",
        action: () => {
          pushUndo();
          const copy = [...row];
          const idC = colIdx("ID");
          if (idC >= 0) copy[idC] = "";
          state.products.splice(rIdx + 1, 0, copy);
          setDirty(true);
          renderGrid();
          toast("Duplicated row.");
        },
      },
      {
        label: selRows.length > 1 ? `Delete ${selRows.length} Selected Rows` : "Delete Row",
        danger: true,
        action: () => deleteRowsByIndices(selRows.length ? selRows : [rIdx]),
      }
    );

    showContextMenu(x, y, items);
  }

  function showDrawerContextMenu(x, y, it) {
    const isWeb = it.kind === "web" && it.url;
    const entries = [
      {
        label: "🔍 Preview Image",
        action: () => {
          const lbItems = drawerFilteredItems.map((img) => makeDrawerLightboxItem(img));
          const startIdx = drawerFilteredItems.findIndex((img) => (isWeb ? img.url === it.url : img.path === it.path));
          const src = isWeb ? it.url : "/api/local-image?path=" + encodeURIComponent(it.path);
          openLightbox(src, isWeb ? it.url : it.path, lbItems, Math.max(0, startIdx));
        },
      },
      { sep: true },
      { label: "Set as Featured Image (selected row)", action: () => assignLocalImageToActiveRow(it, "Featured image") },
      { label: "Set as Hover Image (selected row)", action: () => assignLocalImageToActiveRow(it, "Hover image") },
      { label: "Add to Gallery (selected row)", action: () => assignLocalImageToActiveRow(it, "Gallery images") },
      { sep: true },
      {
        label: "✂ Crop / Rotate / Resize...",
        action: () => {
          openImageStudio([
            {
              label: it.name,
              src: isWeb ? "/api/proxy-image?url=" + encodeURIComponent(it.url) : "/api/local-image?path=" + encodeURIComponent(it.path),
              originalPath: isWeb ? "" : it.path,
              filename: it.name,
              rIdx: -1,
              ci: -1,
              hLow: "",
              tokenIdx: -1,
              folder: "",
            },
          ]);
        },
      },
    ];
    if (!isWeb) {
      entries.push({
        label: "Rename Original File",
        shortcut: "F2",
        action: () => openRenameImageModal(it),
      });
    }
    entries.push(
      { sep: true },
      {
        label: "Copy Filename",
        action: async () => {
          try {
            await navigator.clipboard.writeText(it.name);
            toast(`Copied "${it.name}"`);
          } catch {}
        },
      },
      {
        label: isWeb ? "Copy Image URL" : "Copy Full Path",
        action: async () => {
          try {
            await navigator.clipboard.writeText(isWeb ? it.url : it.path);
            toast(isWeb ? "Copied image URL." : "Copied full path.");
          } catch {}
        },
      }
    );
    showContextMenu(x, y, entries);
  }

  // ---------- API & Workbook Load / Save ----------
  async function apiGet(url) {
    try {
      const r = await fetch(url);
      return await r.json();
    } catch (err) {
      return { ok: false, error: "Local editor server is not responding. Please reopen OdellaSheetEditor." };
    }
  }

  async function apiPost(url, data) {
    try {
      const r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      });
      return await r.json();
    } catch (err) {
      return { ok: false, error: "Local editor server is not responding. Please reopen OdellaSheetEditor." };
    }
  }

  function esc(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  async function loadFileList(selectPath = null) {
    const data = await apiGet("/api/files");
    const sel = $("fileSelect");
    sel.innerHTML = "";
    (data.files || []).forEach((f) => {
      const o = document.createElement("option");
      o.value = f.path;
      o.textContent = f.name;
      sel.appendChild(o);
    });
    const preferred = selectPath || (state.settings && state.settings.last_opened_file) || "";
    const hasPreferred = preferred && (data.files || []).some((f) => f.path === preferred);
    if (hasPreferred) {
      sel.value = preferred;
    } else if (data.files && data.files.length) {
      sel.value = data.files[0].path;
    }
    if (sel.value) {
      await openWorkbook(sel.value);
    }
  }

  async function openWorkbook(filePath) {
    const data = await apiGet("/api/workbook?file=" + encodeURIComponent(filePath));
    if (data.error) {
      toast(data.error, true);
      return;
    }
    Object.assign(state, {
      file: data.file,
      filename: data.filename,
      headers: data.headers,
      products: data.products,
      categories: data.categories,
      tags: data.tags,
      attribute_tokens: data.attribute_tokens,
      attribute_groups: data.attribute_groups,
      variation_headers: data.variation_headers,
      variations: data.variations,
      lists: data.lists,
      filebird: data.filebird,
      category_en_map: Object.fromEntries(
        Object.entries(data.category_en_map || {}).map(([k, v]) => [k.toLowerCase(), v])
      ),
      paths: data.paths || {},
      images_root: data.images_root || "",
      varSaleDiscounts: data.var_sale_discounts || {},
      ratioBadRows: new Set(),
      colFilters: new Map(),
      sortCol: -1,
      sortDir: 0,
      undoStack: [],
      redoStack: [],
      baselineRows: new Map(
        (data.products || []).map((r, idx) => [idx, JSON.stringify(r)])
      ),
      baselineVariations: buildBaselineVariationsMap(data.variation_headers || [], data.variations || []),
    });
    updateUndoRedoButtons();
    $("countRatio").textContent = "0";
    $("imagesRootInput").value = state.images_root;
    // Populate Bulk Column Edit dropdown (every editable column) & Bulk FileBird dropdown
    const bcol = $("bulkColSel");
    if (bcol) {
      bcol.innerHTML = "";
      state.headers.forEach((h, idx) => {
        const low = h.trim().toLowerCase();
        if (low === "id" || low === "categories (en)" || low === "categories_en") return;
        const o = document.createElement("option");
        o.value = String(idx);
        o.textContent = `Column: ${h}`;
        bcol.appendChild(o);
      });
    }
    const bfb = $("bulkFbSel");
    if (bfb) {
      bfb.innerHTML = `<option value="">FileBird folder...</option>`;
      state.filebird.forEach((fb) => {
        const o = document.createElement("option");
        o.value = fb;
        o.textContent = fb;
        bfb.appendChild(o);
      });
    }
    setDirty(false);
    renderGrid();
    applySettingsToUI();
    loadLocalImages();
    const realCount = state.products.filter((r) => !isRowEmpty(r)).length;
    toast(`Loaded ${state.filename} (${realCount} products)`);
  }

  // ---------- Settings ----------
  async function loadSettingsFromServer() {
    try {
      const res = await apiGet("/api/settings");
      if (res.ok && res.settings) {
        Object.assign(state.settings, res.settings);
        state.defaultPrompt = res.default_prompt || res.settings.gemini_prompt || "";
        if (Array.isArray(res.settings.frozen_cols)) {
          state.frozenCols = res.settings.frozen_cols.map((x) => String(x).toLowerCase());
        }
        if (res.settings.lang_view && ["all", "ka", "en"].includes(res.settings.lang_view)) {
          state.langView = res.settings.lang_view;
          const ls = $("langSelect");
          if (ls) ls.value = state.langView;
        }
        const drawerEl = $("imagesDrawer");
        if (drawerEl) {
          const dw = Number(res.settings.drawer_width || 0);
          if (dw >= 220 && dw <= 960) drawerEl.style.width = `${dw}px`;
          if (res.settings.drawer_open) drawerEl.classList.remove("hidden");
        }
        if (res.settings.last_images_root) {
          state.images_root = res.settings.last_images_root;
          const ri = $("imagesRootInput");
          if (ri) ri.value = res.settings.last_images_root;
        }
        applySettingsToUI();
      }
    } catch (_) {}
  }

  function openSettingsModal() {
    const s = state.settings;
    $("setTheme").value = s.theme || "dark";
    $("setUiLang").value = s.ui_lang || "en";
    $("setZoom").value = String(s.zoom || 100);
    $("setZoomVal").textContent = String(s.zoom || 100);
    $("setFont").value = String(s.font_size || 13);
    $("setFontVal").textContent = String(s.font_size || 13);
    $("setDensity").value = s.row_density || "normal";
    $("setBlankRows").value = String(s.extra_blank_rows ?? 50);
    $("setAutoBackup").checked = s.auto_backup !== false;
    $("setApiKey").value = s.gemini_api_key || "";
    $("setApiKey").type = "password";
    $("btnToggleKey").textContent = "Show";
    $("setModel").value = s.gemini_model || "gemini-3.8-flash";
    if (!$("setModel").value) {
      const o = document.createElement("option");
      o.value = s.gemini_model || "gemini-3.8-flash";
      o.textContent = o.value;
      $("setModel").appendChild(o);
      $("setModel").value = o.value;
    }
    $("setBatchSize").value = String(s.gemini_batch_size || 12);
    $("setPrompt").value = s.gemini_prompt || state.defaultPrompt || "";
    $("geminiTestStatus").textContent = "";
    $("setWpUrl").value = s.wp_site_url || "https://odella.ge";
    $("setWpUser").value = s.wp_username || "";
    $("setWpPass").value = s.wp_app_password || "";
    $("setWpPass").type = "password";
    $("btnToggleWpPass").textContent = "Show";
    const pb = Math.max(5, Math.min(25, Number(s.push_batch_size || 10)));
    $("setPushBatch").value = String(pb);
    $("setPushBatchVal").textContent = String(pb);
    $("setHostingerToken").value = s.hostinger_api_token || "";
    if ($("setUpdateRepo")) $("setUpdateRepo").value = s.update_repo || "NMeJa/odella-sheet-editor-releases";
    $("wpTestStatus").textContent = "";
    $("settingsModal").classList.remove("hidden");
  }

  async function saveSettingsModal() {
    const updates = {
      theme: $("setTheme").value,
      ui_lang: $("setUiLang").value,
      zoom: Number($("setZoom").value) || 100,
      font_size: Number($("setFont").value) || 13,
      row_density: $("setDensity").value,
      extra_blank_rows: Math.max(0, Number($("setBlankRows").value) || 0),
      auto_backup: $("setAutoBackup").checked,
      gemini_api_key: $("setApiKey").value.trim(),
      gemini_model: $("setModel").value,
      gemini_batch_size: Math.max(1, Math.min(30, Number($("setBatchSize").value) || 12)),
      gemini_prompt: $("setPrompt").value,
      wp_site_url: $("setWpUrl").value.trim() || "https://odella.ge",
      wp_username: $("setWpUser").value.trim(),
      wp_app_password: $("setWpPass").value.trim(),
      push_batch_size: Math.max(5, Math.min(25, Number($("setPushBatch").value) || 10)),
      hostinger_api_token: $("setHostingerToken").value.trim(),
      update_repo: ($("setUpdateRepo")?.value || "").trim() || "NMeJa/odella-sheet-editor-releases",
    };
    const res = await apiPost("/api/settings", updates);
    if (res.ok && res.settings) {
      Object.assign(state.settings, res.settings);
      applySettingsToUI();
      $("settingsModal").classList.add("hidden");
      renderGrid();
      refreshWpConnectionStatus();
      toast("Settings saved.");
    } else {
      toast(res.error || "Failed to save settings.", true);
    }
  }

  // ---------- Gemini Auto-Translate ----------
  function getTranslatableRows(scope) {
    const nameC = colIdx("Name");
    const nameEnC = state.headers.findIndex((h) => ["name (en)", "name_en"].includes(h.trim().toLowerCase()));
    const sdC = colIdx("Short description");
    const sdEnC = state.headers.findIndex((h) => ["short description (en)", "short description_en"].includes(h.trim().toLowerCase()));
    const dC = colIdx("Description");
    const dEnC = state.headers.findIndex((h) => ["description (en)", "description_en"].includes(h.trim().toLowerCase()));

    const selectedSet = new Set(getSelectedRealRowIndices());
    const out = [];
    state.products.forEach((row, rIdx) => {
      if (isRowEmpty(row)) return;
      const hasKa = (nameC >= 0 && row[nameC].trim()) || (sdC >= 0 && row[sdC].trim()) || (dC >= 0 && row[dC].trim());
      if (!hasKa) return;
      if (scope === "selected" && !selectedSet.has(rIdx)) return;
      if (scope === "missing") {
        const missingName = nameC >= 0 && row[nameC].trim() && !(nameEnC >= 0 && row[nameEnC].trim());
        const missingSd = sdC >= 0 && row[sdC].trim() && !(sdEnC >= 0 && row[sdEnC].trim());
        const missingD = dC >= 0 && row[dC].trim() && !(dEnC >= 0 && row[dEnC].trim());
        if (!missingName && !missingSd && !missingD) return;
      }
      out.push(rIdx);
    });
    return out;
  }

  function openTranslateModal(defaultScope = null) {
    if (!state.settings.gemini_api_key) {
      toast("Add your free Gemini API key in Settings (⚙) first.", true);
      openSettingsModal();
      return;
    }
    const missing = getTranslatableRows("missing").length;
    const selected = getTranslatableRows("selected").length;
    const all = getTranslatableRows("all").length;
    $("trCountMissing").textContent = String(missing);
    $("trCountSelected").textContent = String(selected);
    $("trCountAll").textContent = String(all);
    $("translateScope").value = defaultScope || (selected >= 1 ? "selected" : "missing");
    $("translateProgressWrap").classList.add("hidden");
    $("translateProgressBar").style.width = "0%";
    $("btnStartTranslate").disabled = false;
    state.translateCancelled = false;
    $("translateModal").classList.remove("hidden");
  }

  async function runGeminiTranslate() {
    const scope = $("translateScope").value;
    const targetIndices = getTranslatableRows(scope);
    if (!targetIndices.length) {
      toast("No rows match the selected translation scope.", true);
      return;
    }
    const nameC = colIdx("Name");
    const nameEnC = state.headers.findIndex((h) => ["name (en)", "name_en"].includes(h.trim().toLowerCase()));
    const sdC = colIdx("Short description");
    const sdEnC = state.headers.findIndex((h) => ["short description (en)", "short description_en"].includes(h.trim().toLowerCase()));
    const dC = colIdx("Description");
    const dEnC = state.headers.findIndex((h) => ["description (en)", "description_en"].includes(h.trim().toLowerCase()));

    const batchSize = Math.max(1, Number(state.settings.gemini_batch_size || 12));
    const totalBatches = Math.ceil(targetIndices.length / batchSize);
    $("translateProgressWrap").classList.remove("hidden");
    $("btnStartTranslate").disabled = true;
    state.translateCancelled = false;
    pushUndo();

    let done = 0;
    for (let b = 0; b < totalBatches; b++) {
      if (state.translateCancelled) break;
      const slice = targetIndices.slice(b * batchSize, (b + 1) * batchSize);
      $("translateProgressText").textContent = `Batch ${b + 1}/${totalBatches} — translating ${slice.length} product(s) with ${state.settings.gemini_model}...`;
      const items = slice.map((rIdx) => ({
        row_idx: rIdx,
        name: nameC >= 0 ? state.products[rIdx][nameC] || "" : "",
        short_description: sdC >= 0 ? state.products[rIdx][sdC] || "" : "",
        description: dC >= 0 ? state.products[rIdx][dC] || "" : "",
      }));

      const res = await apiPost("/api/translate", { items });
      if (!res.ok) {
        toast(res.error || "Translation failed.", true);
        $("translateProgressText").textContent = `Stopped on batch ${b + 1}: ${res.error || "Error"}`;
        $("btnStartTranslate").disabled = false;
        renderGrid();
        return;
      }

      (res.translations || []).forEach((tr) => {
        const rIdx = Number(tr.row_idx);
        if (!state.products[rIdx]) return;
        if (nameEnC >= 0 && tr.name_en !== undefined) state.products[rIdx][nameEnC] = String(tr.name_en);
        if (sdEnC >= 0 && tr.short_description_en !== undefined) state.products[rIdx][sdEnC] = String(tr.short_description_en);
        if (dEnC >= 0 && tr.description_en !== undefined) state.products[rIdx][dEnC] = String(tr.description_en);
      });

      done += slice.length;
      $("translateProgressBar").style.width = `${Math.round((done / targetIndices.length) * 100)}%`;
      setDirty(true);
    }

    renderGrid();
    $("btnStartTranslate").disabled = false;
    $("translateModal").classList.add("hidden");
    toast(state.translateCancelled ? `Translation cancelled after ${done} product(s).` : `Translated ${done} product(s) to English!`);
  }

  // ---------- Import .xlsx / .csv ----------
  async function runImportFile() {
    const fileInput = $("importFileInput");
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      toast("Please choose an .xlsx or .csv file first.", true);
      return;
    }
    const btn = $("btnRunImport");
    btn.disabled = true;
    try {
      const b64 = await fileToBase64(file);
      const res = await apiPost("/api/import-file", {
        filename: file.name,
        data_base64: b64,
        mode: "open",
      });
      if (!res.ok) {
        toast(res.error || "Import failed.", true);
        return;
      }
      $("importModal").classList.add("hidden");
      fileInput.value = "";
      if (res.workbook) {
        await loadFileList(res.workbook.file);
        toast(`Imported & opened ${res.workbook.filename}`);
      }
    } finally {
      btn.disabled = false;
    }
  }

  // ---------- Auto-Match Local Images ----------
  async function openAutoMatchModal() {
    const sel = $("matchFileBird");
    sel.innerHTML = `<option value="">(No FileBird folder)</option>`;
    state.filebird.forEach((fb) => {
      const o = document.createElement("option");
      o.value = fb;
      o.textContent = fb;
      sel.appendChild(o);
    });
    const res = await apiPost("/api/auto-match-images", {
      headers: state.headers,
      products: state.products,
      paths: state.paths,
      images_root: state.images_root,
      preview: true,
    });
    if (!res.ok) {
      toast(res.error || "Auto-match failed.", true);
      return;
    }
    $("matchSummary").textContent = `Found ${res.matches.length} product(s) without images that match files in ${state.images_root}. (${res.skipped_existing.length} already had images and will be skipped.)`;
    const body = $("matchPreviewBody");
    body.innerHTML = "";
    if (!res.matches.length) {
      body.innerHTML = `<tr><td colspan="4" class="muted-sm" style="padding:12px;text-align:center;">No unmatched products found for current local images.</td></tr>`;
    } else {
      res.matches.forEach((m) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${m.row}</td><td class="mono">${esc(m.sku)}</td><td>${esc(m.name)}</td><td class="mono">${esc(m.files.join("; "))}</td>`;
        body.appendChild(tr);
      });
    }
    $("btnApplyMatch").disabled = !res.matches.length;
    $("matchModal").classList.remove("hidden");
  }

  async function applyAutoMatch() {
    const res = await apiPost("/api/auto-match-images", {
      headers: state.headers,
      products: state.products,
      paths: state.paths,
      images_root: state.images_root,
      filebird_folder: $("matchFileBird").value,
      preview: false,
    });
    if (!res.ok) {
      toast(res.error || "Auto-match failed.", true);
      return;
    }
    pushUndo();
    state.products = res.products;
    state.paths = res.paths;
    setDirty(true);
    $("matchModal").classList.add("hidden");
    renderGrid();
    toast(`Matched images for ${res.matches.length} product(s)!`);
  }

  async function saveWorkbook(saveAsName) {
    const payload = {
      file: state.file,
      save_as: saveAsName || undefined,
      headers: state.headers,
      products: state.products,
      categories: state.categories,
      tags: state.tags,
      attribute_tokens: state.attribute_tokens,
      attribute_groups: state.attribute_groups,
      variation_headers: state.variation_headers,
      variations: state.variations,
      lists: state.lists,
      filebird: state.filebird,
      paths: state.paths,
      images_root: state.images_root,
      sale_discounts: collectSaleDiscountsMap(),
      var_sale_discounts: state.varSaleDiscounts || {},
    };
    const res = await apiPost("/api/workbook/save", payload);
    if (res.ok) {
      state.file = res.file;
      state.filename = res.filename;
      setDirty(false);
      renderGrid(true);
      toast(`Saved ${res.filename}`);
      if (saveAsName) await loadFileList(res.file);
    } else {
      toast(res.error || "Save failed", true);
    }
  }

  // ---------- Vertical Row Inspector ----------
  function renderRowInspector() {
    const body = $("inspectorBody");
    if (!body) return;
    const rIdx = getActiveRealRowIndex();
    if (rIdx < 0 || !state.products[rIdx]) {
      $("inspectorTitle").textContent = "Row Inspector";
      $("inspectorSub").textContent = "Select any product row";
      body.innerHTML = `<div class="muted-sm" style="padding:12px;">Click any product row in the sheet to edit all fields vertically here.</div>`;
      return;
    }
    const row = state.products[rIdx];
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const sku = skuC >= 0 ? (row[skuC] || "").trim() : "";
    const name = nameC >= 0 ? (row[nameC] || "").trim() : "";
    $("inspectorTitle").textContent = `Row ${rIdx + 2}${sku ? ` · ${sku}` : ""}`;
    $("inspectorSub").textContent = name || "(New product)";
    body.innerHTML = "";

    const makeTextField = (label, colName) => {
      const ci = colIdx(colName);
      if (ci < 0) return null;
      const wrap = document.createElement("div");
      wrap.className = "insp-field";
      wrap.innerHTML = `<label>${esc(label)}</label>`;
      const inp = document.createElement("input");
      inp.type = "text";
      inp.className = "input input-sm";
      inp.value = row[ci] || "";
      const colLow = colName.trim().toLowerCase();
      if (colLow === "sale discount") inp.placeholder = "20% or 40 off";
      inp.addEventListener("change", () => {
        pushUndo();
        state.products[rIdx][ci] = inp.value;
        if (colLow === "sale discount" || colLow === "regular price (gel)" || colLow === "sale price (gel)") {
          syncProductRowSaleFromEdit(rIdx, colLow);
        }
        setDirty(true);
        renderGrid();
      });
      wrap.appendChild(inp);
      return wrap;
    };

    // 1. Images Group
    const imgGrp = document.createElement("div");
    imgGrp.className = "insp-group";
    imgGrp.innerHTML = `<h4>Images</h4>`;
    ["Featured image", "Hover image", "Gallery images"].forEach((colName) => {
      const ci = colIdx(colName);
      if (ci < 0) return;
      const isGal = colName === "Gallery images";
      const tokens = splitPictures(row[ci] || "");
      const f = document.createElement("div");
      f.className = "insp-field";
      const head = document.createElement("div");
      head.style.cssText = "display:flex;justify-content:space-between;align-items:center;";
      head.innerHTML = `<label>${esc(colName)} (${tokens.length})</label>`;
      const pickBtn = document.createElement("button");
      pickBtn.type = "button";
      pickBtn.className = "btn btn-sm btn-ghost";
      pickBtn.textContent = isGal ? "+ Add" : (tokens.length ? "Replace" : "+ Choose");
      pickBtn.addEventListener("click", () => pickImageFiles(rIdx, ci, colName.toLowerCase(), isGal));
      head.appendChild(pickBtn);
      f.appendChild(head);

      if (tokens.length) {
        const list = document.createElement("div");
        list.className = "img-list";
        tokens.forEach((tok, idx) => {
          const card = document.createElement("div");
          card.className = "img-card";
          const img = document.createElement("img");
          img.referrerPolicy = "no-referrer";
          img.src = imagePreviewSrc(tok);
          img.addEventListener("click", () => {
            const lbItems = tokens.map((t, ti) => makeCellLightboxItem(rIdx, ci, colName.toLowerCase(), t, ti));
            openLightbox(img.src, tok, lbItems, idx);
          });
          card.appendChild(img);
          const acts = document.createElement("div");
          acts.className = "img-card-actions";
          const bEdit = document.createElement("button");
          bEdit.className = "img-mini-btn";
          bEdit.textContent = "✂";
          bEdit.title = "Crop / Rotate / Resize image";
          bEdit.addEventListener("click", (e) => {
            e.stopPropagation();
            openImageStudio([makeStudioItemFromCell(rIdx, ci, colName.toLowerCase(), tok, idx)]);
          });
          acts.appendChild(bEdit);

          const bDel = document.createElement("button");
          bDel.className = "img-mini-btn del";
          bDel.textContent = "×";
          bDel.title = "Remove";
          bDel.addEventListener("click", (e) => {
            e.stopPropagation();
            pushUndo();
            tokens.splice(idx, 1);
            state.products[rIdx][ci] = isGal ? tokens.join("; ") : (tokens[0] || "");
            setDirty(true);
            renderGrid();
          });
          acts.appendChild(bDel);
          card.appendChild(acts);
          list.appendChild(card);
        });
        f.appendChild(list);
      }
      imgGrp.appendChild(f);
    });
    body.appendChild(imgGrp);

    // 2. Core Info Group
    const coreGrp = document.createElement("div");
    coreGrp.className = "insp-group";
    coreGrp.innerHTML = `<h4>Product Info</h4>`;
    const coreRow = document.createElement("div");
    coreRow.className = "insp-grid-2";
    const fSku = makeTextField("SKU", "SKU");
    if (fSku) coreRow.appendChild(fSku);
    const stC = colIdx("Status");
    if (stC >= 0) {
      const sw = document.createElement("div");
      sw.className = "insp-field";
      sw.innerHTML = `<label>Status</label>`;
      const sel = document.createElement("select");
      sel.className = "select select-sm";
      ["publish", "draft", ""].forEach((v) => {
        const o = document.createElement("option");
        o.value = v;
        o.textContent = v || "(empty)";
        if ((row[stC] || "").trim().toLowerCase() === v) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener("change", () => {
        pushUndo();
        state.products[rIdx][stC] = sel.value;
        setDirty(true);
        renderGrid();
      });
      sw.appendChild(sel);
      coreRow.appendChild(sw);
    }
    coreGrp.appendChild(coreRow);
    const fName = makeTextField("Name (KA)", "Name");
    if (fName) coreGrp.appendChild(fName);
    const fNameEn = makeTextField("Name (EN)", "Name (EN)");
    if (fNameEn) coreGrp.appendChild(fNameEn);
    const typeC = colIdx("Product type");
    if (typeC >= 0) {
      const tw = document.createElement("div");
      tw.className = "insp-field";
      tw.innerHTML = `<label>Product Type</label>`;
      const rowG = document.createElement("div");
      rowG.className = "row-gap";
      const sel = document.createElement("select");
      sel.className = "select select-sm";
      sel.style.flex = "1";
      const curT = (row[typeC] || "simple").trim().toLowerCase();
      ["simple", "variable", "grouped", "external"].forEach((v) => {
        const o = document.createElement("option");
        o.value = v;
        o.textContent = v;
        if (v === curT) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener("change", () => {
        pushUndo();
        state.products[rIdx][typeC] = sel.value;
        setDirty(true);
        renderGrid();
      });
      rowG.appendChild(sel);
      if (curT === "variable") {
        const vb = document.createElement("button");
        vb.type = "button";
        vb.className = "btn btn-sm btn-secondary";
        vb.textContent = `Variations (${getVariationsForRow(rIdx).length})`;
        vb.addEventListener("click", () => openVariationModal(rIdx, false));
        rowG.appendChild(vb);
      }
      tw.appendChild(rowG);
      coreGrp.appendChild(tw);
    }
    body.appendChild(coreGrp);

    // 3. Prices Group
    const priceGrp = document.createElement("div");
    priceGrp.className = "insp-group";
    priceGrp.innerHTML = `<h4>Prices</h4>`;
    const pGrid = document.createElement("div");
    pGrid.className = "insp-grid-2";
    [
      ["Regular (GEL)", "Regular price (GEL)"],
      ["Sale (GEL)", "Sale price (GEL)"],
      ["Sale Discount (% / GEL off)", "Sale Discount"],
      ["EUR Regular", "EUR regular price"],
      ["EUR Sale", "EUR sale price"],
      ["USD Regular", "USD regular price"],
      ["USD Sale", "USD sale price"],
    ].forEach(([lbl, col]) => {
      const el = makeTextField(lbl, col);
      if (el) pGrid.appendChild(el);
    });
    priceGrp.appendChild(pGrid);
    body.appendChild(priceGrp);

    // 4. Categories / Tags / Attributes
    const taxGrp = document.createElement("div");
    taxGrp.className = "insp-group";
    taxGrp.innerHTML = `<h4>Categories &amp; Attributes</h4>`;
    ["Categories", "Tags", "Attributes"].forEach((colName) => {
      const ci = colIdx(colName);
      if (ci < 0) return;
      const w = document.createElement("div");
      w.className = "insp-field";
      const hd = document.createElement("div");
      hd.style.cssText = "display:flex;justify-content:space-between;align-items:center;";
      hd.innerHTML = `<label>${esc(colName)}</label>`;
      const pb = document.createElement("button");
      pb.type = "button";
      pb.className = "btn btn-sm btn-ghost";
      pb.textContent = "Pick...";
      pb.addEventListener("click", (e) => {
        e.stopPropagation();
        openMultiPopover(pb, rIdx, ci, colName.toLowerCase());
      });
      hd.appendChild(pb);
      w.appendChild(hd);
      const valBox = document.createElement("div");
      valBox.className = "muted-sm";
      valBox.style.cssText = "font-size:11.5px;line-height:1.4;word-break:break-word;";
      valBox.textContent = (row[ci] || "").trim() || "(none)";
      w.appendChild(valBox);
      taxGrp.appendChild(w);
    });
    body.appendChild(taxGrp);

    // 5. Descriptions
    const descGrp = document.createElement("div");
    descGrp.className = "insp-group";
    descGrp.innerHTML = `<h4>Descriptions</h4>`;
    ["Short description", "Short description (EN)", "Description", "Description (EN)"].forEach((colName) => {
      const ci = colIdx(colName);
      if (ci < 0) return;
      const w = document.createElement("div");
      w.className = "insp-field";
      const hd = document.createElement("div");
      hd.style.cssText = "display:flex;justify-content:space-between;align-items:center;";
      hd.innerHTML = `<label>${esc(colName)}</label>`;
      const eb = document.createElement("button");
      eb.type = "button";
      eb.className = "btn btn-sm btn-ghost";
      eb.textContent = "Edit Rich Text";
      eb.addEventListener("click", () => openRichModal(rIdx, ci));
      hd.appendChild(eb);
      w.appendChild(hd);
      const preview = document.createElement("div");
      preview.className = "muted-sm";
      preview.style.cssText = "font-size:11px;max-height:48px;overflow:hidden;text-overflow:ellipsis;";
      const plain = String(row[ci] || "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
      preview.textContent = plain ? (plain.length > 140 ? plain.slice(0, 137) + "..." : plain) : "(empty)";
      w.appendChild(preview);
      descGrp.appendChild(w);
    });
    body.appendChild(descGrp);
  }

  function stepInspectorRow(delta) {
    if (!state.visibleRows.length) return;
    const curVis = state.selAnchor ? state.selAnchor.visRow : 0;
    const nextVis = Math.max(0, Math.min(state.visibleRows.length - 1, curVis + delta));
    const col = state.selAnchor ? state.selAnchor.col : 1;
    state.selectedCells.clear();
    state.selectedCells.add(cellKey(nextVis, col));
    state.selAnchor = { visRow: nextVis, col };
    state.selEnd = { visRow: nextVis, col };
    updateSelectionClasses();
    const tr = getGridRowEl(nextVis);
    if (tr) tr.scrollIntoView({ block: "nearest" });
  }

  // ---------- Add New Product Modal ----------
  let addProdState = {
    feat: null,
    hover: null,
    gallery: [],
    templateRIdx: -1,
    templateVariations: [],
    varOverrides: {},
    extraPrices: null,
  };
  let addTemplateItems = [];
  let addTemplateActiveIdx = -1;

  function incrementVariationSku(baseSku, idx) {
    const clean = String(baseSku || "").trim();
    if (!clean) return "";
    if (/^\d+$/.test(clean)) {
      return String(BigInt(clean) + BigInt(idx)).padStart(clean.length, "0");
    }
    return `${clean}-${idx + 1}`;
  }

  function makeAttrComboKey(attrObj) {
    return Object.keys(state.attribute_groups)
      .map((g) => `${g}=${String((attrObj && attrObj[g]) || "").trim().toLowerCase()}`)
      .join("|");
  }

  function buildPlannedAddVariations() {
    if (($("addType")?.value || "simple") !== "variable") return [];
    const baseSku = ($("addSku")?.value || "").trim();
    const attrGroups = Object.keys(state.attribute_groups || {});
    const rawAttr = ($("addAttributes")?.value || "").trim();
    const selectedSet = new Set(
      rawAttr
        .split(/\s*;\s*/)
        .map((s) => s.trim())
        .filter(Boolean)
        .map((s) => s.toLowerCase())
    );

    const byGroup = [];
    attrGroups.forEach((g) => {
      const vals = [];
      (state.attribute_groups[g] || []).forEach((v) => {
        if (selectedSet.has(`${g}: ${v}`.toLowerCase())) vals.push(v);
      });
      if (vals.length) byGroup.push({ group: g, values: vals });
    });

    const tplVars = addProdState.templateVariations || [];
    const tplByKey = new Map();
    tplVars.forEach((tv) => {
      if (tv.comboKey) tplByKey.set(tv.comboKey, tv);
    });
    const firstPriced = tplVars.find((tv) => String(tv.reg || "").trim() !== "");
    const fallbackReg = firstPriced ? firstPriced.reg : ($("addRegPrice")?.value || "").trim();
    const fallbackSale = firstPriced ? firstPriced.sale : ($("addSalePrice")?.value || "").trim();

    let planned = [];
    if (byGroup.length > 0) {
      let combos = [{}];
      byGroup.forEach(({ group, values }) => {
        const next = [];
        combos.forEach((c) => {
          values.forEach((v) => next.push({ ...c, [group]: v }));
        });
        combos = next;
      });
      planned = combos.map((combo, idx) => {
        const ck = makeAttrComboKey(combo);
        let src = tplByKey.get(ck);
        if (!src && tplVars.length) {
          // Partial fallback: match on first overlapping attribute value if available
          src = tplVars.find((tv) =>
            Object.entries(combo).some(([g, val]) => String(tv.attrs[g] || "").toLowerCase() === String(val).toLowerCase())
          );
        }
        const ov = (addProdState.varOverrides || {})[ck] || {};
        const reg = ov.reg !== undefined ? ov.reg : (src ? src.reg : fallbackReg);
        const sale = ov.sale !== undefined ? ov.sale : (src ? src.sale : fallbackSale);
        const status = ov.status !== undefined ? ov.status : (src ? src.status : "publish");
        const attrLabel = Object.entries(combo)
          .map(([g, val]) => `${g}: ${val}`)
          .join(" · ");
        return {
          comboKey: ck,
          attrs: combo,
          attrLabel,
          sku: incrementVariationSku(baseSku, idx),
          reg: reg || "",
          sale: sale || "",
          status: status || "publish",
        };
      });
    } else if (tplVars.length > 0) {
      planned = tplVars.map((tv, idx) => {
        const ov = (addProdState.varOverrides || {})[tv.comboKey] || {};
        const attrLabel = Object.entries(tv.attrs || {})
          .filter(([, val]) => String(val || "").trim())
          .map(([g, val]) => `${g}: ${val}`)
          .join(" · ");
        return {
          comboKey: tv.comboKey,
          attrs: { ...(tv.attrs || {}) },
          attrLabel: attrLabel || `(Variation #${idx + 1})`,
          sku: incrementVariationSku(baseSku, idx),
          reg: ov.reg !== undefined ? ov.reg : (tv.reg || ""),
          sale: ov.sale !== undefined ? ov.sale : (tv.sale || ""),
          status: ov.status !== undefined ? ov.status : (tv.status || "publish"),
        };
      });
    }
    return planned;
  }

  function renderAddVarPreview() {
    const box = $("addVarPreviewBox");
    const body = $("addVarPreviewBody");
    if (!box || !body) return;
    const pType = $("addType")?.value || "simple";
    const planned = buildPlannedAddVariations();
    if (pType !== "variable" || !planned.length) {
      box.classList.add("hidden");
      body.innerHTML = "";
      return;
    }
    box.classList.remove("hidden");
    $("addVarPreviewCount").textContent = String(planned.length);
    const baseSku = ($("addSku")?.value || "").trim();
    const hint = $("addVarPreviewHint");
    if (hint) {
      hint.textContent = baseSku
        ? `SKUs auto-increment from "${baseSku}" · prices copied from template (editable below).`
        : "Enter a SKU above to preview auto-incremented variation SKUs.";
    }
    body.innerHTML = "";
    planned.forEach((v) => {
      const tr = document.createElement("tr");

      const tdSku = document.createElement("td");
      tdSku.className = "mono";
      tdSku.textContent = v.sku || "(enter SKU)";
      if (!v.sku) tdSku.style.color = "var(--muted)";
      tr.appendChild(tdSku);

      const tdAttr = document.createElement("td");
      tdAttr.textContent = v.attrLabel;
      tr.appendChild(tdAttr);

      const tdReg = document.createElement("td");
      const inpReg = document.createElement("input");
      inpReg.type = "text";
      inpReg.className = "input input-sm";
      inpReg.value = v.reg;
      inpReg.placeholder = "GEL";
      tdReg.appendChild(inpReg);
      tr.appendChild(tdReg);

      const tdSale = document.createElement("td");
      const inpSale = document.createElement("input");
      inpSale.type = "text";
      inpSale.className = "input input-sm";
      inpSale.value = v.sale;
      inpSale.placeholder = "Optional";
      tdSale.appendChild(inpSale);
      tr.appendChild(tdSale);

      const tdDisc = document.createElement("td");
      const inpDisc = document.createElement("input");
      inpDisc.type = "text";
      inpDisc.className = "input input-sm mono";
      const ovCur = (addProdState.varOverrides || {})[v.comboKey] || {};
      inpDisc.value = ovCur.disc !== undefined ? ovCur.disc : deriveUnitDiscount(v.reg, v.sale);
      inpDisc.placeholder = "20% or 40";
      tdDisc.appendChild(inpDisc);
      tr.appendChild(tdDisc);

      inpReg.addEventListener("input", () => {
        if (!addProdState.varOverrides[v.comboKey]) addProdState.varOverrides[v.comboKey] = {};
        addProdState.varOverrides[v.comboKey].reg = inpReg.value.trim();
        const dVal = inpDisc.value.trim();
        if (dVal) {
          const calc = computeSalePriceFromDiscount(inpReg.value, dVal);
          if (calc.ok) {
            inpSale.value = calc.saleStr;
            addProdState.varOverrides[v.comboKey].sale = calc.saleStr;
          }
        } else if (inpSale.value.trim()) {
          const d = deriveUnitDiscount(inpReg.value, inpSale.value);
          inpDisc.value = d;
          addProdState.varOverrides[v.comboKey].disc = d;
        }
      });

      inpSale.addEventListener("input", () => {
        if (!addProdState.varOverrides[v.comboKey]) addProdState.varOverrides[v.comboKey] = {};
        addProdState.varOverrides[v.comboKey].sale = inpSale.value.trim();
        const d = deriveUnitDiscount(inpReg.value, inpSale.value);
        inpDisc.value = d;
        addProdState.varOverrides[v.comboKey].disc = d;
      });

      inpDisc.addEventListener("input", () => {
        if (!addProdState.varOverrides[v.comboKey]) addProdState.varOverrides[v.comboKey] = {};
        const raw = inpDisc.value.trim();
        addProdState.varOverrides[v.comboKey].disc = raw;
        if (!raw || raw.toLowerCase() === "(clear)") {
          inpSale.value = "";
          addProdState.varOverrides[v.comboKey].sale = "";
          return;
        }
        const calc = computeSalePriceFromDiscount(inpReg.value, raw);
        if (calc.ok) {
          inpSale.value = calc.saleStr;
          addProdState.varOverrides[v.comboKey].sale = calc.saleStr;
          addProdState.varOverrides[v.comboKey].disc = calc.normalized;
        }
      });

      const tdSt = document.createElement("td");
      tdSt.className = "muted-sm";
      tdSt.textContent = v.status;
      tr.appendChild(tdSt);

      body.appendChild(tr);
    });
  }

  function buildAddTemplateItems() {
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const nameEnC = state.headers.findIndex((h) => ["name (en)", "name_en"].includes(h.trim().toLowerCase()));
    const typeC = colIdx("Product type");
    const regC = state.headers.findIndex((h) => ["regular price (gel)", "regular price", "price"].includes(h.trim().toLowerCase()));
    const catC = colIdx("Categories");
    const varRegC = varCol("Regular price (GEL)");

    const items = [];
    state.products.forEach((row, rIdx) => {
      if (isRowEmpty(row)) return;
      const sku = skuC >= 0 ? String(row[skuC] || "").trim() : "";
      const nameKa = nameC >= 0 ? String(row[nameC] || "").trim() : "";
      const nameEn = nameEnC >= 0 ? String(row[nameEnC] || "").trim() : "";
      if (!sku && !nameKa && !nameEn) return;
      const pType = typeC >= 0 ? String(row[typeC] || "").trim().toLowerCase() : "simple";
      const cats = catC >= 0 ? String(row[catC] || "").trim() : "";
      let priceLabel = "";
      let varCount = 0;
      if (pType === "variable") {
        const vars = getVariationsForRow(rIdx);
        varCount = vars.length;
        const nums = vars
          .map((v) => (varRegC >= 0 ? parseFloat(String(v[varRegC] || "").replace(",", ".")) : NaN))
          .filter((n) => !Number.isNaN(n) && n > 0);
        if (nums.length) {
          const mn = Math.min(...nums);
          const mx = Math.max(...nums);
          priceLabel = mn === mx ? `${mn} ₾` : `${mn}–${mx} ₾`;
        }
      } else {
        const reg = regC >= 0 ? String(row[regC] || "").trim() : "";
        if (reg) priceLabel = `${reg} ₾`;
      }
      const displayTitle = `${sku ? sku + " · " : ""}${nameKa || nameEn}`;
      items.push({
        rIdx,
        sku,
        nameKa,
        nameEn,
        cats,
        pType: pType === "variable" ? "variable" : "simple",
        varCount,
        priceLabel,
        displayTitle,
        searchText: `${sku} ${nameKa} ${nameEn} ${cats}`.toLowerCase(),
      });
    });
    return items;
  }

  function applyAddTemplate(rIdx) {
    const row = state.products[rIdx];
    if (!row) return;
    const getC = (...names) => {
      const ci = state.headers.findIndex((h) => names.includes(h.trim().toLowerCase()));
      return ci >= 0 ? String(row[ci] || "").trim() : "";
    };
    const pType = getC("product type").toLowerCase() === "variable" ? "variable" : "simple";
    $("addType").value = pType;
    const st = getC("status").toLowerCase();
    if (st === "publish" || st === "draft") $("addStatus").value = st;

    const regPrice = getC("regular price (gel)", "regular price", "price");
    const salePrice = getC("sale price (gel)", "sale price");
    $("addRegPrice").value = regPrice;
    $("addSalePrice").value = salePrice;
    if ($("addSaleDiscount")) {
      const discVal = getC("sale discount");
      $("addSaleDiscount").value = discVal || deriveUnitDiscount(regPrice, salePrice);
    }

    const eurReg = getC("eur regular price");
    const eurSale = getC("eur sale price");
    const usdReg = getC("usd regular price");
    const usdSale = getC("usd sale price");
    addProdState.extraPrices = (eurReg || eurSale || usdReg || usdSale)
      ? { eurReg, eurSale, usdReg, usdSale }
      : null;

    const cats = getC("categories");
    $("addCategories").value = cats;
    $("addTags").value = getC("tags");
    let attrsText = getC("attributes");
    $("addShortDesc").value = getC("short description");
    $("addShortDescEn").value = getC("short description (en)", "short_description_en");
    $("addDesc").value = getC("description");
    $("addDescEn").value = getC("description (en)", "description_en");

    const sugFb = suggestFileBirdForCategoryText(cats);
    if (sugFb) $("addFileBird").value = sugFb;

    addProdState.templateRIdx = rIdx;
    addProdState.varOverrides = {};
    addProdState.templateVariations = [];

    if (pType === "variable") {
      const vars = getVariationsForRow(rIdx);
      const attrGroupNames = Object.keys(state.attribute_groups || {});
      const vRegC = varCol("Regular price (GEL)");
      const vSaleC = varCol("Sale price (GEL)");
      const vStC = varCol("Status");
      const discoveredAttrs = new Set(
        attrsText.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean)
      );

      addProdState.templateVariations = vars.map((v) => {
        const attrs = {};
        attrGroupNames.forEach((g) => {
          const gi = varCol(g);
          const val = gi >= 0 ? String(v[gi] || "").trim() : "";
          if (val) {
            attrs[g] = val;
            discoveredAttrs.add(`${g}: ${val}`);
          }
        });
        return {
          comboKey: makeAttrComboKey(attrs),
          attrs,
          reg: vRegC >= 0 ? String(v[vRegC] || "").trim() : "",
          sale: vSaleC >= 0 ? String(v[vSaleC] || "").trim() : "",
          status: (vStC >= 0 ? String(v[vStC] || "").trim() : "") || "publish",
        };
      });
      if (!attrsText && discoveredAttrs.size > 0) {
        attrsText = Array.from(discoveredAttrs).join("; ");
      }
      $("addAttributes").value = attrsText;
      renderAddVarPreview();
      toast(`Copied variable template (${vars.length} variation price(s)). Enter SKU to auto-increment variation SKUs.`);
    } else {
      $("addAttributes").value = attrsText;
      renderAddVarPreview();
      toast("Copied template fields & price (enter SKU, Name & Images for the new product).");
    }
  }

  function renderAddTemplateDropdown(filterText = "") {
    const list = $("addTemplateList");
    if (!list) return;
    const q = String(filterText || "").trim().toLowerCase();
    const tokens = q ? q.split(/\s+/).filter(Boolean) : [];
    const matches = tokens.length
      ? addTemplateItems.filter((it) => tokens.every((t) => it.searchText.includes(t)))
      : addTemplateItems;
    const shown = matches.slice(0, 80);
    addTemplateActiveIdx = shown.length ? 0 : -1;
    list.innerHTML = "";
    if (!shown.length) {
      list.innerHTML = `<div class="tpl-empty">No matching products found.</div>`;
      return;
    }
    shown.forEach((it, idx) => {
      const div = document.createElement("div");
      div.className = "tpl-item" + (idx === addTemplateActiveIdx ? " active" : "");
      div.dataset.rIdx = String(it.rIdx);

      const main = document.createElement("div");
      main.className = "tpl-item-main";
      const title = document.createElement("div");
      title.className = "tpl-item-title";
      title.textContent = it.displayTitle;
      main.appendChild(title);
      if (it.nameEn && it.nameEn !== it.nameKa) {
        const sub = document.createElement("div");
        sub.className = "tpl-item-sub";
        sub.textContent = it.nameEn;
        main.appendChild(sub);
      }
      div.appendChild(main);

      const meta = document.createElement("div");
      meta.className = "tpl-item-meta";
      if (it.priceLabel) {
        const bP = document.createElement("span");
        bP.className = "tpl-badge price";
        bP.textContent = it.priceLabel;
        meta.appendChild(bP);
      }
      const bT = document.createElement("span");
      bT.className = "tpl-badge" + (it.pType === "variable" ? " var" : "");
      bT.textContent = it.pType === "variable" ? `var (${it.varCount})` : "simple";
      meta.appendChild(bT);
      div.appendChild(meta);

      div.addEventListener("mousedown", (e) => {
        e.preventDefault();
        selectAddTemplateItem(it);
      });
      list.appendChild(div);
    });
  }

  function selectAddTemplateItem(it) {
    const inp = $("addTemplateSearch");
    const clr = $("btnClearTemplate");
    const list = $("addTemplateList");
    if (inp) inp.value = it.displayTitle;
    if (clr) clr.classList.remove("hidden");
    if (list) list.classList.add("hidden");
    applyAddTemplate(it.rIdx);
  }

  function clearAddTemplateSelection() {
    const inp = $("addTemplateSearch");
    const clr = $("btnClearTemplate");
    const list = $("addTemplateList");
    if (inp) inp.value = "";
    if (clr) clr.classList.add("hidden");
    if (list) list.classList.add("hidden");
    addProdState.templateRIdx = -1;
    addProdState.templateVariations = [];
    addProdState.varOverrides = {};
    addProdState.extraPrices = null;
    renderAddVarPreview();
  }

  function suggestFileBirdForCategoryText(kaRaw) {
    const folders = state.filebird || [];
    if (!folders.length) return "";
    const findFolder = (target) => folders.find((f) => f.toLowerCase() === target.toLowerCase()) || "";
    const kaParts = String(kaRaw || "").split(/\s*[;,]\s*/).map((s) => s.trim()).filter(Boolean);
    const kaMapLow = new Map(Object.entries(state.category_en_map || {}).map(([k, v]) => [k.toLowerCase(), v]));
    const enParts = kaParts.map((k) => kaMapLow.get(k.toLowerCase())).filter(Boolean);
    const joinedLow = [...enParts, ...kaParts].join(" ; ").toLowerCase();
    const rules = [
      { test: /long\s+double[\s-]*sided|გრძელი\s+ორმხრივი/i, folder: "Products/Long Double-sided Scarf" },
      { test: /kimono->short|მოსაცმელი->მოკლე/i, folder: "Products/Short Kimono" },
      { test: /kimono->long|მოსაცმელი->გრძელი/i, folder: "Products/Long Kimono" },
      { test: /beach\s*bag|beachwear->bag|bag->beach|ზღვის\s+სამოსი->ჩანთა|ჩანთა->ზღვის/i, folder: "Products/Beach Bag" },
      { test: /beach\s*dress|ზღვის\s+სამოსი->კაბა/i, folder: "Products/Beach Dress" },
      { test: /beach\s*mat|სეზონური->მატი/i, folder: "Products/Beach Mat" },
      { test: /kaftan|კაფტანი/i, folder: "Products/Kaftan" },
      { test: /pareo|პარეო/i, folder: "Products/Pareo" },
      { test: /coat|პალტო/i, folder: "Products/Coat" },
      { test: /gift\s*set|სასაჩუქრე\s+ნაკრები/i, folder: "Products/Gift Set" },
      { test: /\bvest\b|ჟილეტი/i, folder: "Products/Vest" },
      { test: /scarf|თავსაბურავი/i, folder: "Products/Silk Scarf" },
      { test: /kimono|მოსაცმელი/i, folder: "Products/Long Kimono" },
    ];
    for (const r of rules) {
      if (r.test.test(joinedLow)) {
        const hit = findFolder(r.folder);
        if (hit) return hit;
      }
    }
    return findFolder("Products") || "";
  }

  function populateDescReuseSelect(selectId, colNames, targetTextareaId) {
    const sel = $(selectId);
    if (!sel) return;
    sel.innerHTML = `<option value="">Copy from existing...</option>`;
    const ci = state.headers.findIndex((h) => colNames.includes(h.trim().toLowerCase()));
    if (ci < 0) return;
    const skuC = colIdx("SKU");
    const nameC = colIdx("Name");
    const seen = new Set();
    const entries = [];
    state.products.forEach((row) => {
      const raw = String(row[ci] || "").trim();
      if (!raw || seen.has(raw)) return;
      seen.add(raw);
      const sku = skuC >= 0 ? String(row[skuC] || "").trim() : "";
      const name = nameC >= 0 ? String(row[nameC] || "").trim() : "";
      const plain = raw.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
      const snippet = plain.length > 42 ? plain.slice(0, 39) + "..." : plain;
      entries.push({ value: raw, label: `${sku ? sku + " · " : ""}${name ? name.slice(0, 18) + " — " : ""}${snippet}` });
    });
    entries.slice(0, 120).forEach((ent) => {
      const o = document.createElement("option");
      o.value = ent.value;
      o.textContent = ent.label;
      sel.appendChild(o);
    });
    sel.onchange = () => {
      if (sel.value) {
        $(targetTextareaId).value = sel.value;
        sel.value = "";
      }
    };
  }

  function openAddProductModal() {
    if (!state.headers.length) {
      toast("Open or import a spreadsheet first.", true);
      return;
    }
    addProdState = {
      feat: null,
      hover: null,
      gallery: [],
      templateRIdx: -1,
      templateVariations: [],
      varOverrides: {},
      extraPrices: null,
    };
    $("addSku").value = "";
    $("addStatus").value = "draft";
    $("addNameKa").value = "";
    $("addNameEn").value = "";
    $("addType").value = "simple";
    $("addRegPrice").value = "";
    $("addSalePrice").value = "";
    if ($("addSaleDiscount")) $("addSaleDiscount").value = "";
    $("addCategories").value = "";
    $("addTags").value = "";
    $("addAttributes").value = "";
    $("addShortDesc").value = "";
    $("addShortDescEn").value = "";
    $("addDesc").value = "";
    $("addDescEn").value = "";
    $("addProdWarn").classList.add("hidden");
    $("addProdWarn").textContent = "";

    populateDescReuseSelect("addReuseShortKa", ["short description"], "addShortDesc");
    populateDescReuseSelect("addReuseShortEn", ["short description (en)", "short_description_en"], "addShortDescEn");
    populateDescReuseSelect("addReuseDescKa", ["description"], "addDesc");
    populateDescReuseSelect("addReuseDescEn", ["description (en)", "description_en"], "addDescEn");

    // Populate Searchable Quick Template combobox
    addTemplateItems = buildAddTemplateItems();
    const tplInp = $("addTemplateSearch");
    const tplClr = $("btnClearTemplate");
    const tplList = $("addTemplateList");
    if (tplInp) tplInp.value = "";
    if (tplClr) tplClr.classList.add("hidden");
    if (tplList) tplList.classList.add("hidden");
    renderAddVarPreview();

    const fbSel = $("addFileBird");
    fbSel.innerHTML = `<option value="">(No FileBird folder)</option>`;
    const defFb = (state.filebird || []).find((f) => f.toLowerCase() === "products") || "";
    (state.filebird || []).forEach((fb) => {
      const o = document.createElement("option");
      o.value = fb;
      o.textContent = fb;
      if (fb === defFb) o.selected = true;
      fbSel.appendChild(o);
    });

    // Keep Local Images drawer visible so user can drag or click F/H/+G directly into the form
    if ($("imagesDrawer").classList.contains("hidden")) {
      $("imagesDrawer").classList.remove("hidden");
      if (!state.localImages || !state.localImages.total) loadLocalImages();
    }

    renderAddProductImages();
    $("addProductModal").classList.remove("hidden");
    setTimeout(() => $("addSku").focus(), 20);
  }

  function renderAddProductImages() {
    const renderSlot = (containerId, list, onRemove) => {
      const el = $(containerId);
      el.innerHTML = "";
      if (!list.length) {
        el.innerHTML = `<span class="muted-sm" style="font-size:11px;">None selected</span>`;
        return;
      }
      list.forEach((item, idx) => {
        const card = document.createElement("div");
        card.className = "img-card";
        card.title = item.name;
        const img = document.createElement("img");
        img.referrerPolicy = "no-referrer";
        img.src = item.url ? item.url : "/api/local-image?path=" + encodeURIComponent(item.path);
        img.addEventListener("click", () => {
          const slot = containerId === "addFeatPreview" ? "feat" : containerId === "addHoverPreview" ? "hover" : "gallery";
          const lbItems = list.map((it2) => ({
            src: it2.url ? it2.url : "/api/local-image?path=" + encodeURIComponent(it2.path),
            caption: it2.url || it2.name,
            source: "addModal",
            slot,
            addItem: it2,
          }));
          openLightbox(img.src, item.url || item.name, lbItems, idx);
        });
        card.appendChild(img);
        const acts = document.createElement("div");
        acts.className = "img-card-actions";
        const bEdit = document.createElement("button");
        bEdit.type = "button";
        bEdit.className = "img-mini-btn";
        bEdit.textContent = "✂";
        bEdit.title = "Crop / Rotate / Resize image";
        bEdit.addEventListener("click", (e) => {
          e.stopPropagation();
          openImageStudio([{
            label: item.name,
            src: "/api/local-image?path=" + encodeURIComponent(item.path),
            originalPath: item.path,
            filename: item.name,
            rIdx: -1,
            ci: -1,
            hLow: "",
            tokenIdx: -1,
            folder: "",
          }]);
        });
        acts.appendChild(bEdit);

        const bDel = document.createElement("button");
        bDel.type = "button";
        bDel.className = "img-mini-btn del";
        bDel.textContent = "×";
        bDel.addEventListener("click", (e) => {
          e.stopPropagation();
          onRemove(idx);
          renderAddProductImages();
        });
        acts.appendChild(bDel);
        card.appendChild(acts);
        el.appendChild(card);
      });
    };
    renderSlot("addFeatPreview", addProdState.feat ? [addProdState.feat] : [], () => (addProdState.feat = null));
    renderSlot("addHoverPreview", addProdState.hover ? [addProdState.hover] : [], () => (addProdState.hover = null));
    renderSlot("addGalPreview", addProdState.gallery, (idx) => addProdState.gallery.splice(idx, 1));
  }

  function pickAddProductImages(target) {
    const isMulti = target === "gallery";
    const picker = document.createElement("input");
    picker.type = "file";
    picker.accept = ".jpg,.jpeg,.png,.gif,.webp,image/*";
    picker.multiple = isMulti;
    picker.addEventListener("change", async () => {
      const files = Array.from(picker.files || []).filter((f) => /\.(jpe?g|png|gif|webp)$/i.test(f.name));
      if (!files.length) return;
      const toUpload = isMulti ? files : [files[0]];
      for (const file of toUpload) {
        const b64 = await fileToBase64(file);
        const res = await apiPost("/api/upload-drop", {
          filename: file.name,
          data_base64: b64,
          images_root: state.images_root,
        });
        if (res.ok) {
          const entry = { name: res.filename, path: res.path };
          if (target === "feat") addProdState.feat = entry;
          else if (target === "hover") addProdState.hover = entry;
          else addProdState.gallery.push(entry);
        } else {
          toast(res.error || `Failed to load ${file.name}`, true);
        }
      }
      renderAddProductImages();
    });
    picker.click();
  }

  function openAddModalPicker(anchorEl, title, options, inputId, onPicked) {
    const current = ($(inputId).value || "").split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
    colFilterCtx = null;
    multiCtx = {
      options,
      selected: new Set(current),
      onApply: (vals) => {
        $(inputId).value = vals.join("; ");
        if (onPicked) onPicked(vals);
      },
    };
    $("multiTitle").textContent = title;
    $("multiSearch").value = "";
    renderMultiPopover();
    const pop = $("multiPopover");
    pop.classList.remove("hidden");
    const rect = anchorEl.getBoundingClientRect();
    const top = Math.min(window.innerHeight - 470, Math.max(10, rect.bottom + 4));
    const left = Math.min(window.innerWidth - 375, Math.max(10, rect.left));
    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
    $("multiSearch").focus();
  }

  function submitAddProductModal(keepOpen = false) {
    const sku = $("addSku").value.trim();
    const nameKa = $("addNameKa").value.trim();
    const warnEl = $("addProdWarn");
    if (!sku && !nameKa) {
      warnEl.textContent = "Please enter at least a SKU or a Product Name.";
      warnEl.classList.remove("hidden");
      $("addSku").focus();
      return;
    }
    const skuC = colIdx("SKU");
    if (sku && skuC >= 0) {
      const dup = state.products.some((r) => (r[skuC] || "").trim().toLowerCase() === sku.toLowerCase());
      if (dup) {
        warnEl.textContent = `Warning: SKU "${sku}" already exists in this sheet.`;
        warnEl.classList.remove("hidden");
        return;
      }
    }

    const nr = new Array(state.headers.length).fill("");
    const setCol = (name, val) => {
      const c = colIdx(name);
      if (c >= 0 && val !== undefined && val !== null) nr[c] = String(val);
    };
    const kaCats = $("addCategories").value.trim();
    const kaMapLow = new Map(Object.entries(state.category_en_map || {}).map(([k, v]) => [k.toLowerCase(), v]));
    const enCats = kaCats
      .split(/\s*;\s*/)
      .map((s) => kaMapLow.get(s.trim().toLowerCase()) || "")
      .filter(Boolean)
      .join("; ");

    const fb = $("addFileBird").value.trim();
    const fmtImg = (item) => {
      if (!item) return "";
      if (item.url) return item.url;
      const tok = fb ? `${fb}/${item.name}` : item.name;
      state.paths[tok] = item.path;
      state.paths[item.name] = item.path;
      return tok;
    };

    const pType = $("addType").value || "simple";
    const plannedVars = pType === "variable" ? buildPlannedAddVariations() : [];
    setCol("SKU", sku);
    setCol("Name", nameKa);
    setCol("Name (EN)", $("addNameEn").value.trim());
    setCol("Categories", kaCats);
    setCol("Categories (EN)", enCats);
    setCol("Tags", $("addTags").value.trim());
    setCol("Attributes", $("addAttributes").value.trim());
    setCol("Product type", pType);
    setCol("Status", $("addStatus").value || "draft");
    if (pType === "variable" && plannedVars.length > 0) {
      setCol("Regular price (GEL)", "");
      setCol("Sale price (GEL)", "");
      setCol("Sale Discount", "");
    } else {
      const regStr = $("addRegPrice").value.trim();
      let saleStr = $("addSalePrice").value.trim();
      let discStr = ($("addSaleDiscount")?.value || "").trim();
      if (discStr && !saleStr) {
        const calc = computeSalePriceFromDiscount(regStr, discStr);
        if (calc.ok) {
          saleStr = calc.saleStr;
          discStr = calc.normalized;
        }
      } else if (saleStr && !discStr) {
        discStr = deriveUnitDiscount(regStr, saleStr);
      }
      setCol("Regular price (GEL)", regStr);
      setCol("Sale price (GEL)", saleStr);
      setCol("Sale Discount", discStr);
      if (addProdState.extraPrices) {
        if (addProdState.extraPrices.eurReg) setCol("EUR regular price", addProdState.extraPrices.eurReg);
        if (addProdState.extraPrices.eurSale) setCol("EUR sale price", addProdState.extraPrices.eurSale);
        if (addProdState.extraPrices.usdReg) setCol("USD regular price", addProdState.extraPrices.usdReg);
        if (addProdState.extraPrices.usdSale) setCol("USD sale price", addProdState.extraPrices.usdSale);
      }
    }
    setCol("Short description", $("addShortDesc").value.trim());
    setCol("Short description (EN)", $("addShortDescEn").value.trim());
    setCol("Description", $("addDesc").value.trim());
    setCol("Description (EN)", $("addDescEn").value.trim());
    setCol("Featured image", fmtImg(addProdState.feat));
    setCol("Hover image", fmtImg(addProdState.hover));
    setCol("Gallery images", addProdState.gallery.map(fmtImg).filter(Boolean).join("; "));

    pushUndo();
    // Insert right after the last non-empty product row (before blank scratch rows)
    let insertAt = state.products.length;
    while (insertAt > 0 && isRowEmpty(state.products[insertAt - 1])) {
      insertAt--;
    }
    state.products.splice(insertAt, 0, nr);

    // Auto-generate variation rows for variable products (with copied prices & auto-incremented SKUs)
    if (pType === "variable" && plannedVars.length > 0 && state.variation_headers.length > 0) {
      const pidC = varCol("Parent ID");
      const pskuC = varCol("Parent SKU");
      const vidC = varCol("Variation ID");
      const vSkuC = varCol("SKU");
      const vRegC = varCol("Regular price (GEL)");
      const vSaleC = varCol("Sale price (GEL)");
      const vStC = varCol("Status");
      plannedVars.forEach((pv) => {
        const vr = new Array(state.variation_headers.length).fill("");
        if (pidC >= 0) vr[pidC] = "";
        if (pskuC >= 0) vr[pskuC] = sku;
        if (vidC >= 0) vr[vidC] = "";
        if (vSkuC >= 0) vr[vSkuC] = pv.sku || "";
        Object.entries(pv.attrs || {}).forEach(([g, val]) => {
          const gi = varCol(g);
          if (gi >= 0) vr[gi] = val;
        });
        if (vRegC >= 0) vr[vRegC] = pv.reg || "";
        if (vSaleC >= 0) vr[vSaleC] = pv.sale || "";
        if (vStC >= 0) vr[vStC] = pv.status || "publish";
        state.variations.push(vr);
        const ovDisc = ((addProdState.varOverrides || {})[pv.comboKey] || {}).disc || "";
        const vKey = getVarDiscountKey(vr);
        if (vKey && ovDisc) {
          const dp = parseSaleDiscount(ovDisc);
          if (dp.kind === "pct" || dp.kind === "unit") {
            if (!state.varSaleDiscounts) state.varSaleDiscounts = {};
            state.varSaleDiscounts[vKey] = dp.normalized;
          }
        }
      });
    }

    setDirty(true);
    renderGrid();

    const newVis = state.visibleRows.indexOf(insertAt);
    if (newVis >= 0) {
      const col = skuC >= 0 ? skuC : 1;
      state.selectedCells.clear();
      state.selectedCells.add(cellKey(newVis, col));
      state.selAnchor = { visRow: newVis, col };
      state.selEnd = { visRow: newVis, col };
      updateSelectionClasses();
      const tr = getGridRowEl(newVis);
      if (tr) tr.scrollIntoView({ block: "center" });
    }
    toast(
      plannedVars.length > 0
        ? `Added variable product "${nameKa || sku}" with ${plannedVars.length} variation(s) at row ${insertAt + 2}.`
        : `Added product "${nameKa || sku}" at row ${insertAt + 2}.`
    );

    if (keepOpen) {
      // Reset unique fields (SKU, Name, Images) while keeping template/variations/Category/Price/Descriptions for fast next entry
      addProdState.feat = null;
      addProdState.hover = null;
      addProdState.gallery = [];
      $("addSku").value = "";
      $("addNameKa").value = "";
      $("addNameEn").value = "";
      $("addProdWarn").classList.add("hidden");
      renderAddProductImages();
      renderAddVarPreview();
      setTimeout(() => $("addSku").focus(), 20);
      return;
    }

    $("addProductModal").classList.add("hidden");
    if (pType === "variable" && plannedVars.length === 0) {
      openVariationModal(insertAt, true);
    }
  }

  // ---------- Image Crop / Rotate / Resize Studio (BIRME-style) ----------
  const studio = {
    items: [], // [{ label, src, originalPath, filename, rIdx, ci, hLow, tokenIdx, folder, img, rot, flipH, flipV, zoom, panX, panY, w, h, fit, bg, fmt, qual, saved }]
    idx: 0,
    dragging: false,
    dragStartX: 0,
    dragStartY: 0,
    dragOrigPanX: 0,
    dragOrigPanY: 0,
  };

  function makeStudioItemFromCell(rIdx, ci, hLow, tok, tokenIdx) {
    const parsed = parsePictureToken(tok);
    let src = "";
    let originalPath = "";
    let filename = parsed.file || "image.jpg";
    if (parsed.kind === "url") {
      src = "/api/proxy-image?url=" + encodeURIComponent(parsed.file);
      try {
        filename = decodeURIComponent(new URL(parsed.file).pathname.split("/").pop() || "image.jpg");
      } catch {}
    } else {
      originalPath = state.paths[tok] || state.paths[parsed.file] || "";
      src = imagePreviewSrc(tok);
    }
    const skuC = colIdx("SKU");
    const sku = skuC >= 0 && state.products[rIdx] ? (state.products[rIdx][skuC] || "").trim() : "";
    const folder = parsed.folder || suggestFileBirdFolderForRow(rIdx) || "";
    return {
      label: `${sku ? sku + " · " : ""}${filename}`,
      src,
      originalPath,
      filename,
      rIdx,
      ci,
      hLow,
      tokenIdx,
      folder,
    };
  }

  function openBadRatioStudio() {
    const collected = [];
    const imgCols = [colIdx("Featured image"), colIdx("Hover image"), colIdx("Gallery images")].filter((c) => c >= 0);
    const targetRows = state.ratioBadRows.size
      ? Array.from(state.ratioBadRows).sort((a, b) => a - b)
      : getSelectedRealRowIndices();

    if (!targetRows.length) {
      toast("No Bad Ratio images detected yet (or select rows first to bulk edit their images).", true);
      return;
    }
    targetRows.forEach((rIdx) => {
      const row = state.products[rIdx];
      if (!row) return;
      imgCols.forEach((ci) => {
        const hLow = (state.headers[ci] || "").trim().toLowerCase();
        const tokens = splitPictures(row[ci] || "");
        tokens.forEach((tok, ti) => {
          const parsed = parsePictureToken(tok);
          if (parsed.kind === "url" || parsed.kind === "file") {
            collected.push(makeStudioItemFromCell(rIdx, ci, hLow, tok, ti));
          }
        });
      });
    });
    if (!collected.length) {
      toast("No images found in the flagged/selected rows.", true);
      return;
    }
    openImageStudio(collected);
  }

  function openImageStudio(rawItems) {
    if (!rawItems || !rawItems.length) return;
    const defW = parseInt($("studioW").value, 10) || 1000;
    const defH = parseInt($("studioH").value, 10) || 1500;
    const defFit = $("studioFit").value || "cover";
    const defBg = $("studioBgColor").value || "#ffffff";
    const defFmt = $("studioFmt").value || "jpg";
    const defQual = (parseInt($("studioQual").value, 10) || 88) / 100;

    studio.items = rawItems.map((raw) => ({
      ...raw,
      img: null,
      loading: false,
      error: false,
      rot: 0,
      straighten: 0,
      flipH: false,
      flipV: false,
      zoom: 1,
      panX: 0,
      panY: 0,
      align: "center",
      w: defW,
      h: defH,
      fit: defFit,
      bg: defBg,
      fmt: defFmt,
      qual: defQual,
      saved: false,
    }));
    studio.idx = 0;
    $("studioCount").textContent = String(studio.items.length);
    $("studioProgress").textContent = "";
    $("studioOverwriteOrig").checked = false;
    $("imageStudioModal").classList.remove("hidden");
    renderStudioFilmstrip();
    selectStudioItem(0);
  }

  function renderStudioFilmstrip() {
    const strip = $("studioFilmstrip");
    strip.innerHTML = "";
    studio.items.forEach((it, i) => {
      const card = document.createElement("div");
      card.className = "studio-thumb-item" + (i === studio.idx ? " active" : "") + (it.saved ? " saved" : "");
      card.innerHTML = `
        <img src="${esc(it.src)}" loading="lazy" alt="">
        <div class="studio-thumb-meta" title="${esc(it.label)}">${it.saved ? "✓ " : ""}${esc(it.label)}</div>
      `;
      card.addEventListener("click", () => selectStudioItem(i));
      strip.appendChild(card);
    });
  }

  function syncStudioControlsFromItem(it) {
    if (!it) return;
    $("studioW").value = String(it.w);
    $("studioH").value = String(it.h);
    const presetKey = `${it.w}x${it.h}`;
    const ps = $("studioPreset");
    const hasOpt = Array.from(ps.options).some((o) => o.value === presetKey);
    ps.value = hasOpt ? presetKey : "custom";
    $("studioFit").value = it.fit;
    $("studioBgRow").classList.toggle("hidden", it.fit !== "contain");
    $("studioBgColor").value = it.bg;
    $("studioFmt").value = it.fmt;
    $("studioQual").value = String(Math.round(it.qual * 100));
    $("studioQualVal").textContent = String(Math.round(it.qual * 100));
    $("studioZoom").value = String(it.zoom);
    $("studioZoomVal").textContent = it.zoom.toFixed(2);
    $("studioStraighten").value = String(it.straighten || 0);
    $("studioStraightenVal").textContent = Number(it.straighten || 0).toFixed(1);
  }

  function alignStudioItem(it, mode) {
    if (!it || !it.img || !it.img.naturalWidth) return;
    it.align = mode;
    it.panX = 0;
    if (mode === "center") {
      it.panY = 0;
      return;
    }
    const outW = Math.max(100, it.w);
    const outH = Math.max(100, it.h);
    const swapped = Math.abs(it.rot) % 180 === 90;
    const effW = swapped ? it.img.naturalHeight : it.img.naturalWidth;
    const effH = swapped ? it.img.naturalWidth : it.img.naturalHeight;
    const baseScale =
      it.fit === "contain"
        ? Math.min(outW / effW, outH / effH)
        : Math.max(outW / effW, outH / effH);
    const drawnH = effH * baseScale * it.zoom;
    const maxShift = Math.max(0, (drawnH - outH) / 2);
    it.panY = mode === "top" ? maxShift : -maxShift;
  }

  let studioSizeTimer = null;
  function scheduleStudioSizeEstimate() {
    clearTimeout(studioSizeTimer);
    const badge = $("studioSizeBadge");
    if (!badge) return;
    studioSizeTimer = setTimeout(() => {
      const it = studio.items[studio.idx];
      if (!it || !it.img || !it.img.naturalWidth) {
        badge.textContent = "";
        return;
      }
      try {
        const off = document.createElement("canvas");
        drawStudioToCanvas(off, it, false);
        const mime = it.fmt === "png" ? "image/png" : it.fmt === "webp" ? "image/webp" : "image/jpeg";
        const url = off.toDataURL(mime, it.qual);
        const b64Len = url.length - (url.indexOf(",") + 1);
        const kb = Math.max(1, Math.round((b64Len * 0.75) / 1024));
        badge.textContent = `~${kb} KB`;
      } catch {
        badge.textContent = "";
      }
    }, 240);
  }

  function loadStudioImage(it) {
    return new Promise((resolve) => {
      if (it.img && it.img.complete && it.img.naturalWidth) {
        resolve(it.img);
        return;
      }
      const im = new Image();
      im.onload = () => {
        it.img = im;
        it.error = false;
        resolve(im);
      };
      im.onerror = () => {
        it.error = true;
        resolve(null);
      };
      im.src = it.src;
    });
  }

  async function selectStudioItem(index) {
    if (index < 0 || index >= studio.items.length) return;
    studio.idx = index;
    const it = studio.items[index];
    syncStudioControlsFromItem(it);
    renderStudioFilmstrip();
    $("studioLoading").classList.remove("hidden");
    await loadStudioImage(it);
    $("studioLoading").classList.add("hidden");
    if (studio.idx === index) renderStudioCanvas();
  }

  function drawStudioToCanvas(canvas, it, showGuides) {
    const ctx = canvas.getContext("2d");
    const outW = Math.max(100, it.w);
    const outH = Math.max(100, it.h);
    if (canvas.width !== outW || canvas.height !== outH) {
      canvas.width = outW;
      canvas.height = outH;
    }
    ctx.save();
    ctx.clearRect(0, 0, outW, outH);
    ctx.fillStyle = it.fit === "contain" ? it.bg : "#0f172a";
    ctx.fillRect(0, 0, outW, outH);

    const img = it.img;
    if (img && img.naturalWidth && img.naturalHeight) {
      const rotRad = (((it.rot || 0) + (it.straighten || 0)) * Math.PI) / 180;
      const swapped = Math.abs(it.rot) % 180 === 90;
      const effW = swapped ? img.naturalHeight : img.naturalWidth;
      const effH = swapped ? img.naturalWidth : img.naturalHeight;

      const baseScale =
        it.fit === "contain"
          ? Math.min(outW / effW, outH / effH)
          : Math.max(outW / effW, outH / effH);
      const scale = baseScale * it.zoom;

      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.translate(outW / 2 + it.panX, outH / 2 + it.panY);
      ctx.rotate(rotRad);
      ctx.scale(it.flipH ? -scale : scale, it.flipV ? -scale : scale);
      ctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
    }
    ctx.restore();

    if (showGuides) {
      ctx.save();
      ctx.strokeStyle = "rgba(255, 255, 255, 0.28)";
      ctx.lineWidth = Math.max(1, outW / 500);
      for (let k = 1; k <= 2; k++) {
        ctx.beginPath();
        ctx.moveTo((outW * k) / 3, 0);
        ctx.lineTo((outW * k) / 3, outH);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(0, (outH * k) / 3);
        ctx.lineTo(outW, (outH * k) / 3);
        ctx.stroke();
      }
      ctx.restore();
    }
  }

  function renderStudioCanvas() {
    const it = studio.items[studio.idx];
    if (!it) return;
    const canvas = $("studioCanvas");
    const wrap = $("studioCanvasWrap");
    const stageW = Math.max(320, Math.round(wrap.clientWidth || 640));
    const stageH = Math.max(320, Math.round(wrap.clientHeight || 520));
    if (canvas.width !== stageW || canvas.height !== stageH) {
      canvas.width = stageW;
      canvas.height = stageH;
    }

    const outW = Math.max(100, it.w);
    const outH = Math.max(100, it.h);
    const targetRatio = outW / outH;

    // Fit the bright crop frame cleanly inside the stage with generous margin so cropped-out parts show at dimmed opacity
    const maxCropW = stageW * 0.76;
    const maxCropH = stageH * 0.80;
    let cropW = maxCropW;
    let cropH = cropW / targetRatio;
    if (cropH > maxCropH) {
      cropH = maxCropH;
      cropW = cropH * targetRatio;
    }
    const cropX = (stageW - cropW) / 2;
    const cropY = (stageH - cropH) / 2;
    const previewScale = cropW / outW;
    studio.previewCropScale = outW / cropW;

    const ctx = canvas.getContext("2d");
    ctx.save();
    ctx.clearRect(0, 0, stageW, stageH);
    ctx.fillStyle = "#020617";
    ctx.fillRect(0, 0, stageW, stageH);

    // Fill crop box background (visible in 'contain' pad mode)
    ctx.fillStyle = it.fit === "contain" ? it.bg : "#0f172a";
    ctx.fillRect(cropX, cropY, cropW, cropH);

    const img = it.img;
    if (img && img.naturalWidth && img.naturalHeight) {
      const rotRad = (((it.rot || 0) + (it.straighten || 0)) * Math.PI) / 180;
      const swapped = Math.abs(it.rot) % 180 === 90;
      const effW = swapped ? img.naturalHeight : img.naturalWidth;
      const effH = swapped ? img.naturalWidth : img.naturalHeight;

      const baseScale =
        it.fit === "contain"
          ? Math.min(outW / effW, outH / effH)
          : Math.max(outW / effW, outH / effH);
      const scale = baseScale * it.zoom * previewScale;

      ctx.save();
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.translate(stageW / 2 + it.panX * previewScale, stageH / 2 + it.panY * previewScale);
      ctx.rotate(rotRad);
      ctx.scale(it.flipH ? -scale : scale, it.flipV ? -scale : scale);
      ctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
      ctx.restore();
    }

    // Dim everything outside the crop frame so user sees the full picture + what gets cropped
    ctx.fillStyle = "rgba(2, 6, 23, 0.68)";
    ctx.fillRect(0, 0, stageW, cropY); // Top
    ctx.fillRect(0, cropY + cropH, stageW, stageH - (cropY + cropH)); // Bottom
    ctx.fillRect(0, cropY, cropX, cropH); // Left
    ctx.fillRect(cropX + cropW, cropY, stageW - (cropX + cropW), cropH); // Right

    // Rule-of-thirds guides inside crop frame
    ctx.strokeStyle = "rgba(255, 255, 255, 0.28)";
    ctx.lineWidth = 1;
    for (let k = 1; k <= 2; k++) {
      ctx.beginPath();
      ctx.moveTo(cropX + (cropW * k) / 3, cropY);
      ctx.lineTo(cropX + (cropW * k) / 3, cropY + cropH);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(cropX, cropY + (cropH * k) / 3);
      ctx.lineTo(cropX + cropW, cropY + (cropH * k) / 3);
      ctx.stroke();
    }

    // Crisp Crop Frame Border
    ctx.strokeStyle = "#3b82f6";
    ctx.lineWidth = 2;
    ctx.strokeRect(cropX + 0.5, cropY + 0.5, cropW - 1, cropH - 1);

    ctx.restore();

    const origInfo = it.img ? `Orig: ${it.img.naturalWidth}×${it.img.naturalHeight}` : "Failed to load";
    $("studioDimBadge").textContent = `${origInfo} → Out: ${it.w}×${it.h}`;
    scheduleStudioSizeEstimate();
  }

  async function saveStudioItems(indices) {
    const overwriteOrig = $("studioOverwriteOrig").checked;
    if (overwriteOrig) {
      const ok = confirm(
        `You checked "Also overwrite the original file on disk".\n\nA copy (*_2x3) will be created in the same folder AND the original local file(s) will be overwritten. Continue?`
      );
      if (!ok) return;
    }

    const btnOne = $("btnStudioSaveOne");
    const btnAll = $("btnStudioSaveAll");
    btnOne.disabled = true;
    btnAll.disabled = true;
    const offscreen = document.createElement("canvas");
    let savedCount = 0;
    let sheetUpdated = false;

    try {
      for (let k = 0; k < indices.length; k++) {
        const idx = indices[k];
        const it = studio.items[idx];
        $("studioProgress").textContent = `Saving ${k + 1} / ${indices.length}: ${it.filename}...`;
        await loadStudioImage(it);
        if (!it.img || !it.img.naturalWidth) continue;

        drawStudioToCanvas(offscreen, it, false);
        const mime = it.fmt === "png" ? "image/png" : it.fmt === "webp" ? "image/webp" : "image/jpeg";
        const dataUrl = offscreen.toDataURL(mime, it.qual);
        const b64 = dataUrl.includes(",") ? dataUrl.split(",")[1] : dataUrl;

        const res = await apiPost("/api/save-edited-image", {
          original_path: it.originalPath,
          filename: it.filename,
          data_base64: b64,
          ext: it.fmt,
          overwrite_original: overwriteOrig,
          images_root: state.images_root,
        });
        if (!res.ok) {
          toast(res.error || `Failed to save ${it.filename}`, true);
          continue;
        }

        it.saved = true;
        savedCount++;
        const newTok = it.folder ? `${it.folder}/${res.copy_name}` : res.copy_name;
        state.paths[newTok] = res.copy_path;
        state.paths[res.copy_name] = res.copy_path;

        // Update the product cell in the sheet if this image came from a product row
        if (it.rIdx >= 0 && it.ci >= 0 && state.products[it.rIdx]) {
          if (!sheetUpdated) pushUndo();
          if (it.hLow === "gallery images") {
            const parts = splitPictures(state.products[it.rIdx][it.ci] || "");
            if (it.tokenIdx >= 0 && it.tokenIdx < parts.length) {
              parts[it.tokenIdx] = newTok;
            }
            state.products[it.rIdx][it.ci] = parts.join("; ");
          } else {
            state.products[it.rIdx][it.ci] = newTok;
          }
          sheetUpdated = true;
        }
      }
    } finally {
      btnOne.disabled = false;
      btnAll.disabled = false;
      $("studioProgress").textContent = "";
    }

    if (savedCount > 0) {
      if (sheetUpdated) {
        setDirty(true);
        state.ratioBadRows.clear();
        $("countRatio").textContent = "0";
        renderGrid();
      }
      loadLocalImages();
      renderStudioFilmstrip();
      toast(
        `Saved ${savedCount} resized image copy(ies) in original folder${overwriteOrig ? " (+ updated originals)" : ""}!`
      );
      if (indices.length === studio.items.length) {
        $("imageStudioModal").classList.add("hidden");
      }
    }
  }

  // ---------- WordPress Live Sync (Pull, Push, Purge/Reindex/Warm, Speed Check, New Category) ----------
  const wpSync = {
    connected: false,
    columns: [],
    categories: [], // [{ id, parent, name, ka_path, en_path }]
    newCatStep: 1,
  };

  async function refreshWpConnectionStatus(silent = true) {
    const dot = $("wpConnDot");
    const s = state.settings || {};
    if (!s.wp_username || !s.wp_app_password) {
      wpSync.connected = false;
      if (dot) {
        dot.className = "conn-dot";
        dot.title = "odella.ge not connected — click to configure WordPress Application Password in Settings";
      }
      return { ok: false, error: "Not configured" };
    }
    const res = await apiPost("/api/wp/test", {});
    if (res.ok) {
      wpSync.connected = true;
      wpSync.columns = res.columns || [];
      wpSync.categories = res.categories || [];
      if (dot) {
        dot.className = "conn-dot ok";
        dot.title = `Connected to ${res.site || s.wp_site_url} as ${res.user}`;
      }
    } else {
      wpSync.connected = false;
      if (dot) {
        dot.className = "conn-dot err";
        dot.title = res.error || "odella.ge connection failed — click to open Settings";
      }
      if (!silent) toast(res.error || "Connection failed", true);
    }
    return res;
  }

  function ensureWpConfigured() {
    const s = state.settings || {};
    if (!s.wp_username || !s.wp_app_password) {
      toast("Configure your WordPress Username & Application Password in Settings first.", true);
      openSettingsModal();
      return false;
    }
    return true;
  }

  // ---- Pull ----
  const DEFAULT_PULL_COLS = [
    { key: "id", label: "ID", default: true, required: true },
    { key: "sku", label: "SKU", default: true, required: true },
    { key: "name", label: "Name", default: true, required: true },
    { key: "name_en", label: "Name (EN)", default: true },
    { key: "categories", label: "Categories", default: true },
    { key: "categories_en", label: "Categories (EN)", default: true },
    { key: "tags", label: "Tags", default: false },
    { key: "product_type", label: "Product type", default: true },
    { key: "featured", label: "Featured image", default: true },
    { key: "hover", label: "Hover image", default: true },
    { key: "gallery", label: "Gallery images", default: true },
    { key: "status", label: "Status", default: true },
    { key: "regular_price", label: "Regular price (GEL)", default: true },
    { key: "sale_price", label: "Sale price (GEL)", default: true },
    { key: "short_description", label: "Short description", default: true },
    { key: "short_description_en", label: "Short description (EN)", default: true },
    { key: "description", label: "Description", default: true },
    { key: "description_en", label: "Description (EN)", default: true },
    { key: "eur_regular", label: "EUR regular price", default: true },
    { key: "eur_sale", label: "EUR sale price", default: true },
    { key: "usd_regular", label: "USD regular price", default: true },
    { key: "usd_sale", label: "USD sale price", default: true },
    { key: "attributes", label: "Attributes", default: true },
  ];

  async function openPullModal() {
    if (!ensureWpConfigured()) return;
    $("pullDirtyWarn").classList.toggle("hidden", !state.dirty);
    $("pullStatusText").textContent = "";
    if (!wpSync.columns.length) {
      $("pullStatusText").textContent = "Loading column list from odella.ge...";
      await refreshWpConnectionStatus(true);
      $("pullStatusText").textContent = "";
    }
    const cols = wpSync.columns.length ? wpSync.columns : DEFAULT_PULL_COLS;
    const grid = $("pullColGrid");
    grid.innerHTML = "";
    cols.forEach((c) => {
      const lbl = document.createElement("label");
      lbl.className = "checkbox-row";
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.value = c.key;
      cb.checked = !!c.default || !!c.required;
      if (c.required) cb.disabled = true;
      cb.dataset.default = c.default ? "1" : "0";
      lbl.appendChild(cb);
      const sp = document.createElement("span");
      sp.textContent = c.label + (c.required ? " (required)" : "");
      lbl.appendChild(sp);
      grid.appendChild(lbl);
    });
    $("pullModal").classList.remove("hidden");
  }

  async function runWpPull() {
    const selectedCols = Array.from($("pullColGrid").querySelectorAll("input[type=checkbox]"))
      .filter((cb) => cb.checked || cb.disabled)
      .map((cb) => cb.value);
    const btn = $("btnStartPull");
    btn.disabled = true;
    $("pullStatusText").textContent = "Downloading fresh .xlsx from odella.ge and creating new workbook...";
    try {
      const res = await apiPost("/api/wp/pull", { columns: selectedCols });
      if (!res.ok) {
        $("pullStatusText").textContent = res.error || "Pull failed.";
        toast(res.error || "Pull failed.", true);
        return;
      }
      $("pullModal").classList.add("hidden");
      await loadFileList(res.file);
      toast(`Pulled & created new file: ${res.filename}`);
    } finally {
      btn.disabled = false;
    }
  }

  // ---- Push ----
  function buildBaselineVariationsMap(varHeaders, variations) {
    const map = new Map();
    const pidC = (varHeaders || []).findIndex((h) => String(h || "").trim().toLowerCase() === "parent id");
    const pskuC = (varHeaders || []).findIndex((h) => String(h || "").trim().toLowerCase() === "parent sku");
    (variations || []).forEach((v) => {
      const pid = pidC >= 0 ? String(v[pidC] || "").trim().replace(/\.0+$/, "") : "";
      const psku = pskuC >= 0 ? String(v[pskuC] || "").trim().toLowerCase() : "";
      const key = pid ? `id:${pid}` : (psku ? `sku:${psku}` : "");
      if (!key) return;
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(JSON.stringify(v));
    });
    const out = new Map();
    map.forEach((arr, k) => out.set(k, arr.join("\n")));
    return out;
  }

  function getChangedAndNewRowIndices() {
    const idC = colIdx("ID");
    const skuC = colIdx("SKU");
    const typeC = colIdx("Product type");
    const base = state.baselineRows || new Map();
    const baseVars = state.baselineVariations instanceof Map ? state.baselineVariations : new Map();
    const curVars = buildBaselineVariationsMap(state.variation_headers || [], state.variations || []);
    const changed = [];
    let newCount = 0;
    let modCount = 0;
    state.products.forEach((row, idx) => {
      if (isRowEmpty(row)) return;
      const isNew = idC >= 0 && !(row[idC] || "").trim();
      const curStr = JSON.stringify(row);
      const prevStr = base.get(idx);
      let varsModified = false;
      const pType = typeC >= 0 ? String(row[typeC] || "").trim().toLowerCase() : "";
      if (pType === "variable") {
        const pid = idC >= 0 ? String(row[idC] || "").trim().replace(/\.0+$/, "") : "";
        const psku = skuC >= 0 ? String(row[skuC] || "").trim().toLowerCase() : "";
        const vKey = pid ? `id:${pid}` : (psku ? `sku:${psku}` : "");
        if (vKey && (curVars.get(vKey) || "") !== (baseVars.get(vKey) || "")) {
          varsModified = true;
        }
      }
      if (isNew) {
        changed.push(idx);
        newCount++;
      } else if (prevStr === undefined || prevStr !== curStr || varsModified) {
        changed.push(idx);
        modCount++;
      }
    });
    return { indices: changed, newCount, modCount };
  }

  function countLocalImagesInRows(rowIndices) {
    const imgCols = [colIdx("Featured image"), colIdx("Hover image"), colIdx("Gallery images")].filter((c) => c >= 0);
    let count = 0;
    rowIndices.forEach((r) => {
      const row = state.products[r];
      if (!row) return;
      imgCols.forEach((c) => {
        splitPictures(row[c] || "").forEach((tok) => {
          if (parsePictureToken(tok).kind === "file") count++;
        });
      });
    });
    return count;
  }

  function openPushModal() {
    if (!ensureWpConfigured()) return;
    if (!state.headers.length) {
      toast("Open or pull a spreadsheet first.", true);
      return;
    }
    const nonEmptyIndices = state.products.map((r, i) => (isRowEmpty(r) ? -1 : i)).filter((i) => i >= 0);
    const { indices: changedIndices, newCount, modCount } = getChangedAndNewRowIndices();
    const localImgs = countLocalImagesInRows(nonEmptyIndices);
    const batchSize = Math.max(5, Math.min(25, Number(state.settings.push_batch_size || 10)));

    $("pushChangedCount").textContent = String(changedIndices.length);
    $("pushAllCount").textContent = String(nonEmptyIndices.length);
    $("pushLocalImgCount").textContent = String(localImgs);
    $("pushSummaryBox").innerHTML = `
      <div><b>Summary:</b> ${newCount} new product(s) · ${modCount} modified row(s) · ${localImgs} local image(s) · Batch size: <b>${batchSize}</b></div>
      <div class="muted-sm" style="margin-top:4px;">New products are automatically created as <b>In Stock</b>, and their new WooCommerce IDs are written back to this sheet automatically.</div>
    `;

    if (changedIndices.length > 0) {
      $("pushScopeChanged").checked = true;
      $("pushScopeChanged").disabled = false;
    } else {
      $("pushScopeAll").checked = true;
      $("pushScopeChanged").disabled = true;
    }
    $("chkPushImages").checked = localImgs > 0;
    $("chkPushPurgeAfter").checked = true;
    $("chkPushTrashMissing").checked = false;
    $("chkPushTrashMissing").disabled = $("pushScopeChanged").checked;
    $("pushProgressWrap").classList.add("hidden");
    $("pushErrorBox").classList.add("hidden");
    $("pushErrorBox").innerHTML = "";
    $("pushModal").classList.remove("hidden");
  }

  async function runWpPush() {
    const scope = $("pushScopeChanged").checked ? "changed" : "all";
    const uploadImages = $("chkPushImages").checked;
    const purgeAfter = $("chkPushPurgeAfter").checked;
    const trashMissing = scope === "all" && $("chkPushTrashMissing").checked;
    const batchSize = Math.max(5, Math.min(25, Number(state.settings.push_batch_size || 10)));
    const changedOnly = scope === "changed" ? getChangedAndNewRowIndices().indices : null;

    if (scope === "changed" && (!changedOnly || !changedOnly.length)) {
      toast("No changed or new rows detected. Select 'Full sheet' if you want to push everything.", true);
      return;
    }

    const btn = $("btnStartPush");
    btn.disabled = true;
    $("pushErrorBox").classList.add("hidden");
    $("pushProgressWrap").classList.remove("hidden");
    const setProg = (pct, msg) => {
      $("pushProgressBar").style.width = `${Math.max(2, Math.min(100, Math.round(pct)))}%`;
      $("pushProgressText").textContent = msg;
    };

    try {
      setProg(6, uploadImages ? "Running Fix Naming & Zip + uploading to odella.ge..." : "Uploading & running preflight check on odella.ge...");
      const startRes = await apiPost("/api/wp/push-start", {
        file: state.file,
        headers: state.headers,
        products: state.products,
        variation_headers: state.variation_headers,
        variations: state.variations,
        paths: state.paths,
        images_root: state.images_root,
        upload_images: uploadImages,
        changed_only_indices: changedOnly,
        batch_size: batchSize,
        trash_missing: trashMissing,
      });

      if (startRes.updated_products) {
        state.products = startRes.updated_products;
        if (startRes.updated_paths) state.paths = startRes.updated_paths;
        setDirty(true);
        renderGrid();
      }

      if (!startRes.ok) {
        const errs = startRes.errors || [];
        if (errs.length) {
          $("pushErrorBox").innerHTML = `<b>Preflight found ${errs.length} error(s) — nothing was changed on odella.ge:</b><ul style="margin:6px 0 0;padding-left:18px;">` +
            errs.slice(0, 40).map((e) => `<li>Row ${esc(e.row || "—")} · ${esc(e.column || "")}: ${esc(e.message)}</li>`).join("") +
            `</ul>`;
          $("pushErrorBox").classList.remove("hidden");
        }
        setProg(0, startRes.error || "Push preflight failed.");
        toast(startRes.error || "Push preflight found errors.", true);
        return;
      }

      const jobId = startRes.job_id;
      const totalP = startRes.total_products || 0;
      const totalV = startRes.total_variations || 0;
      const totalUnits = Math.max(1, totalP + totalV + (trashMissing ? 1 : 0));
      let finalStep = null;

      while (true) {
        const step = await apiPost("/api/wp/push-step", { job_id: jobId });
        if (!step.ok) {
          $("pushErrorBox").textContent = step.error || "Batch failed.";
          $("pushErrorBox").classList.remove("hidden");
          toast(step.error || "Push batch failed.", true);
          return;
        }
        const doneUnits = (step.products_done || 0) + (step.variations_done || 0) + (step.done && trashMissing ? 1 : 0);
        const pct = 10 + (doneUnits / totalUnits) * (purgeAfter ? 65 : 88);
        setProg(
          pct,
          `Pushing in batches of ${batchSize}... Products: ${step.products_done || 0}/${totalP}` +
            (totalV ? ` · Variations: ${step.variations_done || 0}/${totalV}` : "") +
            ` · Saved changes: ${step.changes_count || 0}`
        );
        if (step.done) {
          finalStep = step;
          break;
        }
      }

      // Auto-write newly created WooCommerce IDs back into local sheet & save!
      let idsWritten = 0;
      const createdBySku = (finalStep && finalStep.created_by_sku) || {};
      const idC = colIdx("ID");
      const skuC = colIdx("SKU");
      if (idC >= 0 && skuC >= 0 && Object.keys(createdBySku).length > 0) {
        state.products.forEach((row) => {
          if (isRowEmpty(row)) return;
          if ((row[idC] || "").trim()) return;
          const skuLow = (row[skuC] || "").trim().toLowerCase();
          if (skuLow && createdBySku[skuLow]) {
            row[idC] = String(createdBySku[skuLow]);
            idsWritten++;
          }
        });
      }

      // Reset baseline & save local workbook so new IDs and renamed image tokens persist
      state.baselineRows = new Map(state.products.map((r, idx) => [idx, JSON.stringify(r)]));
      if (state.file) {
        await saveWorkbook(null);
      }
      renderGrid();

      if (purgeAfter) {
        setProg(78, "Purging caches, Hostinger CDN & rebuilding catalog index...");
        const pRes = await apiPost("/api/wp/purge-reindex", {});
        if (pRes.ok && pRes.warm_token && pRes.warm_total > 0) {
          let offset = 0;
          const totalW = pRes.warm_total;
          while (offset < totalW) {
            setProg(82 + (offset / totalW) * 16, `Warming page cache: ${offset} / ${totalW} URLs...`);
            const wRes = await apiPost("/api/wp/warm-step", { warm_token: pRes.warm_token, offset, limit: 6 });
            if (!wRes.ok || wRes.done) break;
            offset = wRes.warmed || (offset + 6);
          }
        }
      }

      setProg(
        100,
        `✓ Push complete! ${finalStep ? finalStep.changes_count || 0 : 0} change(s) saved` +
          (idsWritten ? ` · ${idsWritten} new ID(s) written back to sheet` : "") +
          (purgeAfter ? " · Cache purged & warmed!" : "")
      );
      toast(`Push complete! ${idsWritten ? `${idsWritten} new ID(s) synced back.` : ""}`);
    } finally {
      btn.disabled = false;
    }
  }

  // ---- Purge, Reindex, Warm Cache & Speed Check ----
  function openPurgeModal() {
    if (!ensureWpConfigured()) return;
    $("purgeProgressWrap").classList.add("hidden");
    $("purgeLogBox").classList.add("hidden");
    $("speedResultsBox").classList.add("hidden");
    $("purgeModal").classList.remove("hidden");
  }

  async function runPurgeAndWarm() {
    const btn = $("btnRunPurgeWarm");
    btn.disabled = true;
    $("purgeProgressWrap").classList.remove("hidden");
    $("purgeLogBox").classList.add("hidden");
    const setP = (pct, txt) => {
      $("purgeProgressBar").style.width = `${Math.max(4, Math.min(100, Math.round(pct)))}%`;
      $("purgeProgressText").textContent = txt;
    };
    try {
      setP(15, "Rebuilding catalog index, purging WordPress, LiteSpeed & Hostinger Edge CDN...");
      const res = await apiPost("/api/wp/purge-reindex", {});
      if (!res.ok) {
        setP(0, res.error || "Purge failed.");
        toast(res.error || "Purge failed.", true);
        return;
      }
      const msgs = res.messages || [];
      $("purgeLogBox").innerHTML = msgs.map((m) => `<div>✓ ${esc(m)}</div>`).join("");
      $("purgeLogBox").classList.remove("hidden");

      if (res.warm_token && res.warm_total > 0) {
        let offset = 0;
        let totalHits = 0;
        let totalMisses = 0;
        const total = res.warm_total;
        while (offset < total) {
          setP(30 + (offset / total) * 68, `Warming site cache: ${offset} / ${total} pages...`);
          const w = await apiPost("/api/wp/warm-step", { warm_token: res.warm_token, offset, limit: 6 });
          if (!w.ok) break;
          totalHits += w.hits || 0;
          totalMisses += w.misses || 0;
          offset = w.warmed || (offset + 6);
          if (w.done) break;
        }
        $("purgeLogBox").innerHTML += `<div>✓ Cache warmed (${total} pages · ${totalHits} HIT, ${totalMisses} primed).</div>`;
      }
      setP(100, "✓ Purge, reindex & cache warm-up complete!");
      toast("Purge, reindex & cache warm-up complete!");
    } finally {
      btn.disabled = false;
    }
  }

  async function runSpeedCheck() {
    const btn = $("btnRunSpeedCheck");
    btn.disabled = true;
    $("speedResultsBox").classList.remove("hidden");
    $("speedResultsBody").innerHTML = `<tr><td colspan="4" class="muted-sm" style="padding:10px;text-align:center;">Testing live response speed on odella.ge...</td></tr>`;
    try {
      const res = await apiGet("/api/wp/speed-check");
      if (!res.ok) {
        $("speedResultsBody").innerHTML = `<tr><td colspan="4" style="color:#fca5a5;padding:10px;">${esc(res.error || "Speed check failed.")}</td></tr>`;
        return;
      }
      $("speedResultsBody").innerHTML = (res.results || [])
        .map((r) => {
          const speedColor = r.ms < 600 ? "#86efac" : r.ms < 1400 ? "#fcd34d" : "#fca5a5";
          return `<tr>
            <td class="mono">${esc(r.url)}</td>
            <td>${r.status || "ERR"}</td>
            <td class="mono" style="color:${speedColor};font-weight:600;">${r.ms} ms</td>
            <td class="mono">${esc(r.cache || "n/a")}</td>
          </tr>`;
        })
        .join("");
    } finally {
      btn.disabled = false;
    }
  }

  // ---- New Category 4-Step Wizard ----
  async function openNewCategoryModal() {
    if (!ensureWpConfigured()) return;
    wpSync.newCatStep = 1;
    $("newCatNameKa").value = "";
    $("newCatNameEn").value = "";
    $("newCatError").classList.add("hidden");
    const res = await refreshWpConnectionStatus(false);
    if (!res.ok) return;

    const sel = $("newCatParent");
    sel.innerHTML = `<option value="0">(No parent — Root Category)</option>`;
    (wpSync.categories || []).forEach((c) => {
      const o = document.createElement("option");
      o.value = String(c.id);
      o.textContent = `${c.ka_path}${c.en_path ? "  (" + c.en_path + ")" : ""}`;
      sel.appendChild(o);
    });
    renderNewCatStep();
    $("newCatModal").classList.remove("hidden");
  }

  function getNewCatPathsPreview() {
    const parentId = parseInt($("newCatParent").value, 10) || 0;
    const nameKa = $("newCatNameKa").value.trim();
    const nameEn = $("newCatNameEn").value.trim();
    const parentObj = (wpSync.categories || []).find((c) => Number(c.id) === parentId);
    const kaPath = parentObj ? `${parentObj.ka_path}->${nameKa}` : nameKa;
    const enPath = parentObj && parentObj.en_path ? `${parentObj.en_path}->${nameEn}` : nameEn;
    return { parentId, nameKa, nameEn, kaPath, enPath };
  }

  function renderNewCatStep() {
    const s = wpSync.newCatStep;
    const titles = {
      1: "Step 1 of 4 — Important Warning",
      2: "Step 2 of 4 — Choose Parent & Category Names (KA + EN)",
      3: "Step 3 of 4 — Preview Category Hierarchy",
      4: "Step 4 of 4 — Final Confirmation",
    };
    $("newCatStepLabel").textContent = titles[s] || "";
    $("newCatError").classList.add("hidden");
    for (let k = 1; k <= 4; k++) {
      $(`newCatStep${k}`).classList.toggle("hidden", k !== s);
    }
    $("btnNewCatBack").classList.toggle("hidden", s <= 1);
    $("btnNewCatNext").textContent = s === 4 ? "Yes, Create Category" : (s === 1 ? "I Understand, Next →" : "Next →");
    $("btnNewCatNext").className = s === 4 ? "btn btn-accent" : "btn btn-primary";

    if (s === 3 || s === 4) {
      const { kaPath, enPath } = getNewCatPathsPreview();
      $("newCatPreviewKa").textContent = kaPath;
      $("newCatPreviewEn").textContent = enPath;
      $("newCatFinalSummary").innerHTML = `KA: ${esc(kaPath)}<br>EN: ${esc(enPath)}`;
    }
  }

  async function handleNewCatNext() {
    $("newCatError").classList.add("hidden");
    if (wpSync.newCatStep === 1) {
      wpSync.newCatStep = 2;
      renderNewCatStep();
      setTimeout(() => $("newCatNameKa").focus(), 20);
      return;
    }
    if (wpSync.newCatStep === 2) {
      const { nameKa, nameEn } = getNewCatPathsPreview();
      if (!nameKa || !nameEn) {
        $("newCatError").textContent = "Please enter BOTH the Georgian name and the English name.";
        $("newCatError").classList.remove("hidden");
        return;
      }
      wpSync.newCatStep = 3;
      renderNewCatStep();
      return;
    }
    if (wpSync.newCatStep === 3) {
      wpSync.newCatStep = 4;
      renderNewCatStep();
      return;
    }
    // Step 4: Confirmed -> create category on odella.ge
    const { parentId, nameKa, nameEn } = getNewCatPathsPreview();
    const btn = $("btnNewCatNext");
    btn.disabled = true;
    try {
      const res = await apiPost("/api/wp/create-category", {
        parent_ka_id: parentId,
        name_ka: nameKa,
        name_en: nameEn,
      });
      if (!res.ok) {
        $("newCatError").textContent = res.error || "Could not create category.";
        $("newCatError").classList.remove("hidden");
        return;
      }
      if (Array.isArray(res.categories)) {
        wpSync.categories = res.categories;
      }
      if (res.ka_path && !state.categories.includes(res.ka_path)) {
        state.categories.push(res.ka_path);
        state.categories.sort((a, b) => a.localeCompare(b));
      }
      if (res.ka_path && res.en_path) {
        state.category_en_map[res.ka_path.toLowerCase()] = res.en_path;
      }
      $("newCatModal").classList.add("hidden");
      toast(`Created category: ${res.ka_path} (${res.en_path})`);
    } finally {
      btn.disabled = false;
    }
  }

  // ---------- Event Wiring ----------
  function init() {
    $("fileSelect").addEventListener("change", (e) => openWorkbook(e.target.value));
    $("langSelect").addEventListener("change", (e) => {
      state.langView = e.target.value;
      apiPost("/api/settings", { lang_view: state.langView }).catch(() => {});
      renderGrid();
    });
    $("btnReload").addEventListener("click", () => state.file && openWorkbook(state.file));
    $("btnWpPull").addEventListener("click", openPullModal);
    $("btnStartPull").addEventListener("click", runWpPull);
    $("btnPullColsDefault").addEventListener("click", () => {
      $("pullColGrid").querySelectorAll("input[type=checkbox]").forEach((cb) => {
        if (!cb.disabled) cb.checked = cb.dataset.default === "1";
      });
    });
    $("btnPullColsAll").addEventListener("click", () => {
      $("pullColGrid").querySelectorAll("input[type=checkbox]").forEach((cb) => {
        cb.checked = true;
      });
    });

    $("btnWpPush").addEventListener("click", openPushModal);
    $("btnStartPush").addEventListener("click", runWpPush);
    $("pushScopeChanged").addEventListener("change", () => {
      $("chkPushTrashMissing").checked = false;
      $("chkPushTrashMissing").disabled = true;
    });
    $("pushScopeAll").addEventListener("change", () => {
      $("chkPushTrashMissing").disabled = false;
    });

    $("btnWpPurge").addEventListener("click", openPurgeModal);
    $("btnRunPurgeWarm").addEventListener("click", runPurgeAndWarm);
    $("btnRunSpeedCheck").addEventListener("click", runSpeedCheck);

    $("btnNewCategory").addEventListener("click", openNewCategoryModal);
    $("btnNewCatBack").addEventListener("click", () => {
      if (wpSync.newCatStep > 1) {
        wpSync.newCatStep--;
        renderNewCatStep();
      }
    });
    $("btnNewCatNext").addEventListener("click", handleNewCatNext);

    $("wpConnDot").addEventListener("click", openSettingsModal);
    $("btnToggleWpPass").addEventListener("click", () => {
      const inp = $("setWpPass");
      const show = inp.type === "password";
      inp.type = show ? "text" : "password";
      $("btnToggleWpPass").textContent = show ? "Hide" : "Show";
    });
    $("setPushBatch").addEventListener("input", () => {
      $("setPushBatchVal").textContent = $("setPushBatch").value;
    });
    $("btnTestWp").addEventListener("click", async () => {
      $("wpTestStatus").textContent = "Testing...";
      $("wpTestStatus").style.color = "";
      const res = await apiPost("/api/wp/test", {
        wp_site_url: $("setWpUrl").value.trim(),
        wp_username: $("setWpUser").value.trim(),
        wp_app_password: $("setWpPass").value.trim(),
      });
      if (res.ok) {
        $("wpTestStatus").textContent = `✓ Connected as ${res.user} (${(res.categories || []).length} categories)`;
        $("wpTestStatus").style.color = "#10b981";
      } else {
        $("wpTestStatus").textContent = `✗ ${res.error || "Failed"}`;
        $("wpTestStatus").style.color = "#ef4444";
      }
    });

    $("btnAddRow").addEventListener("click", openAddProductModal);
    $("btnConfirmAddProduct").addEventListener("click", () => submitAddProductModal(false));
    $("btnConfirmAddAnother").addEventListener("click", () => submitAddProductModal(true));
    $("btnAddPickCat").addEventListener("click", (e) => {
      e.stopPropagation();
      openAddModalPicker(e.currentTarget, "Select Categories", state.categories, "addCategories", (vals) => {
        const suggested = suggestFileBirdForCategoryText(vals.join("; "));
        if (suggested) $("addFileBird").value = suggested;
      });
    });
    $("addCategories").addEventListener("click", () => $("btnAddPickCat").click());
    $("btnAddPickTag").addEventListener("click", (e) => {
      e.stopPropagation();
      openAddModalPicker(e.currentTarget, "Select Tags", state.tags, "addTags");
    });
    $("addTags").addEventListener("click", () => $("btnAddPickTag").click());
    $("btnAddPickAttr").addEventListener("click", (e) => {
      e.stopPropagation();
      openAddModalPicker(e.currentTarget, "Select Attributes", state.attribute_tokens, "addAttributes", () => {
        renderAddVarPreview();
      });
    });
    $("addAttributes").addEventListener("click", () => $("btnAddPickAttr").click());
    $("addSku").addEventListener("input", renderAddVarPreview);
    $("addRegPrice").addEventListener("input", renderAddVarPreview);
    $("addSalePrice").addEventListener("input", renderAddVarPreview);
    $("addType").addEventListener("change", () => {
      if ($("addType").value === "simple" && !$("addRegPrice").value.trim() && addProdState.templateVariations.length) {
        const fp = addProdState.templateVariations.find((tv) => String(tv.reg || "").trim() !== "");
        if (fp) {
          $("addRegPrice").value = fp.reg;
          if (!$("addSalePrice").value.trim() && fp.sale) $("addSalePrice").value = fp.sale;
        }
      }
      renderAddVarPreview();
    });

    // Searchable Quick Template combobox events
    const tplInp = $("addTemplateSearch");
    const tplList = $("addTemplateList");
    const tplClr = $("btnClearTemplate");
    const tplToggle = $("btnToggleTemplateList");
    if (tplInp && tplList) {
      tplInp.addEventListener("focus", () => {
        renderAddTemplateDropdown(tplInp.value);
        tplList.classList.remove("hidden");
      });
      tplInp.addEventListener("input", () => {
        if (tplClr) tplClr.classList.toggle("hidden", !tplInp.value.trim());
        renderAddTemplateDropdown(tplInp.value);
        tplList.classList.remove("hidden");
      });
      tplInp.addEventListener("keydown", (e) => {
        const items = Array.from(tplList.querySelectorAll(".tpl-item"));
        if (e.key === "Escape") {
          tplList.classList.add("hidden");
          return;
        }
        if (!items.length) return;
        if (e.key === "ArrowDown") {
          e.preventDefault();
          tplList.classList.remove("hidden");
          addTemplateActiveIdx = Math.min(items.length - 1, addTemplateActiveIdx + 1);
          items.forEach((el, i) => el.classList.toggle("active", i === addTemplateActiveIdx));
          items[addTemplateActiveIdx]?.scrollIntoView({ block: "nearest" });
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          addTemplateActiveIdx = Math.max(0, addTemplateActiveIdx - 1);
          items.forEach((el, i) => el.classList.toggle("active", i === addTemplateActiveIdx));
          items[addTemplateActiveIdx]?.scrollIntoView({ block: "nearest" });
        } else if (e.key === "Enter") {
          e.preventDefault();
          const chosen = items[addTemplateActiveIdx] || items[0];
          if (chosen) {
            const rIdx = parseInt(chosen.dataset.rIdx, 10);
            const found = addTemplateItems.find((x) => x.rIdx === rIdx);
            if (found) selectAddTemplateItem(found);
          }
        }
      });
      tplInp.addEventListener("blur", () => {
        setTimeout(() => tplList.classList.add("hidden"), 150);
      });
    }
    if (tplClr) {
      tplClr.addEventListener("click", (e) => {
        e.preventDefault();
        clearAddTemplateSelection();
        tplInp?.focus();
      });
    }
    if (tplToggle && tplInp && tplList) {
      tplToggle.addEventListener("mousedown", (e) => {
        e.preventDefault();
        if (tplList.classList.contains("hidden")) {
          renderAddTemplateDropdown(tplInp.value);
          tplList.classList.remove("hidden");
          tplInp.focus();
        } else {
          tplList.classList.add("hidden");
        }
      });
    }
    $("btnAddPickFeat").addEventListener("click", () => pickAddProductImages("feat"));
    $("btnAddPickHover").addEventListener("click", () => pickAddProductImages("hover"));
    $("btnAddPickGal").addEventListener("click", () => pickAddProductImages("gallery"));
    $("btnAddToggleDrawer").addEventListener("click", () => {
      $("imagesDrawer").classList.toggle("hidden");
      if (!$("imagesDrawer").classList.contains("hidden") && (!state.localImages || !state.localImages.total)) {
        loadLocalImages();
      }
    });
    document.querySelectorAll(".add-img-drop").forEach((zone) => {
      const target = zone.dataset.addTarget;
      zone.addEventListener("dragover", (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add("drag-over");
      });
      zone.addEventListener("dragleave", () => zone.classList.remove("drag-over"));
      zone.addEventListener("drop", async (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.remove("drag-over");
        const cellImgData = e.dataTransfer.getData("application/x-odella-cell-image");
        if (cellImgData) {
          const info = JSON.parse(cellImgData);
          const p = parsePictureToken(info.token || "");
          const entry = p.kind === "url"
            ? { name: p.file.split("/").pop() || "image", url: p.file, path: "" }
            : { name: p.file, path: info.realPath || "" };
          if (target === "feat") addProdState.feat = entry;
          else if (target === "hover") addProdState.hover = entry;
          else addProdState.gallery.push(entry);
          renderAddProductImages();
          return;
        }
        const webData = e.dataTransfer.getData("application/x-odella-web-image");
        if (webData) {
          const info = JSON.parse(webData);
          const entry = { name: info.name, url: info.url, path: "" };
          if (target === "feat") addProdState.feat = entry;
          else if (target === "hover") addProdState.hover = entry;
          else addProdState.gallery.push(entry);
          renderAddProductImages();
          return;
        }
        const drawerData = e.dataTransfer.getData("application/x-odella-local-image");
        if (drawerData) {
          const info = JSON.parse(drawerData);
          const matchedUrl = await matchLocalToWebUrl(info.path);
          const entry = matchedUrl ? { name: info.name, url: matchedUrl, path: "" } : { name: info.name, path: info.path };
          if (target === "feat") addProdState.feat = entry;
          else if (target === "hover") addProdState.hover = entry;
          else addProdState.gallery.push(entry);
          renderAddProductImages();
          if (matchedUrl) toast("Same image already on web — used existing web URL!");
          return;
        }
        const files = Array.from(e.dataTransfer.files || []).filter((f) => /\.(jpe?g|png|gif|webp)$/i.test(f.name));
        if (!files.length) return;
        const toUpload = target === "gallery" ? files : [files[0]];
        for (const file of toUpload) {
          const b64 = await fileToBase64(file);
          const res = await apiPost("/api/upload-drop", {
            filename: file.name,
            data_base64: b64,
            images_root: state.images_root,
          });
          if (res.ok) {
            const entry = { name: res.filename, path: res.path };
            if (target === "feat") addProdState.feat = entry;
            else if (target === "hover") addProdState.hover = entry;
            else addProdState.gallery.push(entry);
          }
        }
        renderAddProductImages();
      });
    });
    $("addSku").addEventListener("input", () => $("addProdWarn").classList.add("hidden"));
    $("btnUndo").addEventListener("click", performUndo);
    $("btnRedo").addEventListener("click", performRedo);

    $("btnSave").addEventListener("click", () => saveWorkbook(null));
    $("btnSaveAs").addEventListener("click", () => {
      const base = state.filename.replace(/\.xlsx$/i, "") + "-edited.xlsx";
      const name = prompt("Save as filename inside Odella-excel (or full path):", base);
      if (name && name.trim()) saveWorkbook(name.trim());
    });

    // Settings, Theme, Translate, Import, Auto-Match
    $("btnSettings").addEventListener("click", openSettingsModal);
    $("btnSaveSettings").addEventListener("click", saveSettingsModal);
    $("btnTheme").addEventListener("click", async () => {
      state.settings.theme = state.settings.theme === "light" ? "dark" : "light";
      applySettingsToUI();
      await apiPost("/api/settings", { theme: state.settings.theme });
    });
    $("setZoom").addEventListener("input", (e) => ($("setZoomVal").textContent = e.target.value));
    $("setFont").addEventListener("input", (e) => ($("setFontVal").textContent = e.target.value));
    $("btnToggleKey").addEventListener("click", () => {
      const inp = $("setApiKey");
      inp.type = inp.type === "password" ? "text" : "password";
      $("btnToggleKey").textContent = inp.type === "password" ? "Show" : "Hide";
    });
    $("btnResetPrompt").addEventListener("click", () => ($("setPrompt").value = state.defaultPrompt));
    $("btnLoadModels").addEventListener("click", async () => {
      $("geminiTestStatus").textContent = "Loading models...";
      const res = await apiPost("/api/gemini-models", { gemini_api_key: $("setApiKey").value.trim() });
      if (!res.ok) {
        $("geminiTestStatus").textContent = `✗ ${res.error || "Failed"}`;
        $("geminiTestStatus").style.color = "#ef4444";
        return;
      }
      const sel = $("setModel");
      const prev = sel.value;
      sel.innerHTML = "";
      (res.models || []).forEach((m) => {
        const o = document.createElement("option");
        o.value = m.id;
        o.textContent = m.label || m.id;
        sel.appendChild(o);
      });
      if (prev && Array.from(sel.options).some((o) => o.value === prev)) sel.value = prev;
      $("geminiTestStatus").textContent = `✓ Loaded ${(res.models || []).length} models from your API key.`;
      $("geminiTestStatus").style.color = "#10b981";
    });
    $("btnTestGemini").addEventListener("click", async () => {
      $("geminiTestStatus").textContent = "Testing...";
      const res = await apiPost("/api/test-gemini", {
        gemini_api_key: $("setApiKey").value.trim(),
        gemini_model: $("setModel").value,
      });
      if (res.ok && res.translations && res.translations[0]) {
        $("geminiTestStatus").textContent = `✓ Connected! Sample: "${res.translations[0].name_en}"`;
        $("geminiTestStatus").style.color = "#10b981";
      } else {
        $("geminiTestStatus").textContent = `✗ ${res.error || "Failed"}`;
        $("geminiTestStatus").style.color = "#ef4444";
      }
    });

    $("btnTranslate").addEventListener("click", () => openTranslateModal(null));
    $("btnStartTranslate").addEventListener("click", runGeminiTranslate);
    $("btnCancelTranslate").addEventListener("click", () => (state.translateCancelled = true));

    $("btnImport").addEventListener("click", () => $("importModal").classList.remove("hidden"));
    $("btnRunImport").addEventListener("click", runImportFile);

    $("btnAutoMatch").addEventListener("click", openAutoMatchModal);
    $("btnApplyMatch").addEventListener("click", applyAutoMatch);
    $("btnCleanVars").addEventListener("click", cleanAllWorkbookVariations);

    // Bulk Action Bar handlers — Bulk Edit ANY column (smart dropdown / multi-select / text)
    $("bulkColSel").addEventListener("change", syncBulkValueControl);
    $("btnBulkPick").addEventListener("click", (e) => {
      e.stopPropagation();
      const ci = Number($("bulkColSel").value);
      const hLow = (state.headers[ci] || "").trim().toLowerCase();
      if (!MULTI_COLS.has(hLow)) return;
      const options =
        hLow === "categories" ? state.categories : hLow === "tags" ? state.tags : state.attribute_tokens;
      const current = ($("bulkColVal").value || "")
        .split(/\s*;\s*/)
        .map((s) => s.trim())
        .filter(Boolean);
      colFilterCtx = null;
      multiCtx = { bulkTarget: true, ci, hLow, options, selected: new Set(current) };
      $("multiTitle").textContent = `Bulk Pick: ${state.headers[ci]}`;
      $("multiSearch").value = "";
      renderMultiPopover();
      const pop = $("multiPopover");
      pop.classList.remove("hidden");
      const rect = $("btnBulkPick").getBoundingClientRect();
      pop.style.top = `${Math.min(window.innerHeight - 470, Math.max(10, rect.bottom + 4))}px`;
      pop.style.left = `${Math.min(window.innerWidth - 375, Math.max(10, rect.left))}px`;
      $("multiSearch").focus();
    });
    $("btnBulkColApply").addEventListener("click", () => {
      const ci = Number($("bulkColSel").value);
      const valSel = $("bulkColSelect");
      const val = !valSel.classList.contains("hidden") ? valSel.value : $("bulkColVal").value;
      const rows = getSelectedRealRowIndices();
      if (isNaN(ci) || ci < 0 || ci >= state.headers.length || !rows.length) return;
      const hLow = (state.headers[ci] || "").trim().toLowerCase();
      if (hLow === "sale discount") {
        applyBulkSaleDiscount(rows, val);
        return;
      }
      pushUndo();
      const finalVal = val.trim().toLowerCase() === "(clear)" ? "" : val;
      rows.forEach((r) => {
        state.products[r][ci] = finalVal;
        if (hLow === "regular price (gel)" || hLow === "sale price (gel)") {
          syncProductRowSaleFromEdit(r, hLow);
        }
      });
      setDirty(true);
      renderGrid();
      toast(`Updated "${state.headers[ci]}" on ${rows.length} row(s).`);
    });

    function applyBulkSaleDiscount(rows, rawDiscount) {
      if (!rows.length) return;
      const trimmed = String(rawDiscount || "").trim();
      const isClear = !trimmed || trimmed.toLowerCase() === "(clear)";
      const parsed = parseSaleDiscount(trimmed);
      if (!isClear && parsed.kind === "invalid") {
        toast(parsed.message, true);
        return;
      }
      const regC = colIdx("Regular price (GEL)");
      const saleC = colIdx("Sale price (GEL)");
      const discC = colIdx("Sale Discount");
      const typeC = colIdx("Product type");
      const varRegC = varCol("Regular price (GEL)");
      const varSaleC = varCol("Sale price (GEL)");

      pushUndo();
      let updatedSimple = 0;
      let updatedVars = 0;
      let skipped = 0;
      if (!state.varSaleDiscounts) state.varSaleDiscounts = {};

      rows.forEach((r) => {
        const row = state.products[r];
        if (!row) return;
        const pType = typeC >= 0 ? String(row[typeC] || "").trim().toLowerCase() : "simple";
        if (pType === "variable") {
          const vars = getVariationsForRow(r);
          if (!vars.length) {
            skipped++;
            return;
          }
          vars.forEach((vr) => {
            const vKey = getVarDiscountKey(vr);
            if (isClear) {
              if (varSaleC >= 0) vr[varSaleC] = "";
              if (vKey) delete state.varSaleDiscounts[vKey];
              updatedVars++;
              return;
            }
            const vReg = varRegC >= 0 ? String(vr[varRegC] || "").trim() : "";
            const calc = computeSalePriceFromDiscount(vReg, trimmed);
            if (calc.ok && varSaleC >= 0) {
              vr[varSaleC] = calc.saleStr;
              if (vKey) state.varSaleDiscounts[vKey] = calc.normalized;
              updatedVars++;
            } else {
              skipped++;
            }
          });
          return;
        }
        if (isClear) {
          if (saleC >= 0) row[saleC] = "";
          if (discC >= 0) row[discC] = "";
          updatedSimple++;
          return;
        }
        const reg = regC >= 0 ? String(row[regC] || "").trim() : "";
        const calc = computeSalePriceFromDiscount(reg, trimmed);
        if (calc.ok && saleC >= 0) {
          row[saleC] = calc.saleStr;
          if (discC >= 0) row[discC] = calc.normalized;
          updatedSimple++;
        } else {
          skipped++;
        }
      });

      const totalUpdated = updatedSimple + updatedVars;
      if (totalUpdated > 0) {
        setDirty(true);
        renderGrid();
      }
      if (isClear) {
        toast(`Cleared sale on ${updatedSimple} product(s)` + (updatedVars ? ` and ${updatedVars} variation(s).` : "."));
      } else {
        toast(
          `Applied ${parsed.normalized} discount to ${updatedSimple} product(s)` +
            (updatedVars ? ` + ${updatedVars} variation(s)` : "") +
            (skipped ? ` (${skipped} skipped: missing/invalid regular price).` : "."),
          totalUpdated === 0
        );
      }
    }

    function convertBulkSaleMode(targetMode) {
      const rows = getSelectedRealRowIndices();
      if (!rows.length) return;
      const regC = colIdx("Regular price (GEL)");
      const saleC = colIdx("Sale price (GEL)");
      const discC = colIdx("Sale Discount");
      const typeC = colIdx("Product type");
      const varRegC = varCol("Regular price (GEL)");
      const varSaleC = varCol("Sale price (GEL)");

      pushUndo();
      let converted = 0;
      let unchanged = 0;
      let skipped = 0;
      if (!state.varSaleDiscounts) state.varSaleDiscounts = {};

      rows.forEach((r) => {
        const row = state.products[r];
        if (!row) return;
        const pType = typeC >= 0 ? String(row[typeC] || "").trim().toLowerCase() : "simple";
        if (pType === "variable") {
          const vars = getVariationsForRow(r);
          vars.forEach((vr) => {
            const vReg = varRegC >= 0 ? String(vr[varRegC] || "").trim() : "";
            const curDisc = getStoredOrDerivedVarDiscount(vr);
            if (!curDisc) {
              skipped++;
              return;
            }
            const nextDisc = toggleDiscountModeKeepNumber(curDisc, targetMode);
            if (!nextDisc) {
              skipped++;
              return;
            }
            if (nextDisc === curDisc) {
              unchanged++;
              return;
            }
            const calc = computeSalePriceFromDiscount(vReg, nextDisc);
            if (calc.ok && varSaleC >= 0) {
              vr[varSaleC] = calc.saleStr;
              const vKey = getVarDiscountKey(vr);
              if (vKey) state.varSaleDiscounts[vKey] = calc.normalized;
              converted++;
            } else {
              skipped++;
            }
          });
          return;
        }
        const reg = regC >= 0 ? String(row[regC] || "").trim() : "";
        const sale = saleC >= 0 ? String(row[saleC] || "").trim() : "";
        const curDisc = (discC >= 0 ? String(row[discC] || "").trim() : "") || deriveUnitDiscount(reg, sale);
        if (!curDisc) {
          skipped++;
          return;
        }
        const nextDisc = toggleDiscountModeKeepNumber(curDisc, targetMode);
        if (!nextDisc) {
          skipped++;
          return;
        }
        if (nextDisc === curDisc) {
          unchanged++;
          return;
        }
        const calc = computeSalePriceFromDiscount(reg, nextDisc);
        if (calc.ok && saleC >= 0) {
          row[saleC] = calc.saleStr;
          if (discC >= 0) row[discC] = calc.normalized;
          converted++;
        } else {
          skipped++;
        }
      });

      if (converted > 0) {
        setDirty(true);
        renderGrid();
      }
      const modeLabel = targetMode === "pct" ? "GEL → %" : "% → GEL";
      toast(
        `${modeLabel}: converted ${converted}` +
          (unchanged ? `, ${unchanged} already in mode` : "") +
          (skipped ? `, ${skipped} skipped (no discount or out of range)` : "") +
          ".",
        converted === 0 && skipped > 0
      );
    }

    $("btnBulkSaleApply")?.addEventListener("click", () => {
      const val = ($("bulkSaleVal")?.value || "").trim();
      if (!val) {
        toast("Enter a discount like 20% or 40 first.", true);
        return;
      }
      applyBulkSaleDiscount(getSelectedRealRowIndices(), val);
    });
    $("btnBulkSaleClear")?.addEventListener("click", () => {
      applyBulkSaleDiscount(getSelectedRealRowIndices(), "(clear)");
    });
    $("btnBulkSaleToUnit")?.addEventListener("click", () => convertBulkSaleMode("unit"));
    $("btnBulkSaleToPct")?.addEventListener("click", () => convertBulkSaleMode("pct"));
    $("btnSelectAllVisible")?.addEventListener("click", () => toggleSelectAllVisible(null));

    // Add Product Modal: bidirectional Sale Price <-> Sale Discount sync
    $("addRegPrice")?.addEventListener("input", () => {
      const discEl = $("addSaleDiscount");
      const saleEl = $("addSalePrice");
      if (!discEl || !saleEl) return;
      const d = discEl.value.trim();
      if (d) {
        const calc = computeSalePriceFromDiscount($("addRegPrice").value, d);
        if (calc.ok) saleEl.value = calc.saleStr;
      } else if (saleEl.value.trim()) {
        discEl.value = deriveUnitDiscount($("addRegPrice").value, saleEl.value);
      }
    });
    $("addSalePrice")?.addEventListener("input", () => {
      const discEl = $("addSaleDiscount");
      if (!discEl) return;
      discEl.value = deriveUnitDiscount($("addRegPrice").value, $("addSalePrice").value);
    });
    $("addSaleDiscount")?.addEventListener("input", () => {
      const saleEl = $("addSalePrice");
      if (!saleEl) return;
      const raw = $("addSaleDiscount").value.trim();
      if (!raw || raw.toLowerCase() === "(clear)") {
        saleEl.value = "";
        renderAddVarPreview();
        return;
      }
      const calc = computeSalePriceFromDiscount($("addRegPrice").value, raw);
      if (calc.ok) {
        saleEl.value = calc.saleStr;
        renderAddVarPreview();
      }
    });
    $("warnTypeSelect").addEventListener("change", (e) => {
      const val = e.target.value || null;
      state.warnTypeFilter = val;
      if (val) {
        setFilter("warnings", true);
      } else if (state.filter === "warnings") {
        renderGrid();
      }
    });
    $("btnBulkPrice").addEventListener("click", () => {
      const pct = parseFloat($("bulkPricePct").value);
      const regC = colIdx("Regular price (GEL)");
      const rows = getSelectedRealRowIndices();
      if (isNaN(pct) || regC < 0 || !rows.length) return;
      pushUndo();
      let changed = 0;
      rows.forEach((r) => {
        const cur = parseFloat(String(state.products[r][regC] || "").replace(",", "."));
        if (!isNaN(cur) && cur > 0) {
          state.products[r][regC] = String(Math.round(cur * (1 + pct / 100)));
          changed++;
        }
      });
      if (changed) {
        setDirty(true);
        renderGrid();
        toast(`Adjusted Regular price (GEL) by ${pct > 0 ? "+" : ""}${pct}% on ${changed} row(s).`);
      }
    });
    $("btnBulkFb").addEventListener("click", () => {
      const fb = $("bulkFbSel").value;
      const rows = getSelectedRealRowIndices();
      if (!fb || !rows.length) return;
      const imgCols = [colIdx("Hover image"), colIdx("Featured image"), colIdx("Gallery images")].filter((c) => c >= 0);
      pushUndo();
      let tagged = 0;
      rows.forEach((r) => {
        imgCols.forEach((c) => {
          const cell = (state.products[r][c] || "").trim();
          if (!cell) return;
          const isGal = c === colIdx("Gallery images");
          const parts = splitPictures(cell).map((tok) => {
            const p = parsePictureToken(tok);
            if (p.kind !== "file") return tok;
            const nextTok = `${fb}/${p.file}`;
            if (state.paths[tok]) state.paths[nextTok] = state.paths[tok];
            else if (state.paths[p.file]) state.paths[nextTok] = state.paths[p.file];
            tagged++;
            return nextTok;
          });
          state.products[r][c] = isGal ? parts.join("; ") : (parts[0] || "");
        });
      });
      if (tagged) {
        setDirty(true);
        renderGrid();
        toast(`Tagged ${tagged} local image reference(s) with "${fb}".`);
      }
    });
    $("btnBulkTranslate").addEventListener("click", () => openTranslateModal("selected"));
    $("btnBulkCleanVars").addEventListener("click", cleanSelectedVariations);
    $("btnBulkDelete").addEventListener("click", () => deleteRowsByIndices(getSelectedRealRowIndices()));

    $("btnAutoFix").addEventListener("click", openAutoFixModal);
    $("btnConfirmAutoFix").addEventListener("click", applyAutoFix);
    $("btnWarnings").addEventListener("click", openWarningsModal);
    $("btnClearWarnType").addEventListener("click", () => {
      state.warnTypeFilter = null;
      $("warnModal").classList.add("hidden");
      setFilter("all");
    });
    $("btnErrors").addEventListener("click", () => setFilter("errors"));
    $("btnFixZip").addEventListener("click", openZipModal);
    $("btnRenameWeb").addEventListener("click", () => openWebRenameModal("all"));
    $("btnBulkRenameWeb").addEventListener("click", () => openWebRenameModal("selected"));
    $("btnUnfreezeRows").addEventListener("click", unfreezeAllRows);
    $("btnEditCols").addEventListener("click", openEditColsModal);
    $("btnResetCols").addEventListener("click", resetHiddenCols);
    $("editColsSearch").addEventListener("input", renderEditColsList);
    $("btnColsShowAll").addEventListener("click", resetHiddenCols);
    $("btnColsHideAll").addEventListener("click", hideAllCols);
    $("btnColsResetFooter").addEventListener("click", resetHiddenCols);
    $("btnClearColFilters").addEventListener("click", () => {
      if (!state.colFilters.size) return;
      state.colFilters.clear();
      renderGrid();
      toast("Cleared all column filters.");
    });
    $("btnWebRenameScopeAll").addEventListener("click", () => {
      webRenameCtx.scope = "all";
      updateWebRenameScopeButtons();
      loadWebRenamePlan();
    });
    $("btnWebRenameScopeSel").addEventListener("click", () => {
      if (!webRenameCtx.selectedRows.length) return;
      webRenameCtx.scope = "selected";
      updateWebRenameScopeButtons();
      loadWebRenamePlan();
    });
    $("chkWebRenameAll").addEventListener("change", (e) => {
      const checked = e.target.checked;
      webRenameCtx.items.forEach((it) => (it.checked = checked));
      $("btnRunWebRename").disabled = !checked || webRenameCtx.items.length === 0;
      renderWebRenameTable();
    });
    $("btnRunWebRename").addEventListener("click", openWebRenameConfirmStep);
    $("btnWebRenameBack").addEventListener("click", () => showWebRenameStep(1));
    $("btnConfirmWebRenameNow").addEventListener("click", runWebRename);
    $("btnDeferredWarnBadge").addEventListener("click", () => saveWorkbook());
    $("btnRunZip").addEventListener("click", executeFixAndZip);

    $("btnOpenStudioBadRatio").addEventListener("click", openBadRatioStudio);
    $("btnStudioRotL").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.rot = (it.rot - 90 + 360) % 360;
      renderStudioCanvas();
    });
    $("btnStudioRotR").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.rot = (it.rot + 90) % 360;
      renderStudioCanvas();
    });
    $("btnStudioFlipH").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.flipH = !it.flipH;
      renderStudioCanvas();
    });
    $("btnStudioFlipV").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.flipV = !it.flipV;
      renderStudioCanvas();
    });
    $("btnStudioAlignTop").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      alignStudioItem(it, "top");
      renderStudioCanvas();
    });
    $("btnStudioAlignCenter").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      alignStudioItem(it, "center");
      renderStudioCanvas();
    });
    $("btnStudioAlignBottom").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      alignStudioItem(it, "bottom");
      renderStudioCanvas();
    });
    $("studioStraighten").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.straighten = parseFloat($("studioStraighten").value) || 0;
      $("studioStraightenVal").textContent = it.straighten.toFixed(1);
      renderStudioCanvas();
    });
    $("lblStudioStraighten").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.straighten = 0;
      $("studioStraighten").value = "0";
      $("studioStraightenVal").textContent = "0.0";
      renderStudioCanvas();
    });
    $("btnStudioReset").addEventListener("click", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.rot = 0;
      it.straighten = 0;
      it.flipH = false;
      it.flipV = false;
      it.zoom = 1;
      it.panX = 0;
      it.panY = 0;
      it.align = "center";
      syncStudioControlsFromItem(it);
      renderStudioCanvas();
    });
    $("studioZoom").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.zoom = parseFloat($("studioZoom").value) || 1;
      $("studioZoomVal").textContent = it.zoom.toFixed(2);
      renderStudioCanvas();
    });
    $("studioPreset").addEventListener("change", () => {
      const it = studio.items[studio.idx];
      const v = $("studioPreset").value;
      if (!it || v === "custom") return;
      const [w, h] = v.split("x").map(Number);
      if (w && h) {
        it.w = w;
        it.h = h;
        $("studioW").value = String(w);
        $("studioH").value = String(h);
        renderStudioCanvas();
      }
    });
    $("studioW").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      const w = Math.max(100, Math.min(4000, parseInt($("studioW").value, 10) || 1000));
      it.w = w;
      if ($("studioLock23").checked) {
        it.h = Math.round((w * 3) / 2);
        $("studioH").value = String(it.h);
      }
      $("studioPreset").value = "custom";
      renderStudioCanvas();
    });
    $("studioH").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      const h = Math.max(100, Math.min(4000, parseInt($("studioH").value, 10) || 1500));
      it.h = h;
      if ($("studioLock23").checked) {
        it.w = Math.round((h * 2) / 3);
        $("studioW").value = String(it.w);
      }
      $("studioPreset").value = "custom";
      renderStudioCanvas();
    });
    $("studioFit").addEventListener("change", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.fit = $("studioFit").value;
      $("studioBgRow").classList.toggle("hidden", it.fit !== "contain");
      renderStudioCanvas();
    });
    $("studioBgColor").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.bg = $("studioBgColor").value;
      renderStudioCanvas();
    });
    $("studioFmt").addEventListener("change", () => {
      const it = studio.items[studio.idx];
      if (!it) return;
      it.fmt = $("studioFmt").value;
      scheduleStudioSizeEstimate();
    });
    $("studioQual").addEventListener("input", () => {
      const it = studio.items[studio.idx];
      const q = parseInt($("studioQual").value, 10) || 88;
      $("studioQualVal").textContent = String(q);
      if (it) {
        it.qual = q / 100;
        scheduleStudioSizeEstimate();
      }
    });
    $("btnStudioApplyAll").addEventListener("click", () => {
      const cur = studio.items[studio.idx];
      if (!cur) return;
      studio.items.forEach((it) => {
        it.w = cur.w;
        it.h = cur.h;
        it.fit = cur.fit;
        it.bg = cur.bg;
        it.fmt = cur.fmt;
        it.qual = cur.qual;
        it.align = cur.align || "center";
        if (it.img && it.img.naturalWidth) alignStudioItem(it, it.align);
      });
      toast(`Applied ${cur.w}×${cur.h} (${cur.fit}, ${cur.align}, ${cur.fmt.toUpperCase()}) to all ${studio.items.length} image(s).`);
    });
    $("btnStudioSaveOne").addEventListener("click", () => saveStudioItems([studio.idx]));
    $("btnStudioSaveAll").addEventListener("click", () => saveStudioItems(studio.items.map((_, i) => i)));

    // Canvas drag-to-pan & wheel-to-zoom inside Image Studio
    const stCanvas = $("studioCanvas");
    stCanvas.addEventListener("mousedown", (e) => {
      const it = studio.items[studio.idx];
      if (!it) return;
      studio.dragging = true;
      studio.dragStartX = e.clientX;
      studio.dragStartY = e.clientY;
      studio.dragOrigPanX = it.panX;
      studio.dragOrigPanY = it.panY;
    });
    window.addEventListener("mousemove", (e) => {
      if (!studio.dragging) return;
      const it = studio.items[studio.idx];
      if (!it) return;
      const k = studio.previewCropScale || 1;
      it.panX = studio.dragOrigPanX + (e.clientX - studio.dragStartX) * k;
      it.panY = studio.dragOrigPanY + (e.clientY - studio.dragStartY) * k;
      renderStudioCanvas();
    });
    window.addEventListener("mouseup", () => {
      studio.dragging = false;
    });
    stCanvas.addEventListener(
      "wheel",
      (e) => {
        const it = studio.items[studio.idx];
        if (!it) return;
        e.preventDefault();
        const nextZ = Math.max(1, Math.min(3, +(it.zoom + (e.deltaY < 0 ? 0.08 : -0.08)).toFixed(2)));
        it.zoom = nextZ;
        $("studioZoom").value = String(nextZ);
        $("studioZoomVal").textContent = nextZ.toFixed(2);
        renderStudioCanvas();
      },
      { passive: false }
    );

    $("btnInspector").addEventListener("click", () => {
      $("rowInspector").classList.toggle("hidden");
      if (!$("rowInspector").classList.contains("hidden")) renderRowInspector();
    });
    $("btnCloseInspector").addEventListener("click", () => $("rowInspector").classList.add("hidden"));
    $("btnInspPrev").addEventListener("click", () => stepInspectorRow(-1));
    $("btnInspNext").addEventListener("click", () => stepInspectorRow(1));

    document.querySelectorAll(".drawer-preset-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const w = Number(btn.dataset.width || 340);
        $("imagesDrawer").style.width = `${w}px`;
        try { localStorage.setItem("odella_drawer_width", String(w)); } catch {}
        apiPost("/api/settings", { drawer_width: w }).catch(() => {});
      });
    });

    $("btnDrawer").addEventListener("click", () => {
      const dr = $("imagesDrawer");
      if (!dr.classList.contains("hidden") && drawerMode === "local") {
        dr.classList.add("hidden");
        apiPost("/api/settings", { drawer_open: false }).catch(() => {});
      } else {
        dr.classList.remove("hidden");
        setDrawerMode("local");
        apiPost("/api/settings", { drawer_open: true }).catch(() => {});
      }
    });
    $("btnWebDrawer").addEventListener("click", () => {
      const dr = $("imagesDrawer");
      if (!dr.classList.contains("hidden") && drawerMode === "web") {
        dr.classList.add("hidden");
        apiPost("/api/settings", { drawer_open: false }).catch(() => {});
      } else {
        dr.classList.remove("hidden");
        setDrawerMode("web");
        apiPost("/api/settings", { drawer_open: true }).catch(() => {});
      }
    });
    $("tabDrawerLocal").addEventListener("click", () => setDrawerMode("local"));
    $("tabDrawerWeb").addEventListener("click", () => setDrawerMode("web"));
    $("btnCloseDrawer").addEventListener("click", () => {
      $("imagesDrawer").classList.add("hidden");
      apiPost("/api/settings", { drawer_open: false }).catch(() => {});
    });
    // Resizable Local Images Drawer (drag left edge)
    const drawerEl = $("imagesDrawer");
    const drawerHandle = $("drawerResizeHandle");
    const savedDrawerW = parseInt(localStorage.getItem("odella_drawer_width") || "", 10);
    if (savedDrawerW >= 220 && savedDrawerW <= 900) {
      drawerEl.style.width = `${savedDrawerW}px`;
    }
    drawerHandle.addEventListener("mousedown", (e) => {
      e.preventDefault();
      drawerHandle.classList.add("resizing");
      document.body.style.cursor = "ew-resize";
      document.body.style.userSelect = "none";
      const onMove = (ev) => {
        const maxW = Math.min(960, Math.max(300, window.innerWidth - 260));
        const nextW = Math.max(220, Math.min(maxW, Math.round(window.innerWidth - ev.clientX)));
        drawerEl.style.width = `${nextW}px`;
      };
      const onUp = () => {
        drawerHandle.classList.remove("resizing");
        document.body.style.cursor = "";
        document.body.style.userSelect = "";
        const finalW = parseInt(drawerEl.style.width, 10);
        if (finalW >= 220) {
          try { localStorage.setItem("odella_drawer_width", String(finalW)); } catch {}
          apiPost("/api/settings", { drawer_width: finalW }).catch(() => {});
        }
        window.removeEventListener("mousemove", onMove);
        window.removeEventListener("mouseup", onUp);
      };
      window.addEventListener("mousemove", onMove);
      window.addEventListener("mouseup", onUp);
    });
    $("btnBrowseRoot").addEventListener("click", browseRootFolder);
    $("imagesRootInput").addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        loadLocalImages();
      }
    });
    $("imagesRootInput").addEventListener("change", () => {
      const p = $("imagesRootInput").value.trim().replace(/^["']|["']$/g, "");
      $("imagesRootInput").value = p;
      if (p) loadLocalImages();
    });

    // Header Zoom In / Out / Reset + Trackpad Pinch-to-Zoom (Ctrl+Wheel)
    let zoomSaveTimer = null;
    const setZoomLevel = (nextZoom, immediateSave = true) => {
      const clamped = Math.max(70, Math.min(150, Math.round(nextZoom / 5) * 5));
      if (clamped === Number(state.settings.zoom || 100)) return;
      state.settings.zoom = clamped;
      applySettingsToUI();
      updateStickyOffsets();
      clearTimeout(zoomSaveTimer);
      if (immediateSave) {
        apiPost("/api/settings", { zoom: clamped }).catch(() => {});
      } else {
        zoomSaveTimer = setTimeout(() => apiPost("/api/settings", { zoom: clamped }).catch(() => {}), 350);
      }
    };
    $("btnZoomOut").addEventListener("click", () => setZoomLevel(Number(state.settings.zoom || 100) - 5));
    $("btnZoomIn").addEventListener("click", () => setZoomLevel(Number(state.settings.zoom || 100) + 5));
    $("btnZoomReset").addEventListener("click", () => setZoomLevel(100));

    // Trackpad pinch-to-zoom on grid (Windows Precision Touchpad emits Ctrl+Wheel)
    $("gridContainer").addEventListener(
      "wheel",
      (e) => {
        if (!e.ctrlKey) return;
        e.preventDefault();
        setZoomLevel(Number(state.settings.zoom || 100) + (e.deltaY < 0 ? 5 : -5), false);
      },
      { passive: false }
    );

    // Excel-style Keyboard Navigation (Arrows, Shift+Arrows, Tab, Enter, Space, Esc, Ctrl+/-/0)
    document.addEventListener("keydown", (e) => {
      const anyModalOpen = !!document.querySelector(".modal-backdrop:not(.hidden), .popover:not(.hidden)");
      if (anyModalOpen) return;

      const mod = e.ctrlKey || e.metaKey;
      const activeTag = document.activeElement?.tagName || "";
      const isTyping = ["INPUT", "TEXTAREA", "SELECT"].includes(activeTag) || document.activeElement?.isContentEditable;

      if (mod && (e.key === "=" || e.key === "+")) {
        e.preventDefault();
        setZoomLevel(Number(state.settings.zoom || 100) + 5);
        return;
      }
      if (mod && e.key === "-") {
        e.preventDefault();
        setZoomLevel(Number(state.settings.zoom || 100) - 5);
        return;
      }
      if (mod && e.key === "0") {
        e.preventDefault();
        setZoomLevel(100);
        return;
      }

      if (e.key === "Escape" && isTyping && document.activeElement.closest("#sheetTable")) {
        document.activeElement.blur();
        return;
      }

      if (isTyping || !state.visibleRows.length) return;

      const { order: colOrder } = getDisplayColumnOrder();
      const visCols = colOrder.filter((ci) => !isColHiddenByLang(state.headers[ci] || ""));
      if (!visCols.length) return;

      const moveSelection = (dRow, dCol, extend) => {
        const curVisRow = state.selAnchor ? state.selAnchor.visRow : 0;
        const curCol = state.selAnchor ? state.selAnchor.col : visCols[0];
        const baseVisRow = extend && state.selEnd ? state.selEnd.visRow : curVisRow;
        const baseCol = extend && state.selEnd ? state.selEnd.col : curCol;
        const baseColPos = Math.max(0, visCols.indexOf(baseCol));

        let nextVisRow = Math.max(0, Math.min(state.visibleRows.length - 1, baseVisRow + dRow));
        let nextColPos = baseColPos + dCol;
        if (!extend && dRow === 0 && dCol !== 0) {
          if (nextColPos >= visCols.length) {
            nextColPos = 0;
            nextVisRow = Math.min(state.visibleRows.length - 1, nextVisRow + 1);
          } else if (nextColPos < 0) {
            nextColPos = visCols.length - 1;
            nextVisRow = Math.max(0, nextVisRow - 1);
          }
        } else {
          nextColPos = Math.max(0, Math.min(visCols.length - 1, nextColPos));
        }
        const nextCol = visCols[nextColPos];

        if (extend && state.selAnchor) {
          state.selectedCells.clear();
          const aPos = visCols.indexOf(state.selAnchor.col);
          const r1 = Math.min(state.selAnchor.visRow, nextVisRow);
          const r2 = Math.max(state.selAnchor.visRow, nextVisRow);
          const c1 = Math.min(aPos, nextColPos);
          const c2 = Math.max(aPos, nextColPos);
          for (let vr = r1; vr <= r2; vr++) {
            for (let cp = c1; cp <= c2; cp++) {
              state.selectedCells.add(cellKey(vr, visCols[cp]));
            }
          }
          state.selEnd = { visRow: nextVisRow, col: nextCol };
        } else {
          state.selectedCells.clear();
          state.selectedCells.add(cellKey(nextVisRow, nextCol));
          state.selAnchor = { visRow: nextVisRow, col: nextCol };
          state.selEnd = { visRow: nextVisRow, col: nextCol };
        }
        updateSelectionClasses();
        const td = getGridRowEl(nextVisRow)?.querySelector(`[data-ci="${nextCol}"]`);
        if (td) td.scrollIntoView({ block: "nearest", inline: "nearest" });
      };

      if (e.key === "ArrowDown") { e.preventDefault(); moveSelection(1, 0, e.shiftKey); }
      else if (e.key === "ArrowUp") { e.preventDefault(); moveSelection(-1, 0, e.shiftKey); }
      else if (e.key === "ArrowRight") { e.preventDefault(); moveSelection(0, 1, e.shiftKey); }
      else if (e.key === "ArrowLeft") { e.preventDefault(); moveSelection(0, -1, e.shiftKey); }
      else if (e.key === "Tab") { e.preventDefault(); moveSelection(0, e.shiftKey ? -1 : 1, false); }
      else if ((e.key === "Enter" || e.key === " ") && state.selAnchor) {
        const { visRow, col } = state.selAnchor;
        const rIdx = state.visibleRows[visRow];
        if (rIdx === undefined) return;
        const td = getGridRowEl(visRow)?.querySelector(`[data-ci="${col}"]`);
        if (!td) return;
        const hLow = (state.headers[col] || "").trim().toLowerCase();
        e.preventDefault();
        if (hLow === "id") {
          return;
        } else if (IMAGE_COLS.has(hLow)) {
          const tokens = splitPictures(state.products[rIdx][col] || "");
          if (e.key === " " && tokens.length) {
            const lbItems = tokens.map((t, ti) => makeCellLightboxItem(rIdx, col, hLow, t, ti));
            openLightbox(lbItems[0].src, lbItems[0].caption, lbItems, 0);
          } else {
            pickImageFiles(rIdx, col, hLow, hLow === "gallery images");
          }
        } else if (MULTI_COLS.has(hLow)) {
          openMultiPopover(td, rIdx, col, hLow);
        } else if (["short description", "description", "short description (en)", "description (en)", "short_description_en", "description_en"].includes(hLow)) {
          openRichModal(rIdx, col);
        } else {
          const inp = td.querySelector("input, textarea, select");
          if (inp) {
            inp.focus();
            if (inp.select) inp.select();
          }
        }
      }
    });

    // Lightbox navigation & backdrop close
    $("lightboxPrev").addEventListener("click", (e) => {
      e.stopPropagation();
      stepLightbox(-1);
    });
    $("lightboxNext").addEventListener("click", (e) => {
      e.stopPropagation();
      stepLightbox(1);
    });
    $("lightboxModal").addEventListener("click", (e) => {
      if (e.target === $("lightboxModal")) $("lightboxModal").classList.add("hidden");
    });
    document.addEventListener("keydown", (e) => {
      if ($("lightboxModal").classList.contains("hidden")) return;
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        stepLightbox(-1);
      } else if (e.key === "ArrowRight") {
        e.preventDefault();
        stepLightbox(1);
      } else if (e.key === "Escape") {
        $("lightboxModal").classList.add("hidden");
      }
    });

    // Dismiss custom context menu on click, scroll, or Escape
    document.addEventListener("click", hideContextMenu);
    window.addEventListener("blur", hideContextMenu);
    $("gridContainer").addEventListener("scroll", hideContextMenu);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hideContextMenu();
    });
    const rootDrop = $("rootFolderDropZone");
    rootDrop.addEventListener("dragover", (e) => {
      e.preventDefault();
      e.stopPropagation();
      rootDrop.classList.add("drag-over");
    });
    rootDrop.addEventListener("dragleave", () => rootDrop.classList.remove("drag-over"));
    rootDrop.addEventListener("drop", handleRootFolderDrop);
    $("btnRefreshImages").addEventListener("click", loadLocalImages);
    $("drawerFolderSelect").addEventListener("change", renderDrawerGrid);
    $("drawerSearch").addEventListener("input", renderDrawerGrid);
    $("drawerGrid").addEventListener("scroll", () => {
      const g = $("drawerGrid");
      if (drawerRenderedCount < drawerFilteredItems.length && g.scrollTop + g.clientHeight >= g.scrollHeight - 240) {
        appendNextDrawerBatch();
      }
    });
    $("btnConfirmRenameImage").addEventListener("click", executeRenameLocalImage);
    $("renameImageInput").addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        executeRenameLocalImage();
      } else if (e.key === "Escape") {
        $("renameImageModal").classList.add("hidden");
      }
    });
    document.addEventListener("keydown", (e) => {
      if (e.key !== "F2") return;
      if ($("imagesDrawer").classList.contains("hidden")) return;
      if (!selectedDrawerItem) return;
      const tag = document.activeElement?.tagName || "";
      if (["INPUT", "TEXTAREA", "SELECT"].includes(tag) || document.activeElement?.isContentEditable) return;
      e.preventDefault();
      openRenameImageModal(selectedDrawerItem);
    });

    document.querySelectorAll(".chip[data-filter]").forEach((btn) => {
      btn.addEventListener("click", () => setFilter(btn.dataset.filter));
    });
    $("searchInput").addEventListener("input", (e) => {
      state.search = e.target.value;
      renderGrid();
    });

    // Multi-select & Column Filter popover events
    $("multiSearch").addEventListener("input", () => {
      if (colFilterCtx) renderColFilterPopover();
      else renderMultiPopover();
    });
    $("multiClose").addEventListener("click", () => {
      colFilterCtx = null;
      closeMultiPopover();
    });
    $("multiCancel").addEventListener("click", () => {
      colFilterCtx = null;
      closeMultiPopover();
    });
    $("multiClear").addEventListener("click", () => {
      if (colFilterCtx) {
        state.colFilters.delete(colFilterCtx.ci);
        colFilterCtx = null;
        closeMultiPopover();
        renderGrid();
      } else if (multiCtx) {
        multiCtx.selected.clear();
        renderMultiPopover();
      }
    });
    $("multiApply").addEventListener("click", () => {
      if (colFilterCtx) {
        if (colFilterCtx.selected.size >= colFilterCtx.options.length) {
          state.colFilters.delete(colFilterCtx.ci);
        } else {
          state.colFilters.set(colFilterCtx.ci, new Set(colFilterCtx.selected));
        }
        colFilterCtx = null;
        closeMultiPopover();
        renderGrid();
        return;
      }
      if (!multiCtx) return;
      if (typeof multiCtx.onApply === "function") {
        multiCtx.onApply(Array.from(multiCtx.selected));
        closeMultiPopover();
        return;
      }
      if (multiCtx.bulkTarget) {
        $("bulkColVal").value = Array.from(multiCtx.selected).join("; ");
        closeMultiPopover();
        return;
      }
      pushUndo();
      state.products[multiCtx.rIdx][multiCtx.ci] = Array.from(multiCtx.selected).join("; ");
      setDirty(true);
      closeMultiPopover();
      renderGrid();
    });

    // Variation modal events
    $("btnGenCombos").addEventListener("click", generateCombinations);
    $("btnCleanModalVars").addEventListener("click", () => {
      if (!varCtx) return;
      const { cleaned, removed } = cleanVariationList(varCtx.rows);
      varCtx.rows = cleaned;
      renderVarTable();
      toast(removed ? `Removed ${removed} duplicate/empty variation(s).` : "No duplicates found for this product.");
    });
    $("btnAutoSku").addEventListener("click", () => autoFillVariationSkus(true));
    $("btnAddVarRow").addEventListener("click", () => {
      if (!varCtx) return;
      const nr = new Array(state.variation_headers.length).fill("");
      const pidC = varCol("Parent ID");
      const pskuC = varCol("Parent SKU");
      const stC = varCol("Status");
      if (pidC >= 0) nr[pidC] = varCtx.pid;
      if (pskuC >= 0) nr[pskuC] = varCtx.psku;
      if (stC >= 0) nr[stC] = "publish";
      varCtx.rows.push(nr);
      renderVarTable();
    });
    $("btnSaveVars").addEventListener("click", saveVariationModal);

    // Generic modal close
    document.querySelectorAll("[data-close]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-close");
        $(id).classList.add("hidden");
        if (id === "dropModal" && dropResolve) {
          const r = dropResolve;
          dropResolve = null;
          r();
        }
      });
    });

    // Excel-like shortcuts: Ctrl+S (quick save), Ctrl+Z, Ctrl+D, Escape
    window.addEventListener("keydown", (e) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === "s") {
        e.preventDefault();
        if (document.activeElement && ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)) {
          document.activeElement.blur();
        }
        setTimeout(() => saveWorkbook(null), 0);
      } else if (mod && e.key.toLowerCase() === "z" && !e.shiftKey && !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "")) {
        e.preventDefault();
        performUndo();
      } else if (
        mod &&
        (e.key.toLowerCase() === "y" || (e.key.toLowerCase() === "z" && e.shiftKey)) &&
        !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "")
      ) {
        e.preventDefault();
        performRedo();
      } else if (mod && e.key.toLowerCase() === "d") {
        const b = getSelectionBounds();
        if (b && b.r2 > b.r1) {
          e.preventDefault();
          applyFillDown();
        }
      } else if (e.key === "Escape") {
        colFilterCtx = null;
        closeMultiPopover();
        $("lightboxModal").classList.add("hidden");
      }
    });

    // Multi-cell Excel TSV Copy (Ctrl+C)
    document.addEventListener("copy", (e) => {
      const b = getSelectionBounds();
      if (!b) return;
      const isSingleInput =
        b.r1 === b.r2 &&
        b.c1 === b.c2 &&
        ["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "") &&
        document.activeElement.selectionStart !== document.activeElement.selectionEnd;
      if (isSingleInput) return; // Let normal text selection copy work inside one input
      const tsv = serializeTSV(b);
      e.clipboardData.setData("text/plain", tsv);
      e.preventDefault();
      toast(`Copied ${(b.r2 - b.r1 + 1) * (b.c2 - b.c1 + 1)} cell(s).`);
    });

    // End drag-selection cleanly
    window.addEventListener("mouseup", () => {
      if (state.isDraggingSel) {
        state.isDraggingSel = false;
        $("sheetTable").classList.remove("no-select");
      }
    });

    // Rich text modal toolbar & save
    document.querySelectorAll("#richModal [data-cmd]").forEach((btn) => {
      btn.addEventListener("mousedown", (e) => e.preventDefault());
      btn.addEventListener("click", () => {
        if (!richCtx || richCtx.htmlMode) return;
        document.execCommand(btn.dataset.cmd, false, null);
        $("richEditor").focus();
      });
    });
    $("btnRichLink").addEventListener("mousedown", (e) => e.preventDefault());
    $("btnRichLink").addEventListener("click", () => {
      if (!richCtx || richCtx.htmlMode) return;
      const url = prompt("Enter link URL (https://...):", "https://");
      if (url && url.trim()) {
        document.execCommand("createLink", false, url.trim());
        $("richEditor").focus();
      }
    });
    $("btnRichHtmlToggle").addEventListener("click", () => {
      if (!richCtx) return;
      richCtx.htmlMode = !richCtx.htmlMode;
      if (richCtx.htmlMode) {
        $("richHtmlArea").value = cleanEditorHtml($("richEditor").innerHTML);
        $("richEditor").classList.add("hidden");
        $("richHtmlArea").classList.remove("hidden");
        $("btnRichHtmlToggle").textContent = "Visual";
      } else {
        $("richEditor").innerHTML = textToEditorHtml($("richHtmlArea").value);
        $("richHtmlArea").classList.add("hidden");
        $("richEditor").classList.remove("hidden");
        $("btnRichHtmlToggle").textContent = "HTML";
      }
    });
    $("btnRichSave").addEventListener("click", () => {
      if (!richCtx) return;
      const value = richCtx.htmlMode
        ? $("richHtmlArea").value.trim()
        : cleanEditorHtml($("richEditor").innerHTML);
      pushUndo();
      state.products[richCtx.rIdx][richCtx.ci] = value;
      setDirty(true);
      $("richModal").classList.add("hidden");
      richCtx = null;
      renderGrid();
      toast("Description updated.");
    });

    // Multi-line / Multi-cell Excel TSV Paste (Ctrl+V)
    document.addEventListener("paste", (e) => {
      if (!state.selAnchor) return;
      // Ignore pastes inside modal dialogs, popovers, toolbars, or drawer
      if (document.activeElement?.closest(".modal, .popover, .topbar, .filterbar, .drawer")) return;
      const text = e.clipboardData.getData("text/plain");
      if (!text) return;
      const matrix = parseTSV(text);
      const b = getSelectionBounds();
      const hasMultiSel = b && (b.r2 > b.r1 || b.c2 > b.c1);
      const isMultiPaste = matrix.length > 1 || (matrix[0] && matrix[0].length > 1);
      const inInput = ["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "");
      // Only let native input paste run when pasting a single value into a single focused cell
      if (!isMultiPaste && !hasMultiSel && inInput) return;

      e.preventDefault();
      pushUndo();
      const idC = colIdx("ID");
      let written = 0;

      if (hasMultiSel && matrix.length === 1 && matrix[0].length === 1) {
        // Excel behavior: pasting 1 copied value fills ALL selected cells (including Ctrl+Click non-contiguous!)
        const val = matrix[0][0];
        if (state.selectedCells.size > 1) {
          state.selectedCells.forEach((k) => {
            const [vr, c] = k.split(":").map(Number);
            const realIdx = state.visibleRows[vr];
            if (realIdx === undefined || c >= state.headers.length || c === idC) return;
            state.products[realIdx][c] = val;
            const hLow = (state.headers[c] || "").trim().toLowerCase();
            if (hLow === "sale discount" || hLow === "regular price (gel)" || hLow === "sale price (gel)") {
              syncProductRowSaleFromEdit(realIdx, hLow);
            }
            written++;
          });
        } else {
          for (let vr = b.r1; vr <= b.r2; vr++) {
            const realIdx = state.visibleRows[vr];
            if (realIdx === undefined) continue;
            for (let c = b.c1; c <= b.c2; c++) {
              if (c >= state.headers.length || c === idC) continue;
              state.products[realIdx][c] = val;
              const hLow = (state.headers[c] || "").trim().toLowerCase();
              if (hLow === "sale discount" || hLow === "regular price (gel)" || hLow === "sale price (gel)") {
                syncProductRowSaleFromEdit(realIdx, hLow);
              }
              written++;
            }
          }
        }
        if (written) {
          setDirty(true);
          renderGrid();
          toast(`Pasted into ${written} selected cell(s).`);
        }
        return;
      }

      const startVr = b ? b.r1 : state.selAnchor.visRow;
      const startCol = b ? b.c1 : state.selAnchor.col;
      const targetRows = hasMultiSel && matrix.length === 1 ? b.r2 - b.r1 + 1 : matrix.length;
      const targetCols = hasMultiSel && matrix[0].length === 1 ? b.c2 - b.c1 + 1 : Math.max(...matrix.map((r) => r.length));

      for (let rOff = 0; rOff < targetRows; rOff++) {
        let realIdx = state.visibleRows[startVr + rOff];
        if (realIdx === undefined) {
          const nr = new Array(state.headers.length).fill("");
          state.products.push(nr);
          realIdx = state.products.length - 1;
          state.visibleRows.push(realIdx);
        }
        const srcRow = matrix[rOff % matrix.length];
        for (let cOff = 0; cOff < targetCols; cOff++) {
          const targetCol = startCol + cOff;
          if (targetCol >= state.headers.length || targetCol === idC) continue;
          const val = srcRow[cOff % srcRow.length];
          if (val !== undefined) {
            state.products[realIdx][targetCol] = val;
            const hLow = (state.headers[targetCol] || "").trim().toLowerCase();
            if (hLow === "sale discount" || hLow === "regular price (gel)" || hLow === "sale price (gel)") {
              syncProductRowSaleFromEdit(realIdx, hLow);
            }
            written++;
          }
        }
      }
      if (written) {
        setDirty(true);
        renderGrid();
        toast(`Pasted ${targetRows} row(s) × ${targetCols} col(s) (${written} cell(s)).`);
      }
    });

    // Heartbeat ping + instant pagehide close beacon (2.5s grace so F5 reload cancels shutdown)
    const sendPing = () => fetch("/api/ping", { cache: "no-store" }).catch(() => {});
    sendPing();
    setInterval(sendPing, 4000);
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") sendPing();
    });
    window.addEventListener("focus", sendPing);
    window.addEventListener("pagehide", () => {
      if (navigator.sendBeacon) navigator.sendBeacon("/api/closing", "{}");
    });
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    // App Update (GitHub Releases) wiring
    function updateAppUpdateButtonUI() {
      const btn = $("btnAppUpdate");
      if (!btn) return;
      const cur = state.appInfo?.version || "1.1.0";
      const info = state.updateInfo;
      if (info && info.ok && info.update_available) {
        btn.classList.add("has-update");
        btn.textContent = `⬆ Update v${info.latest_version}`;
        btn.title = `New version v${info.latest_version} available on GitHub (current: v${cur}). Click to update.`;
      } else {
        btn.classList.remove("has-update");
        btn.textContent = `⬇ v${cur}`;
        btn.title = `OdellaSheet v${cur} — Click to check for updates on GitHub`;
      }
    }

    function renderUpdateModal() {
      const cur = state.appInfo?.version || "1.1.0";
      const info = state.updateInfo;
      if ($("updateCurrentVer")) $("updateCurrentVer").textContent = `v${cur}`;
      if ($("updateModalSub")) {
        const repo = info?.repo || state.appInfo?.update_repo || state.settings.update_repo || "NMeJa/odella-sheet-editor-releases";
        $("updateModalSub").textContent = `GitHub Releases: ${repo}`;
      }
      const dirtyWarn = $("updateDirtyWarn");
      if (dirtyWarn) dirtyWarn.classList.toggle("hidden", !state.dirty);

      const errBox = $("updateErrorBox");
      const statusBox = $("updateStatusBanner");
      const notesWrap = $("updateNotesWrap");
      const notesEl = $("updateNotes");
      const linkEl = $("linkUpdateRelease");
      const installBtn = $("btnInstallUpdate");

      errBox.classList.add("hidden");
      errBox.textContent = "";

      if (!info) {
        $("updateLatestVer").textContent = "Checking...";
        statusBox.textContent = "Checking GitHub Releases...";
        notesWrap.classList.add("hidden");
        linkEl.classList.add("hidden");
        installBtn.classList.add("hidden");
        return;
      }
      if (!info.ok) {
        $("updateLatestVer").textContent = "—";
        statusBox.textContent = "Could not check for updates.";
        errBox.textContent = info.error || "Unknown error contacting GitHub.";
        errBox.classList.remove("hidden");
        notesWrap.classList.add("hidden");
        linkEl.classList.add("hidden");
        installBtn.classList.add("hidden");
        return;
      }

      $("updateLatestVer").textContent = info.latest_version ? `v${info.latest_version}` : (info.tag || "—");
      if (info.html_url) {
        linkEl.href = info.html_url;
        linkEl.classList.remove("hidden");
      } else {
        linkEl.classList.add("hidden");
      }

      if (info.notes && info.notes.trim()) {
        notesEl.textContent = info.notes.trim();
        notesWrap.classList.remove("hidden");
      } else {
        notesWrap.classList.add("hidden");
      }

      if (info.update_available) {
        const sizeMb = info.asset_size ? ` (${(info.asset_size / (1024 * 1024)).toFixed(1)} MB)` : "";
        const pubDate = info.published_at ? ` · Published ${info.published_at.slice(0, 10)}` : "";
        statusBox.innerHTML = `<b style="color:var(--accent);">Update available: v${esc(info.latest_version)}</b>${esc(sizeMb)}${esc(pubDate)}`;
        if (!info.is_frozen) {
          statusBox.innerHTML += `<div class="muted-sm" style="margin-top:4px;">Running from source (<code>python server.py</code>). Use <code>git pull</code> to update locally.</div>`;
          installBtn.classList.add("hidden");
        } else if (!info.asset_url) {
          statusBox.innerHTML += `<div class="muted-sm" style="margin-top:4px;">Release has no Windows <code>OdellaSheetEditor.exe</code> or <code>.zip</code> asset attached yet.</div>`;
          installBtn.classList.add("hidden");
        } else {
          installBtn.classList.remove("hidden");
          installBtn.disabled = false;
        }
      } else {
        statusBox.innerHTML = `<b style="color:var(--accent);">You're up to date!</b> (v${esc(cur)} is the latest version.)`;
        installBtn.classList.add("hidden");
      }
    }

    async function checkAppUpdate(silent = false) {
      if (!silent) {
        state.updateInfo = null;
        renderUpdateModal();
      }
      try {
        const res = await apiGet("/api/update/check");
        state.updateInfo = res;
        updateAppUpdateButtonUI();
        if (!$("updateModal").classList.contains("hidden")) {
          renderUpdateModal();
        }
        if (!silent && res.ok && res.update_available) {
          toast(`New version v${res.latest_version} is available!`);
        }
      } catch (err) {
        if (!silent) {
          state.updateInfo = { ok: false, error: String(err) };
          renderUpdateModal();
        }
      }
    }

    async function installAppUpdate() {
      const info = state.updateInfo;
      if (!info || !info.ok || !info.asset_url) return;
      if (state.dirty && !confirm("You have unsaved changes in your spreadsheet. Continue updating and restarting without saving?")) {
        return;
      }
      const btn = $("btnInstallUpdate");
      const chkBtn = $("btnCheckUpdateAgain");
      const progWrap = $("updateProgressWrap");
      const progBar = $("updateProgressBar");
      const progText = $("updateProgressText");
      const errBox = $("updateErrorBox");

      btn.disabled = true;
      if (chkBtn) chkBtn.disabled = true;
      errBox.classList.add("hidden");
      progWrap.classList.remove("hidden");
      progBar.style.width = "3%";
      progText.textContent = "Starting download...";

      try {
        const res = await apiPost("/api/update/install", {
          asset_url: info.asset_url,
          asset_name: info.asset_name,
          checksum_url: info.checksum_url || "",
        });
        if (!res.ok) {
          throw new Error(res.error || "Could not start update.");
        }
        const poll = async () => {
          try {
            const st = await apiGet("/api/update/status");
            progBar.style.width = `${Math.max(3, Math.min(100, Number(st.pct || 0)))}%`;
            if (st.message) progText.textContent = st.message;
            if (st.state === "error") {
              errBox.textContent = st.error || "Update failed.";
              errBox.classList.remove("hidden");
              btn.disabled = false;
              if (chkBtn) chkBtn.disabled = false;
              return;
            }
            if (st.state === "restarting") {
              progBar.style.width = "100%";
              progText.textContent = "Update installed! Restarting OdellaSheetEditor...";
              state.dirty = false; // prevent beforeunload prompt during intentional restart
              setTimeout(() => {
                window.close();
              }, 800);
              return;
            }
            setTimeout(poll, 300);
          } catch (_) {
            // Server already exited to complete the .exe swap
            progBar.style.width = "100%";
            progText.textContent = "Restarting with new version...";
            state.dirty = false;
            setTimeout(() => {
              window.close();
            }, 600);
          }
        };
        setTimeout(poll, 300);
      } catch (err) {
        errBox.textContent = err.message || String(err);
        errBox.classList.remove("hidden");
        btn.disabled = false;
        if (chkBtn) chkBtn.disabled = false;
      }
    }

    $("btnAppUpdate")?.addEventListener("click", () => {
      $("updateProgressWrap")?.classList.add("hidden");
      $("updateModal")?.classList.remove("hidden");
      renderUpdateModal();
      checkAppUpdate(false);
    });
    $("btnCheckUpdateAgain")?.addEventListener("click", () => checkAppUpdate(false));
    $("btnInstallUpdate")?.addEventListener("click", installAppUpdate);
    $("btnUpdateSaveFirst")?.addEventListener("click", async () => {
      await saveWorkbook(null);
      if ($("updateDirtyWarn")) $("updateDirtyWarn").classList.toggle("hidden", !state.dirty);
    });

    async function loadAppInfoAndCheckUpdate() {
      try {
        const info = await apiGet("/api/app-info");
        if (info && info.ok) {
          state.appInfo = info;
          updateAppUpdateButtonUI();
        }
      } catch (_) {}
      setTimeout(() => checkAppUpdate(true), 1500);
    }

    loadSettingsFromServer().then(() => {
      refreshWpConnectionStatus(true);
      loadAppInfoAndCheckUpdate();
      return loadFileList();
    });
  }

  function setFilter(f, keepWarnType = false) {
    state.filter = f;
    if (!keepWarnType) state.warnTypeFilter = null;
    const wsel = $("warnTypeSelect");
    if (wsel) wsel.value = state.warnTypeFilter || "";
    document.querySelectorAll(".chip[data-filter]").forEach((c) => {
      c.classList.toggle("active", c.dataset.filter === f);
    });
    renderGrid(true);
  }

  init();
})();
