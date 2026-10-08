"""
Capture frames directly from a webcam with OpenCV (server-side camera).

The web application normally captures frames in the BROWSER
(navigator.mediaDevices.getUserMedia) and sends them to PHP. This script is
provided for:
  * testing that OpenCV can open the camera on the exam-hall PC;
  * offline / command-line enrollment and recognition tests.

Examples
--------
    # Is a camera available?
    python capture.py --check

    # Capture 8 frames that each contain exactly one face
    python capture.py --output-dir captures --count 8

    # Then enroll them / test recognition from the command line:
    python enroll.py detect --output-dir ../uploads/faces captures/*.jpg
    python recognize.py --image captures/<file>.jpg --model-dir models

Add --preview to show a live window (press q to abort).
Output: one JSON object on stdout.
"""

import argparse
import os
import secrets
import sys
import time

from face_utils import cv2, detect_faces, emit


def open_camera(index):
    # CAP_DSHOW starts much faster on Windows; fall back to the default backend.
    backends = [cv2.CAP_DSHOW, cv2.CAP_ANY] if os.name == "nt" else [cv2.CAP_ANY]
    for backend in backends:
        cam = cv2.VideoCapture(index, backend)
        if cam.isOpened():
            return cam
        cam.release()
    return None


def main():
    parser = argparse.ArgumentParser(description="Capture face frames from a webcam")
    parser.add_argument("--camera", type=int, default=0, help="camera index (default 0)")
    parser.add_argument("--check", action="store_true", help="only check that the camera works")
    parser.add_argument("--output-dir", default="captures")
    parser.add_argument("--count", type=int, default=8, help="frames with one face to capture")
    parser.add_argument("--timeout", type=float, default=30.0, help="give up after N seconds")
    parser.add_argument("--interval", type=float, default=0.4, help="seconds between saved frames")
    parser.add_argument("--preview", action="store_true", help="show a preview window")
    args = parser.parse_args()

    cam = open_camera(args.camera)
    if cam is None:
        emit({"success": False, "reason": "CAMERA_UNAVAILABLE",
              "message": "Could not open camera {}.".format(args.camera)})

    try:
        ok, frame = cam.read()
        if not ok or frame is None:
            emit({"success": False, "reason": "CAMERA_READ_FAILED", "message": "Camera opened but returned no frame."})
        if args.check:
            h, w = frame.shape[:2]
            emit({"success": True, "camera": args.camera, "resolution": [w, h]})

        os.makedirs(args.output_dir, exist_ok=True)
        saved, start, last = [], time.time(), 0.0
        while len(saved) < args.count and time.time() - start < args.timeout:
            ok, frame = cam.read()
            if not ok:
                continue
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            boxes = detect_faces(gray)

            if args.preview:
                view = frame.copy()
                for (x, y, w, h) in boxes:
                    colour = (0, 200, 0) if len(boxes) == 1 else (0, 0, 255)
                    cv2.rectangle(view, (x, y), (x + w, y + h), colour, 2)
                cv2.putText(view, "{}/{}".format(len(saved), args.count), (10, 30),
                            cv2.FONT_HERSHEY_SIMPLEX, 1, (255, 255, 255), 2)
                cv2.imshow("capture.py - press q to quit", view)
                if cv2.waitKey(1) & 0xFF == ord("q"):
                    break

            now = time.time()
            if len(boxes) == 1 and now - last >= args.interval:
                path = os.path.join(args.output_dir, secrets.token_hex(8) + ".jpg")
                cv2.imwrite(path, frame)
                saved.append(os.path.abspath(path))
                last = now

        emit({
            "success": len(saved) >= args.count,
            "captured": len(saved),
            "files": saved,
            "message": "Captured {} frame(s) with one face.".format(len(saved)),
        })
    finally:
        cam.release()
        if args.preview:
            cv2.destroyAllWindows()


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:
        sys.stderr.write("capture.py error: {!r}\n".format(exc))
        emit({"success": False, "reason": "ENGINE_ERROR", "message": str(exc)}, 1)
