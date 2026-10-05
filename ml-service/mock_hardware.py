"""Mock hardware server for competition sorting (runs locally, subscribes to arm/command)."""
import asyncio
import json
import threading
from datetime import datetime

import paho.mqtt.client as mqtt

from config import settings


# State machine states (lowercase to match ArmStatus::STATES)
STATES = ["ready", "busy", "picking", "moving", "placing", "returning", "completed", "error"]

# Counter tracking (in-memory for demo) - keys match canonical colors
counters = {"green": 0, "yellow": 0, "red": 0}

current_state = "ready"
current_color = None
current_detection_id = None
current_event_uuid = None
start_time = None

# Event loop for thread-safe asyncio from paho callbacks
_loop: asyncio.AbstractEventLoop | None = None


def _schedule(coro):
    """Schedule a coroutine on the mock server's event loop from the paho thread."""
    global _loop
    if _loop and _loop.is_running():
        asyncio.run_coroutine_threadsafe(coro, _loop)
    else:
        # Fallback: run in new loop (should not happen in normal operation)
        asyncio.run(coro)


def pick_destination(color_name: str) -> str:
    """Map canonical color to destination bowl."""
    return f"BOWL_{color_name.upper()}"


async def publish_status(
    client,
    state: str,
    color: str | None = None,
    detection_id: str | None = None,
    event_uuid: str | None = None,
    confidence: float = 0.0,
    detail: str | None = None,
) -> bool:
    """Publish arm status to arm/status topic.

    event_uuid echoes the command's UUID through every state (including
    COMPLETED) so the backend can match feedback to the originating sort.
    """
    status = {
        "state": state,
        "color": color,
        "detection_id": detection_id,
        "event_uuid": event_uuid,
        "confidence": confidence,
        "timestamp": datetime.utcnow().isoformat() + "Z",
    }
    if detail is not None:
        status["detail"] = detail
    result = client.publish("arm/status", json.dumps(status, separators=(",", ":")), qos=1)
    return result.rc == mqtt.MQTT_ERR_SUCCESS


def on_command(client, userdata, message):
    """Callback for incoming command messages on arm/command (runs in paho network thread)."""
    global current_state, current_color, current_detection_id, current_event_uuid, start_time, counters

    try:
        payload = json.loads(message.payload.decode())
        action = payload.get("action")

        if action == "sort" and settings.competition_mode:
            current_color = payload.get("color")  # expects canonical green/yellow/red
            destination = payload.get("destination", pick_destination(current_color))
            confidence = payload.get("confidence", 1.0)
            current_event_uuid = payload.get("event_uuid")
            current_detection_id = payload.get("detection_id")

            current_state = "busy"
            start_time = datetime.utcnow()

            # Execute sort sequence (schedule on our event loop)
            _schedule(_sort_sequence(client, destination, confidence))

        elif action == "reset_session":
            current_state = "ready"
            current_color = None
            current_detection_id = None
            current_event_uuid = None
            # Must use global keyword to modify the module-level dict
            global counters
            counters = {"green": 0, "yellow": 0, "red": 0}
            _schedule(publish_status(client, "ready"))
            print("=== Demo session reset ===")

        elif action == "error":
            detail = payload.get("detail", "unknown_error")
            _schedule(publish_status(client, "error", detail=detail))
            print(f"[MOCK] Error command received: {detail}")

    except (json.JSONDecodeError, KeyError) as e:
        print(f"Error processing command: {e}")


