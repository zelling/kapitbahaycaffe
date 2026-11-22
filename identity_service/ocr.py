"""In-memory OCR and conservative, table-driven ID field parsing."""

from __future__ import annotations

import re
import statistics
from datetime import date, datetime

import easyocr
import numpy as np


# Add or change label aliases here without changing the extraction code.
FIELD_LABELS = {
    "national_id": {
        "name": ("last name", "first name", "given names", "middle name", "pangalan"),
        "date_of_birth": ("date of birth", "birth date", "petsa ng kapanganakan"),
        "address": ("address", "tirahan", "address of residence"),
        "id_number": ("pcn", "crn", "id no"),
        "expiry_date": ("expiration date", "expiry"),
    },
    "drivers_license": {
        "name": ("last name", "first name", "middle name", "given names"),
        "date_of_birth": ("date of birth", "birthdate", "birth date"),
        "address": ("address", "tirahan"),
        "id_number": ("license no", "license number", "lic no"),
        "expiry_date": ("expiration date", "expiry", "expires"),
    },
    "passport": {
        "name": ("surname", "given names", "name"),
        "date_of_birth": ("date of birth", "birth date"),
        "address": ("address",),
        "id_number": ("passport no", "passport number", "document no"),
        "expiry_date": ("date of expiry", "expiration date", "expiry"),
    },
    "umid": {
        "name": ("surname", "given name", "middle name", "name"),
        "date_of_birth": ("date of birth", "birth date"),
        "address": ("address", "tirahan"),
        "id_number": ("crn", "common reference number", "id no"),
        "expiry_date": ("expiration date", "expiry", "valid until"),
    },
}

# Shapes of ID numbers, tried before the label search. Untested except the license.
ID_PATTERNS = {
    "drivers_license": r"[A-Z]\d{2}\s?-\s?\d{2}\s?-\s?\d{6}",
    "national_id": r"\d{4}-\d{4}-\d{4}-\d{4}",
    "umid": r"\d{4}-\d{7}-\d",
    "passport": r"P\d{7}[A-Z]",
}

# Year-first dates (used on Philippine licenses) come first.
DATE_PATTERNS = (
    r"\b\d{4}[/-]\d{1,2}[/-]\d{1,2}\b",
    r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b",
    r"\b(?:JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)\s+\d{1,2},?\s+\d{4}\b",
)

# How many lines after a label to search for its date value.
DATE_LOOKAHEAD = 8
# How many lines after the address label belong to the address.
ADDRESS_LINES = 2


def load_reader() -> easyocr.Reader:
    """Create the one shared CPU OCR reader."""
    return easyocr.Reader(["en"], gpu=False)


def read_image(reader: easyocr.Reader, image: np.ndarray) -> tuple[list[str], float]:
    """Return text lines and the median OCR confidence without retaining either."""
    results = reader.readtext(image, detail=1, paragraph=False)
    lines = [str(item[1]).strip() for item in results if str(item[1]).strip()]
    scores = [float(item[2]) for item in results]
    # Median, so a few noisy hologram fragments do not drag the score down.
    confidence = statistics.median(scores) if scores else 0.0
    return lines, round(confidence, 2)


def parse_fields(lines: list[str], id_type: str) -> dict[str, str | None]:
    """Extract available fields using the labels configured for this ID type."""
    labels = FIELD_LABELS[id_type]
    all_labels = [label for group in labels.values() for label in group]
    fields = {"full_name": None, "date_of_birth": None, "address": None,
              "id_number": None, "expiry_date": None}
    fields["full_name"] = _find_name(lines, labels["name"], all_labels)
    fields["date_of_birth"] = _find_date(lines, labels["date_of_birth"] + ("birth",), False)
    fields["address"] = _find_address(lines, labels["address"], all_labels)
    fields["id_number"] = _find_id_number(lines, id_type, labels["id_number"], all_labels)
    fields["expiry_date"] = _find_date(lines, labels["expiry_date"], True)
    if fields["date_of_birth"] and not _plausible_birth(fields["date_of_birth"]):
        fields["date_of_birth"] = None
    return fields


def _has_label(line: str, labels) -> bool:
    lower = line.casefold()
    return any(label in lower for label in labels)


def _strip_label(line: str, labels) -> str:
    """Remove the label words from a line, keeping any value on the same line."""
    ordered = sorted(labels, key=len, reverse=True)
    pattern = re.compile("|".join(re.escape(label) for label in ordered), re.IGNORECASE)
    return pattern.sub(" ", line).strip(" .:#,-")


