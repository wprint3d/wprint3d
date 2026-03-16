#!/usr/bin/env python3

from __future__ import annotations

import argparse
import os
import subprocess
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[1]
VERIFY_SCRIPT = REPO_ROOT / "scripts" / "plugin_verify_signature.py"


def default_output_path(plugin_path: Path) -> Path:
    return plugin_path / "builds" / f"{plugin_path.name}.w3dp"


def default_public_key_path(package_path: Path) -> Path:
    return package_path.with_suffix(".pub.pem")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Build and sign a WPrint 3D plugin package from a full source checkout.",
    )
    parser.add_argument("plugin_path", help="Path to the plugin source directory")
    parser.add_argument("--private-key", required=True, help="Path to the PEM private key")
    parser.add_argument("--output", help="Optional output .w3dp path")
    parser.add_argument("--passphrase", help="Optional private key passphrase")
    parser.add_argument(
        "--write-public-key",
        help="Write the embedded public key to this PEM path after signing",
    )
    parser.add_argument(
        "--previous-package",
        help="Optional previous release .w3dp used to confirm public-key continuity",
    )

    return parser


def main() -> int:
    args = build_parser().parse_args()

    plugin_path = Path(args.plugin_path).resolve()
    private_key = Path(args.private_key).resolve()
    output_path = Path(args.output).resolve() if args.output else default_output_path(plugin_path)
    public_key_output = (
        Path(args.write_public_key).resolve()
        if args.write_public_key
        else default_public_key_path(output_path)
    )

    if not plugin_path.is_dir():
        print(f"Plugin directory does not exist: {plugin_path}", file=sys.stderr)
        return 1

    if not private_key.is_file():
        print(f"Private key does not exist: {private_key}", file=sys.stderr)
        return 1

    pack_command = [
        "php",
        "artisan",
        "plugin:pack",
        str(plugin_path),
        f"--output={output_path}",
        f"--signing-key={private_key}",
    ]

    if args.passphrase:
        pack_command.append(f"--passphrase={args.passphrase}")

    php_env = os.environ.copy()
    php_env.setdefault("CACHE_DRIVER", "array")
    php_env.setdefault("LOG_CHANNEL", "stderr")

    pack_result = subprocess.run(pack_command, cwd=REPO_ROOT, check=False, env=php_env)

    if pack_result.returncode != 0:
        return pack_result.returncode

    verify_command = [
        sys.executable,
        str(VERIFY_SCRIPT),
        "verify",
        str(output_path),
        f"--write-public-key={public_key_output}",
    ]

    if args.previous_package:
        verify_command.append(f"--previous-package={Path(args.previous_package).resolve()}")

    verify_result = subprocess.run(verify_command, cwd=REPO_ROOT, check=False)

    if verify_result.returncode != 0:
        return verify_result.returncode

    print(f"Signed package: {output_path}")
    print(f"Extracted public key: {public_key_output}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
