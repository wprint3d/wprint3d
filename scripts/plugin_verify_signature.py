#!/usr/bin/env python3

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any


def normalize_public_key(pem: str | None) -> str | None:
    if pem is None:
        return None

    normalized = pem.strip()

    if not normalized:
        return None

    return normalized + "\n"


def canonicalize(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: canonicalize(value[key]) for key in sorted(value)}

    if isinstance(value, list):
        return [canonicalize(item) for item in value]

    return value


def canonical_payload(manifest: dict[str, Any]) -> str:
    payload = dict(manifest)
    payload.pop("signature", None)

    return json.dumps(
        canonicalize(payload),
        indent=4,
        ensure_ascii=False,
    )


def read_manifest(package_path: Path) -> dict[str, Any]:
    with zipfile.ZipFile(package_path) as archive:
        try:
            raw_manifest = archive.read("plugin.json")
        except KeyError as exc:
            raise SystemExit(f"{package_path}: plugin.json is missing from the archive") from exc

    try:
        manifest = json.loads(raw_manifest.decode("utf-8"))
    except json.JSONDecodeError as exc:
        raise SystemExit(f"{package_path}: plugin.json is not valid JSON: {exc}") from exc

    if not isinstance(manifest, dict):
        raise SystemExit(f"{package_path}: plugin.json must contain a JSON object")

    return manifest


def public_key_sha256(public_key: str) -> str:
    return hashlib.sha256(normalize_public_key(public_key).encode("utf-8")).hexdigest()


def public_key_key_id(public_key: str) -> str:
    return hashlib.sha1(normalize_public_key(public_key).encode("utf-8")).hexdigest()


def verify_signature_with_public_key(manifest: dict[str, Any], public_key: str) -> bool:
    signature = manifest.get("signature")

    if not isinstance(signature, dict):
        return False

    encoded_signature = signature.get("value")

    if not isinstance(encoded_signature, str) or not encoded_signature:
        return False

    try:
        signature_bytes = base64.b64decode(encoded_signature, validate=True)
    except ValueError:
        return False

    with tempfile.TemporaryDirectory(prefix="wprint3d-signature-verify-") as temp_dir:
        temp_root = Path(temp_dir)
        payload_path = temp_root / "payload.json"
        signature_path = temp_root / "manifest.sig"
        public_key_path = temp_root / "signer.pub.pem"

        payload_path.write_text(canonical_payload(manifest), encoding="utf-8")
        signature_path.write_bytes(signature_bytes)
        public_key_path.write_text(normalize_public_key(public_key), encoding="utf-8")

        result = subprocess.run(
            [
                "openssl",
                "dgst",
                "-sha256",
                "-verify",
                str(public_key_path),
                "-signature",
                str(signature_path),
                str(payload_path),
            ],
            check=False,
            capture_output=True,
            text=True,
        )

    return result.returncode == 0


def write_public_key(public_key: str, destination: Path) -> Path:
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(normalize_public_key(public_key), encoding="utf-8")

    return destination


def inspect_package(package_path: Path) -> dict[str, Any]:
    manifest = read_manifest(package_path)
    signature = manifest.get("signature") or {}
    public_key = normalize_public_key(signature.get("publicKey"))
    fingerprint = public_key_sha256(public_key) if public_key else None

    return {
        "package": str(package_path),
        "pluginId": manifest.get("id"),
        "version": manifest.get("version"),
        "algorithm": signature.get("algorithm", "none"),
        "keyId": signature.get("keyId"),
        "publicKeySha256": signature.get("publicKeySha256") or fingerprint,
        "embeddedPublicKeyMatchesKeyId": public_key_key_id(public_key) == signature.get("keyId") if public_key else False,
        "hasEmbeddedPublicKey": public_key is not None,
        "manifest": manifest,
        "embeddedPublicKey": public_key,
    }


def run_inspect(args: argparse.Namespace) -> int:
    report = inspect_package(Path(args.package).resolve())

    if report["embeddedPublicKey"] and args.write_public_key:
        write_public_key(report["embeddedPublicKey"], Path(args.write_public_key).resolve())

    if args.json:
        print(
            json.dumps(
                {key: value for key, value in report.items() if key not in {"manifest", "embeddedPublicKey"}},
                indent=2,
                ensure_ascii=False,
            )
        )
    else:
        print(f"Package: {report['package']}")
        print(f"Plugin: {report['pluginId']} @ {report['version']}")
        print(f"Algorithm: {report['algorithm']}")
        print(f"Key ID: {report['keyId'] or 'n/a'}")
        print(f"Embedded public key: {'yes' if report['hasEmbeddedPublicKey'] else 'no'}")
        print(f"Public key SHA-256: {report['publicKeySha256'] or 'n/a'}")
        print(f"Key ID matches embedded key: {'yes' if report['embeddedPublicKeyMatchesKeyId'] else 'no'}")

    return 0


