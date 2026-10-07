import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import mock_hardware as hardware
from config import settings


class MockHardwareTests(unittest.TestCase):
    def setUp(self):
        hardware.current_state = "ready"
        hardware._sequence_future = None
        hardware._seen_events.clear()
        hardware.counters = {"green": 0, "yellow": 0, "red": 0}
        self.future = Mock()
        self.scheduled = []

        def schedule(coroutine):
            self.scheduled.append(coroutine.cr_code.co_name)
            coroutine.close()
            return self.future

        mode = patch.object(settings, "competition_mode", True)
        scheduling = patch.object(hardware, "_schedule", side_effect=schedule)
        mode.start()
        scheduling.start()
        self.addCleanup(mode.stop)
        self.addCleanup(scheduling.stop)

    def send(self, payload):
        hardware.on_command(Mock(), None, SimpleNamespace(payload=json.dumps(payload).encode()))

    def test_busy_and_redelivered_commands_do_not_start_another_sequence(self):
        first = {"action": "sort", "color": "green", "event_uuid": "event-1", "detection_id": 1}
        self.send(first)
        self.send({**first, "event_uuid": "event-2"})
        self.assertEqual(self.scheduled, ["_sort_sequence"])
        self.assertEqual(hardware.current_event_uuid, "event-1")
        hardware.current_state = "ready"
        self.send(first)
        self.assertEqual(self.scheduled, ["_sort_sequence"])

    def test_invalid_payloads_are_ignored(self):
        for payload in ([], None, {"action": "sort"}, {"action": "sort", "color": "blue", "event_uuid": "event-1"}):
            self.send(payload)
        self.assertEqual(hardware.current_state, "ready")
        self.assertEqual(self.scheduled, [])

    def test_reset_cancels_motion_and_does_not_replay_old_events(self):
        command = {"action": "sort", "color": "red", "event_uuid": "event-1"}
        self.send(command)
        self.send({"action": "reset_session"})
        self.future.cancel.assert_called_once()
        self.assertEqual(hardware.current_state, "ready")
        self.send(command)
        self.assertEqual(self.scheduled.count("_sort_sequence"), 1)

    def test_error_cancels_motion(self):
        self.send({"action": "sort", "color": "red", "event_uuid": "event-1"})
        self.send({"action": "error", "detail": "test error"})
        self.future.cancel.assert_called_once()
        self.assertEqual(hardware.current_state, "error")


if __name__ == "__main__":
    unittest.main()
