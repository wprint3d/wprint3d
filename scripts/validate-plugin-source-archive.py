#!/usr/bin/env python3
"""Validate a release plugin source tarball before CI extracts it."""

from __future__ import annotations

import argparse
import sys
import tarfile
from pathlib import PurePosixPath

MAX_MEMBERS = 100_000
MAX_BYTES = 2 * 1024 * 1024 * 1024


def fail(message: str) -> "NoReturn":
    print(message, file=sys.stderr)
    raise SystemExit(1)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("archive")
    args = parser.parse_args()

    try:
        archive = tarfile.open(args.archive, mode="r:*")
    except (OSError, tarfile.TarError) as exc:
        fail(f"Unable to read plugin source archive: {exc}")

    with archive:
        members = archive.getmembers()
        if not members or len(members) > MAX_MEMBERS:
            fail("Plugin source archive has an invalid member count.")

        top_levels: set[str] = set()
        regular_bytes = 0
        manifests: list[str] = []
        for member in members:
            path = PurePosixPath(member.name)
            if path.is_absolute() or ".." in path.parts or not path.parts:
                fail(f"Plugin source archive contains an unsafe path: {member.name}")
            top_levels.add(path.parts[0])

            if member.issym() or member.islnk():
                fail(f"Plugin source archive contains a link: {member.name}")
            if member.isdir():
                continue
            if not member.isreg():
                fail(f"Plugin source archive contains an unsupported member: {member.name}")
            regular_bytes += max(0, member.size)
            if regular_bytes > MAX_BYTES:
                fail("Plugin source archive exceeds the size limit.")
            if path.name == "plugin.json":
                manifests.append(member.name)

        if len(top_levels) != 1:
            fail("Plugin source archive must contain one top-level directory.")
        top = next(iter(top_levels))
        if manifests != [f"{top}/plugin.json"]:
            fail("Plugin source archive must contain exactly one top-level plugin.json.")

    return 0


if __name__ == "__main__":
    main()