def run_verify(args: argparse.Namespace) -> int:
    report = inspect_package(Path(args.package).resolve())
    manifest = report["manifest"]
    signature = manifest.get("signature") or {}

    if signature.get("algorithm", "none") == "none":
        print("Package is unsigned.", file=sys.stderr)
        return 1

    public_key: str | None

    if args.public_key:
        public_key = normalize_public_key(Path(args.public_key).resolve().read_text(encoding="utf-8"))
    else:
        public_key = report["embeddedPublicKey"]

    if public_key is None:
        print("Package does not embed a public key and no --public-key was provided.", file=sys.stderr)
        return 1

    if args.write_public_key:
        write_public_key(public_key, Path(args.write_public_key).resolve())

    verified = verify_signature_with_public_key(manifest, public_key)

    previous_fingerprint = None
    previous_matches = None

    if args.previous_package:
        previous_report = inspect_package(Path(args.previous_package).resolve())
        previous_public_key = previous_report["embeddedPublicKey"]

        if previous_public_key is None:
            print("Previous package does not embed a public key, so continuity cannot be checked.", file=sys.stderr)
            return 1

        previous_fingerprint = public_key_sha256(previous_public_key)
        previous_matches = previous_fingerprint == public_key_sha256(public_key)

    if args.expected_public_key_sha256:
        expected_matches = public_key_sha256(public_key) == args.expected_public_key_sha256.strip().lower()
    else:
        expected_matches = None

    if args.json:
        payload = {
            "package": report["package"],
            "pluginId": report["pluginId"],
            "version": report["version"],
            "verified": verified,
            "keyId": signature.get("keyId"),
            "publicKeySha256": public_key_sha256(public_key),
            "previousPublicKeySha256": previous_fingerprint,
            "matchesPreviousReleaseKey": previous_matches,
            "matchesExpectedPublicKeySha256": expected_matches,
        }
        print(json.dumps(payload, indent=2, ensure_ascii=False))
    else:
        print(f"Package: {report['package']}")
        print(f"Plugin: {report['pluginId']} @ {report['version']}")
        print(f"Verified: {'yes' if verified else 'no'}")
        print(f"Key ID: {signature.get('keyId') or 'n/a'}")
        print(f"Public key SHA-256: {public_key_sha256(public_key)}")

        if previous_fingerprint is not None:
            print(f"Previous release public key SHA-256: {previous_fingerprint}")
            print(f"Matches previous release key: {'yes' if previous_matches else 'no'}")

        if expected_matches is not None:
            print(f"Matches expected public key SHA-256: {'yes' if expected_matches else 'no'}")

    if not verified:
        return 1

    if previous_matches is False:
        return 1

    if expected_matches is False:
        return 1

    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Inspect and verify WPrint 3D plugin package signatures.",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    inspect_parser = subparsers.add_parser("inspect", help="Print embedded signature metadata.")
    inspect_parser.add_argument("package", help="Path to the .w3dp package")
    inspect_parser.add_argument("--write-public-key", help="Write the embedded public key to this PEM path")
    inspect_parser.add_argument("--json", action="store_true", help="Print machine-readable JSON")
    inspect_parser.set_defaults(func=run_inspect)

    verify_parser = subparsers.add_parser("verify", help="Verify a package signature.")
    verify_parser.add_argument("package", help="Path to the .w3dp package")
    verify_parser.add_argument("--public-key", help="Verify against this PEM public key instead of the embedded key")
    verify_parser.add_argument("--previous-package", help="Compare the embedded key to the previous release package")
    verify_parser.add_argument("--expected-public-key-sha256", help="Require this public-key fingerprint")
    verify_parser.add_argument("--write-public-key", help="Write the verified public key to this PEM path")
    verify_parser.add_argument("--json", action="store_true", help="Print machine-readable JSON")
    verify_parser.set_defaults(func=run_verify)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
