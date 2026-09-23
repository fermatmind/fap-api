#!/usr/bin/env python3
"""Validate and extract the exact Career package without trusting tar metadata."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import tarfile


HEX40 = re.compile(r"[0-9a-f]{40}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")


def require(condition, code):
    if not condition:
        raise ValueError(code)


def normalized(name):
    require(isinstance(name, str) and name and not name.startswith("/"), "PACKAGE_PATH_INVALID")
    require("\\" not in name and "\x00" not in name, "PACKAGE_PATH_INVALID")
    if name in (".", "./"):
        return ""
    require(name.startswith("./"), "PACKAGE_ROOT_INVALID")
    parts = name[2:].rstrip("/").split("/")
    require(all(part not in ("", ".", "..") for part in parts), "PACKAGE_PATH_INVALID")
    return "/".join(parts)


def extract(archive, destination, archive_sha, base, head, tree):
    for value in (base, head, tree):
        require(HEX40.fullmatch(value), "PACKAGE_GIT_BINDING_INVALID")
    require(HEX64.fullmatch(archive_sha), "PACKAGE_ARCHIVE_SHA_INVALID")
    digest = hashlib.sha256()
    with archive.open("rb") as source:
        while chunk := source.read(1024 * 1024):
            digest.update(chunk)
    require(digest.hexdigest() == archive_sha, "PACKAGE_ARCHIVE_HASH_MISMATCH")
    require(not destination.exists(), "PACKAGE_DESTINATION_EXISTS")

    with tarfile.open(archive, "r:gz") as bundle:
        members = bundle.getmembers()
        names = {}
        root_count = 0
        for member in members:
            path = normalized(member.name)
            if path == "":
                root_count += 1
                require(member.isdir(), "PACKAGE_ROOT_INVALID")
                continue
            require(path not in names, "PACKAGE_DUPLICATE_ENTRY")
            require(member.isdir() or member.isfile(), "PACKAGE_LINK_OR_TYPE_FORBIDDEN")
            require(path in ("binding.json", "projection-index.json", "payload")
                    or path.startswith("payload/"),
                    "PACKAGE_FILE_SET_INVALID")
            names[path] = member
        require(root_count == 1, "PACKAGE_ROOT_INVALID")
        binding_member = names.get("binding.json")
        require(binding_member is not None and binding_member.isfile(), "PACKAGE_BINDING_MISSING")
        binding = json.load(bundle.extractfile(binding_member))
        require(binding.get("schema_version") == "fermatmind.career-content-package.v1"
                and binding.get("base_sha") == base and binding.get("head_sha") == head
                and binding.get("candidate_tree_sha") == tree and binding.get("no_deletions") is True,
                "PACKAGE_BINDING_INVALID")
        files = binding.get("files")
        require(isinstance(files, list) and len(files) == binding.get("payload_file_count")
                and len(files) >= 3, "PACKAGE_BINDING_FILES_INVALID")
        expected = {"binding.json", "projection-index.json"}
        for record in files:
            path = record.get("path") if isinstance(record, dict) else None
            require(isinstance(path, str) and normalized("./" + path) == path
                    and path.startswith("backend/content_assets/career/"), "PACKAGE_PAYLOAD_PATH_INVALID")
            require(HEX64.fullmatch(str(record.get("after_sha256", "")))
                    and isinstance(record.get("bytes"), int) and record["bytes"] >= 0,
                    "PACKAGE_PAYLOAD_RECORD_INVALID")
            expected.add("payload/" + path)
        actual = {path for path, member in names.items() if member.isfile()}
        require(actual == expected and len(files) + 2 == len(expected), "PACKAGE_FILE_SET_MISMATCH")
        require(all(any(file.startswith(path + "/") for file in expected)
                    for path, member in names.items() if member.isdir()), "PACKAGE_DIRECTORY_SET_MISMATCH")
        for record in files:
            member = names["payload/" + record["path"]]
            data = bundle.extractfile(member).read()
            require(len(data) == record["bytes"]
                    and hashlib.sha256(data).hexdigest() == record["after_sha256"],
                    "PACKAGE_PAYLOAD_HASH_MISMATCH")
        destination.mkdir(parents=True, exist_ok=False)
        for path, member in names.items():
            target = destination / path
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with target.open("xb") as output:
                    source = bundle.extractfile(member)
                    while chunk := source.read(1024 * 1024):
                        output.write(chunk)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    for argument in ("archive", "destination", "archive-sha", "base", "head", "tree"):
        parser.add_argument("--" + argument, required=True)
    args = parser.parse_args()
    try:
        extract(Path(args.archive), Path(args.destination), args.archive_sha, args.base, args.head, args.tree)
    except (ValueError, OSError, tarfile.TarError, json.JSONDecodeError) as error:
        raise SystemExit(str(error)) from None
