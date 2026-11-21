"""Pure-Python stdlib .xlsx reader and writer compatible with Odella Product Spreadsheet."""

from __future__ import annotations

import re
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any
from xml.sax.saxutils import escape

NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_REL = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_PKG_REL = "http://schemas.openxmlformats.org/package/2006/relationships"
NS_MAP = {"m": NS_MAIN, "r": NS_REL}


def col_to_index(ref: str) -> int:
    m = re.match(r"([A-Z]+)", ref.upper())
    if not m:
        return 0
    n = 0
    for ch in m.group(1):
        n = n * 26 + (ord(ch) - 64)
    return n - 1


def index_to_col(idx: int) -> str:
    n = idx + 1
    out = []
    while n > 0:
        n, rem = divmod(n - 1, 26)
        out.append(chr(65 + rem))
    return "".join(reversed(out))


def read_xlsx(path: str | Path) -> dict[str, list[list[str]]]:
    """Read all sheets from an .xlsx file as dict[sheet_name, list[list[str]]]."""
    path = Path(path)
    sheets: dict[str, list[list[str]]] = {}
    with zipfile.ZipFile(path, "r") as zf:
        wb_xml = ET.fromstring(zf.read("xl/workbook.xml"))
        rels_xml = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
        rel_map: dict[str, str] = {}
        for rel in rels_xml:
            rid = rel.attrib.get("Id", "")
            target = rel.attrib.get("Target", "")
            if rid and target:
                rel_map[rid] = target

        shared_strings: list[str] = []
        if "xl/sharedStrings.xml" in zf.namelist():
            ss_xml = ET.fromstring(zf.read("xl/sharedStrings.xml"))
            for si in ss_xml.findall("m:si", NS_MAP):
                parts = [t.text or "" for t in si.iter(f"{{{NS_MAIN}}}t")]
                shared_strings.append("".join(parts))

        for sheet_el in wb_xml.findall(".//m:sheets/m:sheet", NS_MAP):
            name = sheet_el.attrib.get("name", "")
            rid = sheet_el.attrib.get(f"{{{NS_REL}}}id", "")
            target = rel_map.get(rid, "")
            if not target:
                continue
            target = target.lstrip("/")
            if not target.startswith("xl/"):
                target = "xl/" + target
            if target not in zf.namelist():
                continue
            root = ET.fromstring(zf.read(target))
            raw_rows: list[tuple[int, dict[int, str]]] = []
            max_col = 0
            for row_el in root.findall(".//m:sheetData/m:row", NS_MAP):
                r_num = int(row_el.attrib.get("r", len(raw_rows) + 1))
                row_dict: dict[int, str] = {}
                for c_el in row_el.findall("m:c", NS_MAP):
                    ref = c_el.attrib.get("r", "")
                    c_idx = col_to_index(ref) if ref else len(row_dict)
                    t = c_el.attrib.get("t", "")
                    v_el = c_el.find("m:v", NS_MAP)
                    if t == "s" and v_el is not None and v_el.text is not None:
                        s_idx = int(v_el.text)
                        val = shared_strings[s_idx] if 0 <= s_idx < len(shared_strings) else ""
                    elif t == "inlineStr":
                        val = "".join(x.text or "" for x in c_el.iter(f"{{{NS_MAIN}}}t"))
                    elif t == "b" and v_el is not None:
                        val = "TRUE" if v_el.text == "1" else "FALSE"
                    elif v_el is not None and v_el.text is not None:
                        val = v_el.text
                    else:
                        val = ""
                    if val != "":
                        row_dict[c_idx] = val
                        if c_idx + 1 > max_col:
                            max_col = c_idx + 1
                if row_dict:
                    raw_rows.append((r_num, row_dict))

            # Build dense list of rows (skipping trailing empty rows, preserving header row 1)
            dense: list[list[str]] = []
            for _, row_dict in raw_rows:
                dense.append([row_dict.get(i, "") for i in range(max_col)])
            sheets[name] = dense

    return sheets


def _xml_text(val: str) -> str:
    # Strip control characters invalid in XML 1.0
    cleaned = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F]", "", val)
    return escape(cleaned)


