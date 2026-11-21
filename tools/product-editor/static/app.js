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
    colWidths: new Map(), // colIdx -> px
    rowHeights: new Map(), // realRowIdx -> px
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
    },
    defaultPrompt: "",
    translateCancelled: false,
  };

  const UI_I18N = {
    en: {
      import: "Import",
      reload: "Reload",
      add_product: "+ Add",
      warnings: "Warnings",
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
    if (tbl) tbl.style.zoom = `${Number(s.zoom || 100)}%`;
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
    if (state.langView === "ka" && EN_ONLY_COLS.has(h)) return true;
    if (state.langView === "en" && KA_ONLY_COLS.has(h)) return true;
    return false;
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
      .split(/\s*(?:;|\n)\s*/)
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
      missing_hover: new Set(),
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
    const eurRegC = colIdx("EUR regular price");
    const usdRegC = colIdx("USD regular price");
    const featC = colIdx("Featured image");
    const hovC = colIdx("Hover image");
    const galC = colIdx("Gallery images");
    const sdC = colIdx("Short description");
    const dC = colIdx("Description");
    const dEnC = state.headers.findIndex((h) => ["description (en)", "description_en"].includes(h.trim().toLowerCase()));

    const seenSku = new Map();
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
          addErr(r, skuC, `Duplicate SKU (also on row ${seenSku.get(k) + 2}).`);
          addErr(seenSku.get(k), skuC, `Duplicate SKU (also on row ${r + 2}).`);
        } else {
          seenSku.set(k, r);
        }
      }
      if (isNew && !name) addErr(r, nameC, "New product needs a Name.");

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
      if (reg && isNaN(Number(reg.replace(",", ".")))) addErr(r, regC, "Invalid number.");
      if (sale && sale.toLowerCase() !== "(clear)" && isNaN(Number(sale.replace(",", ".")))) addErr(r, saleC, "Invalid number.");
      if (reg && sale && !isNaN(Number(reg)) && !isNaN(Number(sale)) && Number(sale) >= Number(reg)) {
        addErr(r, saleC, "Sale price must be lower than Regular price.");
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

    state.errorsByRow = errorsByRow;
    state.warningsByRow = warningsByRow;
    state.warnBuckets = warnBuckets;
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
        incomplete_vars: `Info — Variations: Fewer Combos (${warnBuckets.incomplete_vars.size})`,
        missing_hover: `Info — Hover Image: Missing (${warnBuckets.missing_hover.size})`,
        eur_usd_auto: `Info — EUR / USD: Auto NBG Rate (${warnBuckets.eur_usd_auto.size})`,
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

  function runAutoFix() {
    const catC = colIdx("Categories");
    const tagC = colIdx("Tags");
    const attrC = colIdx("Attributes");
    const galC = colIdx("Gallery images");
    let fixed = 0;
    state.products.forEach((row) => {
      let rowChanged = false;
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
            row[catC] = next;
            rowChanged = true;
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
          row[c] = next;
          rowChanged = true;
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
            row[galC] = next;
            rowChanged = true;
          }
        }
      }
      if (rowChanged) fixed++;
    });
    if (fixed > 0) {
      setDirty(true);
      renderGrid();
      toast(`Auto-fixed ${fixed} row(s) (normalized separators to "; " & category prefixes).`);
    }
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

  // ---------- Undo History ----------
  function pushUndo() {
    state.undoStack.push(state.products.map((r) => [...r]));
    if (state.undoStack.length > 30) state.undoStack.shift();
  }

  function performUndo() {
    if (!state.undoStack.length) {
      toast("Nothing to undo.");
      return;
    }
    state.products = state.undoStack.pop();
    setDirty(true);
    renderGrid();
    toast("Undid last change.");
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

  function updateSelectionClasses() {
    const tbody = $("sheetBody");
    if (!tbody) return;
    Array.from(tbody.rows).forEach((tr, vIdx) => {
      for (let c = 0; c < state.headers.length; c++) {
        const td = tr.cells[c + 1];
        if (!td) continue;
        const inSel = state.selectedCells.has(cellKey(vIdx, c));
        const isAnchor = state.selAnchor && vIdx === state.selAnchor.visRow && c === state.selAnchor.col;
        td.classList.toggle("cell-selected", inSel);
        td.classList.toggle("cell-anchor", !!isAnchor);
      }
    });
    updateBulkBar();
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

  function openColFilterPopover(anchorEl, ci) {
    const uniqMap = new Map(); // normalized -> display
    state.products.forEach((r) => {
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
    const selected = new Set(existing ? existing : allOpts);

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
    const indices = [];
    for (let i = 0; i < state.products.length; i++) {
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

    // Sorting
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

    state.visibleRows = indices;
    return indices;
  }

  function renderGrid() {
    ensureScratchRows();
    syncCategoriesEn();
    runValidation();

    // Header
    const thead = $("sheetHead");
    thead.innerHTML = "";
    const trH = document.createElement("tr");
    const thNum = document.createElement("th");
    thNum.textContent = "#";
    thNum.className = "sticky-0";
    trH.appendChild(thNum);

    state.headers.forEach((h, ci) => {
      const th = document.createElement("th");
      if (ci === 0) th.className = "sticky-1";
      else if (ci === 1) th.className = "sticky-2";
      else if (ci === 2) th.className = "sticky-3";
      if (isColHiddenByLang(h)) th.classList.add("hidden");
      if (state.colWidths.has(ci)) {
        const w = state.colWidths.get(ci);
        th.style.width = `${w}px`;
        th.style.minWidth = `${w}px`;
        th.style.maxWidth = `${w}px`;
      }

      const inner = document.createElement("div");
      inner.className = "th-inner";

      const sortSpan = document.createElement("span");
      sortSpan.className = "th-sort";
      const arrow = state.sortCol === ci ? (state.sortDir === 1 ? " ▲" : " ▼") : "";
      sortSpan.textContent = h + arrow;
      sortSpan.title = "Click to sort";
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

      const filterBtn = document.createElement("button");
      filterBtn.className = "th-filter-btn" + (state.colFilters.has(ci) ? " active" : "");
      filterBtn.textContent = "▾";
      filterBtn.title = "Filter column";
      filterBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        openColFilterPopover(filterBtn, ci);
      });

      inner.appendChild(sortSpan);
      inner.appendChild(filterBtn);
      th.appendChild(inner);

      // Column drag resizer
      const resizer = document.createElement("div");
      resizer.className = "col-resizer";
      resizer.title = "Drag to resize column";
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
    const visible = getFilteredRowIndices();
    const frag = document.createDocumentFragment();

    visible.forEach((rIdx, visIdx) => {
      const row = state.products[rIdx];
      const rowErrs = state.errorsByRow.get(rIdx);
      const rowWarns = state.warningsByRow.get(rIdx);
      const tr = document.createElement("tr");
      if (isRowEmpty(row)) tr.classList.add("scratch-row");
      if (state.rowHeights.has(rIdx)) {
        tr.style.height = `${state.rowHeights.get(rIdx)}px`;
      }

      const tdNum = document.createElement("td");
      tdNum.textContent = String(rIdx + 2);
      tdNum.className = "sticky-0";
      tdNum.style.cursor = "pointer";
      tdNum.title = "Click to select row · Ctrl+Click to toggle · Shift+Click for range";
      tdNum.addEventListener("mousedown", (e) => {
        if (e.button !== 0 || e.target.closest(".row-del-btn, .row-resizer")) return;
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
        delBtn.className = "row-del-btn";
        delBtn.textContent = "×";
        delBtn.title = "Delete row (Ctrl+Z to undo)";
        delBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          deleteRowsByIndices([rIdx]);
        });
        tdNum.appendChild(delBtn);
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

      state.headers.forEach((header, ci) => {
        const hLow = header.trim().toLowerCase();
        const td = document.createElement("td");
        if (ci === 0) td.classList.add("sticky-1");
        else if (ci === 1) td.classList.add("sticky-2");
        else if (ci === 2) td.classList.add("sticky-3");
        if (isColHiddenByLang(header)) td.classList.add("hidden");
        if (state.colWidths.has(ci)) {
          const w = state.colWidths.get(ci);
          td.style.width = `${w}px`;
          td.style.minWidth = `${w}px`;
          td.style.maxWidth = `${w}px`;
        }

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

        if (IMAGE_COLS.has(hLow)) {
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
  }

  function applyColumnWidth(ci, widthPx) {
    const tbl = $("sheetTable");
    if (!tbl) return;
    const th = tbl.tHead?.rows[0]?.cells[ci + 1];
    if (th) {
      th.style.width = `${widthPx}px`;
      th.style.minWidth = `${widthPx}px`;
      th.style.maxWidth = `${widthPx}px`;
    }
    const body = tbl.tBodies[0];
    if (body) {
      for (const tr of body.rows) {
        const td = tr.cells[ci + 1];
        if (td) {
          td.style.width = `${widthPx}px`;
          td.style.minWidth = `${widthPx}px`;
          td.style.maxWidth = `${widthPx}px`;
        }
      }
    }
    if (ci <= 2) updateStickyOffsets();
  }

  function updateStickyOffsets() {
    const tbl = $("sheetTable");
    if (!tbl || !tbl.tHead?.rows[0]) return;
    const cells = tbl.tHead.rows[0].cells;
    let left = 0;
    for (let k = 0; k <= 3 && k < cells.length; k++) {
      if (cells[k].classList.contains("hidden")) continue;
      const w = cells[k].getBoundingClientRect().width;
      const cls = `.sticky-${k}`;
      tbl.querySelectorAll(cls).forEach((el) => {
        el.style.left = `${left}px`;
      });
      left += w;
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

        const img = document.createElement("img");
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
        img.addEventListener("click", () => openLightbox(img.src, img.dataset.caption || tok));
        card.appendChild(img);

        if (parsed.folder) {
          const badge = document.createElement("div");
          badge.className = "img-tag-badge";
          badge.textContent = parsed.folder.split("/").pop();
          badge.title = "FileBird: " + parsed.folder;
          card.appendChild(badge);
        }

        const actions = document.createElement("div");
        actions.className = "img-card-actions";
        if (isGallery && idx > 0) {
          const bL = document.createElement("button");
          bL.className = "img-mini-btn";
          bL.textContent = "←";
          bL.title = "Move left";
          bL.addEventListener("click", (e) => {
            e.stopPropagation();
            [tokens[idx - 1], tokens[idx]] = [tokens[idx], tokens[idx - 1]];
            state.products[rIdx][ci] = tokens.join("; ");
            setDirty(true);
            renderGrid();
          });
          actions.appendChild(bL);
        }
        if (isGallery && idx < tokens.length - 1) {
          const bR = document.createElement("button");
          bR.className = "img-mini-btn";
          bR.textContent = "→";
          bR.title = "Move right";
          bR.addEventListener("click", (e) => {
            e.stopPropagation();
            [tokens[idx + 1], tokens[idx]] = [tokens[idx], tokens[idx + 1]];
            state.products[rIdx][ci] = tokens.join("; ");
            setDirty(true);
            renderGrid();
          });
          actions.appendChild(bR);
        }
        const bDel = document.createElement("button");
        bDel.className = "img-mini-btn del";
        bDel.textContent = "×";
        bDel.title = "Remove";
        bDel.addEventListener("click", (e) => {
          e.stopPropagation();
          tokens.splice(idx, 1);
          state.products[rIdx][ci] = isGallery ? tokens.join("; ") : (tokens[0] || "");
          setDirty(true);
          renderGrid();
        });
        actions.appendChild(bDel);

        card.appendChild(actions);
        list.appendChild(card);
      });
      td.appendChild(list);
    } else {
      const hint = document.createElement("div");
      hint.className = "drop-hint";
      hint.textContent = isGallery ? "Drop images here" : "Drop 1 image here";
      td.appendChild(hint);
    }

    // Raw text input for pasting https:// URLs or editing
    const inp = document.createElement("input");
    inp.className = "img-raw-input";
    inp.placeholder = "Paste https:// URL or drop file";
    inp.value = val;
    inp.addEventListener("change", () => {
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

      // Case 1: Dragged from in-app Local Images Drawer
      const drawerData = e.dataTransfer.getData("application/x-odella-local-image");
      if (drawerData) {
        const info = JSON.parse(drawerData);
        openDropModal(rIdx, ci, hLow, info.name, info.path);
        return;
      }

      // Case 2: Dragged from Windows Explorer
      const files = Array.from(e.dataTransfer.files || []).filter((f) => /\.(jpe?g|png|gif|webp)$/i.test(f.name));
      if (!files.length) {
        toast("Please drop an image file (.jpg, .jpeg, .png, .gif, .webp).", true);
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
          await openDropModalAsync(rIdx, ci, hLow, res.filename, res.path);
        }
      }
    });
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
      if (fb === state.lastFileBird) o.selected = true;
      sel.appendChild(o);
    });

    const updatePreview = () => {
      const fb = sel.value;
      $("dropCellPreview").textContent = fb ? `${fb}/${filename}` : filename;
    };
    sel.onchange = updatePreview;
    updatePreview();

    $("btnDropConfirm").onclick = () => {
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
    ["Var ID", "SKU", ...attrGroupNames, "Regular (GEL)", "Sale (GEL)", "Status", ""].forEach((t) => {
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

    varCtx.rows.forEach((vrow, idx) => {
      const tr = document.createElement("tr");

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
      inpReg.oninput = () => {
        if (regC >= 0) vrow[regC] = inpReg.value;
      };
      tdReg.appendChild(inpReg);
      tr.appendChild(tdReg);

      // Sale price
      const tdSale = document.createElement("td");
      const inpSale = document.createElement("input");
      inpSale.className = "input";
      inpSale.value = saleC >= 0 ? vrow[saleC] || "" : "";
      inpSale.oninput = () => {
        if (saleC >= 0) vrow[saleC] = inpSale.value;
      };
      tdSale.appendChild(inpSale);
      tr.appendChild(tdSale);

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

    rows.forEach((v) => {
      if (pidIdx >= 0) v[pidIdx] = pid;
      if (pskuIdx >= 0) v[pskuIdx] = psku;
    });
    state.variations = [...kept, ...rows];

    setDirty(true);
    $("varModal").classList.add("hidden");
    varCtx = null;
    renderGrid();
    toast(`Saved ${rows.length} variation(s).`);
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

  // ---------- Local Images Drawer ----------
  async function loadLocalImages() {
    const root = $("imagesRootInput").value.trim() || state.images_root;
    state.images_root = root;
    const res = await apiGet("/api/local-images?root=" + encodeURIComponent(root));
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

  function renderDrawerGrid() {
    const grid = $("drawerGrid");
    grid.innerHTML = "";
    const folder = $("drawerFolderSelect").value || "__all__";
    const q = $("drawerSearch").value.trim().toLowerCase();
    const items = [];
    Object.entries(state.localImages.groups || {}).forEach(([f, arr]) => {
      if (folder !== "__all__" && f !== folder) return;
      arr.forEach((it) => {
        if (!q || it.name.toLowerCase().includes(q) || f.toLowerCase().includes(q)) {
          items.push(it);
        }
      });
    });

    if (!items.length) {
      grid.innerHTML = `<div class="muted-sm" style="grid-column:1/-1;padding:12px;">No images found.</div>`;
      return;
    }

    const frag = document.createDocumentFragment();
    items.slice(0, 250).forEach((it) => {
      const div = document.createElement("div");
      div.className = "drawer-item";
      div.draggable = true;
      div.title = it.path;
      div.innerHTML = `
        <img src="/api/local-image?path=${encodeURIComponent(it.path)}" loading="lazy" alt="">
        <div class="drawer-item-name">${esc(it.name)}</div>
        <div class="drawer-item-folder">${esc(it.folder)}</div>
      `;
      div.addEventListener("dragstart", (e) => {
        e.dataTransfer.setData("application/x-odella-local-image", JSON.stringify({ name: it.name, path: it.path }));
      });
      div.addEventListener("dblclick", () => {
        openLightbox("/api/local-image?path=" + encodeURIComponent(it.path), it.path);
      });
      frag.appendChild(div);
    });
    grid.appendChild(frag);
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
      { key: "incomplete_vars", title: "Variations: Fewer Than Attribute Combos", desc: "Minor info — product has fewer variations than its full attribute combinations (often intentional, e.g. only 2 sizes).", info: true },
      { key: "missing_hover", title: "Missing Hover Image", desc: "Product card won't swap to a second photo on mouse hover.", info: true },
      { key: "eur_usd_auto", title: "Using Default Auto EUR / USD Rate", desc: "EUR or USD cell is blank — odella-pricing.php will auto-calculate from GEL (NBG + 1%, rounded up to 9). Type a number only to manually override.", info: true },
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
  function openLightbox(src, caption) {
    $("lightboxImg").src = src;
    $("lightboxCaption").textContent = caption;
    $("lightboxModal").classList.remove("hidden");
  }

  // ---------- API & Workbook Load / Save ----------
  async function apiGet(url) {
    const r = await fetch(url);
    return r.json();
  }

  async function apiPost(url, data) {
    const r = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    return r.json();
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
    if (selectPath) {
      sel.value = selectPath;
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
      ratioBadRows: new Set(),
      colFilters: new Map(),
      sortCol: -1,
      sortDir: 0,
      undoStack: [],
    });
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
    };
    const res = await apiPost("/api/settings", updates);
    if (res.ok && res.settings) {
      Object.assign(state.settings, res.settings);
      applySettingsToUI();
      $("settingsModal").classList.add("hidden");
      renderGrid();
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
    const mode = $("importMode").value;
    const b64 = await fileToBase64(file);
    const res = await apiPost("/api/import-file", {
      filename: file.name,
      data_base64: b64,
      mode,
      headers: state.headers,
      products: state.products,
    });
    if (!res.ok) {
      toast(res.error || "Import failed.", true);
      return;
    }
    $("importModal").classList.add("hidden");
    fileInput.value = "";
    if (res.mode === "open" && res.workbook) {
      await loadFileList(res.workbook.file);
      toast(`Imported & opened ${res.workbook.filename}`);
    } else if (res.mode === "merge") {
      pushUndo();
      state.products = res.products;
      setDirty(true);
      renderGrid();
      toast(`Merged ${file.name}: ${res.updated} updated, ${res.added} added.`);
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
    };
    const res = await apiPost("/api/workbook/save", payload);
    if (res.ok) {
      state.file = res.file;
      state.filename = res.filename;
      setDirty(false);
      toast(`Saved ${res.filename}`);
      if (saveAsName) await loadFileList(res.file);
    } else {
      toast(res.error || "Save failed", true);
    }
  }

  // ---------- Event Wiring ----------
  function init() {
    $("fileSelect").addEventListener("change", (e) => openWorkbook(e.target.value));
    $("langSelect").addEventListener("change", (e) => {
      state.langView = e.target.value;
      renderGrid();
    });
    $("btnReload").addEventListener("click", () => state.file && openWorkbook(state.file));
    $("btnAddRow").addEventListener("click", () => {
      const nr = new Array(state.headers.length).fill("");
      const typeC = colIdx("Product type");
      const stC = colIdx("Status");
      if (typeC >= 0) nr[typeC] = "simple";
      if (stC >= 0) nr[stC] = "draft";
      state.products.push(nr);
      setDirty(true);
      renderGrid();
      const gc = $("gridContainer");
      gc.scrollTop = gc.scrollHeight;
    });

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
    $("btnNativeOpen").addEventListener("click", async () => {
      const res = await apiGet("/api/browse-open");
      if (res.cancelled) return;
      if (!res.ok) {
        toast(res.error || "Could not open file.", true);
        return;
      }
      $("importModal").classList.add("hidden");
      await loadFileList(res.file);
      toast(`Opened original file: ${res.filename} (Save will update it directly)`);
    });
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
      pushUndo();
      const finalVal = val.trim().toLowerCase() === "(clear)" ? "" : val;
      rows.forEach((r) => (state.products[r][ci] = finalVal));
      setDirty(true);
      renderGrid();
      toast(`Updated "${state.headers[ci]}" on ${rows.length} row(s).`);
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

    $("btnAutoFix").addEventListener("click", runAutoFix);
    $("btnWarnings").addEventListener("click", openWarningsModal);
    $("btnClearWarnType").addEventListener("click", () => {
      state.warnTypeFilter = null;
      $("warnModal").classList.add("hidden");
      setFilter("all");
    });
    $("btnErrors").addEventListener("click", () => setFilter("errors"));
    $("btnFixZip").addEventListener("click", openZipModal);
    $("btnRunZip").addEventListener("click", executeFixAndZip);

    $("btnDrawer").addEventListener("click", () => $("imagesDrawer").classList.toggle("hidden"));
    $("btnCloseDrawer").addEventListener("click", () => $("imagesDrawer").classList.add("hidden"));
    $("btnRefreshImages").addEventListener("click", loadLocalImages);
    $("drawerFolderSelect").addEventListener("change", renderDrawerGrid);
    $("drawerSearch").addEventListener("input", renderDrawerGrid);

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
      } else if (mod && e.key.toLowerCase() === "z" && !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "")) {
        e.preventDefault();
        performUndo();
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
            written++;
          });
        } else {
          for (let vr = b.r1; vr <= b.r2; vr++) {
            const realIdx = state.visibleRows[vr];
            if (realIdx === undefined) continue;
            for (let c = b.c1; c <= b.c2; c++) {
              if (c >= state.headers.length || c === idC) continue;
              state.products[realIdx][c] = val;
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
    window.addEventListener("pagehide", () => {
      if (navigator.sendBeacon) navigator.sendBeacon("/api/closing", "{}");
    });
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    loadSettingsFromServer().then(() => loadFileList());
  }

  function setFilter(f, keepWarnType = false) {
    state.filter = f;
    if (!keepWarnType) state.warnTypeFilter = null;
    const wsel = $("warnTypeSelect");
    if (wsel) wsel.value = state.warnTypeFilter || "";
    document.querySelectorAll(".chip[data-filter]").forEach((c) => {
      c.classList.toggle("active", c.dataset.filter === f);
    });
    renderGrid();
  }

  init();
})();
