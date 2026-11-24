#!/usr/bin/env python3
"""Remove on-disk file caches when files rows are inserted, updated or deleted.

Brand file copies under WEB_ROOT are removed on UPDATE/DELETE. Since 2026-11-11 (#15) every files change
(INSERT, UPDATE or DELETE) also clears that brand's automatic sitemap cache (lib/DwSitemap.php), so
/sitemap.xml is rebuilt on the next request instead of after DwSitemap::MAX_AGE (24 h).

Runs a short-lived loop (up to 30 cycles or 58 seconds), sleeping 1 second
between DB checks. Cron starts it each minute. Only one process per host,
tracked by /dev/shm/clear_cache/pid.
"""
from __future__ import annotations

import atexit
import os
import re
import shutil
import signal
import subprocess
import sys
import time
from pathlib import Path

import json


def _settings() -> dict:
    """Settings (refs #79): environment DW_* > files_cache_clean.json > single-host defaults.

    The hosted service keeps its values in files_cache_clean.json next to this script (or in the
    app checkout at /srv/app/html/infra/nodes/bin/). A self-host install sets DW_* in the environment.
    """
    conf: dict = {}
    for cand in (Path(__file__).resolve().with_name("files_cache_clean.json"),
                 Path("/srv/app/html/infra/nodes/bin/files_cache_clean.json")):
        try:
            conf = json.loads(cand.read_text(encoding="utf-8"))
            break
        except (OSError, ValueError):
            continue
    data_dir = os.environ.get("DW_DATA_DIR") or "/var/lib/app/data"
    out = {
        "web_root": os.environ.get("DW_WEB_ROOT") or conf.get("web_root") or "/var/www/html",
        "state_path": conf.get("state_path") or data_dir + "/files_cache_clean.state",
        "password_file": conf.get("password_file") or "",
        "db_host": os.environ.get("DW_DB_HOST") or conf.get("db_host") or "127.0.0.1",
        "db_user": os.environ.get("DW_DB_USER") or conf.get("db_user") or "dwalliance",
        "db_name": os.environ.get("DW_DB_NAME") or conf.get("db_name") or "dwalliance",
        "sitemap_caches": conf.get("sitemap_caches") or [data_dir + "/sitemap-cache"],
    }
    if os.environ.get("DW_DATA_DIR"):
        out["state_path"] = data_dir + "/files_cache_clean.state"
        out["sitemap_caches"] = [data_dir + "/sitemap-cache"]
    return out


_S = _settings()
WEB_ROOT = Path(_S["web_root"])
STATE_PATH = Path(_S["state_path"])
PID_DIR = Path("/dev/shm/clear_cache")
PID_PATH = PID_DIR / "pid"
PASSWORD_PATH = Path(_S["password_file"]) if _S["password_file"] else None
DB_HOST = _S["db_host"]
DB_USER = _S["db_user"]
DB_NAME = _S["db_name"]
# Automatic sitemap caches (/var/lib/app/data/sitemap-cache inside the app); the hosted service also
# lists its staging copy in files_cache_clean.json.
SITEMAP_CACHES = [Path(p) for p in _S["sitemap_caches"]]
RESERVED = {"lib", "files", "session", "account_setup", "internal-files"}
BRAND_RE = re.compile(r"^[A-Za-z0-9]{1,10}$")
SAFE_PART_RE = re.compile(r"^[A-Za-z0-9._-]+$")
MAX_CYCLES = 30
MAX_RUNTIME_SEC = 58
SLEEP_SEC = 1


def log(msg: str) -> None:
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def read_password() -> str:
    if os.environ.get("DW_DB_PASSWORD"):
        return os.environ["DW_DB_PASSWORD"]
    if PASSWORD_PATH is None:
        return ""
    return PASSWORD_PATH.read_text(encoding="utf-8").strip()


def mysql_query(sql: str) -> list[list[str]]:
    env = os.environ.copy()
    env["MYSQL_PWD"] = read_password()
    proc = subprocess.run(
        [
            "mysql",
            "-u" + DB_USER,
            "-h" + DB_HOST,
            "--batch",
            "--raw",
            "--skip-column-names",
            DB_NAME,
            "-e",
            sql,
        ],
        capture_output=True,
        text=True,
        env=env,
        timeout=30,
        check=False,
    )
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "mysql failed").strip().replace("\n", " ")[:300]
        raise RuntimeError(err)
    rows = []
    for line in proc.stdout.splitlines():
        if line == "":
            continue
        rows.append(line.split("\t"))
    return rows


def load_state() -> tuple[str, str]:
    if not STATE_PATH.exists():
        return "", ""
    text = STATE_PATH.read_text(encoding="utf-8").strip()
    if not text:
        return "", ""
    parts = text.split("\t", 1)
    if len(parts) == 1:
        return parts[0], ""
    return parts[0], parts[1]


def save_state(archived_at: str, history_id: str) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_PATH.with_suffix(".tmp")
    tmp.write_text("%s\t%s\n" % (archived_at, history_id), encoding="utf-8")
    tmp.replace(STATE_PATH)


