<?php
/**
 * Helpers the spreadsheet file cannot carry by itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ops_google_script(): string
{
    return <<<'JS'
/**
 * Odella product sheet for Google Sheets.
 * If the Odella menu is missing, choose odellaSetup in the function list, click Run, and allow the permissions.
 * Reload the spreadsheet, then Odella → Show pictures and lists, and allow the script.
 * Categories, Tags, and Attributes open a ticked list. Click a tick to remove it.
 * Odella → Build variation rows adds a Variations row for each attribute combination.
 */
function onOpen() {
  SpreadsheetApp.getUi().createMenu('Odella')
    .addItem('Show pictures and lists', 'odellaSetup')
    .addItem('Open the Odella panel', 'odellaOpenPanel')
    .addItem('Read dropped pictures', 'odellaReadDroppedPictures')
    .addItem('Build variation rows', 'odellaBuildVariations')
    .addToUi();
}

function odellaSetup() {
  var ss = SpreadsheetApp.getActive();
  var ui = SpreadsheetApp.getUi();
  var products = ss.getSheetByName('Products');
  if (!products) {
    ui.alert('There is no Products sheet in this file.');
    return;
  }
  var triggers = ScriptApp.getProjectTriggers();
  for (var i = 0; i < triggers.length; i++) {
    var handler = triggers[i].getHandlerFunction();
    if (handler === 'odellaOpenPicker' || handler === 'odellaOnSelect') ScriptApp.deleteTrigger(triggers[i]);
  }
  var lastRow = Math.max(products.getLastRow(), 2);
  if (lastRow > 3000) lastRow = 3000;
  var lastCol = Math.max(products.getLastColumn(), 1);
  var headers = products.getRange(1, 1, 1, lastCol).getValues()[0];
  odellaClearList_(products, headers, ['Categories', 'Tags', 'Attributes'], lastRow);
  odellaList_(products, headers, 'Status', ss.getSheetByName('Lists'), 1, 1, lastRow);
  odellaList_(products, headers, 'Product type', ss.getSheetByName('Lists'), 2, 1, lastRow);
  odellaEnsureColumn_(products, 'Featured image preview', 'Featured image');
  odellaEnsureColumn_(products, 'Hover image preview', 'Hover image');
  odellaEnsureColumn_(products, 'Gallery images preview', 'Gallery images');
  var pictureError = '';
  try {
    headers = products.getRange(1, 1, 1, products.getLastColumn()).getValues()[0];
    odellaPictures_(products, headers, lastRow);
  } catch (err) {
    pictureError = String(err);
  }
  products.setFrozenRows(1);
  products.setFrozenColumns(3);
  odellaOpenPanel();
  if (pictureError) {
    ui.alert('The ticks are in the Odella panel. Click Categories, Tags, or Attributes. The picture step stopped with this error:\n\n' + pictureError);
    return;
  }
  ui.alert('The Odella panel is open. Click Categories, Tags, or Attributes and the ticks follow that cell. Picture previews are filled in again. The words already in the cells were kept.');
}

function odellaClearList_(sheet, headers, names, lastRow) {
  if (lastRow < 2) return;
  for (var i = 0; i < names.length; i++) {
    var col = odellaCol_(headers, names[i]);
    if (col) sheet.getRange(2, col, lastRow - 1, 1).clearDataValidations();
  }
}

function odellaList_(products, headers, name, source, sourceColumn, startRow, lastRow) {
  var col = odellaCol_(headers, name);
  if (!col || !source || lastRow < 2) return;
  var height = Math.max(source.getLastRow(), startRow);
  var values = source.getRange(startRow, sourceColumn, height - startRow + 1, 1).getValues();
  var n = 0;
  for (var i = 0; i < values.length; i++) {
    if (String(values[i][0] || '').trim() === '') break;
    n++;
  }
  if (!n) return;
  var rule = SpreadsheetApp.newDataValidation()
    .requireValueInRange(source.getRange(startRow, sourceColumn, n, 1), true)
    .setAllowInvalid(false)
    .build();
  products.getRange(2, col, lastRow - 1, 1).setDataValidation(rule);
}

function odellaPictures_(products, headers, lastRow) {
  if (lastRow < 2) return;
  var singles = ['Featured image', 'Hover image'];
  for (var s = 0; s < singles.length; s++) {
    odellaOnePicture_(products, headers, singles[s], lastRow);
  }
  var galleryCol = odellaCol_(headers, 'Gallery images');
  var previewCol = odellaCol_(headers, 'Gallery images preview');
  if (!galleryCol || !previewCol) {
    products.setRowHeights(2, lastRow - 1, 72);
    return;
  }
  var values = products.getRange(2, galleryCol, lastRow - 1, 1).getValues();
  var parsed = [];
  var max = 1;
  for (var r = 0; r < values.length; r++) {
    var urls = odellaUrls_(values[r][0]);
    parsed.push(urls);
    if (urls.length > max) max = urls.length;
  }
  var ss = products.getParent();
  var pics = ss.getSheetByName('OdellaPics');
  if (!pics) pics = ss.insertSheet('OdellaPics');
  pics.clear();
  try { if (!pics.isSheetHidden()) pics.hideSheet(); } catch (err) {}
  for (var n = 0; n < max; n++) {
    var column = [];
    for (var r = 0; r < parsed.length; r++) column.push([parsed[r][n] || '']);
    pics.getRange(2, n + 1, column.length, 1).setValues(column);
  }
  var previewCols = [previewCol];
  for (var n = 2; n <= max; n++) {
    var title = 'Gallery preview ' + n;
    headers = products.getRange(1, 1, 1, products.getLastColumn()).getValues()[0];
    var existing = odellaCol_(headers, title);
    if (!existing) {
      products.insertColumnAfter(previewCols[previewCols.length - 1]);
      existing = previewCols[previewCols.length - 1] + 1;
      products.getRange(1, existing).setValue(title);
    }
    previewCols.push(existing);
  }
  for (var n = 0; n < max; n++) {
    var formulas = [];
    var letter = odellaLetter_(n + 1);
    var existingPreview = products.getRange(2, previewCols[n], parsed.length, 1).getFormulas();
    for (var r = 0; r < parsed.length; r++) {
      if (parsed[r][n]) formulas.push(['=IMAGE(OdellaPics!' + letter + (r + 2) + ')']);
      else formulas.push([existingPreview[r][0] || '']);
    }
    products.getRange(2, previewCols[n], formulas.length, 1).setFormulas(formulas);
    products.setColumnWidth(previewCols[n], 110);
  }
  products.setRowHeights(2, lastRow - 1, 72);
  headers = products.getRange(1, 1, 1, products.getLastColumn()).getValues()[0];
  for (var c = 0; c < headers.length; c++) {
    if (/^gallery url \d+$/i.test(String(headers[c] || '').trim())) products.hideColumns(c + 1);
  }
}

function odellaOnePicture_(products, headers, name, lastRow) {
  var urlCol = odellaCol_(headers, name);
  var previewCol = odellaCol_(headers, name + ' preview');
  if (!urlCol || !previewCol) return;
  var values = products.getRange(2, urlCol, lastRow - 1, 1).getValues();
  var existing = products.getRange(2, previewCol, lastRow - 1, 1).getFormulas();
  var letter = odellaLetter_(urlCol);
  var formulas = [];
  for (var r = 0; r < values.length; r++) {
    if (odellaUrls_(values[r][0]).length) formulas.push(['=IMAGE(' + letter + (r + 2) + ')']);
    else formulas.push([existing[r][0] || '']);
  }
  products.getRange(2, previewCol, formulas.length, 1).setFormulas(formulas);
  products.setColumnWidth(previewCol, 110);
}

function odellaUrls_(value) {
  var found = [];
  var matches = String(value || '').match(/https:\/\/[^\s|]+/gi) || [];
  for (var i = 0; i < matches.length; i++) found.push(matches[i]);
  return found;
}

function odellaOpenPicker() {
  return;
}

function odellaOpenPickerLegacy(e) {
  if (!e || !e.range) return;
  var range = e.range;
  if (range.getNumRows() !== 1 || range.getNumColumns() !== 1 || range.getRow() < 2) return;
  var sheet = range.getSheet();
  var header = String(sheet.getRange(1, range.getColumn()).getValue() || '');
  var source = header === 'Categories' || header === 'Tags' || header === 'Attributes' ? header : '';
  if (!source || sheet.getName() !== 'Products') return;
  var html = HtmlService.createHtmlOutput(odellaPickerHtml_(odellaOptions_(source), String(range.getValue() || ''), sheet.getName(), range.getA1Notation()))
    .setWidth(460).setHeight(560);
  SpreadsheetApp.getUi().showModalDialog(html, header);
}

function odellaOptions_(sheetName) {
  var sheet = SpreadsheetApp.getActive().getSheetByName(sheetName);
  if (!sheet || sheet.getLastRow() < 2) return [];
  var values = sheet.getRange(2, 1, sheet.getLastRow() - 1, 1).getValues();
  var options = [];
  for (var i = 0; i < values.length; i++) {
    var text = String(values[i][0] || '').trim();
    if (text) options.push(text);
  }
  return options;
}

function odellaPickerHtml_(options, current, sheetName, a1) {
  var selected = {};
  current.split(',').forEach(function (part) {
    var text = String(part || '').trim();
    if (text) selected[text] = true;
  });
  var boxes = options.map(function (option) {
    var safe = odellaEscape_(option);
    return '<label class="row"><input type="checkbox" value="' + safe + '"' + (selected[option] ? ' checked' : '') + '> ' + safe + '</label>';
  }).join('');
  return '<style>body{font:14px Arial;margin:12px} .row{display:block;margin:0 0 6px} .bar{margin-top:12px}</style>'
    + '<div id="boxes">' + boxes + '</div>'
    + '<div class="bar"><button onclick="save()">Done</button></div>'
    + '<script>function save(){var values=[];document.querySelectorAll("input:checked").forEach(function(box){values.push(box.value)});google.script.run.withSuccessHandler(function(){google.script.host.close()}).odellaApplyPick('
    + JSON.stringify(sheetName) + ',' + JSON.stringify(a1) + ',values)}</script>';
}

