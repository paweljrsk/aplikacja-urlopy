#!/usr/bin/env python3
"""Bounded secret-pattern and browser-sink scan. Never prints matched values.

This is a deterministic repository check, not a general secret detector or pentest.
Scans tracked working files and, with --history, every reachable historical blob.
"""
import argparse
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
RULES = {
    "private-key": re.compile(rb"-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----"),
    "github-token": re.compile(rb"\b(?:gh[pousr]_[A-Za-z0-9]{30,255}|github_pat_[A-Za-z0-9_]{50,255})\b"),
    "aws-access-key": re.compile(rb"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b"),
    "slack-token": re.compile(rb"\bxox[baprs]-[A-Za-z0-9-]{20,255}\b"),
    "openai-token": re.compile(rb"\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{40,255}\b"),
}
SINKS = re.compile(rb"dangerouslySetInnerHTML|\.innerHTML\s*=|\beval\s*\(|\bnew\s+Function\s*\(|\bdocument\.write\s*\(")


def git(*arguments):
    return subprocess.check_output(["git", *arguments], cwd=ROOT)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--history", action="store_true")
    args = parser.parse_args()
    findings = []
    files = 0
    blobs = 0

    def scan(data, source):
        for name, expression in RULES.items():
            if expression.search(data):
                findings.append({"rule": name, "source": source})

    for raw in git("ls-files", "-z").split(b"\0"):
        if not raw:
            continue
        path = pathlib.Path(raw.decode())
        target = ROOT / path
        if not target.is_file():
            continue
        data = target.read_bytes()
        files += 1
        scan(data, path.as_posix())
        if path.as_posix().startswith(("frontend/app/", "frontend/lib/")) and path.suffix in {".js", ".jsx", ".ts", ".tsx"} and SINKS.search(data):
            findings.append({"rule": "browser-html-or-code-sink-needs-review", "source": path.as_posix()})

    if args.history:
        if git("rev-parse", "--is-shallow-repository").strip() == b"true":
            raise RuntimeError("History scan requires a full checkout (fetch-depth: 0).")
        objects = git("rev-list", "--objects", "HEAD").splitlines()
        # Batch reads avoid one process per historical file. Object IDs and paths
        # are metadata only; neither matched content nor secrets reach stdout.
        process = subprocess.Popen(["git", "cat-file", "--batch"], cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        assert process.stdin is not None and process.stdout is not None
        try:
            for item in objects:
                oid = item.split(b" ", 1)[0]
                process.stdin.write(oid + b"\n")
                process.stdin.flush()
                header = process.stdout.readline().split()
                if len(header) != 3:
                    raise RuntimeError("Unreadable Git object.")
                data = process.stdout.read(int(header[2]))
                if process.stdout.read(1) != b"\n":
                    raise RuntimeError("Incomplete Git object.")
                if header[1] == b"blob":
                    blobs += 1
                    scan(data, "history-blob:" + oid[:12].decode())
        finally:
            process.stdin.close()
            process.wait()
        if process.returncode != 0:
            raise RuntimeError("Git object scan failed.")

    print(f"Checked {files} tracked files and {blobs} historical blobs; {len(RULES)} high-confidence secret patterns plus browser source sinks.")
    for finding in findings:
        print(f"Finding: {finding['rule']} in {finding['source']} (matched value redacted).")
    if findings:
        return 1
    print("PASS bounded source scan: no matching secrets or executable HTML/code sinks. Generic passwords and unknown token formats require manual review.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
