#!/usr/bin/env python3
"""Inventory and scan the six running Compose images; never scan runtime secrets.

Exit 1 means the scan did not complete. Findings are preserved and explicitly
reported separately; a successful execution is not a clean security assessment.
Run after `make init` on the isolated development/CI stack. Production images
must be scanned again when stage 12 prepares the actual deployment artifacts.
"""
import collections
import hashlib
import json
import os
import pathlib
import platform
import re
import subprocess
import tarfile
import tempfile
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUTPUT = pathlib.Path("/tmp/urlopy-security/trivy")
VERSION = "0.76.2"
SHA256 = "bc0fc726e017b8927a62b2d3531d7a84ee01769daf5c99ed866dced54089fdbf"
URL = f"https://github.com/aquasecurity/trivy/releases/download/v{VERSION}/trivy_{VERSION}_Linux-64bit.tar.gz"
SERVICES = ("php", "node", "nginx", "mysql", "redis", "rabbit")


def run(arguments, timeout=30):
    return subprocess.run(arguments, cwd=ROOT, capture_output=True, text=True,
                          check=True, timeout=timeout).stdout.strip()


def install_trivy(directory):
    if platform.system() != "Linux" or platform.machine() not in ("x86_64", "amd64"):
        raise RuntimeError("The pinned scanner artifact requires Linux x86_64.")
    archive = directory / "trivy.tar.gz"
    digest = hashlib.sha256()
    with urllib.request.urlopen(URL, timeout=60) as response, archive.open("wb") as output:
        while chunk := response.read(1024 * 1024):
            digest.update(chunk)
            output.write(chunk)
    if digest.hexdigest() != SHA256:
        raise RuntimeError("Pinned scanner archive checksum mismatch.")
    # Extract only the verified executable, without trusting archive paths.
    binary = directory / "trivy"
    with tarfile.open(archive) as package:
        member = package.getmember("trivy")
        if not member.isfile():
            raise RuntimeError("Scanner archive does not contain a regular binary.")
        source = package.extractfile(member)
        if source is None:
            raise RuntimeError("Scanner executable is unreadable.")
        with source, binary.open("wb") as output:
            while chunk := source.read(1024 * 1024):
                output.write(chunk)
    binary.chmod(0o700)
    if run([str(binary), "--version"]).splitlines()[0] != f"Version: {VERSION}":
        raise RuntimeError("Unexpected scanner version.")
    return binary


def main():
    OUTPUT.mkdir(parents=True, exist_ok=True)
    summary = {"scanner": f"Trivy {VERSION}", "archiveSha256": SHA256,
               "scope": "running isolated Compose images, not production sign-off",
               "execution": "incomplete", "assessment": "not_assessed", "images": []}
    failed = False
    try:
        if os.environ.get("CI") != "true":
            raise RuntimeError("CI=true is required for the isolated image scan.")
        with tempfile.TemporaryDirectory(prefix="urlopy-trivy-") as temporary:
            binary = install_trivy(pathlib.Path(temporary))
            for service in SERVICES:
                item = {"service": service, "execution": "incomplete"}
                summary["images"].append(item)
                try:
                    containers = run(["docker", "compose", "ps", "-q", service]).splitlines()
                    if len(containers) != 1:
                        raise RuntimeError("Expected exactly one running service container.")
                    # Read only the image ID, never container environment/credentials.
                    image = run(["docker", "inspect", "--format={{.Image}}", containers[0]])
                    item["imageId"] = image
                    destination = OUTPUT / f"{service}.json"
                    destination.unlink(missing_ok=True)
                    try:
                        run([str(binary), "--config", "", "--cache-dir", "/tmp/urlopy-trivy-cache",
                             "image", "--image-src", "docker", "--scanners", "vuln",
                             "--disable-telemetry", "--skip-version-check", "--no-progress",
                             "--ignorefile", "", "--pkg-types", "os,library",
                             "--severity", "UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL",
                             "--exit-code", "0", "--timeout", "5m",
                             "--format", "json", "--output", str(destination), image], timeout=330)
                    except subprocess.CalledProcessError as error:
                        # Only scanner stderr, never docker inspect output or application logs.
                        diagnostic = (error.stderr or "")[-16000:]
                        diagnostic = re.sub(r"(?i)(https?://)[^/\s@]+@", r"\1[redacted]@", diagnostic)
                        diagnostic = re.sub(r"(?i)(bearer\s+)\S+", r"\1[redacted]", diagnostic)
                        (OUTPUT / f"{service}.error.log").write_text(diagnostic)
                        raise
                    report = json.loads(destination.read_text())
                    if report.get("SchemaVersion") != 2 or not report.get("ArtifactName"):
                        raise RuntimeError("Scanner report is missing its schema or image identity.")
                    findings = [v for result in report.get("Results", [])
                                for v in result.get("Vulnerabilities", [])]
                    counts = dict(collections.Counter(v.get("Severity", "UNKNOWN") for v in findings))
                    item.update(execution="complete", findings=len(findings), bySeverity=counts,
                                withFix=sum(bool(v.get("FixedVersion")) for v in findings),
                                osEndOfLife=bool(report.get("Metadata", {}).get("OS", {}).get("EOSL")))
                    print(f"{service}: scan complete; vulnerability entries={len(findings)}; severity={counts}", flush=True)
                except (OSError, RuntimeError, ValueError, subprocess.SubprocessError) as error:
                    failed = True
                    # Do not emit subprocess output or image contents; reports preserve successful scans.
                    item["errorClass"] = type(error).__name__
                    print(f"{service}: SCAN EXECUTION FAILED ({type(error).__name__}); not a clean result", flush=True)
    except (OSError, RuntimeError, ValueError, tarfile.TarError, subprocess.SubprocessError) as error:
        failed = True
        summary["errorClass"] = type(error).__name__
        print(f"Scanner setup failed ({type(error).__name__}); not a clean result", flush=True)
    metadata = pathlib.Path("/tmp/urlopy-trivy-cache/db/metadata.json")
    if metadata.is_file():
        summary["databaseMetadata"] = json.loads(metadata.read_text())
    summary["execution"] = "failed" if failed else "complete"
    if not failed:
        summary["assessment"] = ("findings_require_review" if any(
            item.get("findings") or item.get("osEndOfLife") for item in summary["images"])
            else "no_reported_vulnerabilities")
    (OUTPUT / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(f"Container scan execution: {summary['execution']}; assessment: {summary['assessment']}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
