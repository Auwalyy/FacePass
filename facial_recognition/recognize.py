"""
Identify a student from one camera frame.

    python recognize.py --image frame.jpg --model-dir models --threshold 70

Pipeline: image -> grayscale -> Haar face detection -> crop/normalise
          -> LBPH predict -> distance vs threshold -> JSON

LBPH returns a DISTANCE (0 = identical; larger = less similar).
The face is accepted only when distance <= threshold.

Success:
    {"success": true, "matched": true, "student_id": "SWE/2026/001", "user_id": 2,
     "distance": 41.7, "confidence": 0.7021, "threshold": 70}
Failure (examples):
    {"success": false, "matched": false, "reason": "NO_MATCH", "message": "No matching face found", ...}
    {"success": false, "matched": false, "reason": "NO_FACE_DETECTED", ...}
    {"success": false, "matched": false, "reason": "MULTIPLE_FACES", ...}
    {"success": false, "matched": false, "reason": "MODEL_NOT_TRAINED", ...}

This script never invents an identity: a student ID is only returned when the
trained model's best match is within the threshold.
"""

import argparse
import json
import os
import sys

from face_utils import (
    LABELS_FILE, MODEL_FILE, confidence_from_distance, create_recognizer, crop_face,
    detect_faces, emit, load_gray,
)


def fail(reason, message, **extra):
    payload = {"success": False, "matched": False, "reason": reason, "message": message}
    payload.update(extra)
    emit(payload)


def main():
    parser = argparse.ArgumentParser(description="Recognise a face with the trained LBPH model")
    parser.add_argument("--image", required=True)
    parser.add_argument("--model-dir", required=True)
    parser.add_argument("--threshold", type=float, default=70.0)
    args = parser.parse_args()

    model_path = os.path.join(args.model_dir, MODEL_FILE)
    labels_path = os.path.join(args.model_dir, LABELS_FILE)
    if not (os.path.isfile(model_path) and os.path.isfile(labels_path)):
        fail("MODEL_NOT_TRAINED", "No faces have been enrolled yet - the recognition model is empty.")

    gray = load_gray(args.image)
    if gray is None:
        fail("INVALID_IMAGE", "The captured image could not be read.")

    boxes = detect_faces(gray)
    if not boxes:
        fail("NO_FACE_DETECTED", "No face detected. Look directly at the camera.")
    if len(boxes) > 1:
        fail("MULTIPLE_FACES", "More than one face detected. Only one student may stand in front of the camera.",
             faces=len(boxes))

    face = crop_face(gray, boxes[0])

    with open(labels_path, "r", encoding="utf-8") as fh:
        labels = json.load(fh).get("labels", {})

    recognizer = create_recognizer()
    recognizer.read(model_path)
    label, distance = recognizer.predict(face)
    distance = float(distance)
    confidence = confidence_from_distance(distance, args.threshold)
    student_id = labels.get(str(label))

    if student_id is None or distance > args.threshold:
        fail("NO_MATCH", "No matching face found",
             distance=round(distance, 2), confidence=confidence, threshold=args.threshold)

    emit({
        "success": True,
        "matched": True,
        "student_id": student_id,
        "user_id": int(label),
        "distance": round(distance, 2),
        "confidence": confidence,
        "threshold": args.threshold,
        "face_box": list(boxes[0]),
    })


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:
        sys.stderr.write("recognize.py error: {!r}\n".format(exc))
        emit({"success": False, "matched": False, "reason": "ENGINE_ERROR", "message": str(exc)}, 1)