def disk_path(brand: str, file_path: str, file_name: str) -> Path | None:
    brand = (brand or "").strip()
    name = (file_name or "").strip()
    path = (file_path or "").strip().strip("/")
    if not brand or not name:
        return None
    if not BRAND_RE.match(brand) or brand.lower() in RESERVED:
        return None
    if name in (".", "..") or "/" in name or "\\" in name or name.startswith("."):
        return None
    if not SAFE_PART_RE.match(name):
        return None
    parts = [brand]
    if path:
        for part in path.split("/"):
            if part in ("", ".", "..") or part.startswith(".") or not SAFE_PART_RE.match(part):
                return None
            parts.append(part)
    parts.append(name)
    target = WEB_ROOT.joinpath(*parts)
    try:
        target.resolve().relative_to(WEB_ROOT.resolve())
    except Exception:
        return None
    return target


def prune_empty(path: Path) -> None:
    try:
        cur = path.parent
        root = WEB_ROOT.resolve()
        while cur != root and root in cur.resolve().parents:
            try:
                cur.rmdir()
            except OSError:
                break
            cur = cur.parent
    except Exception:
        return


def remove_file(brand: str, file_path: str, file_name: str, action: str, history_id: str) -> bool:
    target = disk_path(brand, file_path, file_name)
    if target is None:
        log("skip unsafe path history_id=%s" % history_id)
        return False
    if not target.exists() or not target.is_file():
        return False
    try:
        target.unlink()
        prune_empty(target)
        log("removed %s action=%s id=%s" % (target, action, history_id))
        return True
    except OSError as exc:
        log("failed %s: %s" % (target, exc))
        return False


def clear_sitemap(brand: str) -> int:
    brand = (brand or "").strip()
    if not BRAND_RE.match(brand):
        return 0
    cleared = 0
    for root in SITEMAP_CACHES:
        target = root / brand
        if not target.is_dir() or target.is_symlink():
            continue
        try:
            target.resolve().relative_to(root.resolve())
        except Exception:
            continue
        try:
            shutil.rmtree(target)
            cleared += 1
        except OSError as exc:
            log("failed sitemap cache %s: %s" % (root, exc))
    return cleared


def pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    # Confirm it is this script, not a recycled pid.
    try:
        cmdline = Path("/proc/%d/cmdline" % pid).read_bytes().replace(b"\0", b" ").decode("utf-8", "ignore")
    except OSError:
        return False
    return "files_cache_clean.py" in cmdline


def clear_own_pid() -> None:
    try:
        if not PID_PATH.exists():
            return
        text = PID_PATH.read_text(encoding="utf-8").strip()
        if text.isdigit() and int(text) == os.getpid():
            PID_PATH.unlink()
    except OSError:
        return


def claim_pid() -> bool:
    PID_DIR.mkdir(parents=True, exist_ok=True)
    if PID_PATH.exists():
        try:
            old = int(PID_PATH.read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            old = 0
        if pid_alive(old):
            log("another cleaner is running pid=%d; exiting" % old)
            return False
        try:
            PID_PATH.unlink()
        except OSError:
            pass
    PID_PATH.write_text("%d\n" % os.getpid(), encoding="utf-8")
    atexit.register(clear_own_pid)
    return True


def process_once() -> int:
    archived_at, history_id = load_state()
    if not archived_at:
        rows = mysql_query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")
        now = rows[0][0] if rows else ""
        if not now:
            log("could not read NOW()")
            return 1
        save_state(now, "")
        log("initialized watermark %s" % now)
        return 0

    safe_at = archived_at.replace("'", "")
    safe_id = history_id.replace("'", "")
    if history_id:
        sql = (
            "SELECT history_id, action, brand, IFNULL(file_path,''), IFNULL(file_name,''), "
            "DATE_FORMAT(archived_at, '%%Y-%%m-%%d %%H:%%i:%%s') "
            "FROM files_history WHERE action IN ('INSERT','UPDATE','DELETE') AND "
            "(archived_at > '%s' OR (archived_at = '%s' AND history_id > '%s')) "
            "ORDER BY archived_at ASC, history_id ASC LIMIT 500"
            % (safe_at, safe_at, safe_id)
        )
    else:
        sql = (
            "SELECT history_id, action, brand, IFNULL(file_path,''), IFNULL(file_name,''), "
            "DATE_FORMAT(archived_at, '%%Y-%%m-%%d %%H:%%i:%%s') "
            "FROM files_history WHERE action IN ('INSERT','UPDATE','DELETE') AND archived_at > '%s' "
            "ORDER BY archived_at ASC, history_id ASC LIMIT 500"
            % safe_at
        )

    rows = mysql_query(sql)
    removed = 0
    last_at = archived_at
    last_id = history_id
    brands = set()
    for row in rows:
        if len(row) < 6:
            continue
        hid, action, brand, fpath, fname, at = row[:6]
        if action != "INSERT" and remove_file(brand, fpath, fname, action, hid):
            removed += 1
        brands.add(brand)
        last_at, last_id = at, hid
    sitemaps = sum(clear_sitemap(b) for b in brands)

    if rows:
        save_state(last_at, last_id)
        log("processed %d history rows, removed %d files, cleared %d sitemap caches" % (len(rows), removed, sitemaps))
    return 0


def main() -> int:
    if not claim_pid():
        return 0

    started = time.monotonic()
    cycles = 0
    while True:
        cycles += 1
        try:
            process_once()
        except Exception as exc:
            log("cycle error: %s" % exc)

        elapsed = time.monotonic() - started
        if cycles >= MAX_CYCLES or elapsed >= MAX_RUNTIME_SEC:
            log("exiting after %d cycles in %.1fs" % (cycles, elapsed))
            break
        time.sleep(SLEEP_SEC)

    clear_own_pid()
    return 0


if __name__ == "__main__":
    sys.exit(main())
