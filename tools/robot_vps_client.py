"""Manual/debug client for the VPS robot command bridge (H-1).

Sends already-calculated x/y debug coordinates to the Laravel VPS API over
HTTPS. The ESP32 polls the VPS separately; this script never talks to the
ESP32 directly (no raw TCP).

    ROBOT_API_BASE_URL=https://<vps-domain>/api ROBOT_DEVICE_TOKEN=... \\
        python tools/robot_vps_client.py

Menu:
    1 = Send manual X, Y + color
    2 = Send demo sequence GREEN -> RED -> YELLOW
    q = Quit

Color flags are one-hot:
    GREEN  => G=1, R=0, Y=0
    RED    => G=0, R=1, Y=0
    YELLOW => G=0, R=0, Y=1

Standard library only. Never commit a real token.
"""

import json
import os
import sys
import urllib.error
import urllib.request

API_BASE_URL = os.environ.get("ROBOT_API_BASE_URL", "http://127.0.0.1:8000/api").rstrip("/")
DEVICE_TOKEN = os.environ.get("ROBOT_DEVICE_TOKEN", "")

COLOR_FLAGS = {
    "GREEN": (1, 0, 0),
    "RED": (0, 1, 0),
    "YELLOW": (0, 0, 1),
}

DEMO_SEQUENCE = [
    ("GREEN", 100.0, 50.0),
    ("RED", 120.0, 60.0),
    ("YELLOW", 140.0, 70.0),
]


def api_request(method, path, payload=None):
    """Send one HTTPS request; return (http_status, parsed_json_or_None)."""
    url = API_BASE_URL + path
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    request = urllib.request.Request(url, data=data, method=method)
    request.add_header("Accept", "application/json")
    request.add_header("Authorization", "Bearer {}".format(DEVICE_TOKEN))
    if data is not None:
        request.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            body = response.read().decode("utf-8", "replace")
            return response.status, json.loads(body) if body else None
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        try:
            return exc.code, json.loads(body) if body else None
        except ValueError:
            return exc.code, {"raw": body}
    except (urllib.error.URLError, TimeoutError) as exc:
        return 0, {"error": "connection_failed", "message": str(exc)}


def print_command(status, body):
    """Print the VPS answer in the simulator payload shape."""
    print("HTTP status: {}".format(status))
    command = (body or {}).get("command") if isinstance(body, dict) else None
    if not command:
        print("Response: {}".format(json.dumps(body)))
        return
    print("command id : {}".format(command.get("id")))
    print("uuid       : {}".format(command.get("uuid")))
    print("x / y      : {} / {}".format(command.get("x"), command.get("y")))
    print("G / R / Y  : {} / {} / {}".format(command.get("G"), command.get("R"), command.get("Y")))
    print("color      : {}".format(command.get("color")))
    print("status     : {}".format(command.get("status")))


def send_command(x, y, color, source="manual"):
    """Send one manual command; returns True on accepted."""
    g, r, y_flag = COLOR_FLAGS[color]
    status, body = api_request("POST", "/robot/commands", {
        "x": x,
        "y": y,
        "G": g,
        "R": r,
        "Y": y_flag,
        "source": source,
    })
    print_command(status, body)
    return status in (200, 201)


def send_manual():
    """Prompt for X, Y and color, then send a single command."""
    try:
        x = float(input("X: ").strip())
        y = float(input("Y: ").strip())
    except ValueError:
        print("X and Y must be numeric.")
        return
    color = input("Color [GREEN/RED/YELLOW]: ").strip().upper()
    if color not in COLOR_FLAGS:
        print("Color must be GREEN, RED or YELLOW.")
        return
    send_command(x, y, color)


def send_demo_sequence():
    """Send the GREEN -> RED -> YELLOW demo sequence."""
    for color, x, y in DEMO_SEQUENCE:
        print("--- {} x={} y={} ---".format(color, x, y))
        if not send_command(x, y, color, source="manual-demo"):
            print("Stopping sequence: command was rejected.")
            return


def main():
    if not DEVICE_TOKEN:
        print("ROBOT_DEVICE_TOKEN is not set. Refusing to run without credentials.")
        return 1
    print("Robot VPS client -> {}".format(API_BASE_URL))
    while True:
        print("")
        print("1 = Send manual X, Y + color")
        print("2 = Send demo sequence GREEN -> RED -> YELLOW")
        print("q = Quit")
        choice = input("> ").strip().lower()
        if choice == "1":
            send_manual()
        elif choice == "2":
            send_demo_sequence()
        elif choice == "q":
            return 0
        else:
            print("Unknown choice.")


if __name__ == "__main__":
    sys.exit(main())
