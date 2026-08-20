import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]


class CareerCurrentPublisherResourceGuardTest(unittest.TestCase):
    def test_remote_publisher_uses_the_release_owned_resource_guard(self):
        workflow = (ROOT / ".github/workflows/deploy.yml").read_text()

        self.assertIn(
            "backend/scripts/deploy/run_career_current_authority_publisher.sh",
            workflow,
        )
        self.assertNotIn("php -d memory_limit=1536M", workflow)
        for receipt_fragment in (
            '"timeout_seconds": 900',
            '"nice_adjustment": 15',
            '"ionice_class": 2',
            '"ionice_priority": 7',
            '"memory_limit_mb": 1024',
        ):
            self.assertIn(receipt_fragment, workflow)

    def test_guard_is_bounded_and_exports_the_receipt_contract(self):
        guard = (
            ROOT / "backend/scripts/deploy/run_career_current_authority_publisher.sh"
        ).read_text()

        for command in ("timeout", "nice", "ionice", "php"):
            self.assertIn(f'resolve_command {command}', guard)
        for bound in (
            "readonly timeout_seconds=900",
            "readonly kill_after_seconds=30",
            "readonly nice_adjustment=15",
            "readonly ionice_class=2",
            "readonly ionice_priority=7",
            "readonly memory_limit_mb=1024",
        ):
            self.assertIn(bound, guard)
        self.assertIn('--kill-after="${kill_after_seconds}s"', guard)
        self.assertIn('memory_limit=${memory_limit_mb}M', guard)

    def test_publish_receipt_rejects_an_unbounded_runtime(self):
        publisher = (
            ROOT / "backend/scripts/operations/career_current_authority_publish.php"
        ).read_text()

        self.assertIn("'resource_guard' => $resourceGuard", publisher)
        self.assertIn("'timeout_seconds' => 900", publisher)
        self.assertIn("'nice_adjustment' => 15", publisher)
        self.assertIn("'ionice_priority' => 7", publisher)
        self.assertIn("'memory_limit_mb' => 1024", publisher)


if __name__ == "__main__":
    unittest.main()
