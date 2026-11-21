"""In-memory OCR and conservative, table-driven ID field parsing."""

from __future__ import annotations

import re
from datetime import date, datetime

import easyocr
import numpy as np


# Add or change label aliases here without changing the extraction code.
FIELD_LABELS = {
    "national_id": {
        "name": ("last name", "given names", "middle name", "pangalan"),
        "date_of_birth": ("date of birth", "birth date", "petsa ng kapanganakan"),
        "address": ("address", "tirahan", "address of residence"),
        "id_number": ("philippine identification card", "pcn", "crn", "id no"),
        "expiry_date": ("expiration date", "expiry"),
    },
    "drivers_license": {
        "name": ("last name", "given names", "middle name", "name"),
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

DATE_PATTERNS = (
    r"\b\d{4}-\d{1,2}-\d{1,2}\b",
    r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b",
    r"\b(?:JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)\s+\d{1,2},?\s+\d{4}\b",
)


def load_reader() -> easyocr.Reader:
    """Create the one shared CPU OCR reader."""
    return easyocr.Reader(["en"], gpu=False)


def read_image(reader: easyocr.Reader, image: np.ndarray) -> tuple[list[str], float]:
    """Return text lines and the average OCR confidence without retaining either."""
    results = reader.readtext(image, detail=1, paragraph=False)
    lines = [str(item[1]).strip() for item in results if str(item[1]).strip()]
    scores = [float(item[2]) for item in results]
    confidence = sum(scores) / len(scores) if scores else 0.0
    return lines, round(confidence, 2)


def parse_fields(lines: list[str], id_type: str) -> dict[str, str | None]:
    """Extract available fields using the labels configured for this ID type."""
    labels = FIELD_LABELS[id_type]
    fields = {"full_name": None, "date_of_birth": None, "address": None,
              "id_number": None, "expiry_date": None}
    fields["full_name"] = _find_name(lines, labels["name"])
    fields["date_of_birth"] = _find_date(lines, labels["date_of_birth"], False)
    fields["address"] = _find_value(lines, labels["address"], _clean_address)
    fields["id_number"] = _find_value(lines, labels["id_number"], _clean_id)
    fields["expiry_date"] = _find_date(lines, labels["expiry_date"], True)
    return fields


def _find_value(lines: list[str], labels: tuple[str, ...], cleaner) -> str | None:
    for index, line in enumerate(lines):
        lower = line.casefold()
        if any(label in lower for label in labels):
            value = re.split(r"[:#]", line, maxsplit=1)[-1].strip()
            value = cleaner(value)
            if value:
                return value
            if index + 1 < len(lines):
                value = cleaner(lines[index + 1])
                if value:
                    return value
    return None


def _find_name(lines: list[str], labels: tuple[str, ...]) -> str | None:
    values: list[str] = []
    for label in labels:
        value = _find_value(lines, (label,), _clean_name)
        if value and value not in values:
            values.append(value)
    return " ".join(values) if values else None


def _find_date(lines: list[str], labels: tuple[str, ...], expiry: bool) -> str | None:
    for index, line in enumerate(lines):
        if any(label in line.casefold() for label in labels):
            candidate = " ".join(lines[index:index + 2])
            found = _parse_date_from_text(candidate, expiry)
            if found:
                return found
    return None


def _parse_date_from_text(text: str, day_first: bool) -> str | None:
    for pattern in DATE_PATTERNS:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if not match:
            continue
        value = match.group(0).replace("-", "/")
        formats = ("%Y/%m/%d", "%d/%m/%Y", "%m/%d/%Y") if day_first else ("%Y/%m/%d", "%m/%d/%Y", "%d/%m/%Y")
        if re.search(r"[A-Z]", value, flags=re.IGNORECASE):
            formats = ("%B %d, %Y", "%B %d %Y")
        for form in formats:
            try:
                return datetime.strptime(value.upper(), form).date().isoformat()
            except ValueError:
                continue
    return None


def age_on_today(birth_date: str | None) -> int | None:
    """Calculate age only after a valid ISO date was extracted."""
    if not birth_date:
        return None
    born = date.fromisoformat(birth_date)
    today = date.today()
    return today.year - born.year - ((today.month, today.day) < (born.month, born.day))


def _clean_name(value: str) -> str | None:
    value = re.sub(r"[^A-Za-z .,'-]", " ", value).strip()
    return value if len(value) >= 2 else None


def _clean_address(value: str) -> str | None:
    value = re.sub(r"\s+", " ", value).strip(" :,-")
    return value if len(value) >= 4 else None


def _clean_id(value: str) -> str | None:
    value = re.sub(r"[^A-Za-z0-9-]", "", value).upper()
    return value if len(value) >= 5 else None
