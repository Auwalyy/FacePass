# FacePass: Facial Recognition-Based Student Examination Verification and Management System

An academic MVP that replaces manual identity checks at university examination halls. A webcam at the hall entrance captures the student's face, **Python + OpenCV** identifies the student, and **PHP + MySQL** checks the examination schedule and the student's payment/eligibility status, then records attendance automatically. The system also includes a student portal (timetable, attendance, complaints) and an administrator portal (students, examinations, payments, attendance, complaints, audit logs).

> **Stack:** HTML5 · CSS3 · Vanilla JavaScript · Bootstrap 5 · PHP 8 · MySQL/MariaDB · Python 3 · OpenCV · XAMPP · VS Code
> No React, Node.js, Laravel, Django, Tailwind or Firebase is used.

---

## Table of contents

1. [Project overview](#1-project-overview)
2. [Features](#2-features)
3. [Technology stack](#3-technology-stack)
4. [Requirements](#4-requirements)
5. [XAMPP setup](#5-xampp-setup)
6. [PHP setup](#6-php-setup)
7. [MySQL setup](#7-mysql-setup)
8. [Python setup](#8-python-setup)
9. [Python virtual environment](#9-python-virtual-environment)
10. [OpenCV installation](#10-opencv-installation)
11. [Database installation](#11-database-installation)
12. [Project configuration](#12-project-configuration)
13. [Running the project](#13-running-the-project)
14. [Facial enrollment](#14-facial-enrollment)
15. [Facial verification](#15-facial-verification)
16. [Admin login](#16-admin-login)
17. [Student login](#17-student-login)
18. [Demo workflow](#18-demo-workflow)
19. [Troubleshooting](#19-troubleshooting)
20. [Security notes](#20-security-notes)
21. [Known MVP limitations](#21-known-mvp-limitations)
22. [Project structure](#22-project-structure)
23. [Acceptance test checklist](#23-acceptance-test-checklist)

---

## 1. Project overview

**Problem.** Students are verified by hand before entering examination halls: invigilators compare faces with ID cards and tick paper lists. This is slow, makes impersonation easier, and leaves no reliable digital attendance record.

**Solution.** At the entrance:

```
Student arrives ─► Camera captures face ─► Python/OpenCV recognition ─► Identify student
      ─► Retrieve student record ─► Check the exam being conducted ─► Check payment/eligibility
      ─► Check for an existing "present" record
            ├─ ELIGIBLE      ─► Record attendance ─► "✓ VERIFICATION SUCCESSFUL – proceed to the hall"
            ├─ NOT ELIGIBLE  ─► "✗ NOT ELIGIBLE – reason"
            ├─ ALREADY IN    ─► "⚠ ALREADY VERIFIED"
            └─ NO MATCH      ─► "✗ STUDENT NOT RECOGNIZED – contact staff" (manual fallback)
```

**Architecture.**

```
Browser (kiosk)                      PHP (Apache/XAMPP)                     Python 3 + OpenCV
───────────────                      ──────────────────                     ─────────────────
getUserMedia() camera ──frame──►  verification/verify.php
                                     ├─ auth + CSRF + exam checks
                                     ├─ validate & save temp image
                                     ├─ proc_open(PYTHON_PATH recognize.py) ──► Haar detection → crop
                                     │                                          → LBPH predict → JSON
                                     ├─ parse JSON  ◄───────────────────────────┘
                                     ├─ MySQL: student, eligibility, attendance (transaction)
                                     └─ audit log ─► JSON response ──► result panel
```

---

## 2. Features

| Area | Features |
|---|---|
| **Authentication** | Session login for students (student ID or e-mail) and admins (e-mail), `password_hash()`, session-fixation protection, idle timeout, login rate limiting, role-based access (`requireLogin()`, `requireAdmin()`, `requireStudent()`) |
| **Admin dashboard** | Live counts (students, registered faces, today's exams, today's attendance, eligible students, pending complaints), today's exams, recent attendance, pending complaints, recent verification attempts |
| **Students** | Register, edit, view, search, filter (department, level, Face ID, status), activate/deactivate, delete, face status, exam/attendance/eligibility history |
| **Face enrollment** | Webcam capture of 8 frames → OpenCV detection + quality checks (one face, sharpness, lighting) → random-named face crops → LBPH model retrained automatically |
| **Examinations** | Create/edit/cancel/delete, date/time/venue/department/level/status, venue clash detection, Upcoming/Ongoing/Completed/Cancelled views, "Use on kiosk" |
| **Payments & eligibility** | Per-exam toggles for *school fees paid* and *eligible* (instant AJAX save), reasons, bulk actions. No external payment gateway |
| **Verification kiosk** | Full-screen camera page, one-click/space-bar verification, clear result screens, live list of recent verifications |
| **Manual fallback** | Search by student ID or name, verify after checking the ID card, same eligibility rules, `verification_method = manual`, audited |
| **Attendance** | Filters (exam, date, department, level, status, method), audited manual corrections with mandatory reason, add records, mark absentees, CSV export |
| **Complaints** | Students submit complaints tied to an exam; admins review, respond, resolve or reject; students see status and response |
| **Student portal** | Dashboard (today's exam, next exam, attendance summary, completed exams, open complaints), timetable (upcoming/past), completed exams, attendance, complaints, profile, password change, optional one-time self enrollment |
| **Audit logs** | Logins, verifications (success, failure, duplicate, eligibility rejection, manual), attendance changes, student/exam changes, complaint resolutions, settings |

---

## 3. Technology stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, Vanilla JavaScript (ES2017+), Bootstrap 5.3 + Bootstrap Icons (bundled locally in `assets/vendor/`, so it works offline) |
| Backend | PHP 8.0+ (PDO, sessions, `proc_open`) |
| Database | MySQL 5.7+ / MariaDB 10.4+ (InnoDB, utf8mb4) |
| Face recognition | Python 3.8+, `opencv-contrib-python` (Haar cascade + LBPH), NumPy |
| Environment | XAMPP (Apache + MySQL) on Windows, VS Code |

---

## 4. Requirements

* Windows 10/11 (macOS/Linux also work with path adjustments)
* [XAMPP](https://www.apachefriends.org/) with **PHP 8.0 or newer** (PHP 8.2 recommended)
* [Python 3.8 – 3.12](https://www.python.org/downloads/) (tick **"Add python.exe to PATH"** during installation)
* A webcam (built-in or USB)
* Google Chrome, Microsoft Edge or Firefox
* Around 300 MB of disk space for the Python packages

---

## 5. XAMPP setup

1. Install XAMPP (default folder `C:\xampp`).
2. Open the **XAMPP Control Panel** and click **Start** for **Apache** and **MySQL**.
3. Copy (or `git clone`) this project into:

   ```
   C:\xampp\htdocs\exam-verification-system
   ```

   The folder name is up to you because the base URL is detected automatically. The rest of this guide assumes `exam-verification-system`.
4. Check that Apache is running by opening <http://localhost/>.

---

## 6. PHP setup

XAMPP's PHP already includes everything needed. Check that these extensions are enabled in `C:\xampp\php\php.ini` (they are by default):

```ini
extension=pdo_mysql
extension=fileinfo
extension=mbstring
```

Upload limits. Enrollment sends 8 webcam frames of about 200 KB each. XAMPP's defaults (`upload_max_filesize=40M`, `post_max_size=40M`, `max_file_uploads=20`) are enough. Restart Apache after any `php.ini` change.

`proc_open` must not be listed in `disable_functions` (it isn't by default). PHP uses it to run Python.

---

## 7. MySQL setup

XAMPP's MySQL/MariaDB works out of the box with user `root` and an **empty password**, which is what `config/config.php` expects. If you set a root password or create a dedicated user, update `DB_USER` / `DB_PASS` (see [§12](#12-project-configuration)).

phpMyAdmin: <http://localhost/phpmyadmin/>

---

## 8. Python setup

1. Install Python 3 from <https://www.python.org/downloads/>. On the first installer screen, tick **"Add python.exe to PATH"**.
2. Open a **new** Command Prompt and verify:

   ```bat
   python --version
   ```

   You should see something like `Python 3.12.x`. If Windows opens the Microsoft Store instead, disable the alias under *Settings → Apps → Advanced app settings → App execution aliases* (turn off `python.exe` and `python3.exe`).

---

## 9. Python virtual environment

Create the virtual environment **inside the project folder** (PHP detects it automatically there):

```bat
cd C:\xampp\htdocs\exam-verification-system
python -m venv venv
venv\Scripts\activate
```

Your prompt now starts with `(venv)`.

> The `venv` folder is excluded from Git and blocked from web access by `.htaccess`.

---

## 10. OpenCV installation

With the virtual environment activated:

```bat
pip install --upgrade pip
pip install -r facial_recognition\requirements.txt
```

`requirements.txt` installs:

```
opencv-contrib-python>=4.8,<5
numpy>=1.24
```

> **Important:** the LBPH recognizer (`cv2.face.LBPHFaceRecognizer_create`) exists **only** in `opencv-contrib-python`. If plain `opencv-python` is also installed, the two packages conflict. Fix it with:
>
> ```bat
> pip uninstall -y opencv-python opencv-contrib-python
> pip install opencv-contrib-python
> ```

Verify the installation:

```bat
python facial_recognition\selftest.py
```

Expected output (one JSON line):

```json
{"success": true, "checks": {"python_version": "3.12.x", "opencv_version": "4.x.x", "lbph_available (opencv-contrib)": true, "haar_cascade_loaded": true, "model_trained": false, ...}, "message": ""}
```

`model_trained` stays `false` until the first face is enrolled.

---

## 11. Database installation

### Option A: phpMyAdmin (recommended)

1. Open <http://localhost/phpmyadmin/>.
2. Click the **Import** tab (top menu, with no database selected).
3. Choose `database/schema.sql` → **Import**. This creates the `exam_verification_system` database and all tables.
4. Click **Import** again, choose `database/seed.sql` → **Import**. This loads the demo data.

### Option B: command line

```bat
cd C:\xampp\htdocs\exam-verification-system
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root < database\seed.sql
```

(Add `-p` if your root user has a password.)

> ⚠ `schema.sql` **drops and recreates** the database. Re-importing `seed.sql` resets all demo data. Seed dates are relative to the import day, so the demo exam **SWE401 is always "today" and ongoing**. Re-import the seed on another day to refresh it.

Tables: `users`, `student_faces`, `examinations`, `exam_eligibility`, `attendance` (unique `(student_id, exam_id)`), `complaints`, `audit_logs`, `settings`.

---

## 12. Project configuration

All settings live in **`config/config.php`**. Each value can also be overridden with an environment variable of the same name.

| Setting | Default | Meaning |
|---|---|---|
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` | MySQL server |
| `DB_NAME` | `exam_verification_system` | Database name |
| `DB_USER`, `DB_PASS` | `root`, *(empty)* | MySQL credentials |
| `APP_TIMEZONE` | `Africa/Lagos` | Used for exam dates/times. Change it to your timezone |
| `INSTITUTION_NAME` | `Bayero University, Kano` | Shown in the header |
| `PYTHON_PATH` | auto | Python interpreter used by PHP (see below) |
| `PYTHON_TIMEOUT` | `60` | Seconds before a Python call is aborted |
| `DEFAULT_RECOGNITION_THRESHOLD` | `70` | LBPH distance threshold (overridden in Admin → Settings) |
| `ENROLL_FRAMES` / `ENROLL_MIN_FACES` | `8` / `5` | Frames captured per enrollment / minimum frames that must pass |
| `ENROLL_MIN_SHARPNESS` | `5` | Minimum face sharpness during enrollment. Soft laptop webcams measure about 6–60, motion-smeared faces 2–4. If enrollment reports *too blurry*, the error shows the measured values; set this slightly below them (not below ~3) |
| `MAX_IMAGE_BYTES` | 2 MB | Maximum size of one uploaded frame |
| `APP_DEBUG` | `false` | Show raw PHP errors (development only) |

### How PHP finds Python

`PYTHON_PATH` is resolved in this order:

1. The `PYTHON_PATH` environment variable or a value you type in `config.php`.
2. `<project>\venv\Scripts\python.exe`: the virtual environment from [§9](#9-python-virtual-environment). **No configuration is needed if you created it there.**
3. `<project>/venv/bin/python` (Linux/macOS).
4. `python` from the system `PATH`.

Apache (especially when running as a Windows service) often **does not see your user PATH**. If the venv is somewhere else, set a full path:

```php
// config/config.php
$__python = env_or('PYTHON_PATH', 'C:\\xampp\\htdocs\\exam-verification-system\\venv\\Scripts\\python.exe');
```

To check from the browser, go to **Admin → Settings → Test Python / OpenCV**.

### Recognition threshold

LBPH returns a **distance**: `0` means identical, and larger values mean less similar. A face is accepted **only if `distance ≤ threshold`**. The threshold is set in **Admin → Settings** (slider 30–120, default **70**):

* **Lower** (e.g. 50–60): stricter. Fewer false matches, but more genuine students rejected in poor light.
* **Higher** (e.g. 80–90): more lenient. Fewer rejections, but a higher risk of accepting the wrong person.

The stored/displayed **confidence** is `1 − distance / (2 × threshold)`, clamped to 0–1. A match exactly at the threshold shows **50 %**, and a perfect match shows **100 %**. Tune the threshold with your own camera and lighting: enroll a few people, then check the distances written to the audit log (`FACE_NOT_RECOGNIZED … closest distance …`).

---

## 13. Running the project

1. Start **Apache** and **MySQL** in XAMPP.
2. Open **<http://localhost/exam-verification-system/>**. You are redirected to the login page.
3. Log in with a demo account ([§16](#16-admin-login), [§17](#17-student-login)).

> **Camera access requires a secure context.** Browsers only allow `getUserMedia()` on `https://` pages or on `http://localhost`. Run the kiosk **on the XAMPP computer itself** via `http://localhost/...`. To use a kiosk on a *different* computer, enable HTTPS in XAMPP (Apache SSL, `https://<server-ip>/exam-verification-system/`) or, for a lab demo only, start Chrome with `--unsafely-treat-insecure-origin-as-secure=http://<server-ip>`.

---

## 14. Facial enrollment

Enrollment is supervised by an administrator: the officer checks the student's ID card first.

1. **Admin → Students →** click the 📷 icon next to a student (or open the student and click **Enroll face**).
2. Click **Start camera** and allow camera access in the browser.
3. Seat the student alone in front of the camera, in good even light, with the face inside the oval.
4. Click **Capture & Enroll** and have the student **hold still** while the browser takes **8 frames** (about 3 seconds). Movement causes blurry frames.
5. PHP validates each frame (size, real MIME type, dimensions) and runs `facial_recognition/enroll.py detect`, which for **each frame**:
   * detects faces with the Haar cascade and **rejects frames with no face or more than one face**;
   * rejects **blurry** frames (variance of the Laplacian of the face resized to 200×200 below `ENROLL_MIN_SHARPNESS`, default 5) and **too dark/bright** frames;
   * crops the face, converts it to 200×200 grayscale with histogram equalisation, and saves it to `uploads/faces/` with a **random 32-hex-character file name**.
6. If **fewer than 5 frames** pass, nothing is saved and the screen explains why (e.g. "no face ×4, too blurry ×1").
7. If enrollment succeeds, `student_faces` rows are written, `users.face_registered = 1`, and the **LBPH model is retrained automatically** (`enroll.py train`) from all active students' faces. The student can be recognised immediately.

Re-enrolling **replaces** the old samples. **Delete face data** is on the student's *Face Data* tab.

**Student self-enrollment** (optional, on by default for the demo): a student whose face is not registered yet can enroll once from **Profile & Face ID**. Re-enrollment always requires an administrator. Turn this off in **Admin → Settings** for real deployments, because unsupervised enrollment lets a student register someone else's face.

Command-line alternative (no browser):

```bat
venv\Scripts\activate
cd facial_recognition
python capture.py --check                                   :: camera test
python capture.py --output-dir captures --count 8 --preview :: capture frames with OpenCV
python enroll.py detect --output-dir ..\uploads\faces captures\*.jpg
```

(Faces added this way are not linked to a student in the database. Use the web enrollment for real data.)

---

## 15. Facial verification

1. An admin selects the examination being conducted in **Admin → Settings → Verification kiosk examination** (tick *Also set the examination status to Ongoing*), or uses **Examinations → More → Use on kiosk**.
2. Open **Verification Kiosk** (sidebar) on the entrance computer. The camera starts automatically.
3. The student looks at the camera, and the officer clicks **VERIFY STUDENT** or presses **Space**.
4. `verification/verify.php`:
   1. checks the officer's session and CSRF token;
   2. checks that `exam_id` **is the kiosk-selected exam and its status is `ongoing`** (students can never choose an exam);
   3. validates the image and saves it temporarily under a random name;
   4. runs `recognize.py` → Haar detection → crop → LBPH → distance vs threshold → JSON;
   5. deletes the temporary frame (camera frames are never kept);
   6. maps the recognised label to the student record and checks that the account is active;
   7. inside a DB transaction: checks for an existing **present** record (→ `ALREADY_VERIFIED`), then the **eligibility record**, requiring `school_fees_paid = 1 AND eligible = 1` (→ `NOT_ELIGIBLE` with reason; a `rejected` attendance row is kept as a trail);
   8. records `attendance` (`present`, `facial_recognition`, confidence) and `exam_eligibility.verified_at`;
   9. writes an audit log entry and returns JSON.
5. The kiosk shows one of:
   * **✓ VERIFICATION SUCCESSFUL**: name, ID, course, exam, date, time, *ELIGIBLE*, *RECORDED*, "You may proceed to the examination hall."
   * **✗ STUDENT NOT RECOGNIZED**: "Please contact examination staff." (also shown for no face / multiple faces)
   * **✗ NOT ELIGIBLE**: with the reason, e.g. *"School fees/payment requirement has not been satisfied."*
   * **⚠ ALREADY VERIFIED**: "This student has already been marked present for this examination."

### Manual fallback

When recognition fails (poor light, no enrolled face, camera fault), click **Manual verification** on the kiosk. Search by **student ID or name**, check the ID card, choose or type a reason, and click **Verify manually**. The **same eligibility and duplicate rules apply**. Attendance is stored with `verification_method = manual` and a `MANUAL_VERIFICATION` audit entry that includes the reason.

### Example JSON

```json
{"success": true, "verified": true, "reason": "VERIFIED",
 "student": {"id": "SWE/2026/001", "name": "Auwal Muhammad", ...},
 "exam": {"course_code": "SWE401", "course_title": "Software Architecture and Design", ...},
 "attendance": {"status": "present", "recorded": true, "time": "07 Oct 2026, 9:02 AM"},
 "confidence": 0.7314, "distance": 37.6}

{"success": false, "verified": false, "reason": "FACE_NOT_RECOGNIZED", "message": "Please contact examination staff."}
{"success": false, "verified": false, "reason": "NOT_ELIGIBLE", "message": "School fees/payment requirement has not been satisfied."}
{"success": false, "verified": false, "reason": "ALREADY_VERIFIED", "message": "This student has already been marked present for this examination."}
```

`recognize.py` output (what PHP receives):

```json
{"success": true, "matched": true, "student_id": "SWE/2026/001", "user_id": 2, "distance": 37.6, "confidence": 0.7314, "threshold": 70.0}
{"success": false, "matched": false, "reason": "NO_MATCH", "message": "No matching face found", "distance": 96.2, "confidence": 0.3129, "threshold": 70.0}
```

---

## 16. Admin login

| Field | Value |
|---|---|
| URL | <http://localhost/exam-verification-system/> |
| E-mail | `admin@buk.edu.ng` |
| Password | `Admin@123` |

## 17. Student login

Students log in with their **student ID** *or* e-mail. All demo students use the password **`Student@123`**.

| Student ID | Name | Demo situation for SWE401 (today) |
|---|---|---|
| `SWE/2026/001` | Auwal Muhammad | Paid & eligible, not verified yet → **use for the success test** |
| `SWE/2026/002` | Aisha Bello | Paid & eligible, not verified yet |
| `SWE/2026/003` | Ibrahim Musa | **School fees NOT paid** → use for the *not eligible* test |
| `SWE/2026/004` | Fatima Abdullahi | **Already verified** today → use for the *already verified* test |
| `SWE/2026/005` | Chinedu Okafor | Eligible; has a **pending complaint** (marked absent for SWE405) |

Demo exams: **SWE401** Software Architecture and Design (today, ongoing, kiosk exam), **SWE403** Software Testing and Quality Assurance (in 3 days, scheduled), **SWE405** Software Project Management (5 days ago, completed).

> No faces are seeded, because biometric data has to come from a real camera. Enroll yourself as a demo student before testing recognition.
> **Change all demo passwords** before any real use.

---

## 18. Demo workflow

A 10-minute walkthrough covering the whole system:

1. **Import** `schema.sql` and `seed.sql`; start Apache and MySQL.
2. **Admin login** → the dashboard shows live counts and "Kiosk examination: SWE401".
3. **Enroll a face:** *Students* → `SWE/2026/001` → **Enroll face** → Start camera → **Capture & Enroll**. Expect "Face enrolled with N sample(s). Recognition model updated."
4. **(Optional) enroll a second person** as `SWE/2026/003` (unpaid) to test the *not eligible* path, and someone as `SWE/2026/004` to test *already verified*.
5. **Verification Kiosk** → stand in front of the camera → **VERIFY STUDENT**. Expect ✓ *VERIFICATION SUCCESSFUL*.
6. Click **VERIFY STUDENT** again. Expect ⚠ *ALREADY VERIFIED* (no duplicate record).
7. Point the camera at someone who is not enrolled. Expect ✗ *STUDENT NOT RECOGNIZED*.
8. Verify the person enrolled as `SWE/2026/003`. Expect ✗ *NOT ELIGIBLE: School fees/payment requirement has not been satisfied.*
9. **Payments & Eligibility** → SWE401 → switch **School fees** on for `SWE/2026/003` → verify again. Expect ✓.
10. **Manual verification** on the kiosk → search "Aisha" → *Verify manually*. Expect ✓ with method *Manual*.
11. Log out, then log in as **`SWE/2026/001` / `Student@123`** → *My Attendance* shows SWE401 **Present**. *Timetable* shows SWE401/SWE403. Submit a **complaint**.
12. Log in as admin → **Complaints** → *Review* → respond and **Resolve**. The student sees the new status and response.
13. **Attendance** → filter by SWE401 → **Correct** a record (a reason is required) → check **Audit Logs**.

---

## 19. Troubleshooting

| Symptom | Fix |
|---|---|
| "The database is unavailable…" | Start MySQL in XAMPP; import `schema.sql` + `seed.sql`; check `DB_*` in `config/config.php`. |
| Login says "Invalid credentials" for demo users | Re-import `database/seed.sql`. After 5 failed attempts from one IP, login is locked for 15 minutes (delete the `LOGIN_FAILED` rows in `audit_logs` to reset during testing). |
| **"Could not start Python"** / "Python was not found" | Create the venv in the project folder ([§9](#9-python-virtual-environment)) or set a full `PYTHON_PATH`. Disable the Windows Store `python.exe` alias. Restart Apache. |
| "Required Python packages are missing" | `venv\Scripts\activate` then `pip install -r facial_recognition\requirements.txt`. Make sure PHP uses the **same** interpreter (Settings → Test Python / OpenCV shows `python_executable`). |
| `lbph_available: false` / "cv2.face… unavailable" | `pip uninstall -y opencv-python opencv-contrib-python` then `pip install opencv-contrib-python`. |
| Camera does not start / "Camera API unavailable" | Use `http://localhost/...` or HTTPS ([§13](#13-running-the-project)); allow camera permission (camera icon in the address bar); close other apps using the camera (Teams, Zoom). |
| Enrollment: "Only N of 8 frames contained one clear face" | Hold still during capture (movement = blur). If it says *too blurry* although the student was still, the camera is soft: lower `ENROLL_MIN_SHARPNESS` in `config/config.php` to just below the "Measured sharpness" shown in the message. Otherwise: Improve front lighting, remove backlight (windows behind the student), move closer, make sure only one person is visible, hold still. |
| Always "STUDENT NOT RECOGNIZED" | Enroll in the **same location/lighting** as the kiosk; check that the model is trained (Settings); raise the threshold slightly (e.g. 75–80) after checking distances in the audit log. |
| Wrong student recognised | **Lower** the threshold, re-enroll both students with better images, and always keep staff supervision. |
| "Verification is only allowed while the examination is ongoing" | Settings → choose the exam with *Also set status to Ongoing*, or Examinations → More → *Mark as Ongoing*. |
| "Face processing timed out" | The first OpenCV run can be slow on old PCs or with antivirus scanning. Increase `PYTHON_TIMEOUT`. |
| Blank page / 500 error | Check `logs/php-error.log` (technical errors are logged there and never shown to users). Set `APP_DEBUG` to `true` temporarily. |
| CSS/JS missing (unstyled page) | Make sure the project path in `htdocs` is correct. If you use a custom virtual host, set `BASE_URL` (e.g. `''` or `/exam-verification-system`). |
| `Forbidden` on all pages | Your Apache config might not allow `.htaccess`. XAMPP's default `AllowOverride All` works. Otherwise enable it for `htdocs`. |

---

## 20. Security notes

Implemented:

* **SQL injection:** every query uses PDO prepared statements (`ATTR_EMULATE_PREPARES = false`). Dynamic parts of `WHERE` clauses are built only from fixed SQL fragments with bound parameters.
* **XSS:** all output goes through `e()` (`htmlspecialchars`, ENT_QUOTES, UTF-8), and JS inserts text via `EVS.escape()`.
* **CSRF:** a per-session token is required on every POST form and AJAX call (`X-CSRF-Token` header), compared with `hash_equals`. Logout is POST + CSRF.
* **Passwords:** `password_hash()` / `password_verify()` with automatic rehash. Plaintext passwords are never stored.
* **Sessions:** `session_regenerate_id(true)` on login (prevents session fixation), HttpOnly + SameSite=Lax cookies, strict mode, 30-minute idle timeout, and the account is re-checked on each request (deactivated users are logged out).
* **Authorisation:** `requireLogin()`, `requireAdmin()`, `requireStudent()` on every page/API. Students only ever see their own data.
* **Brute force:** at most 5 failed logins per IP per 15 minutes (tracked in `audit_logs`).
* **Uploads:** size limit (2 MB/frame), real MIME type via `finfo` (JPEG/PNG only), `getimagesize` dimension checks, `is_uploaded_file`, random file names (`random_bytes`). Temporary frames are deleted right after processing.
* **Biometric files:** `uploads/` is blocked by `.htaccess` (`Require all denied`). Admins view face samples through the authenticated `admin/face-image.php`. Faces are stored as files, not BLOBs, with unguessable names.
* **No shell injection:** Python is started with `proc_open()` using an **argument array** (no shell), with a timeout.
* **Direct access:** `config/`, `includes/`, `database/`, `facial_recognition/`, `logs/`, `uploads/` are denied by `.htaccess`. Include files exit unless loaded through the bootstrap. `.sql`, `.py`, `.md`, `.log`, `.json` and dot-files are blocked site-wide.
* **Errors:** `display_errors = 0`. Exceptions are logged to `logs/php-error.log`, and users see friendly Bootstrap alerts (or JSON on APIs).
* **Headers:** `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` (camera only from this site).
* **Audit trail:** verifications, eligibility rejections, manual verifications, attendance corrections (with mandatory reason), student/exam/complaint/settings changes.
* **CSV export:** cells that start with `= + - @` are prefixed to prevent spreadsheet formula injection.

Recommended before production: HTTPS, change all demo passwords, a dedicated MySQL user with least privilege, disable student self-enrollment, regular backups, a data-protection/consent policy for biometric data (e.g. NDPR/GDPR), and log retention rules.

---

## 21. Known MVP limitations

This is an **academic prototype**. OpenCV Haar + LBPH is a classical technique and **does not provide perfect identity verification**.

* **Lighting:** LBPH is sensitive to strong side light and backlight. Enroll and verify under similar, even lighting.
* **Camera quality:** low-resolution or noisy webcams reduce accuracy.
* **Pose:** the Haar cascade detects frontal faces only. Turned heads, glasses glare, masks, caps and hijab style changes can cause failures.
* **Multiple faces:** the kiosk rejects frames with more than one face. Queue control is still needed.
* **Spoofing:** there is **no liveness detection**. A photo or video of an enrolled student could be accepted. Invigilators must watch the kiosk.
* **Threshold trade-off:** no threshold eliminates both false accepts and false rejects. 70 is a starting point and must be tuned on site.
* **Similar appearance / twins:** LBPH cannot reliably tell very similar faces apart.
* **Closed-set behaviour:** LBPH always returns the *nearest* enrolled student. Only the distance threshold separates "unknown" from "known", so with very few enrolled students, strangers may sometimes fall under the threshold.
* **Scale:** the model is retrained from all samples on every enrollment. That is fine for hundreds of students, but large cohorts would need incremental training or a modern embedding model (e.g. a DNN such as SFace/ArcFace).
* **Biometric privacy:** face images are personal data. This MVP stores them on the server's disk with access controls, but has no encryption at rest or automatic retention/deletion policy.
* **Human fallback is mandatory:** staff must stay at the entrance and use manual verification (ID card) whenever recognition fails or seems wrong.
* **Payments** are confirmed manually by staff. There is no real payment gateway integration.
* **Single kiosk exam:** one examination is active for verification at a time (multiple halls would need one setting per kiosk/venue).
* **Camera on remote PCs** requires HTTPS (browser security rule).

---

## 22. Project structure

```
exam-verification-system/
├── index.php                  Entry point (redirects by role)
├── .htaccess                  Apache hardening
├── config/                    config.php (settings), database.php (PDO)  [web-denied]
├── includes/                  bootstrap, auth, csrf, functions, header/sidebar/footer,
│                              verification rules, python bridge, student helpers   [web-denied]
├── auth/                      login.php, logout.php, register.php (admin registers students)
├── admin/                     dashboard, students, student-view, enroll-face, face-image,
│                              examinations, examination-create, payments, attendance,
│                              complaints, users, audit-logs, settings
├── student/                   dashboard, timetable, examinations, attendance, complaints, profile
├── verification/              index.php (kiosk), verify.php (face API), attendance.php (manual API),
│                              result.php (live log)
├── api/                       students, examinations, attendance, payments, complaints, enroll
├── facial_recognition/        face_utils.py, capture.py, enroll.py, recognize.py, selftest.py,
│                              requirements.txt, models/ (trained LBPH model)   [web-denied]
├── assets/                    css/ (style, admin, student), js/ (main, admin, enroll, verification),
│                              images/, vendor/ (Bootstrap 5.3.3 + Bootstrap Icons, offline)
├── uploads/faces/             enrolled face crops (random names)              [web-denied]
├── uploads/tmp/               short-lived temp frames                          [web-denied]
├── logs/                      php-error.log                                    [web-denied]
└── database/                  schema.sql, seed.sql                             [web-denied]
```

---

## 23. Acceptance test checklist

| # | Scenario | How to test | Expected |
|---|---|---|---|
| 1 | Admin logs in | `admin@buk.edu.ng` / `Admin@123` | Admin dashboard |
| 2 | Student logs in | `SWE/2026/001` / `Student@123` | Student dashboard |
| 3 | Admin creates student | Students → Add Student | Appears in student list |
| 4 | Admin creates examination | Examinations → Create (dept *Software Engineering*, level *400*) | Appears in the students' timetable |
| 5 | Admin marks student eligible | Payments & Eligibility → toggle *School fees* | Result shows ELIGIBLE; kiosk accepts the student |
| 6 | Facial enrollment | Students → 📷 → Capture & Enroll | Face detected, samples saved, *Face ID registered* |
| 7 | Correct student verifies | Kiosk → VERIFY | Recognised, eligibility checked, attendance recorded |
| 8 | Verify twice | Kiosk → VERIFY again | ⚠ ALREADY VERIFIED; still one attendance row |
| 9 | Unrecognised face | Non-enrolled person at kiosk | ✗ STUDENT NOT RECOGNIZED |
| 10 | Not eligible | Enrolled `SWE/2026/003` (unpaid) at kiosk | ✗ NOT ELIGIBLE + reason |
| 11 | Student timetable | Student → Exam Timetable | Correct exams listed |
| 12 | Student attendance | Student → My Attendance | Verified exam shows *Present* |
| 13 | Student complaint | Student → Complaints → Submit | Visible in Admin → Complaints / dashboard |
| 14 | Admin resolves complaint | Admin → Complaints → Review → Resolved | Student sees *Resolved* + response |
| 15 | Manual verification | Kiosk → Manual verification | Attendance with method *Manual* + audit log |

---

*Academic MVP. Built with PHP, MySQL, Bootstrap, Python and OpenCV for XAMPP.*
