"""
Environment self-test - run it after installing requirements:

    python facial_recognition/selftest.py

Also triggered from Admin > Settings > "Test Python / OpenCV".
"""

import os
import platform
import sys

from face_utils import LABELS_FILE, MODEL_FILE, cv2, emit, get_cascade, lbph_available, np


def main():
    checks = {
        "python_version": platform.python_version(),
        "python_executable": sys.executable,
        "opencv_version": cv2.__version__,
        "numpy_version": np.__version__,
        "lbph_available (opencv-contrib)": lbph_available(),
    }
    try:
        get_cascade()
        checks["haar_cascade_loaded"] = True
    except Exception as exc:  # noqa: BLE001
        checks["haar_cascade_loaded"] = False
        checks["haar_cascade_error"] = str(exc)

    model_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "models")
    checks["model_trained"] = os.path.isfile(os.path.join(model_dir, MODEL_FILE)) and \
        os.path.isfile(os.path.join(model_dir, LABELS_FILE))

    ok = checks["lbph_available (opencv-contrib)"] and checks["haar_cascade_loaded"]
    message = "" if ok else (
        "Install the contrib build: pip uninstall -y opencv-python && pip install opencv-contrib-python"
        if not checks["lbph_available (opencv-contrib)"] else "Haar cascade could not be loaded."
    )
    emit({"success": ok, "checks": checks, "message": message})


if __name__ == "__main__":
    main()
