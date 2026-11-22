"""In-memory face pose and ID-photo comparison helpers."""

from __future__ import annotations

import os

# Must be set before TensorFlow or DeepFace is imported (RetinaFace needs legacy Keras).
os.environ["TF_USE_LEGACY_KERAS"] = "1"

from pathlib import Path

import cv2
import numpy as np
from deepface import DeepFace
import mediapipe as mp
from mediapipe.tasks import python
from mediapipe.tasks.python import vision


# Starting thresholds only; tune these with a real mirrored webcam before release.
YAW_STRAIGHT_MAX = 0.10
YAW_TURN_MIN = 0.18
SIZE_CLOSE_MIN = 0.52
SIZE_FAR_MAX = 0.22
SIZE_OK_MIN = 0.25
SIZE_OK_MAX = 0.50
MIN_FACE_AREA_RATIO = 0.25
MIN_FACE_CONFIDENCE = 0.8
FACE_MODEL = "ArcFace"
DETECTOR_BACKEND = "retinaface"
MODEL_PATH = Path(__file__).parent / "models" / "face_landmarker.task"


def load_landmarker() -> vision.FaceLandmarker:
    """Create the shared offline MediaPipe Tasks landmarker."""
    if not MODEL_PATH.is_file():
        raise RuntimeError("FaceLandmarker model file is unavailable.")
    options = vision.FaceLandmarkerOptions(
        base_options=python.BaseOptions(model_asset_path=str(MODEL_PATH)),
        running_mode=vision.RunningMode.IMAGE,
        num_faces=2,
    )
    return vision.FaceLandmarker.create_from_options(options)


def warm_up_deepface() -> None:
    """Load the fixed recognition model once at service startup."""
    DeepFace.build_model(FACE_MODEL)


def capture_check(landmarker: vision.FaceLandmarker, image: np.ndarray, pose: str) -> dict:
    """Perform a basic movement check; this is not liveness or anti-spoofing."""
    landmarks = _detect_landmarks(landmarker, image)
    if len(landmarks) != 1:
        return _pose_result(False, pose, 0.0, 0.0, "Make sure only your face is visible.")
    yaw, size, bounds = _measure_face(landmarks[0], image.shape[1])
    if _face_brightness(image, bounds) < 60:
        return _pose_result(False, pose, yaw, size, "Lighting is too dark.")
    passed = _pose_passes(pose, yaw, size, bounds)
    message = "Pose check passed." if passed else "Please adjust your position and try again."
    return _pose_result(passed, pose, yaw, size, message)


def match_faces(id_image: np.ndarray, selfie: np.ndarray) -> dict:
    """Compare the ID photo with the single selfie face; no anti-spoofing is performed."""
    id_faces = _clear_faces(id_image)
    selfie_faces = _clear_faces(selfie)
    if not id_faces or len(selfie_faces) != 1:
        return _match_result(len(id_faces), len(selfie_faces), False, None, None,
                             "No clear face found. Please retake the photo.")
    # Whole images go in: the detector aligns each face itself (tested working).
    result = DeepFace.verify(img1_path=id_image, img2_path=selfie,
                             model_name=FACE_MODEL, detector_backend=DETECTOR_BACKEND,
                             align=True, enforce_detection=True)
    distance = float(result["distance"])
    threshold = float(result["threshold"])
    verified = distance <= threshold
    message = "Face matches the ID photo." if verified else "Face does not match the ID photo."
    return _match_result(len(id_faces), len(selfie_faces), verified, distance, threshold, message)


def _detect_landmarks(landmarker: vision.FaceLandmarker, image: np.ndarray) -> list:
    rgb = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
    mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
    return landmarker.detect(mp_image).face_landmarks


def _measure_face(points: list, width: int) -> tuple[float, float, tuple[float, float, float, float]]:
    xs = [point.x for point in points]
    ys = [point.y for point in points]
    left, right = min(xs), max(xs)
    top, bottom = min(ys), max(ys)
    nose = points[1].x
    ratio = (nose - left) / max(right - left, 0.001)
    yaw = round(ratio - 0.5, 3)
    return yaw, round((right - left), 3), (left, top, right, bottom)


def _face_brightness(image: np.ndarray, bounds: tuple[float, float, float, float]) -> float:
    height, width = image.shape[:2]
    left, top, right, bottom = bounds
    crop = image[max(0, int(top * height)):min(height, int(bottom * height)),
                 max(0, int(left * width)):min(width, int(right * width))]
    return float(cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY).mean()) if crop.size else 0.0


def _pose_passes(pose: str, yaw: float, size: float, bounds: tuple[float, float, float, float]) -> bool:
    if pose == "straight":
        return abs(yaw) <= YAW_STRAIGHT_MAX and SIZE_OK_MIN <= size <= SIZE_OK_MAX
    # Webcam previews are usually mirrored, so confirm left/right direction in real testing.
    if pose == "left":
        return yaw <= -YAW_TURN_MIN
    if pose == "right":
        return yaw >= YAW_TURN_MIN
    if pose == "closer":
        return size >= SIZE_CLOSE_MIN
    return size <= SIZE_FAR_MAX and all(0.01 <= value <= 0.99 for value in bounds)


def _clear_faces(image: np.ndarray) -> list[dict]:
    """Return real faces only: confident, both eyes found, not tiny next to the largest."""
    faces = DeepFace.extract_faces(img_path=image, detector_backend=DETECTOR_BACKEND,
                                   enforce_detection=False)
    kept = []
    for face in faces:
        area = face["facial_area"]
        confidence = float(face.get("confidence", face.get("face_confidence", 1.0)))
        if confidence < MIN_FACE_CONFIDENCE:
            continue
        if not area.get("left_eye") or not area.get("right_eye"):
            continue
        kept.append(face)
    if not kept:
        return []
    biggest = max(f["facial_area"]["w"] * f["facial_area"]["h"] for f in kept)
    return [f for f in kept
            if f["facial_area"]["w"] * f["facial_area"]["h"] >= MIN_FACE_AREA_RATIO * biggest]


def _pose_result(passed: bool, pose: str, yaw: float, size: float, message: str) -> dict:
    return {"ok": passed, "pose": pose, "passed": passed, "yaw": yaw,
            "face_size": size, "message": message}


def _match_result(id_faces: int, selfie_faces: int, verified: bool, distance: float | None,
                  threshold: float | None, message: str) -> dict:
    return {"ok": True, "id_faces": id_faces, "selfie_faces": selfie_faces,
            "verified": verified, "distance": distance, "threshold": threshold,
            "message": message}