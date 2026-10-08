"""
Face enrollment and model training.

Sub-commands
------------
1) detect - validate enrollment frames and save cropped faces

    python enroll.py detect --output-dir ../uploads/faces --min-faces 5 IMG1 IMG2 ...

   For every frame: exactly one face must be detected, the face must be sharp
   enough (--min-sharpness) and reasonably lit. Valid faces are cropped, normalised (200x200 grayscale,
   histogram-equalised) and saved with RANDOM file names. If fewer than
   --min-faces frames pass, nothing is kept and success=false is returned.

2) train - (re)build the LBPH model from a manifest written by PHP

    python enroll.py train --manifest manifest.json --model-dir models

   manifest.json = {"samples": [{"label": 12, "student_id": "SWE/2026/001", "path": "..."}]}
   Each sample is also added horizontally flipped to make the model a little
   more tolerant of pose.

Output: one JSON object on stdout.
"""

import argparse
import datetime
import json
import os
import secrets
import sys

from face_utils import (
    LABELS_FILE, MAX_BRIGHTNESS, MIN_BRIGHTNESS, MIN_SHARPNESS, MODEL_FILE,
    brightness, create_recognizer, crop_face, cv2, detect_faces, emit, face_sharpness,
    load_gray, np, save_png,
)


def cmd_detect(args):
    if not os.path.isdir(args.output_dir):
        os.makedirs(args.output_dir, exist_ok=True)

    saved, rejected, measured = [], [], []
    for index, path in enumerate(args.images):
        gray = load_gray(path)
        if gray is None:
            rejected.append({"frame": index, "reason": "unreadable"})
            continue

        boxes = detect_faces(gray)
        if not boxes:
            rejected.append({"frame": index, "reason": "no_face"})
            continue
        if len(boxes) > 1:
            rejected.append({"frame": index, "reason": "multiple_faces"})
            continue

        x, y, w, h = boxes[0]
        sharp, bright = face_sharpness(gray, boxes[0]), brightness(gray[y:y + h, x:x + w])
        measured.append(round(sharp, 1))
        if sharp < args.min_sharpness:
            rejected.append({"frame": index, "reason": "too_blurry", "sharpness": round(sharp, 1)})
            continue
        if not (MIN_BRIGHTNESS <= bright <= MAX_BRIGHTNESS):
            rejected.append({"frame": index, "reason": "poor_lighting", "brightness": round(bright, 1)})
            continue

        face = crop_face(gray, boxes[0])
        filename = secrets.token_hex(16) + ".png"      # unguessable file name
        save_png(os.path.join(args.output_dir, filename), face)
        saved.append({
            "file": filename,
            "frame": index,
            "bbox": [x, y, w, h],
            "sharpness": round(sharp, 1),
            "brightness": round(bright, 1),
        })

    if len(saved) < args.min_faces:
        # Not enough good frames: delete what we saved, keep nothing.
        for item in saved:
            try:
                os.remove(os.path.join(args.output_dir, item["file"]))
            except OSError:
                pass
        reasons = {}
        for r in rejected:
            reasons[r["reason"]] = reasons.get(r["reason"], 0) + 1
        message = ("Only {} of {} frames contained one clear face (need {}). "
                   "Face the camera alone, in good light, and hold still.").format(
                       len(saved), len(args.images), args.min_faces)
        if reasons.get("too_blurry"):
            message += " Measured sharpness {}-{} (minimum {}); if the camera is simply soft, " \
                       "lower ENROLL_MIN_SHARPNESS in config/config.php.".format(
                           min(measured), max(measured), args.min_sharpness)
        emit({
            "success": False,
            "reason": "INSUFFICIENT_FACES",
            "message": message,
            "accepted": len(saved),
            "rejected": rejected,
            "rejection_summary": reasons,
            "measured_sharpness": measured,
        })

    emit({
        "success": True,
        "accepted": len(saved),
        "faces": saved,
        "rejected": rejected,
        "face_size": [200, 200],
        "method": "haar_cascade+lbph",
    })


def cmd_train(args):
    with open(args.manifest, "r", encoding="utf-8") as fh:
        manifest = json.load(fh)

    images, labels, label_map, missing = [], [], {}, 0
    for sample in manifest.get("samples", []):
        gray = load_gray(sample["path"])
        if gray is None:
            missing += 1
            continue
        if gray.shape[:2] != (200, 200):
            gray = cv2.resize(gray, (200, 200))
        label = int(sample["label"])
        images.append(gray)
        labels.append(label)
        images.append(cv2.flip(gray, 1))            # augmentation: mirrored copy
        labels.append(label)
        label_map[str(label)] = sample["student_id"]

    os.makedirs(args.model_dir, exist_ok=True)
    model_path = os.path.join(args.model_dir, MODEL_FILE)
    labels_path = os.path.join(args.model_dir, LABELS_FILE)

    if not images:
        # No enrolled students: remove any stale model so nobody can match.
        for path in (model_path, labels_path):
            if os.path.exists(path):
                os.remove(path)
        emit({"success": True, "trained": False, "student_count": 0, "sample_count": 0,
              "message": "No enrolled faces - the recognition model was cleared."})

    recognizer = create_recognizer()
    recognizer.train(images, np.array(labels, dtype=np.int32))

    # Write to temp files then atomically replace, so a running recognition never reads a half-written model.
    tmp_model = model_path + ".tmp.yml"
    recognizer.write(tmp_model)
    os.replace(tmp_model, model_path)

    meta = {
        "labels": label_map,
        "student_count": len(label_map),
        "sample_count": len(images) // 2,
        "trained_at": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "algorithm": "LBPH (radius=1, neighbors=8, grid=8x8)",
    }
    tmp_labels = labels_path + ".tmp"
    with open(tmp_labels, "w", encoding="utf-8") as fh:
        json.dump(meta, fh, indent=2)
    os.replace(tmp_labels, labels_path)

    emit({
        "success": True,
        "trained": True,
        "student_count": meta["student_count"],
        "sample_count": meta["sample_count"],
        "missing_files": missing,
        "message": "Model trained with {} face sample(s) from {} student(s).".format(
            meta["sample_count"], meta["student_count"]),
    })


def main():
    parser = argparse.ArgumentParser(description="Face enrollment / LBPH training")
    sub = parser.add_subparsers(dest="command")

    p_detect = sub.add_parser("detect", help="validate frames and save cropped faces")
    p_detect.add_argument("--output-dir", required=True)
    p_detect.add_argument("--min-faces", type=int, default=5)
    p_detect.add_argument("--min-sharpness", type=float, default=MIN_SHARPNESS,
                          help="minimum Laplacian variance of the 200x200 face (default %(default)s)")
    p_detect.add_argument("images", nargs="+")

    p_train = sub.add_parser("train", help="train the LBPH model from a manifest")
    p_train.add_argument("--manifest", required=True)
    p_train.add_argument("--model-dir", required=True)

    args = parser.parse_args()
    if args.command == "detect":
        cmd_detect(args)
    elif args.command == "train":
        cmd_train(args)
    else:
        emit({"success": False, "reason": "USAGE", "message": "Use 'detect' or 'train'."}, 2)


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:  # always answer PHP with JSON
        sys.stderr.write("enroll.py error: {!r}\n".format(exc))
        emit({"success": False, "reason": "ENGINE_ERROR", "message": str(exc)}, 1)
