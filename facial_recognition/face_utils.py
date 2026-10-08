"""
Shared OpenCV helpers for the examination verification system.

Pipeline used by BOTH enrollment and recognition (they must match):

    image -> grayscale -> Haar cascade face detection -> crop (with margin)
          -> resize to 200x200 -> histogram equalisation -> LBPH

All scripts print exactly ONE JSON object on stdout so PHP can parse it.
"""

import json
import os
import sys

# Keep OpenCV quiet so stdout only ever contains our JSON.
os.environ.setdefault("OPENCV_LOG_LEVEL", "ERROR")

try:
    import cv2  # noqa: E402
    import numpy as np  # noqa: E402
except ImportError as exc:  # pragma: no cover - reported to PHP as JSON
    print(json.dumps({
        "success": False,
        "matched": False,
        "reason": "OPENCV_MISSING",
        "message": "Python packages missing ({}). Run: pip install -r facial_recognition/requirements.txt".format(exc),
    }))
    sys.exit(1)

FACE_SIZE = (200, 200)          # every stored / compared face is resized to this
MODEL_FILE = "lbph_model.yml"   # trained LBPH model (inside the model directory)
LABELS_FILE = "labels.json"     # label (users.id) -> student_id mapping + metadata

# Image quality gates used during enrollment.
MIN_SHARPNESS = 35.0            # variance of Laplacian; lower = blurrier
MIN_BRIGHTNESS = 40.0           # mean grey level (0-255)
MAX_BRIGHTNESS = 220.0


def emit(payload, exit_code=0):
    """Print the JSON result for PHP and exit."""
    sys.stdout.write(json.dumps(payload) + "\n")
    sys.stdout.flush()
    sys.exit(exit_code)


def lbph_available():
    """LBPH lives in the contrib module (opencv-contrib-python)."""
    return hasattr(cv2, "face") and hasattr(cv2.face, "LBPHFaceRecognizer_create")


def create_recognizer():
    if not lbph_available():
        raise RuntimeError(
            "cv2.face.LBPHFaceRecognizer is unavailable. Uninstall opencv-python and install "
            "opencv-contrib-python (pip uninstall opencv-python && pip install opencv-contrib-python)."
        )
    # radius=1, neighbors=8, 8x8 grid are the standard LBPH parameters.
    return cv2.face.LBPHFaceRecognizer_create(radius=1, neighbors=8, grid_x=8, grid_y=8)


_cascade = None


def get_cascade():
    """Load the frontal-face Haar cascade bundled with OpenCV."""
    global _cascade
    if _cascade is None:
        path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
        cascade = cv2.CascadeClassifier(path)
        if cascade.empty():
            raise RuntimeError("Could not load Haar cascade from " + path)
        _cascade = cascade
    return _cascade


def load_gray(path):
    """Read an image file as a grayscale numpy array (None if unreadable)."""
    if not os.path.isfile(path):
        return None
    # imdecode handles non-ASCII Windows paths better than imread.
    data = np.fromfile(path, dtype=np.uint8)
    if data.size == 0:
        return None
    image = cv2.imdecode(data, cv2.IMREAD_GRAYSCALE)
    return image


def detect_faces(gray):
    """Return a list of (x, y, w, h) face boxes, largest first."""
    h, w = gray.shape[:2]
    min_side = max(60, min(w, h) // 6)   # ignore tiny background faces
    equalised = cv2.equalizeHist(gray)
    faces = get_cascade().detectMultiScale(
        equalised, scaleFactor=1.1, minNeighbors=6, minSize=(min_side, min_side)
    )
    boxes = [tuple(int(v) for v in f) for f in faces]
    boxes.sort(key=lambda b: b[2] * b[3], reverse=True)
    return boxes


def crop_face(gray, box, margin=0.15):
    """Crop a face with a small margin, resize and normalise lighting."""
    x, y, w, h = box
    mx, my = int(w * margin), int(h * margin)
    x0, y0 = max(0, x - mx), max(0, y - my)
    x1, y1 = min(gray.shape[1], x + w + mx), min(gray.shape[0], y + h + my)
    face = gray[y0:y1, x0:x1]
    face = cv2.resize(face, FACE_SIZE, interpolation=cv2.INTER_AREA)
    return cv2.equalizeHist(face)


def sharpness(gray):
    return float(cv2.Laplacian(gray, cv2.CV_64F).var())


def brightness(gray):
    return float(np.mean(gray))


def save_png(path, image):
    """Write an image (imencode + tofile works with any Windows path)."""
    ok, buf = cv2.imencode(".png", image)
    if not ok:
        raise RuntimeError("Could not encode image")
    buf.tofile(path)


def confidence_from_distance(distance, threshold):
    """
    Convert an LBPH distance into a 0..1 score for display/storage.
    distance 0 -> 1.0, distance == threshold -> 0.5, distance >= 2*threshold -> 0.0
    """
    if threshold <= 0:
        return 0.0
    return round(max(0.0, min(1.0, 1.0 - distance / (2.0 * threshold))), 4)