async def _sort_sequence(client, destination: str, confidence: float):
    """Execute the full sort sequence: busy → picking → moving → placing → returning → completed → ready."""
    global current_state, current_color, current_detection_id, current_event_uuid, start_time, counters

    delay = settings.mock_delay_ms / 1000.0  # Convert ms to seconds

    color_display = current_color or "green"
    destination_display = destination or pick_destination(color_display)
    event_uuid = current_event_uuid

    # Step 1: busy → picking
    current_state = "picking"
    await publish_status(client, "picking", current_color, current_detection_id, event_uuid, confidence)
    print(f"  [MOCK] PICKING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 2: picking → moving
    current_state = "moving"
    await publish_status(client, "moving", current_color, current_detection_id, event_uuid, confidence)
    print(f"  [MOCK] MOVING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 3: moving → placing
    current_state = "placing"
    await publish_status(client, "placing", current_color, current_detection_id, event_uuid, confidence)
    print(f"  [MOCK] PLACING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 4: placing → returning
    current_state = "returning"
    await publish_status(client, "returning", current_color, current_detection_id, event_uuid, confidence)
    print(f"  [MOCK] RETURNING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 5: returning → completed
    current_state = "completed"
    await publish_status(client, "completed", current_color, current_detection_id, event_uuid, confidence)

    # Update counters (only on COMPLETED)
    if color_display in counters:
        counters[color_display] += 1
        print(f"  [MOCK] Counter updated: {color_display} = {counters[color_display]}/3")

    # COMPLETED → READY (with small delay)
    await asyncio.sleep(0.5)

    current_state = "ready"
    current_color = None
    current_detection_id = None
    current_event_uuid = None
    await publish_status(client, "ready")
    print(f"  [MOCK] Sequence complete - {color_display} sorted to {destination_display}")


def start_mqtt_client():
    """Initialize and start the MQTT client for the mock hardware.

    Authenticates with MQTT_USERNAME/MQTT_PASSWORD (+TLS) when configured,
    mirroring the production broker requirements; stays anonymous locally.
    """
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    if (settings.mqtt_username or "").strip():
        client.username_pw_set(settings.mqtt_username, settings.mqtt_password or "")
    if settings.mqtt_use_tls:
        client.tls_set()
    client.on_message = on_command
    client.connect(settings.mqtt_broker, settings.mqtt_port, 60)
    client.subscribe("arm/command")
    client.loop_start()
    return client


async def run_mock_server():
    """Run the mock hardware server with automatic MQTT connection."""
    global _loop
    _loop = asyncio.get_running_loop()

    client = start_mqtt_client()

    try:
        # Publish initial Ready status
        await publish_status(client, "ready")

        # Print initial state
        print(f"Mock Hardware Server running...")
        print(f"  Competition Mode: {settings.competition_mode}")
        print(f"  Initial state: READY")
        print(f"  Counters: {counters}")
        print(f"  Topics: subscribed=arm/command, published=arm/status")
        print(f"  Delay: {settings.mock_delay_ms}ms per step")
        print()
        print("=== Available demo controls ===")
        print("  SIMULATE COMPLETED - trigger green sort completion (via Laravel)")
        print("  SIMULATE ERROR - trigger error state (via Laravel)")
        print("  RESET SESSION - reset all counters (via Laravel)")
        print()

        # Keep running
        while True:
            await asyncio.sleep(1)
    except KeyboardInterrupt:
        client.loop_stop()
        client.disconnect()


if __name__ == "__main__":
    print(f"Mock Hardware Server starting...")
    print(f"  Competition Mode: {settings.competition_mode}")
    print(f"  MQTT Broker: {settings.mqtt_broker}:{settings.mqtt_port}")
    print(f"  Model Path: not required (mock mode)")
    print(f"  Topics: arm/command, arm/status")
    print(f"  Delay: {settings.mock_delay_ms}ms per step")
    print()

    if settings.competition_mode:
        print("=== COMPETITION MODE ENABLED ===")
        print("Color mapping (canonical): green→BOWL_GREEN, yellow→BOWL_YELLOW, red→BOWL_RED")
        print("State sequence: ready → busy → picking → moving → placing → returning → completed → ready")
        print("Counters track completed events per color (need 3/3 for SORTING_COMPLETE)")
        print()

    asyncio.run(run_mock_server())