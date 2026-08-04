#!/usr/bin/env python3
"""Compile Publion's simple UTF-8 PO catalogue to a GNU MO file.

This keeps the bundled English fallback reproducible without requiring gettext
to be installed on the release machine.
"""

from __future__ import annotations

import ast
import struct
import sys
from pathlib import Path


def read_po(path: Path) -> dict[str, str]:
    entries: dict[str, str] = {}
    msgid: str | None = None
    msgstr: str | None = None
    state: str | None = None

    def commit() -> None:
        nonlocal msgid, msgstr, state
        if msgid is not None and msgstr is not None:
            entries[msgid] = msgstr
        msgid = msgstr = state = None

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line:
            commit()
            continue
        if line.startswith("#"):
            continue
        if line.startswith("msgid "):
            if msgid is not None:
                commit()
            msgid = ast.literal_eval(line[6:])
            state = "id"
        elif line.startswith("msgstr "):
            msgstr = ast.literal_eval(line[7:])
            state = "str"
        elif line.startswith('"') and state == "id":
            msgid = (msgid or "") + ast.literal_eval(line)
        elif line.startswith('"') and state == "str":
            msgstr = (msgstr or "") + ast.literal_eval(line)
    commit()
    return entries


def write_mo(entries: dict[str, str], destination: Path) -> None:
    catalog = sorted(entries.items())
    ids = b""
    strings = b""
    id_table: list[tuple[int, int]] = []
    string_table: list[tuple[int, int]] = []

    for msgid, msgstr in catalog:
        encoded = msgid.encode("utf-8")
        id_table.append((len(encoded), len(ids)))
        ids += encoded + b"\0"
        encoded = msgstr.encode("utf-8")
        string_table.append((len(encoded), len(strings)))
        strings += encoded + b"\0"

    count = len(catalog)
    header_size = 7 * 4
    ids_offset = header_size
    strings_offset = ids_offset + count * 8
    ids_data_offset = strings_offset + count * 8
    strings_data_offset = ids_data_offset + len(ids)
    output = [struct.pack("<7I", 0x950412DE, 0, count, ids_offset, strings_offset, 0, 0)]
    output.extend(struct.pack("<2I", length, ids_data_offset + offset) for length, offset in id_table)
    output.extend(struct.pack("<2I", length, strings_data_offset + offset) for length, offset in string_table)
    output.extend((ids, strings))
    destination.write_bytes(b"".join(output))


if __name__ == "__main__":
    source = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("publion/languages/publion-en_US.po")
    target = source.with_suffix(".mo")
    write_mo(read_po(source), target)
    print(f"Compiled {target} ({len(read_po(source))} entries)")
