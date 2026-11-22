# Run locally only: uvicorn main:app --host 127.0.0.1 --port 8001
"""Local, authenticated in-memory identity verification service."""

from __future__ import annotations

import hmac
import logging
import os
os.environ["TF_USE_LEGACY_KERAS"] = "1"
from contextlib import asynccontextmanager
from datetime import date
from pathlib import Path

import cv2
import numpy as np
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import JSONResponse

import face
import ocr


MAX_UPLOAD_BYTES = 5 * 1024 * 1024
ALLOWED_ID_TYPES = {"national_id", "drivers_license", "passport", "umid"}
ALLOWED_POSES = {"straight", "left", "right", "closer", "farther"}
logger = logging.getLogger("identity_service")
STATE: dict[str, object] = {}


def _service_key() -> str:
    """Read the key from the environment, then the local ignored config file."""
    key = os.environ.get("SERVICE_KEY", "")
    if key:
        return key
    env_path = Path(__file__).parent / ".env"
    if not env_path.is_file():
        return ""
    for line in env_path.read_text(encoding="utf-8").splitlines():
        if line.startswith("SERVICE_KEY="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    return ""


SERVICE_KEY = _service_key()
if len(SERVICE_KEY) < 32:
    raise RuntimeError("SERVICE_KEY is missing or must be at least 32 characters.")


@asynccontextmanager
async def lifespan(_: FastAPI):
    """Load all ML resources once, before accepting requests."""
    STATE["ocr"] = ocr.load_reader()
    STATE["landmarker"] = face.load_landmarker()
    face.warm_up_deepface()
    yield
    landmarker = STATE.get("landmarker")
    if landmarker:
        landmarker.close()


app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None, lifespan=lifespan)


def require_key(x_service_key: str | None = Header(default=None)) -> None:
    """Protect every non-health endpoint with a constant-time key comparison."""
    if not x_service_key or not hmac.compare_digest(x_service_key, SERVICE_KEY):
        raise HTTPException(status_code=401, detail="Unauthorized.")


@app.exception_handler(HTTPException)
async def http_error(_, error: HTTPException) -> JSONResponse:
    """Keep auth and validation errors in the required safe JSON shape."""
    message = "Unauthorized." if error.status_code == 401 else str(error.detail)
    return JSONResponse(status_code=error.status_code, content={"ok": False, "message": message})


@app.get("/health")
async def health() -> dict:
    """Report local service availability without authentication."""
    return {"ok": True}


@app.post("/scan-id", dependencies=[Depends(require_key)], response_model=None)
async def scan_id(id_image: UploadFile = File(...), id_type: str = Form(...)) -> JSONResponse | dict:
    """OCR an accepted ID type and return only structured in-memory results."""
    try:
        if id_type not in ALLOWED_ID_TYPES:
            raise HTTPException(status_code=400, detail="Invalid ID type.")
        image = await _read_image(id_image)
        lines, confidence = ocr.read_image(STATE["ocr"], image)
        fields = ocr.parse_fields(lines, id_type)
        missing = [name for name in ("full_name", "date_of_birth", "id_number") if not fields[name]]
        expired = _is_expired(fields["expiry_date"])
        scanned = not missing and not expired and confidence >= 0.4
        message = "ID scanned successfully." if scanned else "Some required ID details could not be read."
        if expired:
            message = "This ID is expired."
        if confidence < 0.4:
            message = "The image is too blurry or dark. Please upload a clearer photo."
        result = {"ok": scanned, "scanned": scanned, "fields": fields, "missing": missing,
                  "age": ocr.age_on_today(fields["date_of_birth"]), "expired": expired,
                  "confidence": confidence, "message": message}
        _log("/scan-id", 200)
        return result
    except HTTPException:
        raise
    except Exception as error:
        return _processing_error("/scan-id", error)


@app.post("/capture-check", dependencies=[Depends(require_key)], response_model=None)
async def capture_check(frame: UploadFile = File(...), pose: str = Form(...)) -> JSONResponse | dict:
    """Check one requested facial movement frame without storing it."""
    try:
        if pose not in ALLOWED_POSES:
            raise HTTPException(status_code=400, detail="Invalid pose.")
        image = await _read_image(frame)
        result = face.capture_check(STATE["landmarker"], image, pose)
        _log("/capture-check", 200)
        return result
    except HTTPException:
        raise
    except Exception as error:
        return _processing_error("/capture-check", error)


@app.post("/match", dependencies=[Depends(require_key)], response_model=None)
async def match(id_image: UploadFile = File(...), selfie: UploadFile = File(...)) -> JSONResponse | dict:
    """Compare an ID portrait and a selfie strictly in process memory."""
    try:
        id_data = await _read_image(id_image)
        selfie_data = await _read_image(selfie)
        result = face.match_faces(id_data, selfie_data)
        _log("/match", 200)
        return result
    except HTTPException:
        raise
    except Exception as error:
        return _processing_error("/match", error)


async def _read_image(upload: UploadFile) -> np.ndarray:
    """Enforce size and decode a genuine JPEG or PNG entirely in memory."""
    raw = await upload.read(MAX_UPLOAD_BYTES + 1)
    if len(raw) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="Image upload is too large.")
    image = cv2.imdecode(np.frombuffer(raw, dtype=np.uint8), cv2.IMREAD_COLOR)
    is_jpeg = raw.startswith(b"\xff\xd8\xff")
    is_png = raw.startswith(b"\x89PNG\r\n\x1a\n")
    if image is None or not (is_jpeg or is_png):
        raise HTTPException(status_code=400, detail="Upload a readable JPEG or PNG image.")
    return _downscale(image)


def _downscale(image: np.ndarray) -> np.ndarray:
    """Limit image processing work while retaining aspect ratio."""
    height, width = image.shape[:2]
    longest = max(height, width)
    if longest <= 1600:
        return image
    factor = 1600 / longest
    return cv2.resize(image, (round(width * factor), round(height * factor)), interpolation=cv2.INTER_AREA)


def _is_expired(expiry: str | None) -> bool | None:
    """Return null when a card did not expose an expiry date."""
    return date.fromisoformat(expiry) < date.today() if expiry else None


def _processing_error(endpoint: str, error: Exception) -> JSONResponse:
    """Log only endpoint, status and error type; never sensitive content."""
    _log(endpoint, 200, type(error).__name__)
    return JSONResponse(status_code=200, content={"ok": False,
                                                   "message": "Could not process the image. Please try again."})


def _log(endpoint: str, status: int, error_type: str | None = None) -> None:
    """Emit privacy-safe operational metadata only."""
    logger.info("endpoint=%s status=%s error_type=%s", endpoint, status, error_type or "none")
