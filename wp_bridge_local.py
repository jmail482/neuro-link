"""
wp_bridge_local.py — Zeus WP Bridge v1.3
v1.3: Added POST /git/status, /git/add, /git/commit, /git/push
"""
import os, shutil, subprocess
from pathlib import Path
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Optional, List
import requests

app = FastAPI(title="Zeus WP Bridge", version="1.3.0")

WP_BASE_URL  = os.getenv("WP_BASE_URL",  "http://survmail.test")
WP_USERNAME  = os.getenv("WP_USERNAME",  "survmail")
WP_APP_PASS  = os.getenv("WP_APP_PASSWORD", "5IGHCVc7DycwQRFLWRfZjYKj")
WP_ROOT      = Path(os.getenv("WP_LOCAL_ROOT", r"C:\laragon\www\survmail"))
DRY_RUN      = os.getenv("DRY_RUN", "false").lower() == "true"

APPROVED_ROOTS = [
    Path(r"C:\Users\jfnfi\Documents\AI"),
    Path(r"C:\Users\jfnfi\Desktop"),
    Path(r"C:\Users\jfnfi\Documents"),
    Path(r"C:\laragon\www"),
]

def wp_auth():
    if not WP_APP_PASS:
        raise HTTPException(503, "WP_APP_PASSWORD not set")
    return (WP_USERNAME, WP_APP_PASS)

def resolve_path(p: str) -> Path:
    raw = Path(p)
    if raw.is_absolute():
        resolved = raw.resolve()
        for root in APPROVED_ROOTS:
            if str(resolved).startswith(str(root.resolve())):
                return resolved
        raise HTTPException(403, f"Path outside approved roots: {p}")
    resolved = (WP_ROOT / p).resolve()
    if not str(resolved).startswith(str(WP_ROOT.resolve())):
        raise HTTPException(403, f"Path escapes WP root: {p}")
    return resolved

def resolve_repo(p: str) -> Path:
    resolved = resolve_path(p)
    if not resolved.exists():
        raise HTTPException(404, f"Repo path not found: {p}")
    if not resolved.is_dir():
        raise HTTPException(400, f"Repo path is not a directory: {p}")
    return resolved

def run_git(args: list, cwd: Path) -> dict:
    result = subprocess.run(
        ["git"] + args,
        cwd=str(cwd),
        capture_output=True,
        text=True
    )
    return {
        "repo_path": str(cwd),
        "stdout": result.stdout.strip(),
        "stderr": result.stderr.strip(),
        "returncode": result.returncode
    }

class FileReadReq(BaseModel):
    path: str

class FileWriteReq(BaseModel):
    path: str
    content: str
    backup: bool = True

class FileReplaceReq(BaseModel):
    path: str
    old_text: str
    new_text: str
    backup: bool = True

class FileListReq(BaseModel):
    path: str = "wp-content/plugins/neuro-link"

class DirListReq(BaseModel):
    path: str

class GitRepoReq(BaseModel):
    repo_path: str

class GitAddReq(BaseModel):
    repo_path: str
    paths: List[str]

class GitCommitReq(BaseModel):
    repo_path: str
    message: str

class PostGetReq(BaseModel):
    post_id: int

class PostEditReq(BaseModel):
    post_id: int
    title: Optional[str] = None
    content: Optional[str] = None
    status: Optional[str] = None

@app.get("/health")
def health():
    return {"status": "ok", "wp_base_url": WP_BASE_URL, "wp_local_root": str(WP_ROOT),
            "root_exists": WP_ROOT.exists(), "dry_run": DRY_RUN, "auth_configured": bool(WP_APP_PASS),
            "approved_roots": [str(r) for r in APPROVED_ROOTS], "version": "1.3.0"}

@app.get("/capabilities")
def capabilities():
    return {"endpoints": [
        "GET /health","GET /capabilities",
        "POST /file/read","POST /file/write","POST /file/list","POST /file/replace",
        "POST /dir/list",
        "POST /git/status","POST /git/add","POST /git/commit","POST /git/push"
    ]}

@app.post("/file/read")
def file_read(req: FileReadReq):
    p = resolve_path(req.path)
    if not p.exists():
        raise HTTPException(404, f"Not found: {req.path}")
    if p.is_dir():
        return {"error": "Path is a directory. Use /dir/list."}
    return {"path": req.path, "content": p.read_text(encoding="utf-8", errors="replace")}