def _sheet_xml(rows: list[list[Any]], freeze_header: bool = True, freeze_cols: int = 0) -> str:
    lines = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        f'<worksheet xmlns="{NS_MAIN}" xmlns:r="{NS_REL}">',
    ]
    if freeze_header:
        y_split = 1
        x_split = freeze_cols
        top_left = f"{index_to_col(x_split)}{y_split + 1}"
        pane_attrs = [f'ySplit="{y_split}"', f'topLeftCell="{top_left}"', 'state="frozen"']
        if x_split > 0:
            pane_attrs.insert(0, f'xSplit="{x_split}"')
            pane_attrs.append('activePane="bottomRight"')
        else:
            pane_attrs.append('activePane="bottomLeft"')
        lines.append("<sheetViews><sheetView workbookViewId=\"0\">")
        lines.append(f"<pane {' '.join(pane_attrs)}/>")
        lines.append("</sheetView></sheetViews>")
    lines.append("<sheetData>")
    for r_idx, row in enumerate(rows, start=1):
        cells_xml: list[str] = []
        for c_idx, raw_val in enumerate(row):
            val = "" if raw_val is None else str(raw_val)
            if val == "":
                continue
            ref = f"{index_to_col(c_idx)}{r_idx}"
            style = ' s="1"' if r_idx == 1 else ""
            escaped = _xml_text(val)
            preserve = ' xml:space="preserve"' if (val.startswith(" ") or val.endswith(" ") or "\n" in val) else ""
            cells_xml.append(f'<c r="{ref}" t="inlineStr"{style}><is><t{preserve}>{escaped}</t></is></c>')
        if cells_xml:
            lines.append(f'<row r="{r_idx}">' + "".join(cells_xml) + "</row>")
    lines.append("</sheetData></worksheet>")
    return "\n".join(lines)


def write_xlsx(path: str | Path, sheets: list[tuple[str, list[list[Any]]]]) -> None:
    """Write a clean .xlsx workbook from an ordered list of (sheet_name, rows)."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)

    content_types = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
        '<Default Extension="xml" ContentType="application/xml"/>',
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
    ]
    wb_sheets_xml = []
    wb_rels_xml = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        f'<Relationships xmlns="{NS_PKG_REL}">',
        '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
    ]

    for idx, (name, _) in enumerate(sheets, start=1):
        safe_name = escape(name[:31])
        content_types.append(
            f'<Override PartName="/xl/worksheets/sheet{idx}.xml" '
            'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        )
        wb_sheets_xml.append(f'<sheet name="{safe_name}" sheetId="{idx}" r:id="rId{idx}"/>')
        wb_rels_xml.append(
            f'<Relationship Id="rId{idx}" '
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
            f'Target="worksheets/sheet{idx}.xml"/>'
        )

    content_types.append("</Types>")
    wb_rels_xml.append("</Relationships>")

    root_rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        f'<Relationships xmlns="{NS_PKG_REL}">\n'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>\n'
        "</Relationships>"
    )

    workbook_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        f'<workbook xmlns="{NS_MAIN}" xmlns:r="{NS_REL}">\n'
        "<sheets>" + "".join(wb_sheets_xml) + "</sheets>\n"
        "</workbook>"
    )

    styles_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        f'<styleSheet xmlns="{NS_MAIN}">\n'
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>\n'
        '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill></fills>\n'
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>\n'
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>\n'
        '<cellXfs count="2">'
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        "</cellXfs>\n"
        "</styleSheet>"
    )

    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("[Content_Types].xml", "\n".join(content_types))
        zf.writestr("_rels/.rels", root_rels)
        zf.writestr("xl/workbook.xml", workbook_xml)
        zf.writestr("xl/_rels/workbook.xml.rels", "\n".join(wb_rels_xml))
        zf.writestr("xl/styles.xml", styles_xml)
        for idx, (name, rows) in enumerate(sheets, start=1):
            freeze_cols = 3 if name == "Products" else 0
            freeze_header = name != "Lists"
            zf.writestr(f"xl/worksheets/sheet{idx}.xml", _sheet_xml(rows, freeze_header, freeze_cols))
