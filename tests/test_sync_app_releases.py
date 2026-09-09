import copy
import unittest
from scripts.sync_app_releases import InvalidRelease, merge_release, version_key


class ReleasesTest(unittest.TestCase):
    def setUp(self):
        self.data = {"app": {"appleId": "6762150416", "country": "us"}, "releases": [{"version": "1.0.4", "releaseDate": "2026-08-31", "releaseNotes": "Older notes"}]}
        self.app = {"trackId": 6762150416, "version": "1.0.5", "currentVersionReleaseDate": "2026-09-09T01:12:17Z", "releaseNotes": "* A fix\n* Another fix", "trackViewUrl": "https://apps.apple.com/us/app/id6762150416"}

    def merge(self):
        return merge_release(self.data, {"resultCount": 1, "results": [self.app]})

    def test_preserves_history_and_input(self):
        before = copy.deepcopy(self.data)
        result = self.merge()
        self.assertEqual(self.data, before)
        self.assertEqual([r["version"] for r in result["releases"]], ["1.0.5", "1.0.4"])
        self.assertEqual(result["releases"][1]["releaseNotes"], "Older notes")

    def test_repeat_is_unchanged(self):
        self.data = self.merge()
        self.assertEqual(self.merge(), self.data)

    def test_utc_date_not_invented(self):
        result = self.merge()["releases"][0]
        self.assertEqual(result["releaseDate"], "2026-09-09")
        self.assertEqual(result["releasedAt"], "2026-09-09T01:12:17Z")

    def test_wrong_app_rejected(self):
        self.app["trackId"] = 123
        with self.assertRaises(InvalidRelease): self.merge()

    def test_missing_date_rejected(self):
        del self.app["currentVersionReleaseDate"]
        with self.assertRaises(InvalidRelease): self.merge()

    def test_naive_date_rejected(self):
        self.app["currentVersionReleaseDate"] = "2026-09-09T01:12:17"
        with self.assertRaises(InvalidRelease): self.merge()

    def test_older_feed_cannot_regress(self):
        self.app["version"] = "1.0.3"
        with self.assertRaises(InvalidRelease): self.merge()

    def test_empty_notes_rejected(self):
        self.app["releaseNotes"] = ""
        with self.assertRaises(InvalidRelease): self.merge()

    def test_numeric_order(self):
        self.assertGreater(version_key("1.0.10"), version_key("1.0.9"))

    def test_external_url_rejected(self):
        self.app["trackViewUrl"] = "https://example.com/"
        with self.assertRaises(InvalidRelease): self.merge()

    def test_duplicate_history_rejected(self):
        self.data["releases"].append(copy.deepcopy(self.data["releases"][0]))
        with self.assertRaises(InvalidRelease): self.merge()

    def test_no_app_rejected(self):
        with self.assertRaises(InvalidRelease):
            merge_release(self.data, {"resultCount": 0, "results": []})


if __name__ == "__main__":
    unittest.main()