def _value_after(lines, index, labels, all_labels, cleaner):
    """Value on the label line itself, otherwise on the next non-label line."""
    same = cleaner(_strip_label(lines[index], labels))
    if same:
        return same
    if index + 1 < len(lines) and not _has_label(lines[index + 1], all_labels):
        return cleaner(lines[index + 1])
    return None


def _find_name(lines, labels, all_labels) -> str | None:
    values: list[str] = []
    for index, line in enumerate(lines):
        if _has_label(line, labels):
            value = _value_after(lines, index, labels, all_labels, _clean_name)
            if value and value not in values:
                values.append(value)
    if not values:
        return None
    if len(values) == 1 and "," in values[0]:
        # Licenses print LAST, FIRST MIDDLE; store as FIRST MIDDLE LAST.
        last, rest = values[0].split(",", 1)
        return re.sub(r"\s+", " ", f"{rest} {last}").strip()
    return re.sub(r"\s+", " ", " ".join(values)).strip()


def _find_address(lines, labels, all_labels) -> str | None:
    for index, line in enumerate(lines):
        if not _has_label(line, labels):
            continue
        parts = []
        same = _clean_address(_strip_label(line, labels))
        if same:
            parts.append(same)
        for nxt in lines[index + 1:index + 1 + ADDRESS_LINES]:
            if _has_label(nxt, all_labels):
                break
            cleaned = _clean_address(nxt)
            if cleaned:
                parts.append(cleaned)
        if parts:
            return ", ".join(parts)
    return None


def _find_id_number(lines, id_type, labels, all_labels) -> str | None:
    pattern = ID_PATTERNS.get(id_type)
    if pattern:
        for line in lines:
            match = re.search(pattern, line.upper())
            if match:
                return re.sub(r"\s", "", match.group(0))
    for index, line in enumerate(lines):
        if _has_label(line, labels):
            value = _value_after(lines, index, labels, all_labels, _clean_id)
            if value:
                return value
    return None


def _find_date(lines, labels, expiry: bool) -> str | None:
    for index, line in enumerate(lines):
        if _has_label(line, labels):
            for candidate in lines[index:index + DATE_LOOKAHEAD]:
                found = _date_in_line(candidate, expiry)
                if found:
                    return found
    return None


def _date_in_line(line: str, day_first: bool) -> str | None:
    found = _parse_date_from_text(line, day_first)
    if found:
        return found
    # OCR often reads 0 as O and 1 as I or l inside numbers.
    fixed = line.translate(str.maketrans("OIl|", "0111"))
    return _parse_date_from_text(fixed, day_first) if fixed != line else None


def _parse_date_from_text(text: str, day_first: bool) -> str | None:
    for pattern in DATE_PATTERNS:
        for match in re.finditer(pattern, text, flags=re.IGNORECASE):
            value = re.sub(r"\s+", " ", match.group(0).upper().replace("-", "/"))
            if re.search(r"[A-Z]", value):
                formats = ("%B %d, %Y", "%B %d %Y")
            elif day_first:
                formats = ("%Y/%m/%d", "%d/%m/%Y", "%m/%d/%Y")
            else:
                formats = ("%Y/%m/%d", "%m/%d/%Y", "%d/%m/%Y")
            for form in formats:
                try:
                    return datetime.strptime(value, form).date().isoformat()
                except ValueError:
                    continue
    return None


def _plausible_birth(birth_date: str) -> bool:
    born = date.fromisoformat(birth_date)
    return 1900 <= born.year and born < date.today()


def age_on_today(birth_date: str | None) -> int | None:
    """Calculate age only after a valid ISO date was extracted."""
    if not birth_date:
        return None
    born = date.fromisoformat(birth_date)
    today = date.today()
    return today.year - born.year - ((today.month, today.day) < (born.month, born.day))


def _clean_name(value: str) -> str | None:
    value = re.sub(r"[^A-Za-z .,'-]", " ", value)
    value = re.sub(r"\s+", " ", value).strip(" .,-")
    return value if len(value) >= 2 else None


def _clean_address(value: str) -> str | None:
    value = re.sub(r"\s+", " ", value).strip(" :,-")
    return value if len(value) >= 4 else None


def _clean_id(value: str) -> str | None:
    value = re.sub(r"[^A-Za-z0-9-]", "", value).upper()
    return value if len(value) >= 5 else None