function odellaEscape_(text) {
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function odellaApplyPick(sheetName, a1, values) {
  SpreadsheetApp.getActive().getSheetByName(sheetName).getRange(a1).setValue((values || []).join(', '));
}

function odellaBuildVariations() {
  var ss = SpreadsheetApp.getActive();
  var products = ss.getSheetByName('Products');
  var variations = ss.getSheetByName('Variations');
  if (!products || !variations) {
    SpreadsheetApp.getUi().alert('The file needs a Products sheet and a Variations sheet. Export a fresh file.');
    return;
  }
  var pHeaders = products.getRange(1, 1, 1, products.getLastColumn()).getValues()[0];
  var vHeaders = variations.getRange(1, 1, 1, Math.max(variations.getLastColumn(), 1)).getValues()[0];
  var idCol = odellaCol_(pHeaders, 'ID');
  var typeCol = odellaCol_(pHeaders, 'Product type');
  var attrCol = odellaCol_(pHeaders, 'Attributes');
  if (!idCol || !attrCol) {
    SpreadsheetApp.getUi().alert('The Products sheet needs ID and Attributes columns.');
    return;
  }
  var parentAt = odellaCol_(vHeaders, 'Parent ID');
  if (!parentAt) return;
  var existing = {};
  var vLast = Math.max(variations.getLastRow(), 1);
  if (vLast > 1) {
    var body = variations.getRange(2, 1, vLast - 1, variations.getLastColumn()).getValues();
    for (var r = 0; r < body.length; r++) {
      existing[odellaComboKey_(vHeaders, body[r])] = true;
    }
  }
  var pLast = products.getLastRow();
  var added = 0;
  for (var row = 2; row <= pLast; row++) {
    var type = typeCol ? String(products.getRange(row, typeCol).getValue() || '') : '';
    if (type && type !== 'variable') continue;
    var parent = String(products.getRange(row, idCol).getValue() || '').trim();
    if (!parent) continue;
    var groups = odellaAttributeGroups_(String(products.getRange(row, attrCol).getValue() || ''));
    var labels = Object.keys(groups);
    if (!labels.length) continue;
    var combos = odellaCombinations_(labels, groups);
    for (var c = 0; c < combos.length; c++) {
      var line = [];
      for (var h = 0; h < vHeaders.length; h++) line.push('');
      line[parentAt - 1] = parent;
      for (var label in combos[c]) {
        var at = odellaCol_(vHeaders, label);
        if (at) line[at - 1] = combos[c][label];
      }
      var key = odellaComboKey_(vHeaders, line);
      if (existing[key]) continue;
      variations.appendRow(line);
      existing[key] = true;
      added++;
    }
  }
  SpreadsheetApp.getUi().alert(added ? (added + ' variation rows added.') : 'Every attribute combination already has a variation row.');
}

function odellaAttributeGroups_(text) {
  var groups = {};
  String(text || '').split(',').forEach(function (part) {
    var bits = String(part || '').split(':');
    if (bits.length < 2) return;
    var label = bits.shift().trim();
    var value = bits.join(':').trim();
    if (!label || !value) return;
    if (!groups[label]) groups[label] = [];
    groups[label].push(value);
  });
  return groups;
}

function odellaCombinations_(labels, groups) {
  var combos = [{}];
  for (var i = 0; i < labels.length; i++) {
    var next = [];
    for (var c = 0; c < combos.length; c++) {
      for (var v = 0; v < groups[labels[i]].length; v++) {
        var copy = {};
        for (var key in combos[c]) copy[key] = combos[c][key];
        copy[labels[i]] = groups[labels[i]][v];
        next.push(copy);
      }
    }
    combos = next;
  }
  return combos;
}

function odellaComboKey_(headers, row) {
  var parent = '';
  var skip = {
    'Parent ID': true,
    'Variation ID': true,
    'SKU': true,
    'Status': true,
    'Regular price (GEL)': true,
    'Sale price (GEL)': true
  };
  var bits = [];
  for (var i = 0; i < headers.length; i++) {
    var name = String(headers[i] || '').trim();
    if (name === 'Parent ID') parent = String(row[i] || '').trim();
    else if (name && !skip[name]) bits.push(name + '=' + String(row[i] || '').trim());
  }
  bits.sort();
  return parent + '|' + bits.join('|');
}

function odellaCol_(headers, name) {
  var want = String(name).toLowerCase();
  for (var i = 0; i < headers.length; i++) {
    if (String(headers[i] || '').trim().toLowerCase() === want) return i + 1;
  }
  return 0;
}

function odellaReadDroppedPictures() {
  var sheet = SpreadsheetApp.getActiveSheet();
  odellaReadDrops_({ range: sheet.getActiveCell() });
}

function odellaReadDrops_(e) {
  if (!e || !e.range) return;
  var sheet = e.range.getSheet();
  if (sheet.getName() !== 'Products') return;
  var images;
  try {
    images = sheet.getImages();
  } catch (err) {
    return;
  }
  var fresh = [];
  for (var i = 0; i < images.length; i++) {
    if (String(images[i].getAltTextDescription() || '').indexOf('odella|') !== 0) fresh.push(images[i]);
  }
  for (var n = 0; n < fresh.length; n++) odellaTakeDrop_(sheet, fresh[n]);
  odellaSyncGallery_(sheet);
}

function odellaTakeDrop_(sheet, image) {
  var cell = image.getAnchorCell();
  if (!cell || cell.getRow() < 2) return;
  var header = String(sheet.getRange(1, cell.getColumn()).getValue() || '');
  var target = odellaPictureTarget_(header);
  if (!target) return;
  if (target.kind === 'preview') {
    image.remove();
    SpreadsheetApp.getUi().alert('Use ' + target.real, 'This column only shows the picture.\n\nDrop the file on ' + target.real + ' in this row. The shop reads that cell.', SpreadsheetApp.getButtonSet().OK);
    return;
  }
  var stored;
  try {
    stored = odellaStoreImage_(image.getBlob(), target.kind, cell.getRow());
  } catch (err) {
    SpreadsheetApp.getUi().alert('The picture could not be saved. Allow Drive access for this script, then use Odella → Read dropped pictures.\n\n' + err);
    return;
  }
  if (target.kind === 'gallery') {
    var current = String(sheet.getRange(cell.getRow(), cell.getColumn()).getValue() || '').trim();
    sheet.getRange(cell.getRow(), cell.getColumn()).setValue(current ? current + ' | ' + stored : stored);
    image.setAltTextDescription('odella|gallery|' + cell.getRow() + '|' + stored);
  } else {
    sheet.getRange(cell.getRow(), cell.getColumn()).setValue(stored);
    odellaShowSingle_(sheet, cell.getRow(), target.kind);
    image.remove();
  }
}

function odellaStoreImage_(blob, kind, row) {
  var folders = DriveApp.getFoldersByName('Odella product pictures');
  var folder = folders.hasNext() ? folders.next() : DriveApp.createFolder('Odella product pictures');
  var type = String(blob.getContentType() || '');
  var ext = 'jpg';
  if (type.indexOf('png') >= 0) ext = 'png';
  else if (type.indexOf('webp') >= 0) ext = 'webp';
  else if (type.indexOf('gif') >= 0) ext = 'gif';
  var file = folder.createFile(blob);
  file.setName(kind + '-' + row + '-' + new Date().getTime() + '.' + ext);
  file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  return 'https://lh3.googleusercontent.com/d/' + file.getId();
}

function odellaShowSingle_(sheet, row, kind) {
  var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var title = kind === 'hover' ? 'Hover image' : 'Featured image';
  var urlCol = odellaCol_(headers, title);
  var previewCol = odellaCol_(headers, title + ' preview');
  if (!urlCol || !previewCol) return;
  sheet.getRange(row, previewCol).setFormula('=IMAGE(' + odellaLetter_(urlCol) + row + ')');
}

function odellaSyncGallery_(sheet) {
  var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var galleryCol = odellaCol_(headers, 'Gallery images');
  if (!galleryCol) return;
  var images = sheet.getImages();
  var rows = {};
  for (var i = 0; i < images.length; i++) {
    var note = String(images[i].getAltTextDescription() || '');
    if (note.indexOf('odella|gallery|') !== 0) continue;
    var row = Number(note.split('|')[2] || 0);
    if (!rows[row]) rows[row] = [];
    rows[row].push(images[i]);
  }
  for (var key in rows) odellaSyncGalleryRow_(sheet, headers, galleryCol, Number(key), rows[key]);
}

function odellaSyncGalleryRow_(sheet, headers, galleryCol, row, images) {
  var parts = String(sheet.getRange(row, galleryCol).getValue() || '').split(/\s*\|\s*/);
  var cleaned = [];
  for (var i = 0; i < parts.length; i++) if (String(parts[i] || '').trim()) cleaned.push(String(parts[i]).trim());
  parts = cleaned;
  var mover = null;
  var dest = -1;
  for (var n = 0; n < images.length; n++) {
    var token = images[n].getAltTextDescription().split('|').slice(3).join('|');
    var current = parts.indexOf(token);
    var wanted = odellaPreviewIndex_(headers, images[n].getAnchorCell().getColumn());
    if (current >= 0 && wanted >= 0 && wanted < parts.length && wanted !== current) {
      mover = images[n];
      dest = wanted;
      break;
    }
  }
  if (mover) {
    var token = mover.getAltTextDescription().split('|').slice(3).join('|');
    var current = parts.indexOf(token);
    var hold = parts[dest];
    parts[dest] = parts[current];
    parts[current] = hold;
    sheet.getRange(row, galleryCol).setValue(parts.join(' | '));
  }
  for (var n = 0; n < images.length; n++) {
    var token = images[n].getAltTextDescription().split('|').slice(3).join('|');
    var at = parts.indexOf(token);
    if (at < 0) continue;
    var col = odellaEnsureGalleryPreview_(sheet, at + 1);
    if (!col) continue;
    var home = sheet.getRange(row, col);
    images[n].setAnchorCell(home);
    images[n].setWidth(100);
    images[n].setHeight(64);
  }
}

function odellaEnsureGalleryPreview_(sheet, index) {
  var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var title = index <= 1 ? 'Gallery images preview' : 'Gallery preview ' + index;
  var col = odellaCol_(headers, title);
  if (col) return col;
  var after = index <= 1 ? odellaCol_(headers, 'Gallery images') : odellaEnsureGalleryPreview_(sheet, index - 1);
  if (!after) return 0;
  sheet.insertColumnAfter(after);
  sheet.getRange(1, after + 1).setValue(title);
  sheet.setColumnWidth(after + 1, 110);
  return after + 1;
}

function odellaPreviewIndex_(headers, column) {
  var name = String(headers[column - 1] || '').trim().toLowerCase();
  if (name === 'gallery images preview') return 0;
  var match = name.match(/^gallery preview (\d+)$/);
  return match ? Number(match[1]) - 1 : -1;
}

function odellaPictureTarget_(header) {
  var name = String(header || '').trim().toLowerCase();
  if (name === 'featured image') return { kind: 'featured', real: 'Featured image' };
  if (name === 'hover image') return { kind: 'hover', real: 'Hover image' };
  if (name === 'gallery images') return { kind: 'gallery', real: 'Gallery images' };
  if (name === 'featured image preview') return { kind: 'preview', real: 'Featured image' };
  if (name === 'hover image preview') return { kind: 'preview', real: 'Hover image' };
  if (name === 'gallery images preview' || /^gallery preview \d+$/.test(name)) return { kind: 'preview', real: 'Gallery images' };
  return null;
}

function odellaEnsureColumn_(sheet, title, afterTitle) {
  var headers = sheet.getRange(1, 1, 1, Math.max(sheet.getLastColumn(), 1)).getValues()[0];
  var found = odellaCol_(headers, title);
  if (found) return found;
  var after = odellaCol_(headers, afterTitle);
  if (!after) return 0;
  sheet.insertColumnAfter(after);
  sheet.getRange(1, after + 1).setValue(title);
  sheet.setColumnWidth(after + 1, 110);
  return after + 1;
}

function odellaOpenPanel() {
  SpreadsheetApp.getUi().showSidebar(HtmlService.createHtmlOutput(odellaSidebarHtml_()).setTitle('Odella'));
}

function odellaSidebarState() {
  var sheet = SpreadsheetApp.getActiveSheet();
  var cell = sheet.getActiveCell();
  var header = cell.getRow() > 1 ? String(sheet.getRange(1, cell.getColumn()).getValue() || '') : '';
  var target = odellaPictureTarget_(header);
  var kind = 'other';
  if (header === 'Categories' || header === 'Tags' || header === 'Attributes') kind = 'list';
  else if (target && target.kind === 'preview') kind = 'preview';
  else if (target) kind = 'picture';
  return {
    sheet: sheet.getName(),
    a1: cell.getA1Notation(),
    header: header,
    kind: kind,
    real: target ? target.real : '',
    value: String(cell.getDisplayValue() || ''),
    options: kind === 'list' ? odellaOptions_(header) : []
  };
}

function odellaSidebarHtml_() {
  return '<style>body{font:13px Arial;margin:12px} label{display:block;margin:0 0 6px} button{margin:8px 8px 0 0}</style>'
    + '<div id="body">Loading...</div>'
    + '<script>'
    + 'var stamp="";'
    + 'function draw(state){'
    + 'if(!state){return;}'
    + 'var next=state.sheet+"|"+state.a1+"|"+state.kind+"|"+state.value+"|"+state.options.length;'
    + 'if(next===stamp){return;}'
    + 'stamp=next;'
    + 'var html="";'
    + 'if(state.kind==="list"){'
    + 'var chosen={}; String(state.value||"").split(",").forEach(function(part){var text=part.trim(); if(text) chosen[text]=true;});'
    + 'html="<b>"+state.header+"</b> "+state.a1;'
    + 'state.options.forEach(function(option){html+="<label><input type=checkbox value=\\""+option.replace(/"/g,"&quot;")+"\\" "+(chosen[option]?"checked":"")+"> "+option.replace(/</g,"&lt;")+"</label>";});'
    + 'html+="<button onclick=\\"save()\\">Save ticks</button>";'
    + '}else if(state.kind==="preview"){'
    + 'html="<b>Use "+state.real+"</b><p>This column only shows the picture. Put the file on "+state.real+" in this row. The shop reads that cell.</p>";'
    + '}else if(state.kind==="picture"){'
    + 'html="<b>"+state.header+"</b><p>Drop the picture on this column, then click Save dropped pictures. Gallery pictures are added at the end.</p><button onclick=\\"drops()\\">Save dropped pictures</button>";'
    + '}else{html="<p>Click Categories, Tags, Attributes, or a picture column.</p>";}'
    + 'document.getElementById("body").innerHTML=html;'
    + 'window._odella=state;'
    + '}'
    + 'function save(){var values=[]; document.querySelectorAll("input:checked").forEach(function(box){values.push(box.value);}); google.script.run.withSuccessHandler(function(){stamp=""; tick();}).odellaApplyPick(window._odella.sheet, window._odella.a1, values);}'
    + 'function drops(){google.script.run.odellaReadDroppedPictures();}'
    + 'function tick(){google.script.run.withSuccessHandler(draw).odellaSidebarState();}'
    + 'tick(); setInterval(tick, 1200);'
    + '</script>';
}

function odellaLetter_(n) {
  var s = '';
  while (n > 0) {
    var m = (n - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
}
JS;
}

function ops_excel_script(): string
{
    return <<<'VBA'
' Odella product sheet for Excel.
' Save the workbook as .xlsm and allow macros.
'
' MODULE — Alt+F11, Insert → Module, paste everything below the SHEET marker stays out of the module.
' Paste from the next line through the line just before ===== SHEET =====
' Then double-click the Products sheet and paste the SHEET section.

Public Sub OdellaSetup()
    Dim ws As Worksheet
    Dim lastRow As Long
    Set ws = Worksheets("Products")
    lastRow = Application.WorksheetFunction.Max(ws.Cells(ws.Rows.Count, 1).End(xlUp).Row, 2)
    OdellaClearValidation ws, "Categories", lastRow
    OdellaClearValidation ws, "Tags", lastRow
    OdellaClearValidation ws, "Attributes", lastRow
    OdellaPictures
    ws.Rows(1).AutoFilter
    MsgBox "Pictures are filled in. Double-click Categories, Tags, or Attributes for the ticks. Drop a picture on Featured image, Hover image, or Gallery images, then click a cell. The file is saved in the odella-images folder next to this workbook."
End Sub

Public Sub OdellaShowPicker()
    Dim ws As Worksheet, pick As Worksheet
    Dim header As String, cell As Range
    Dim source As Worksheet, item As String
    Dim row As Long, top As Double, cb As CheckBox
    Set cell = ActiveCell
    Set ws = cell.Worksheet
    If cell.Row < 2 Then Exit Sub
    header = CStr(ws.Cells(1, cell.Column).Value)
    If header <> "Categories" And header <> "Tags" And header <> "Attributes" Then Exit Sub
    On Error Resume Next
    Set source = Worksheets(header)
    On Error GoTo 0
    If source Is Nothing Then
        MsgBox "This file has no " & header & " sheet."
        Exit Sub
    End If
    On Error Resume Next
    Application.DisplayAlerts = False
    Worksheets("OdellaPick").Delete
    Application.DisplayAlerts = True
    On Error GoTo 0
    Set pick = Worksheets.Add(After:=ws)
    pick.Name = "OdellaPick"
    pick.Range("A1").Value = "Tick the items for " & header & ", then click Apply."
    pick.Range("Z1").Value = ws.Name
    pick.Range("Z2").Value = cell.Address
    pick.Columns("Z").Hidden = True
    top = 40
    For row = 2 To source.Cells(source.Rows.Count, 1).End(xlUp).Row
        item = Trim$(CStr(source.Cells(row, 1).Value))
        If item <> "" Then
            Set cb = pick.CheckBoxes.Add(12, top, 420, 18)
            cb.Caption = item
            cb.Value = IIf(OdellaHasItem(CStr(cell.Value), item), xlOn, xlOff)
            top = top + 20
        End If
    Next row
    Dim btn As Button
    Set btn = pick.Buttons.Add(12, 8, 140, 24)
    btn.Caption = "Apply"
    btn.OnAction = "OdellaApplyPick"
    pick.Activate
End Sub

Public Sub OdellaApplyPick()
    Dim pick As Worksheet, target As Range
    Dim chosen As String, cb As CheckBox
    Set pick = Worksheets("OdellaPick")
    Set target = Worksheets(CStr(pick.Range("Z1").Value)).Range(CStr(pick.Range("Z2").Value))
    For Each cb In pick.CheckBoxes
        If cb.Value = xlOn Then
            If chosen <> "" Then chosen = chosen & ", "
            chosen = chosen & cb.Caption
        End If
    Next cb
    target.Value = chosen
    Application.DisplayAlerts = False
    pick.Delete
    Application.DisplayAlerts = True
    target.Worksheet.Activate
    target.Select
End Sub

Public Sub OdellaPictures()
    Dim ws As Worksheet, pics As Worksheet
    Dim lastRow As Long, r As Long, n As Long, maxN As Long
    Dim urls() As String, count As Long
    Dim galleryCol As Long, previewCol As Long
    Set ws = Worksheets("Products")
    lastRow = Application.WorksheetFunction.Max(ws.Cells(ws.Rows.Count, 1).End(xlUp).Row, 2)
    OdellaOnePicture ws, "Featured image", lastRow
    OdellaOnePicture ws, "Hover image", lastRow
    galleryCol = OdellaHeaderCol(ws, "Gallery images")
    previewCol = OdellaHeaderCol(ws, "Gallery images preview")
    If galleryCol = 0 Or previewCol = 0 Then Exit Sub
    On Error Resume Next
    Set pics = Worksheets("OdellaPics")
    On Error GoTo 0
    If pics Is Nothing Then
        Set pics = Worksheets.Add(After:=ws)
        pics.Name = "OdellaPics"
    End If
    pics.Cells.Clear
    pics.Visible = xlSheetVeryHidden
    maxN = 1
    For r = 2 To lastRow
        count = OdellaSplitUrls(CStr(ws.Cells(r, galleryCol).Value), urls)
        If count > maxN Then maxN = count
        For n = 1 To count
            pics.Cells(r, n).Value = urls(n)
        Next n
    Next r
    For n = 2 To maxN
        If OdellaHeaderCol(ws, "Gallery preview " & n) = 0 Then
            ws.Columns(previewCol + n - 1).Insert
            ws.Cells(1, previewCol + n - 1).Value = "Gallery preview " & n
        End If
    Next n
    For n = 1 To maxN
        Dim showCol As Long
        If n = 1 Then
            showCol = OdellaHeaderCol(ws, "Gallery images preview")
        Else
            showCol = OdellaHeaderCol(ws, "Gallery preview " & n)
        End If
        If showCol = 0 Then GoTo NextPicture
        For r = 2 To lastRow
            If Trim$(CStr(pics.Cells(r, n).Value)) <> "" Then
                ws.Cells(r, showCol).Formula = "=IMAGE(OdellaPics!" & pics.Cells(r, n).Address(False, False) & ")"
            Else
                ws.Cells(r, showCol).ClearContents
            End If
        Next r
        ws.Columns(showCol).ColumnWidth = 18
NextPicture:
    Next n
    ws.Rows("2:" & lastRow).RowHeight = 72
    Dim col As Long, title As String
    For col = 1 To ws.Cells(1, ws.Columns.Count).End(xlToLeft).Column
        title = LCase$(Trim$(CStr(ws.Cells(1, col).Value)))
        If Left$(title, 12) = "gallery url " Then ws.Columns(col).Hidden = True
    Next col
End Sub

Public Sub OdellaBuildVariations()
    Dim products As Worksheet, variations As Worksheet
    Dim idCol As Long, typeCol As Long, attrCol As Long, parentCol As Long
    Dim r As Long, added As Long
    Set products = Worksheets("Products")
    Set variations = Worksheets("Variations")
    idCol = OdellaHeaderCol(products, "ID")
    typeCol = OdellaHeaderCol(products, "Product type")
    attrCol = OdellaHeaderCol(products, "Attributes")
    parentCol = OdellaHeaderCol(variations, "Parent ID")
    If idCol = 0 Or attrCol = 0 Or parentCol = 0 Then
        MsgBox "Export a fresh file. Variations are built from ID, Product type, and Attributes."
        Exit Sub
    End If
    For r = 2 To products.Cells(products.Rows.Count, idCol).End(xlUp).Row
        If typeCol > 0 Then
            If CStr(products.Cells(r, typeCol).Value) <> "" And CStr(products.Cells(r, typeCol).Value) <> "variable" Then GoTo NextProduct
        End If
        added = added + OdellaAddCombos(products, variations, r, idCol, attrCol, parentCol)
NextProduct:
    Next r
    If added = 0 Then
        MsgBox "Every attribute combination already has a variation row."
    Else
        MsgBox added & " variation rows added."
    End If
End Sub

Private Sub OdellaClearValidation(ws As Worksheet, header As String, lastRow As Long)
    Dim col As Long
    col = OdellaHeaderCol(ws, header)
    If col = 0 Or lastRow < 2 Then Exit Sub
    On Error Resume Next
    ws.Range(ws.Cells(2, col), ws.Cells(lastRow, col)).Validation.Delete
    On Error GoTo 0
End Sub

Private Sub OdellaOnePicture(ws As Worksheet, header As String, lastRow As Long)
    Dim urlCol As Long, previewCol As Long, r As Long
    urlCol = OdellaHeaderCol(ws, header)
    previewCol = OdellaHeaderCol(ws, header & " preview")
    If urlCol = 0 Or previewCol = 0 Then Exit Sub
    For r = 2 To lastRow
        If InStr(1, CStr(ws.Cells(r, urlCol).Value), "https://", vbTextCompare) > 0 Then
            ws.Cells(r, previewCol).Formula = "=IMAGE(" & ws.Cells(r, urlCol).Address(False, False) & ")"
        End If
    Next r
    ws.Columns(previewCol).ColumnWidth = 18
End Sub

Public Function OdellaHeaderCol(ws As Worksheet, header As String) As Long
    Dim found As Range
    Set found = ws.Rows(1).Find(What:=header, LookAt:=xlWhole, MatchCase:=False)
    If found Is Nothing Then
        OdellaHeaderCol = 0
    Else
        OdellaHeaderCol = found.Column
    End If
End Function

Private Function OdellaHasItem(ByVal list As String, ByVal item As String) As Boolean
    Dim part As Variant
    For Each part In Split(list, ",")
        If StrComp(Trim$(CStr(part)), item, vbTextCompare) = 0 Then
            OdellaHasItem = True
            Exit Function
        End If
    Next part
End Function

Private Function OdellaSplitUrls(ByVal text As String, ByRef urls() As String) As Long
    Dim part As Variant, n As Long
    n = 0
    For Each part In Split(Replace(text, vbLf, "|"), "|")
        If InStr(1, CStr(part), "https://", vbTextCompare) > 0 Then
            n = n + 1
            ReDim Preserve urls(1 To n)
            urls(n) = Trim$(CStr(part))
        End If
    Next part
    OdellaSplitUrls = n
End Function

Private Function OdellaAddCombos(products As Worksheet, variations As Worksheet, row As Long, idCol As Long, attrCol As Long, parentCol As Long) As Long
    Dim parent As String, groups As Object, labels As Collection
    Dim added As Long
    parent = Trim$(CStr(products.Cells(row, idCol).Value))
    If parent = "" Then Exit Function
    Set groups = OdellaGroups(CStr(products.Cells(row, attrCol).Value))
    If groups.Count = 0 Then Exit Function
    Set labels = New Collection
    Dim key As Variant
    For Each key In groups.Keys
        labels.Add CStr(key)
    Next key
    added = OdellaWalk(variations, parent, parentCol, labels, groups, 1, "")
    OdellaAddCombos = added
End Function

Private Function OdellaGroups(ByVal text As String) As Object
    Dim groups As Object, part As Variant, bits() As String
    Dim label As String, value As String
    Set groups = CreateObject("Scripting.Dictionary")
    For Each part In Split(text, ",")
        If InStr(1, CStr(part), ":") > 0 Then
            bits = Split(CStr(part), ":")
            label = Trim$(bits(0))
            value = Trim$(Mid$(CStr(part), Len(bits(0)) + 2))
            If label <> "" And value <> "" Then
                If Not groups.Exists(label) Then groups.Add label, New Collection
                groups(label).Add value
            End If
        End If
    Next part
    Set OdellaGroups = groups
End Function

Private Function OdellaWalk(variations As Worksheet, parent As String, parentCol As Long, labels As Collection, groups As Object, depth As Long, path As String) As Long
    Dim item As Variant, added As Long, nextPath As String
    If depth > labels.Count Then
        If Not OdellaComboExists(variations, parent, parentCol, path) Then
            Dim newRow As Long
            newRow = variations.Cells(variations.Rows.Count, parentCol).End(xlUp).Row + 1
            variations.Cells(newRow, parentCol).Value = parent
            OdellaWritePath variations, newRow, path
            added = 1
        End If
        OdellaWalk = added
        Exit Function
    End If
    For Each item In groups(labels(depth))
        nextPath = path & "|" & labels(depth) & "=" & CStr(item)
        added = added + OdellaWalk(variations, parent, parentCol, labels, groups, depth + 1, nextPath)
    Next item
    OdellaWalk = added
End Function

Private Sub OdellaWritePath(variations As Worksheet, row As Long, path As String)
    Dim part As Variant, bits() As String, col As Long
    For Each part In Split(path, "|")
        If InStr(1, CStr(part), "=") > 0 Then
            bits = Split(CStr(part), "=")
            col = OdellaHeaderCol(variations, Trim$(bits(0)))
            If col > 0 Then variations.Cells(row, col).Value = Trim$(bits(1))
        End If
    Next part
End Sub

Private Function OdellaComboExists(variations As Worksheet, parent As String, parentCol As Long, path As String) As Boolean
    Dim r As Long, lastRow As Long, part As Variant, bits() As String, col As Long, ok As Boolean
    lastRow = variations.Cells(variations.Rows.Count, parentCol).End(xlUp).Row
    For r = 2 To lastRow
        If CStr(variations.Cells(r, parentCol).Value) <> parent Then GoTo NextRow
        ok = True
        For Each part In Split(path, "|")
            If InStr(1, CStr(part), "=") > 0 Then
                bits = Split(CStr(part), "=")
                col = OdellaHeaderCol(variations, Trim$(bits(0)))
                If col = 0 Or StrComp(CStr(variations.Cells(r, col).Value), Trim$(bits(1)), vbTextCompare) <> 0 Then ok = False
            End If
        Next part
        If ok Then
            OdellaComboExists = True
            Exit Function
        End If
NextRow:
    Next r
End Function

' ===== SHEET =====
' Paste the rest into the Products sheet module, not the module above.

Public OdellaBusy As Boolean

Public Sub OdellaCatchPictures()
    Dim ws As Worksheet
    Dim shp As Shape
    Dim pending As Collection
    Dim i As Long
    If OdellaBusy Then Exit Sub
    On Error Resume Next
    Set ws = ActiveSheet
    On Error GoTo 0
    If ws Is Nothing Then Exit Sub
    If ws.Name <> "Products" Then Exit Sub
    OdellaBusy = True
    Application.ScreenUpdating = False
    Application.EnableEvents = False
    On Error GoTo Clean
    Set pending = New Collection
    For Each shp In ws.Shapes
        If shp.Type = 13 Or shp.Type = 11 Then
            If Left$(shp.AlternativeText, 7) <> "odella|" Then pending.Add shp
        End If
    Next shp
    For i = 1 To pending.Count
        OdellaTakeDrop ws, pending(i)
    Next i
    OdellaSyncGallery ws
Clean:
    Application.EnableEvents = True
    Application.ScreenUpdating = True
    OdellaBusy = False
End Sub

Private Sub OdellaTakeDrop(ws As Worksheet, shp As Shape)
    Dim cell As Range
    Dim header As String
    Dim kind As String
    Dim realName As String
    Dim folder As String
    Dim fileName As String
    Dim saved As String
    On Error Resume Next
    Set cell = shp.TopLeftCell
    On Error GoTo 0
    If cell Is Nothing Then Exit Sub
    If cell.Row < 2 Then Exit Sub
    header = CStr(ws.Cells(1, cell.Column).Value)
    kind = OdellaDropKind(header, realName)
    If kind = "" Then Exit Sub
    If kind = "preview" Then
        shp.Delete
        MsgBox "This column only shows the picture." & vbCrLf & vbCrLf & "Drop the file on " & realName & " in this row. The shop reads that cell.", vbInformation, "Use " & realName
        Exit Sub
    End If
    If ThisWorkbook.Path = "" Then
        MsgBox "Save the workbook first. Dropped pictures are copied into an odella-images folder next to the file.", vbInformation, "Save the file"
        Exit Sub
    End If
    folder = OdellaImageFolder()
    fileName = kind & "-" & cell.Row & "-" & Format$(Now, "yyyymmddhhnnss") & ".png"
    saved = folder & "\" & fileName
    If Not OdellaSaveShape(shp, saved) Then
        MsgBox "The picture could not be copied into odella-images.", vbExclamation, "Picture"
        Exit Sub
    End If
    If kind = "gallery" Then
        If Trim$(CStr(ws.Cells(cell.Row, cell.Column).Value)) = "" Then
            ws.Cells(cell.Row, cell.Column).Value = fileName
        Else
            ws.Cells(cell.Row, cell.Column).Value = Trim$(CStr(ws.Cells(cell.Row, cell.Column).Value)) & " | " & fileName
        End If
        shp.AlternativeText = "odella|gallery|" & cell.Row & "|" & fileName
        OdellaParkGallery ws, shp
    Else
        ws.Cells(cell.Row, cell.Column).Value = fileName
        OdellaRemoveKind ws, kind, cell.Row
        shp.AlternativeText = "odella|" & kind & "|" & cell.Row & "|" & fileName
        OdellaParkOn ws, shp, OdellaHeaderCol(ws, IIf(kind = "hover", "Hover image preview", "Featured image preview")), cell.Row
    End If
End Sub

Private Function OdellaSaveShape(shp As Shape, path As String) As Boolean
    Dim co As ChartObject
    On Error GoTo Fail
    shp.CopyPicture xlScreen, xlPicture
    Set co = shp.Parent.ChartObjects.Add(0, 0, shp.Width, shp.Height)
    co.Chart.Paste
    co.Chart.Export path, "PNG"
    co.Delete
    OdellaSaveShape = True
    Exit Function
Fail:
    On Error Resume Next
    If Not co Is Nothing Then co.Delete
    OdellaSaveShape = False
End Function

Private Function OdellaImageFolder() As String
    Dim path As String
    path = ThisWorkbook.Path & "\odella-images"
    If Dir$(path, vbDirectory) = "" Then MkDir path
    OdellaImageFolder = path
End Function

Private Function OdellaDropKind(ByVal header As String, ByRef realName As String) As String
    Dim name As String
    name = LCase$(Trim$(header))
    realName = ""
    If name = "featured image" Then
        OdellaDropKind = "featured"
        realName = "Featured image"
    ElseIf name = "hover image" Then
        OdellaDropKind = "hover"
        realName = "Hover image"
    ElseIf name = "gallery images" Then
        OdellaDropKind = "gallery"
        realName = "Gallery images"
    ElseIf name = "featured image preview" Then
        OdellaDropKind = "preview"
        realName = "Featured image"
    ElseIf name = "hover image preview" Then
        OdellaDropKind = "preview"
        realName = "Hover image"
    ElseIf name = "gallery images preview" Or Left$(name, 16) = "gallery preview " Then
        OdellaDropKind = "preview"
        realName = "Gallery images"
    End If
End Function

Private Sub OdellaRemoveKind(ws As Worksheet, kind As String, row As Long)
    Dim shp As Shape
    Dim doomed As Collection
    Dim i As Long
    Dim prefix As String
    prefix = "odella|" & kind & "|" & row & "|"
    Set doomed = New Collection
    For Each shp In ws.Shapes
        If Left$(shp.AlternativeText, Len(prefix)) = prefix Then doomed.Add shp
    Next shp
    For i = 1 To doomed.Count
        doomed(i).Delete
    Next i
End Sub

Private Sub OdellaParkOn(ws As Worksheet, shp As Shape, col As Long, row As Long)
    Dim cell As Range
    If col < 1 Then Exit Sub
    Set cell = ws.Cells(row, col)
    If Abs(shp.Left - (cell.Left + 2)) < 3 And Abs(shp.Top - (cell.Top + 2)) < 3 Then Exit Sub
    shp.LockAspectRatio = msoTrue
    If shp.Height > cell.Height - 4 Then shp.Height = cell.Height - 4
    If shp.Width > cell.Width - 4 Then shp.Width = cell.Width - 4
    shp.Left = cell.Left + 2
    shp.Top = cell.Top + 2
    shp.Placement = xlFreeFloating
End Sub

Private Sub OdellaParkGallery(ws As Worksheet, shp As Shape)
    Dim bits() As String
    Dim parts As Variant
    Dim token As String
    Dim row As Long
    Dim at As Long
    bits = Split(shp.AlternativeText, "|")
    If UBound(bits) < 3 Then Exit Sub
    row = CLng(bits(2))
    token = bits(3)
    parts = Split(Replace(CStr(ws.Cells(row, OdellaHeaderCol(ws, "Gallery images")).Value), ",", "|"), "|")
    For at = LBound(parts) To UBound(parts)
        If StrComp(Trim$(CStr(parts(at))), token, vbTextCompare) = 0 Then
            OdellaParkOn ws, shp, OdellaEnsureGalleryPreview(ws, at - LBound(parts) + 1), row
            Exit Sub
        End If
    Next at
End Sub

Private Function OdellaEnsureGalleryPreview(ws As Worksheet, index As Long) As Long
    Dim title As String
    Dim col As Long
    Dim after As Long
    If index <= 1 Then title = "Gallery images preview" Else title = "Gallery preview " & index
    col = OdellaHeaderCol(ws, title)
    If col > 0 Then
        OdellaEnsureGalleryPreview = col
        Exit Function
    End If
    If index <= 1 Then
        after = OdellaHeaderCol(ws, "Gallery images")
    Else
        after = OdellaEnsureGalleryPreview(ws, index - 1)
    End If
    If after = 0 Then Exit Function
    ws.Columns(after + 1).Insert
    ws.Cells(1, after + 1).Value = title
    ws.Columns(after + 1).ColumnWidth = 18
    OdellaEnsureGalleryPreview = after + 1
End Function

Private Sub OdellaSyncGallery(ws As Worksheet)
    Dim shp As Shape
    Dim seen As Object
    Dim row As Variant
    Set seen = CreateObject("Scripting.Dictionary")
    For Each shp In ws.Shapes
        If Left$(shp.AlternativeText, 15) = "odella|gallery|" Then
            row = Split(shp.AlternativeText, "|")(2)
            If Not seen.Exists(CStr(row)) Then
                seen.Add CStr(row), CStr(row)
                OdellaSyncGalleryRow ws, CLng(row)
            End If
        End If
    Next shp
End Sub

Private Sub OdellaSyncGalleryRow(ws As Worksheet, row As Long)
    Dim galleryCol As Long
    Dim shp As Shape
    Dim token As String
    Dim current As Long
    Dim wanted As Long
    Dim parts() As String
    Dim text As String
    Dim hold As String
    Dim at As Long
    galleryCol = OdellaHeaderCol(ws, "Gallery images")
    If galleryCol = 0 Then Exit Sub
    text = CStr(ws.Cells(row, galleryCol).Value)
    parts = Split(Replace(text, ",", "|"), "|")
    For Each shp In ws.Shapes
        If Left$(shp.AlternativeText, Len("odella|gallery|" & row & "|")) = "odella|gallery|" & row & "|" Then
            token = Split(shp.AlternativeText, "|")(3)
            current = OdellaTokenIndex(parts, token)
            wanted = OdellaClosestPreview(ws, shp)
            If current >= 0 And wanted >= 0 And wanted <= UBound(parts) And wanted <> current Then
                hold = parts(wanted)
                parts(wanted) = parts(current)
                parts(current) = hold
                ws.Cells(row, galleryCol).Value = OdellaJoinParts(parts)
                Exit For
            End If
        End If
    Next shp
    For Each shp In ws.Shapes
        If Left$(shp.AlternativeText, Len("odella|gallery|" & row & "|")) = "odella|gallery|" & row & "|" Then OdellaParkGallery ws, shp
    Next shp
End Sub

Private Function OdellaTokenIndex(parts() As String, token As String) As Long
    Dim i As Long
    OdellaTokenIndex = -1
    For i = LBound(parts) To UBound(parts)
        If StrComp(Trim$(parts(i)), token, vbTextCompare) = 0 Then
            OdellaTokenIndex = i
            Exit Function
        End If
    Next i
End Function

Private Function OdellaJoinParts(parts() As String) As String
    Dim i As Long
    Dim out As String
    For i = LBound(parts) To UBound(parts)
        If Trim$(parts(i)) <> "" Then
            If out <> "" Then out = out & " | "
            out = out & Trim$(parts(i))
        End If
    Next i
    OdellaJoinParts = out
End Function

Private Function OdellaClosestPreview(ws As Worksheet, shp As Shape) As Long
    Dim headers As Long
    Dim col As Long
    Dim title As String
    Dim best As Double
    Dim dist As Double
    Dim index As Long
    OdellaClosestPreview = -1
    best = 1000000
    headers = ws.Cells(1, ws.Columns.Count).End(xlToLeft).Column
    For col = 1 To headers
        title = LCase$(Trim$(CStr(ws.Cells(1, col).Value)))
        index = -1
        If title = "gallery images preview" Then index = 0
        If Left$(title, 16) = "gallery preview " Then
            If IsNumeric(Mid$(title, 17)) Then index = CLng(Mid$(title, 17)) - 1
        End If
        If index >= 0 Then
            dist = Abs(shp.Left - ws.Cells(shp.TopLeftCell.Row, col).Left)
            If dist < best Then
                best = dist
                OdellaClosestPreview = index
            End If
        End If
    Next col
End Function

Private Sub Worksheet_SelectionChange(ByVal Target As Range)
    OdellaCatchPictures
End Sub

Private Sub Worksheet_BeforeDoubleClick(ByVal Target As Range, Cancel As Boolean)
    Dim header As String
    If Target.Row < 2 Then Exit Sub
    header = CStr(Me.Cells(1, Target.Column).Value)
    If header = "Categories" Or header = "Tags" Or header = "Attributes" Then
        Cancel = True
        OdellaShowPicker
    End If
End Sub
VBA;
}

function ops_libreoffice_script(): string
{
    return <<<'BASIC'
' Odella for LibreOffice Calc and OpenOffice Calc.
' Tools, Macros, Organize Macros, LibreOffice Basic or OpenOffice Basic.
' Select the product file, New, name the module Odella, paste this, Save.
' Then run OdellaSetup once.
' Tools, Customize, Events. Choose the document. Assign Double-click to OdellaDoubleClick.
' Run OdellaEditCell for the tick list. Run OdellaAddPicture to store a picture file.
' Run OdellaGalleryEarlier or OdellaGalleryLater while a gallery preview is selected.

Sub OdellaSetup
    Dim oSheet As Object
    oSheet = OdellaSheet("Products")
    If oSheet Is Nothing Then
        MsgBox "There is no Products sheet in this file.", 48, "Odella"
        Exit Sub
    End If
    OdellaClearValidity oSheet, "Categories"
    OdellaClearValidity oSheet, "Tags"
    OdellaClearValidity oSheet, "Attributes"
    OdellaPictures
    MsgBox "Pictures are filled in. Double-click Categories, Tags, or Attributes for the ticks. Use OdellaAddPicture to store a picture file in the odella-images folder.", 64, "Odella"
End Sub

Sub OdellaDoubleClick(Optional oEvent As Variant)
    OdellaEditCell
End Sub

Sub OdellaEditCell
    Dim oSheet As Object, oCell As Object
    Dim sHeader As String, sReal As String
    oSheet = OdellaProducts()
    If oSheet Is Nothing Then Exit Sub
    oCell = OdellaActiveCell(oSheet)
    If oCell Is Nothing Then Exit Sub
    If oCell.CellAddress.Row < 1 Then Exit Sub
    sHeader = Trim(oSheet.getCellByPosition(oCell.CellAddress.Column, 0).String)
    If OdellaIsPreview(sHeader, sReal) Then
        MsgBox "This column only shows the picture." & Chr(10) & Chr(10) & "Put the picture on " & sReal & " in this row. The shop reads that cell.", 64, "Use " & sReal
        Exit Sub
    End If
    If sHeader = "Categories" Or sHeader = "Tags" Or sHeader = "Attributes" Then
        OdellaPickList oCell, sHeader
    Else
        MsgBox "Click Categories, Tags, or Attributes, then run OdellaEditCell.", 64, "Odella"
    End If
End Sub

Sub OdellaAddPicture
    Dim oSheet As Object, oCell As Object
    Dim sHeader As String, sReal As String, sUrl As String, sName As String, sFolder As String, sDest As String
    Dim oPicker As Object, oCopy As Object
    oSheet = OdellaProducts()
    If oSheet Is Nothing Then Exit Sub
    If Not ThisComponent.hasLocation Then
        MsgBox "Save the workbook first. Pictures are copied into an odella-images folder next to the file.", 64, "Odella"
        Exit Sub
    End If
    oCell = OdellaActiveCell(oSheet)
    If oCell Is Nothing Then Exit Sub
    sHeader = Trim(oSheet.getCellByPosition(oCell.CellAddress.Column, 0).String)
    If OdellaIsPreview(sHeader, sReal) Then
        MsgBox "This column only shows the picture." & Chr(10) & Chr(10) & "Select " & sReal & " in this row, then run OdellaAddPicture.", 64, "Use " & sReal
        Exit Sub
    End If
    If sHeader <> "Featured image" And sHeader <> "Hover image" And sHeader <> "Gallery images" Then
        MsgBox "Select Featured image, Hover image, or Gallery images first.", 64, "Odella"
        Exit Sub
    End If
    oPicker = CreateUnoService("com.sun.star.ui.dialogs.FilePicker")
    oPicker.setTitle "Choose a picture"
    If oPicker.execute() <> 1 Then Exit Sub
    sUrl = oPicker.getFiles()(0)
    sName = OdellaFileName(sUrl)
    sFolder = OdellaImageFolder()
    sDest = OdellaJoin(sFolder, sName)
    oCopy = CreateUnoService("com.sun.star.ucb.SimpleFileAccess")
    oCopy.copy sUrl, ConvertToURL(sDest)
    If sHeader = "Gallery images" Then
        If Trim(oCell.String) = "" Then
            oCell.String = sName
        Else
            oCell.String = Trim(oCell.String) & " | " & sName
        End If
    Else
        oCell.String = sName
    End If
    OdellaPictures
End Sub

Sub OdellaGalleryEarlier
    OdellaMoveGallery -1
End Sub

Sub OdellaGalleryLater
    OdellaMoveGallery 1
End Sub

Sub OdellaMoveGallery(nStep As Long)
    Dim oSheet As Object, oCell As Object
    Dim nGallery As Long, nIndex As Long
    Dim sHeader As String, sText As String
    Dim aParts() As String
    oSheet = OdellaProducts()
    If oSheet Is Nothing Then Exit Sub
    oCell = OdellaActiveCell(oSheet)
    If oCell Is Nothing Then Exit Sub
    sHeader = LCase(Trim(oSheet.getCellByPosition(oCell.CellAddress.Column, 0).String))
    nIndex = OdellaPreviewIndex(sHeader)
    If nIndex < 0 Then
        MsgBox "Select a gallery preview first.", 64, "Odella"
        Exit Sub
    End If
    nGallery = OdellaCol(oSheet, "Gallery images")
    If nGallery < 0 Then Exit Sub
    sText = oSheet.getCellByPosition(nGallery, oCell.CellAddress.Row).String
    aParts = OdellaSplit(sText)
    If nIndex > UBound(aParts) Then Exit Sub
    If nIndex + nStep < 0 Or nIndex + nStep > UBound(aParts) Then Exit Sub
    Dim sHold As String
    sHold = aParts(nIndex + nStep)
    aParts(nIndex + nStep) = aParts(nIndex)
    aParts(nIndex) = sHold
    oSheet.getCellByPosition(nGallery, oCell.CellAddress.Row).String = OdellaJoinParts(aParts)
    OdellaPictures
End Sub

Sub OdellaPictures
    Dim oSheet As Object, nRow As Long, nLast As Long
    Dim nFeatured As Long, nHover As Long, nGallery As Long
    Dim nFeaturedPreview As Long, nHoverPreview As Long
    oSheet = OdellaProducts()
    If oSheet Is Nothing Then Exit Sub
    nLast = OdellaLastRow(oSheet)
    nFeatured = OdellaCol(oSheet, "Featured image")
    nHover = OdellaCol(oSheet, "Hover image")
    nGallery = OdellaCol(oSheet, "Gallery images")
    nFeaturedPreview = OdellaEnsureColumn(oSheet, "Featured image preview", nFeatured)
    nHoverPreview = OdellaEnsureColumn(oSheet, "Hover image preview", nHover)
    OdellaEnsureColumn oSheet, "Gallery images preview", nGallery
    For nRow = 1 To nLast
        If nFeatured >= 0 And nFeaturedPreview >= 0 Then OdellaShowOne oSheet, nRow, nFeatured, nFeaturedPreview, "F"
        If nHover >= 0 And nHoverPreview >= 0 Then OdellaShowOne oSheet, nRow, nHover, nHoverPreview, "H"
        If nGallery >= 0 Then OdellaShowGallery oSheet, nRow, nGallery
    Next nRow
End Sub

Sub OdellaBuildVariations
    MsgBox "Export a fresh file when you need new variation rows, or use the Excel or Google script for that step. This Calc script keeps the pictures and the tick lists.", 64, "Odella"
End Sub

Sub OdellaPickList(oCell As Object, sHeader As String)
    Dim oSource As Object, nLast As Long, n As Long, nCount As Long
    Dim aItems() As String, sValue As String
    oSource = OdellaSheet(sHeader)
    If oSource Is Nothing Then
        MsgBox "This file has no " & sHeader & " sheet.", 48, "Odella"
        Exit Sub
    End If
    nLast = OdellaLastRow(oSource)
    nCount = 0
    For n = 1 To nLast
        If Trim(oSource.getCellByPosition(0, n).String) <> "" Then nCount = nCount + 1
    Next n
    If nCount = 0 Then
        MsgBox "The " & sHeader & " sheet has no choices.", 64, "Odella"
        Exit Sub
    End If
    ReDim aItems(nCount - 1)
    nCount = 0
    For n = 1 To nLast
        sValue = Trim(oSource.getCellByPosition(0, n).String)
        If sValue <> "" Then
            aItems(nCount) = sValue
            nCount = nCount + 1
        End If
    Next n
    Dim oDlg As Object, oModel As Object, oList As Object, oOk As Object, oCancel As Object
    oModel = CreateUnoService("com.sun.star.awt.UnoControlDialogModel")
    oModel.PositionX = 80
    oModel.PositionY = 40
    oModel.Width = 240
    oModel.Height = 220
    oModel.Title = sHeader
    oList = oModel.createInstance("com.sun.star.awt.UnoControlListBoxModel")
    oList.Name = "items"
    oList.PositionX = 8
    oList.PositionY = 8
    oList.Width = 220
    oList.Height = 170
    oList.MultiSelection = True
    oList.StringItemList = aItems
    Dim aPre As Variant
    aPre = OdellaSelected(aItems, oCell.String)
    If IsArray(aPre) Then
        If aPre(LBound(aPre)) >= 0 Then oList.SelectedItems = aPre
    End If
    oModel.insertByName "items", oList
    oOk = oModel.createInstance("com.sun.star.awt.UnoControlButtonModel")
    oOk.Name = "ok"
    oOk.Label = "Done"
    oOk.PositionX = 8
    oOk.PositionY = 186
    oOk.Width = 50
    oOk.Height = 16
    oOk.PushButtonType = 1
    oModel.insertByName "ok", oOk
    oCancel = oModel.createInstance("com.sun.star.awt.UnoControlButtonModel")
    oCancel.Name = "cancel"
    oCancel.Label = "Cancel"
    oCancel.PositionX = 64
    oCancel.PositionY = 186
    oCancel.Width = 50
    oCancel.Height = 16
    oCancel.PushButtonType = 2
    oModel.insertByName "cancel", oCancel
    oDlg = CreateUnoService("com.sun.star.awt.UnoControlDialog")
    oDlg.setModel oModel
    oDlg.createPeer CreateUnoService("com.sun.star.awt.Toolkit"), null
    If oDlg.execute() <> 1 Then
        oDlg.dispose
        Exit Sub
    End If
    Dim aSel As Variant, i As Long, sOut As String
    aSel = oDlg.getControl("items").getSelectedItems()
    sOut = ""
    If IsArray(aSel) Then
        For i = LBound(aSel) To UBound(aSel)
            If sOut <> "" Then sOut = sOut & ", "
            sOut = sOut & aItems(aSel(i))
        Next i
    End If
    oCell.String = sOut
    oDlg.dispose
End Sub

Function OdellaSelected(aItems() As String, sCurrent As String) As Variant
    Dim aChosen() As String, i As Long, n As Long, nFound As Long
    aChosen = OdellaSplitComma(sCurrent)
    nFound = -1
    For i = LBound(aItems) To UBound(aItems)
        For n = LBound(aChosen) To UBound(aChosen)
            If StrComp(Trim(aChosen(n)), aItems(i), 0) = 0 Then
                nFound = nFound + 1
                Exit For
            End If
        Next n
    Next i
    If nFound < 0 Then
        Dim aEmpty(0) As Integer
        aEmpty(0) = -1
        OdellaSelected = aEmpty
        Exit Function
    End If
    Dim aSel(0) As Integer
    ReDim aSel(nFound)
    nFound = 0
    For i = LBound(aItems) To UBound(aItems)
        For n = LBound(aChosen) To UBound(aChosen)
            If StrComp(Trim(aChosen(n)), aItems(i), 0) = 0 Then
                aSel(nFound) = i
                nFound = nFound + 1
                Exit For
            End If
        Next n
    Next i
    OdellaSelected = aSel
End Function

Sub OdellaShowOne(oSheet As Object, nRow As Long, nSource As Long, nPreview As Long, sKind As String)
    Dim sValue As String, sUrl As String
    sValue = Trim(oSheet.getCellByPosition(nSource, nRow).String)
    sUrl = OdellaPictureUrl(sValue)
    OdellaPlaceGraphic oSheet, nPreview, nRow, sUrl, "Odella" & sKind & "_" & nRow
End Sub

Sub OdellaShowGallery(oSheet As Object, nRow As Long, nGallery As Long)
    Dim aParts() As String, i As Long, nPreview As Long, sUrl As String
    aParts = OdellaSplit(oSheet.getCellByPosition(nGallery, nRow).String)
    If UBound(aParts) < 0 Then Exit Sub
    If Trim(aParts(0)) = "" And UBound(aParts) = 0 Then Exit Sub
    For i = LBound(aParts) To UBound(aParts)
        If Trim(aParts(i)) = "" Then Exit For
        nPreview = OdellaEnsureColumn(oSheet, OdellaPreviewTitle(i), OdellaCol(oSheet, OdellaPreviewTitle(i - 1)))
        If i = 0 Then nPreview = OdellaCol(oSheet, "Gallery images preview")
        sUrl = OdellaPictureUrl(Trim(aParts(i)))
        If nPreview >= 0 Then OdellaPlaceGraphic oSheet, nPreview, nRow, sUrl, "OdellaG_" & nRow & "_" & i
    Next i
End Sub

Sub OdellaPlaceGraphic(oSheet As Object, nCol As Long, nRow As Long, sUrl As String, sName As String)
    Dim oPage As Object, i As Long, oShape As Object
    Dim aPos As New com.sun.star.awt.Point
    Dim aSize As New com.sun.star.awt.Size
    oPage = oSheet.getDrawPage()
    For i = oPage.getCount() - 1 To 0 Step -1
        On Error Resume Next
        If oPage.getByIndex(i).Name = sName Then oPage.remove oPage.getByIndex(i)
        On Error GoTo 0
    Next i
    If sUrl = "" Then Exit Sub
    oShape = ThisComponent.createInstance("com.sun.star.drawing.GraphicObjectShape")
    oShape.GraphicURL = sUrl
    oShape.Name = sName
    aSize.Width = 1600
    aSize.Height = 1100
    oShape.setSize aSize
    oPage.add oShape
    On Error Resume Next
    oShape.Anchor = oSheet.getCellByPosition(nCol, nRow)
    On Error GoTo 0
End Sub

Function OdellaPictureUrl(sValue As String) As String
    Dim sFolder As String, sPath As String
    OdellaPictureUrl = ""
    If LCase(Left(sValue, 8)) = "https://" Then
        OdellaPictureUrl = sValue
        Exit Function
    End If
    If sValue = "" Or Not ThisComponent.hasLocation Then Exit Function
    sFolder = OdellaImageFolder()
    sPath = OdellaJoin(sFolder, OdellaFileName(sValue))
    If Dir(sPath) <> "" Then OdellaPictureUrl = ConvertToURL(sPath)
End Function

Function OdellaImageFolder() As String
    Dim sDoc As String, n As Long
    sDoc = ConvertFromURL(ThisComponent.URL)
    n = InStrRev(sDoc, GetPathSeparator())
    OdellaImageFolder = Left(sDoc, n) & "odella-images"
    If Dir(OdellaImageFolder, 16) = "" Then MkDir OdellaImageFolder
End Function

Function OdellaJoin(sFolder As String, sName As String) As String
    OdellaJoin = sFolder & GetPathSeparator() & sName
End Function

Function OdellaFileName(sUrl As String) As String
    Dim s As String, n As Long
    s = ConvertFromURL(sUrl)
    If s = "" Then s = sUrl
    n = InStrRev(Replace(s, "/", GetPathSeparator()), GetPathSeparator())
    If n > 0 Then s = Mid(s, n + 1)
    OdellaFileName = s
End Function

Function OdellaPreviewTitle(nIndex As Long) As String
    If nIndex <= 0 Then
        OdellaPreviewTitle = "Gallery images preview"
    Else
        OdellaPreviewTitle = "Gallery preview " & (nIndex + 1)
    End If
End Function

Function OdellaPreviewIndex(sHeader As String) As Long
    OdellaPreviewIndex = -1
    If sHeader = "gallery images preview" Then OdellaPreviewIndex = 0
    If Left(sHeader, 16) = "gallery preview " Then
        If IsNumeric(Mid(sHeader, 17)) Then OdellaPreviewIndex = CLng(Mid(sHeader, 17)) - 1
    End If
End Function

Function OdellaIsPreview(sHeader As String, ByRef sReal As String) As Boolean
    Dim s As String
    s = LCase(Trim(sHeader))
    sReal = ""
    OdellaIsPreview = False
    If s = "featured image preview" Then
        sReal = "Featured image"
        OdellaIsPreview = True
    ElseIf s = "hover image preview" Then
        sReal = "Hover image"
        OdellaIsPreview = True
    ElseIf s = "gallery images preview" Or Left(s, 16) = "gallery preview " Then
        sReal = "Gallery images"
        OdellaIsPreview = True
    End If
End Function

Function OdellaEnsureColumn(oSheet As Object, sTitle As String, nAfter As Long) As Long
    Dim nCol As Long
    nCol = OdellaCol(oSheet, sTitle)
    If nCol >= 0 Then
        OdellaEnsureColumn = nCol
        Exit Function
    End If
    If nAfter < 0 Then
        OdellaEnsureColumn = -1
        Exit Function
    End If
    oSheet.getColumns().insertByIndex nAfter + 1, 1
    oSheet.getCellByPosition(nAfter + 1, 0).String = sTitle
    OdellaEnsureColumn = nAfter + 1
End Function

Sub OdellaClearValidity(oSheet As Object, sHeader As String)
    Dim nCol As Long, nLast As Long, oRange As Object
    nCol = OdellaCol(oSheet, sHeader)
    If nCol < 0 Then Exit Sub
    nLast = OdellaLastRow(oSheet)
    If nLast < 1 Then Exit Sub
    oRange = oSheet.getCellRangeByPosition(nCol, 1, nCol, nLast)
    On Error Resume Next
    Dim oValidation As Object
    oValidation = oRange.Validation
    oValidation.Type = com.sun.star.sheet.ValidationType.ANY
    oRange.Validation = oValidation
    On Error GoTo 0
End Sub

Function OdellaCol(oSheet As Object, sHeader As String) As Long
    Dim n As Long, s As String
    OdellaCol = -1
    For n = 0 To 120
        s = Trim(oSheet.getCellByPosition(n, 0).String)
        If LCase(s) = LCase(sHeader) Then
            OdellaCol = n
            Exit Function
        End If
    Next n
End Function

Function OdellaLastRow(oSheet As Object) As Long
    Dim oCursor As Object
    oCursor = oSheet.createCursor()
    oCursor.gotoEndOfUsedArea True
    OdellaLastRow = oCursor.RangeAddress.EndRow
    If OdellaLastRow > 3000 Then OdellaLastRow = 3000
End Function

Function OdellaSheet(sName As String) As Object
    On Error Resume Next
    OdellaSheet = ThisComponent.Sheets.getByName(sName)
    On Error GoTo 0
End Function

Function OdellaProducts() As Object
    OdellaProducts = OdellaSheet("Products")
    If OdellaProducts Is Nothing Then MsgBox "There is no Products sheet in this file.", 48, "Odella"
End Function

Function OdellaActiveCell(oSheet As Object) As Object
    Dim oSel As Object
    oSel = ThisComponent.getCurrentSelection()
    If oSel Is Nothing Then Exit Function
    On Error Resume Next
    If oSel.CellAddress.Sheet <> oSheet.RangeAddress.Sheet Then Exit Function
    OdellaActiveCell = oSheet.getCellByPosition(oSel.CellAddress.Column, oSel.CellAddress.Row)
    On Error GoTo 0
End Function

Function OdellaSplit(sText As String) As Variant
    Dim s As String
    s = Replace(sText, ",", "|")
    s = Replace(s, Chr(10), "|")
    OdellaSplit = Split(s, "|")
End Function

Function OdellaSplitComma(sText As String) As Variant
    OdellaSplitComma = Split(sText, ",")
End Function

Function OdellaJoinParts(aParts() As String) As String
    Dim i As Long, s As String
    s = ""
    For i = LBound(aParts) To UBound(aParts)
        If Trim(aParts(i)) <> "" Then
            If s <> "" Then s = s & " | "
            s = s & Trim(aParts(i))
        End If
    Next i
    OdellaJoinParts = s
End Function
BASIC;
}

function ops_onlyoffice_script(): string
{
    return <<<'OO'
// Odella for OnlyOffice Spreadsheet.
// View, Macros. Paste this as one macro named Odella.
// At the bottom, keep the line you want and delete the other odella calls, then Run.
// OnlyOffice cannot read a file dropped from the desktop. Paste an https link, or a file name, into Featured image, Hover image, or Gallery images.
// odellaLists builds a tick sheet. Type yes beside each wanted item, then run odellaApplyList.
// odellaPictures fills the preview columns. odellaMoveGallery(-1) or odellaMoveGallery(1) changes gallery order.

function odellaSheet(name) {
  try {
    return Api.GetSheet(name);
  } catch (err) {
    return null;
  }
}

function odellaCol(sheet, title) {
  var want = String(title).toLowerCase();
  for (var col = 1; col <= 120; col++) {
    var value = String(sheet.GetRange(odellaLetter(col) + "1").GetValue() || "").trim().toLowerCase();
    if (value === want) return col;
  }
  return 0;
}

function odellaLetter(n) {
  var s = "";
  while (n > 0) {
    var m = (n - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
}

function odellaActive() {
  var sheet = Api.GetActiveSheet();
  var cell = sheet.GetActiveCell();
  var address = String(cell.GetAddress() || "").replace(/\$/g, "");
  var match = address.match(/([A-Z]+)(\d+)/i);
  return { sheet: sheet, cell: cell, row: match ? Number(match[2]) : cell.GetRow(), col: match ? odellaColNumber(match[1]) : 1 };
}

function odellaColNumber(letters) {
  var n = 0;
  var text = String(letters).toUpperCase();
  for (var i = 0; i < text.length; i++) n = n * 26 + (text.charCodeAt(i) - 64);
  return n;
}

function odellaHeader(sheet, col) {
  return String(sheet.GetRange(odellaLetter(col) + "1").GetValue() || "").trim();
}

function odellaLastRow(sheet) {
  var last = 2;
  for (var row = 2; row <= 3000; row++) {
    var value = String(sheet.GetRange("A" + row).GetValue() || "");
    if (value !== "") last = row;
  }
  return last;
}

function odellaEnsure(sheet, title, afterTitle) {
  var found = odellaCol(sheet, title);
  if (found) return found;
  var after = odellaCol(sheet, afterTitle);
  if (!after) return 0;
  sheet.GetRange(odellaLetter(after + 1) + ":" + odellaLetter(after + 1)).Insert("right");
  sheet.GetRange(odellaLetter(after + 1) + "1").SetValue(title);
  return after + 1;
}

function odellaUrls(value) {
  var found = [];
  var matches = String(value || "").match(/https:\/\/[^\s|]+/gi) || [];
  for (var i = 0; i < matches.length; i++) found.push(matches[i]);
  if (!found.length && String(value || "").trim()) found.push(String(value).trim());
  return found;
}

function odellaPictures() {
  var sheet = odellaSheet("Products") || Api.GetActiveSheet();
  odellaEnsure(sheet, "Featured image preview", "Featured image");
  odellaEnsure(sheet, "Hover image preview", "Hover image");
  odellaEnsure(sheet, "Gallery images preview", "Gallery images");
  var featured = odellaCol(sheet, "Featured image");
  var hover = odellaCol(sheet, "Hover image");
  var gallery = odellaCol(sheet, "Gallery images");
  var last = odellaLastRow(sheet);
  for (var row = 2; row <= last; row++) {
    if (featured) odellaOne(sheet, row, featured, "Featured image preview");
    if (hover) odellaOne(sheet, row, hover, "Hover image preview");
    if (gallery) odellaGallery(sheet, row, gallery);
  }
}

function odellaOne(sheet, row, sourceCol, previewTitle) {
  var preview = odellaCol(sheet, previewTitle);
  if (!preview) return;
  var value = String(sheet.GetRange(odellaLetter(sourceCol) + row).GetValue() || "");
  if (value.indexOf("https://") === 0) {
    sheet.GetRange(odellaLetter(preview) + row).SetValue("=IMAGE(" + odellaLetter(sourceCol) + row + ")");
  }
}

function odellaGallery(sheet, row, galleryCol) {
  var parts = String(sheet.GetRange(odellaLetter(galleryCol) + row).GetValue() || "").split(/\s*[|,]\s*/);
  var clean = [];
  for (var i = 0; i < parts.length; i++) if (String(parts[i] || "").trim()) clean.push(String(parts[i]).trim());
  for (var n = 0; n < clean.length; n++) {
    var title = n === 0 ? "Gallery images preview" : "Gallery preview " + (n + 1);
    var after = n === 0 ? "Gallery images" : (n === 1 ? "Gallery images preview" : "Gallery preview " + n);
    odellaEnsure(sheet, title, after);
    var col = odellaCol(sheet, title);
    if (col && clean[n].indexOf("https://") === 0) {
      sheet.GetRange(odellaLetter(col) + row).SetValue('=IMAGE("' + clean[n].replace(/"/g, "") + '")');
    }
  }
}

function odellaLists() {
  var here = odellaActive();
  var header = odellaHeader(here.sheet, here.col);
  if (header !== "Categories" && header !== "Tags" && header !== "Attributes") return;
  var source = odellaSheet(header);
  if (!source) return;
  var pick = odellaSheet("OdellaPick");
  if (!pick) {
    Api.AddSheet("OdellaPick");
    pick = odellaSheet("OdellaPick");
  }
  pick.GetRange("A1:B2000").Clear();
  pick.GetRange("A1").SetValue(header);
  pick.GetRange("B1").SetValue("yes");
  pick.GetRange("C1").SetValue("Type yes in column B, click the product cell again, then run odellaApplyList. Cell " + here.cell.GetAddress());
  var chosen = {};
  String(here.cell.GetValue() || "").split(",").forEach(function (part) {
    var text = String(part || "").trim();
    if (text) chosen[text] = true;
  });
  var write = 2;
  for (var row = 2; row <= 2000; row++) {
    var option = String(source.GetRange("A" + row).GetValue() || "").trim();
    if (!option) break;
    pick.GetRange("A" + write).SetValue(option);
    if (chosen[option]) pick.GetRange("B" + write).SetValue("yes");
    write++;
  }
  pick.SetActive();
}

function odellaApplyList() {
  var pick = odellaSheet("OdellaPick");
  var products = odellaSheet("Products");
  if (!pick || !products) return;
  var note = String(pick.GetRange("C1").GetValue() || "");
  var address = (note.match(/\$?[A-Z]+\$?\d+/i) || [""])[0].replace(/\$/g, "");
  if (!address) return;
  var values = [];
  for (var row = 2; row <= 2000; row++) {
    var option = String(pick.GetRange("A" + row).GetValue() || "").trim();
    if (!option) break;
    var mark = String(pick.GetRange("B" + row).GetValue() || "").trim().toLowerCase();
    if (mark === "yes" || mark === "y" || mark === "1") values.push(option);
  }
  products.GetRange(address).SetValue(values.join(", "));
  products.SetActive();
}

function odellaPreviewIndex(header) {
  var name = String(header || "").trim().toLowerCase();
  if (name === "gallery images preview") return 0;
  var match = name.match(/^gallery preview (\d+)$/);
  return match ? Number(match[1]) - 1 : -1;
}

function odellaMoveGallery(step) {
  var here = odellaActive();
  var index = odellaPreviewIndex(odellaHeader(here.sheet, here.col));
  var gallery = odellaCol(here.sheet, "Gallery images");
  if (index < 0 || !gallery) return;
  var parts = String(here.sheet.GetRange(odellaLetter(gallery) + here.row).GetValue() || "").split(/\s*[|,]\s*/);
  var clean = [];
  for (var i = 0; i < parts.length; i++) if (String(parts[i] || "").trim()) clean.push(String(parts[i]).trim());
  var dest = index + step;
  if (dest < 0 || dest >= clean.length) return;
  var hold = clean[dest];
  clean[dest] = clean[index];
  clean[index] = hold;
  here.sheet.GetRange(odellaLetter(gallery) + here.row).SetValue(clean.join(" | "));
  odellaGallery(here.sheet, here.row, gallery);
}

function odellaWarnPreview() {
  var here = odellaActive();
  var header = odellaHeader(here.sheet, here.col).toLowerCase();
  var real = "";
  if (header === "featured image preview") real = "Featured image";
  else if (header === "hover image preview") real = "Hover image";
  else if (header === "gallery images preview" || /^gallery preview \d+$/.test(header)) real = "Gallery images";
  if (!real) return;
  here.sheet.GetRange(odellaLetter(here.col) + here.row).AddComment("This column only shows the picture. Put it on " + real + " in this row.");
}

odellaPictures();
OO;
}
