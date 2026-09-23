import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

from extract_career_content_package import extract


BASE, HEAD, TREE = (digit * 40 for digit in "abc")
PAGE = "backend/content_assets/career/current/careers/actors/en.json"
MANIFEST = "backend/content_assets/career/current/manifest.json"
INTENT = "backend/content_assets/career/career_current_authority_release.v1.json"


class ExtractCareerPackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.stage = self.root / "stage"
        files = {path: (path + "\n").encode() for path in (PAGE, MANIFEST, INTENT)}
        page_sha = hashlib.sha256(files[PAGE]).hexdigest()
        set_sha = hashlib.sha256(f"actors\ten\t{page_sha}\n".encode()).hexdigest()
        changed_bytes = json.dumps({
            "schema_version": "fermatmind.career-content-changed-pages.v1",
            "head_sha": HEAD, "changed_page_set_sha256": set_sha,
            "pages": [{"after_sha256": page_sha, "locale": "en", "slug": "actors"}],
        }).encode()
        binding = {
            "schema_version": "fermatmind.career-content-package.v1",
            "base_sha": BASE, "head_sha": HEAD, "candidate_tree_sha": TREE,
            "no_deletions": True, "payload_file_count": len(files),
            "changed_page_count": 1, "changed_page_set_sha256": set_sha,
            "changed_pages_file_sha256": hashlib.sha256(changed_bytes).hexdigest(),
            "files": [{"path": path, "after_sha256": hashlib.sha256(data).hexdigest(),
                       "bytes": len(data)} for path, data in files.items()],
        }
        contents = {"binding.json": json.dumps(binding).encode(),
                    "projection-index.json": b"{}", "changed-pages.json": changed_bytes,
                    **{"payload/" + path: data for path, data in files.items()}}
        for path, data in contents.items():
            target = self.stage / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        self.archive = self.root / "package.tar.gz"
        command = shutil.which("gtar") or "tar"
        gnu_tar = "GNU tar" in subprocess.run([command, "--version"], capture_output=True,
                                                text=True, check=False).stdout
        options = (["--sort=name", "--format=ustar", "--mtime=@0", "--owner=0",
                    "--group=0", "--numeric-owner"] if gnu_tar else [])
        subprocess.run([command, *options, "-C", str(self.stage), "-czf", str(self.archive), "."],
                       check=True, env={**os.environ, "COPYFILE_DISABLE": "1"})

    def check(self, archive=None):
        archive = archive or self.archive
        extract(archive, self.root / "output", hashlib.sha256(archive.read_bytes()).hexdigest(),
                BASE, HEAD, TREE)

    def rewrite(self, extra):
        archive = self.root / "altered.tar.gz"
        with tarfile.open(self.archive, "r:gz") as original, tarfile.open(archive, "w:gz") as target:
            for member in original:
                target.addfile(member, original.extractfile(member) if member.isfile() else None)
            info = tarfile.TarInfo(extra[0])
            info.type = extra[1]
            info.linkname = extra[2] if len(extra) > 2 else ""
            data = b"attack" if info.isfile() else b""
            info.size = len(data)
            target.addfile(info, io.BytesIO(data) if data else None)
        return archive

    def test_real_generated_archive_and_directories(self):
        self.check()
        self.assertEqual((self.root / "output/payload" / PAGE).read_bytes(), (PAGE + "\n").encode())

    def test_reject_duplicate_and_links(self):
        for extra, message in [
            (("./binding.json", tarfile.REGTYPE), "PACKAGE_DUPLICATE_ENTRY"),
            (("./payload/link", tarfile.SYMTYPE, "../../outside"), "PACKAGE_LINK_OR_TYPE_FORBIDDEN"),
            (("./payload/hard", tarfile.LNKTYPE, "./binding.json"), "PACKAGE_LINK_OR_TYPE_FORBIDDEN"),
        ]:
            with self.subTest(extra=extra):
                with self.assertRaisesRegex(ValueError, message):
                    self.check(self.rewrite(extra))

    def test_reject_traversal_and_wrong_root(self):
        for name, message in [("./payload/../../outside", "PACKAGE_PATH_INVALID"),
                              ("other/payload/file", "PACKAGE_ROOT_INVALID"),
                              ("/absolute/file", "PACKAGE_PATH_INVALID")]:
            with self.subTest(name=name):
                with self.assertRaisesRegex(ValueError, message):
                    self.check(self.rewrite((name, tarfile.REGTYPE)))

    def test_reject_extra_payload_file(self):
        with self.assertRaisesRegex(ValueError, "PACKAGE_FILE_SET_MISMATCH"):
            self.check(self.rewrite(("./payload/backend/content_assets/career/current/extra.json", tarfile.REGTYPE)))

    def test_reject_archive_tampering_and_binding_mismatch(self):
        with self.assertRaisesRegex(ValueError, "PACKAGE_ARCHIVE_HASH_MISMATCH"):
            extract(self.archive, self.root / "output", "0" * 64, BASE, HEAD, TREE)
        for base, head, tree in [("0" * 40, HEAD, TREE), (BASE, "0" * 40, TREE),
                                 (BASE, HEAD, "0" * 40)]:
            with self.subTest(base=base, head=head, tree=tree):
                with self.assertRaisesRegex(ValueError, "PACKAGE_BINDING_INVALID"):
                    extract(self.archive, self.root / "output", hashlib.sha256(self.archive.read_bytes()).hexdigest(),
                            base, head, tree)


if __name__ == "__main__":
    unittest.main()