@app.post("/dir/list")
def dir_list(req: DirListReq):
    p = resolve_path(req.path)
    if not p.exists():
        raise HTTPException(404, f"Not found: {req.path}")
    if not p.is_dir():
        raise HTTPException(400, f"Path is a file, not a directory: {req.path}")
    entries = []
    for item in sorted(p.iterdir(), key=lambda x: (x.is_file(), x.name.lower())):
        entries.append({"name": item.name, "type": "file" if item.is_file() else "dir"})
    return {"path": req.path, "entries": entries}

@app.post("/file/list")
def file_list(req: FileListReq):
    p = resolve_path(req.path)
    if not p.exists():
        raise HTTPException(404, f"Not found: {req.path}")
    if not p.is_dir():
        raise HTTPException(400, f"Path is a file: {req.path}")
    entries = [{"path": str(i), "type": "file" if i.is_file() else "dir"} for i in sorted(p.rglob("*"))]
    return {"base": req.path, "entries": entries}

@app.post("/file/write")
def file_write(req: FileWriteReq):
    p = resolve_path(req.path)
    if req.backup and p.exists() and not DRY_RUN:
        shutil.copy2(p, str(p) + ".bak")
    if DRY_RUN:
        return {"dry_run": True, "would_write": req.path, "bytes": len(req.content.encode())}
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(req.content, encoding="utf-8")
    return {"written": str(p), "bytes": len(req.content.encode())}

@app.post("/file/replace")
def file_replace(req: FileReplaceReq):
    p = resolve_path(req.path)
    if not p.exists():
        raise HTTPException(404, f"Not found: {req.path}")
    if p.is_dir():
        raise HTTPException(400, f"Path is a directory: {req.path}")
    original = p.read_text(encoding="utf-8", errors="replace")
    if req.old_text not in original:
        raise HTTPException(400, "old_text not found in file")
    count = original.count(req.old_text)
    replaced = original.replace(req.old_text, req.new_text)
    if req.backup and not DRY_RUN:
        shutil.copy2(p, str(p) + ".bak")
    if DRY_RUN:
        return {"dry_run": True, "would_replace": count, "path": req.path}
    p.write_text(replaced, encoding="utf-8")
    return {"replaced": count, "path": str(p)}

@app.post("/git/status")
def git_status(req: GitRepoReq):
    return run_git(["status"], resolve_repo(req.repo_path))

@app.post("/git/add")
def git_add(req: GitAddReq):
    return run_git(["add"] + req.paths, resolve_repo(req.repo_path))

@app.post("/git/commit")
def git_commit(req: GitCommitReq):
    return run_git(["commit", "-m", req.message], resolve_repo(req.repo_path))

@app.post("/git/push")
def git_push(req: GitRepoReq):
    return run_git(["push"], resolve_repo(req.repo_path))

@app.post("/post/get")
def post_get(req: PostGetReq):
    r = requests.get(f"{WP_BASE_URL}/wp-json/wp/v2/posts/{req.post_id}", auth=wp_auth(), timeout=10)
    if r.status_code == 404:
        r = requests.get(f"{WP_BASE_URL}/wp-json/wp/v2/pages/{req.post_id}", auth=wp_auth(), timeout=10)
    r.raise_for_status()
    d = r.json()
    return {"id": d.get("id"), "type": d.get("type"), "status": d.get("status"),
            "title": d.get("title", {}).get("rendered"),
            "content_preview": (d.get("content", {}).get("rendered", "") or "")[:500]}

@app.post("/post/edit")
def post_edit(req: PostEditReq):
    payload = {}
    if req.title:   payload["title"]   = req.title
    if req.content: payload["content"] = req.content
    if req.status:  payload["status"]  = req.status
    if not payload:
        raise HTTPException(400, "Nothing to update")
    if DRY_RUN:
        return {"dry_run": True, "would_update": req.post_id, "fields": list(payload.keys())}
    r = requests.post(f"{WP_BASE_URL}/wp-json/wp/v2/posts/{req.post_id}", auth=wp_auth(), json=payload, timeout=10)
    if r.status_code == 404:
        r = requests.post(f"{WP_BASE_URL}/wp-json/wp/v2/pages/{req.post_id}", auth=wp_auth(), json=payload, timeout=10)
    r.raise_for_status()
    d = r.json()
    return {"updated": d.get("id"), "status": d.get("status"), "title": d.get("title", {}).get("rendered")}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8787)
