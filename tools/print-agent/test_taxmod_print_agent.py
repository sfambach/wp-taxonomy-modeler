"""Run: python3 -m unittest discover tools/print-agent"""

import base64
import json
import os
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import taxmod_print_agent as agent  # noqa: E402


def config(**extra):
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as handle:
        json.dump({"rest": "https://example.test/wp-json/taxmod/v1", "token": "t", "printers": {"62": "QL-810W_take"}, **extra}, handle)
    try:
        return agent.load_config(handle.name)
    finally:
        os.unlink(handle.name)


class FakeWordPress:
    def __init__(self, jobs):
        self.jobs = jobs
        self.calls = []

    def call(self, path, body):
        self.calls.append((path, body))
        return {"jobs": self.jobs} if path == "print/pull" else {}


class AgentTest(unittest.TestCase):
    def test_a_format_brings_its_cups_options(self):
        command = agent.lp_command(config(), {"id": 7, "roll": 62.0, "format": "holder62", "code": "O-1"}, "/tmp/x.pdf")
        self.assertEqual(command[:3], ["lp", "-d", "QL-810W_take"])
        self.assertIn("PageSize=62x50", command)
        self.assertIn("orientation-requested=4", command)
        self.assertEqual(command[-1], "/tmp/x.pdf")

    def test_the_config_overrides_a_format(self):
        command = agent.lp_command(config(formats={"holder62": {"PageSize": "62x52"}}), {"id": 1, "roll": 62, "format": "holder62"}, "f.pdf")
        self.assertIn("PageSize=62x52", command)
        self.assertNotIn("orientation-requested=4", command)

    def test_a_job_with_its_printer_brings_queue_and_options(self):
        job = {"id": 3, "roll": 40, "format": "phomemo40x30", "queue": "Phomemo_M220L", "options": {"PageSize": "w40h30"}}
        command = agent.lp_command(config(formats={"phomemo40x30": {"PageSize": "w50h30"}}), job, "f.pdf")
        self.assertEqual(command[:3], ["lp", "-d", "Phomemo_M220L"])
        self.assertIn("PageSize=w40h30", command)
        self.assertNotIn("PageSize=w50h30", command)

    def test_a_queue_or_option_that_is_not_a_plain_name_fails_the_job(self):
        for job in ({"queue": "-h evil"}, {"queue": "Q", "options": {"PageSize": "a b"}}, {"queue": "Q", "options": {"--x": "1"}}):
            service = agent.Agent(config(), run=lambda *a, **k: self.fail("lp must not run"))
            ok, _ = service.print_job({"id": 4, "roll": 40, "format": "x", "pdf": base64.b64encode(b"%PDF-").decode(), **job})
            self.assertFalse(ok)

    def test_a_roll_without_printer_fails_the_job_not_the_service(self):
        service = agent.Agent(config(), run=lambda *a, **k: self.fail("lp must not run"))
        ok, message = service.print_job({"id": 2, "roll": 102.0, "format": "roll102", "pdf": base64.b64encode(b"%PDF-").decode()})
        self.assertFalse(ok)
        self.assertIn("102 mm", message)

    def test_every_job_is_printed_and_answered(self):
        printed = []

        def run(command, **kwargs):
            printed.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="request id is QL-810W_take-12 (1 file(s))", stderr="")

        service = agent.Agent(config(), run=run)
        service.wordpress = FakeWordPress([
            {"id": 5, "roll": 62.0, "format": "roll62", "code": "O-1", "pdf": base64.b64encode(b"%PDF-1.4").decode()},
            {"id": 6, "roll": 62.0, "format": "holder62", "code": "O-2", "pdf": base64.b64encode(b"%PDF-1.4").decode()},
        ])
        self.assertEqual(service.fetch_and_print(), 2)
        self.assertEqual(len(printed), 2)
        paths = [path for path, _ in service.wordpress.calls]
        self.assertEqual(paths, ["print/pull", "print/jobs/5/result", "print/jobs/6/result"])
        self.assertTrue(service.wordpress.calls[1][1]["ok"])
        self.assertIn("62 mm: QL-810W_take", service.wordpress.calls[0][1]["printers"])

    def test_a_failing_lp_is_reported_as_failed(self):
        service = agent.Agent(config(), run=lambda c, **k: subprocess.CompletedProcess(c, 1, stdout="", stderr="lp: The printer or class does not exist."))
        service.wordpress = FakeWordPress([{"id": 9, "roll": 62.0, "format": "roll62", "pdf": base64.b64encode(b"%PDF").decode()}])
        service.fetch_and_print()
        self.assertFalse(service.wordpress.calls[1][1]["ok"])
        self.assertIn("does not exist", service.wordpress.calls[1][1]["message"])

    def test_a_config_without_token_is_refused(self):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as handle:
            json.dump({"rest": "x", "printers": {"62": "q"}}, handle)
        try:
            with self.assertRaises(ValueError):
                agent.load_config(handle.name)
        finally:
            os.unlink(handle.name)


if __name__ == "__main__":
    unittest.main()
