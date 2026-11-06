#!/usr/bin/env python3
"""Taxmod print service: fetches label jobs from WordPress and prints them through CUPS (D-1032, stage 2 of D-1100).

It never polls WordPress. It waits for a push on its ntfy topic, looks at a small static wake file every few minutes
as a fallback, and only then asks WordPress once for every waiting job. Standard library only.

    python3 taxmod_print_agent.py --config ~/.config/taxmod-print-agent/config.json
    python3 taxmod_print_agent.py --config ... --once     # fetch and print once, then stop
"""

from __future__ import annotations

import argparse
import base64
import json
import logging
import os
import re
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

VERSION = "taxmod-print-agent 0.2.0"
TOKEN_HEADER = "X-Taxmod-Print-Token"

# Per label format: the CUPS options. The browser test showed the Brother driver wants landscape for labels as wide
# as the roll; the PageSize names are the ones in the queue's PPD. Override any of them in config.json → "formats".
DEFAULT_FORMATS = {
    "roll62": {"PageSize": "62x100"},
    "holder62": {"PageSize": "62x50", "orientation-requested": "4"},
    "material62": {"PageSize": "62x30", "orientation-requested": "4"},
    "roll102": {"PageSize": "102x62", "orientation-requested": "4"},
}

log = logging.getLogger("taxmod-print-agent")


def load_config(path: str) -> dict:
    with open(os.path.expanduser(path), encoding="utf-8") as handle:
        config = json.load(handle)
    for key in ("rest", "token"):
        if not config.get(key):
            raise ValueError(f"config.json needs «{key}»")
    config.setdefault("printers", {})
    config.setdefault("ntfy", "")
    config.setdefault("wake_url", "")
    config.setdefault("wake_seconds", 300)
    config.setdefault("alive_seconds", 3600)
    formats = dict(DEFAULT_FORMATS)
    for key, options in (config.get("formats") or {}).items():
        formats[key] = options
    config["formats"] = formats
    return config


def printer_for(config: dict, roll_mm: float) -> str:
    """The CUPS queue for a roll width; config "printers" maps "62" / "102" to queue names."""
    queue = config["printers"].get(str(int(round(roll_mm))))
    if not queue:
        raise LookupError(f"no printer for the {roll_mm:g} mm roll in config.json")
    return queue


# D-1190: queue and options can come from WordPress, so they are held to plain names before they reach lp.
PLAIN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.\-]{0,126}$")


def plain(text: str, what: str) -> str:
    if not PLAIN.match(text):
        raise ValueError(f"{what} «{text}» is not a plain name")
    return text


def lp_command(config: dict, job: dict, pdf_path: str) -> list[str]:
    """D-1190: a job whose label names its printer brings queue and CUPS options; otherwise roll width and format decide."""
    queue = plain(str(job["queue"]), "queue") if job.get("queue") else printer_for(config, float(job["roll"]))
    command = ["lp", "-d", queue, "-t", f"taxmod-{job['id']}-{job.get('code', '')}"]
    options = job["options"] if isinstance(job.get("options"), dict) and job["options"] else config["formats"].get(job["format"], {})
    for name, value in options.items():
        name, value = plain(str(name), "option"), plain(str(value), "value") if value != "" else ""
        command += ["-o", f"{name}={value}" if value != "" else name]
    return command + [pdf_path]


class WordPress:
    def __init__(self, config: dict):
        self.base = config["rest"].rstrip("/") + "/"
        self.token = config["token"]

    def call(self, path: str, body: dict) -> dict:
        request = urllib.request.Request(
            self.base + path,
            data=json.dumps(body).encode("utf-8"),
            headers={"Content-Type": "application/json", TOKEN_HEADER: self.token, "User-Agent": VERSION},
            method="POST",
        )
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))


class Agent:
    def __init__(self, config: dict, run=subprocess.run):
        self.config = config
        self.wordpress = WordPress(config)
        self.run = run
        self.wake = threading.Event()
        self.lock = threading.Lock()

    def printers(self) -> list[str]:
        return [f"{roll} mm: {queue}" for roll, queue in sorted(self.config["printers"].items())]

    def fetch_and_print(self) -> int:
        """One pull, then every job printed and answered. Returns how many jobs came."""
        with self.lock:
            answer = self.wordpress.call("print/pull", {"agent": VERSION, "printers": self.printers()})
            jobs = answer.get("jobs", [])
            for job in jobs:
                ok, message = self.print_job(job)
                try:
                    self.wordpress.call(f"print/jobs/{job['id']}/result", {"ok": ok, "message": message})
                except urllib.error.URLError as error:
                    log.error("job %s: answer not delivered: %s", job["id"], error)
            return len(jobs)

    def print_job(self, job: dict) -> tuple[bool, str]:
        try:
            with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as handle:
                handle.write(base64.b64decode(job["pdf"]))
                path = handle.name
            try:
                command = lp_command(self.config, job, path)
                done = self.run(command, capture_output=True, text=True, timeout=60)
            finally:
                os.unlink(path)
        except (LookupError, ValueError, OSError, subprocess.SubprocessError) as error:
            log.error("job %s: %s", job.get("id"), error)
            return False, str(error)
        output = (done.stdout or done.stderr or "").strip()
        log.info("job %s %s: %s", job["id"], job["format"], output)
        return done.returncode == 0, (output or f"lp exit {done.returncode}") + " · " + " ".join(command[:-1])

    def listen_ntfy(self) -> None:
        url = self.config["ntfy"].rstrip("/") + "/json"
        delay = 5
        while True:
            try:
                with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": VERSION}), timeout=90) as stream:
                    delay = 5
                    for line in stream:
                        if json.loads(line or b"{}").get("event") == "message":
                            self.wake.set()
            except (urllib.error.URLError, OSError, ValueError) as error:
                log.warning("ntfy: %s, again in %ss", error, delay)
                time.sleep(delay)
                delay = min(delay * 2, 300)

    def watch_file(self) -> None:
        last = None
        while True:
            try:
                request = urllib.request.Request(self.config["wake_url"], headers={"User-Agent": VERSION, "Cache-Control": "no-cache"})
                with urllib.request.urlopen(request, timeout=30) as response:
                    counter = response.read(32).decode("ascii", "replace").strip()
                if last is not None and counter != last:
                    self.wake.set()
                last = counter
            except (urllib.error.URLError, OSError) as error:
                log.warning("wake file: %s", error)
            time.sleep(int(self.config["wake_seconds"]))

    def serve(self) -> None:
        if self.config["ntfy"]:
            threading.Thread(target=self.listen_ntfy, daemon=True).start()
        if self.config["wake_url"]:
            threading.Thread(target=self.watch_file, daemon=True).start()
        self.wake.set()  # once at start: whatever waits from before
        while True:
            woken = self.wake.wait(timeout=int(self.config["alive_seconds"]))
            self.wake.clear()
            try:
                count = self.fetch_and_print()
                if count or woken:
                    log.info("pull: %d job(s)", count)
            except (urllib.error.URLError, OSError, ValueError) as error:
                log.error("pull: %s", error)
                time.sleep(30)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--config", default="~/.config/taxmod-print-agent/config.json")
    parser.add_argument("--once", action="store_true", help="fetch and print once, then stop")
    arguments = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    agent = Agent(load_config(arguments.config))
    if arguments.once:
        print(f"{agent.fetch_and_print()} job(s)")
        return 0
    agent.serve()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
