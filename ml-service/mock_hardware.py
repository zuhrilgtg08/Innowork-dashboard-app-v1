import asyncio
import json
import os
import paho.mqtt.client as mqtt
from datetime import datetime

MQTT_BROKER = os.getenv("MQTT_BROKER", "localhost")
MQTT_PORT = int(os.getenv("MQTT_PORT", 1883))
MQTT_TOPIC_COMMAND = "arm/command"
MQTT_TOPIC_STATUS = "arm/status"

# Competition Mode
COMPETITION_MODE = os.getenv("COMPETITION_MODE", "false").lower() == "true"

# Color mapping for competition mode
COLOR_MAP = {
    "HIJAU": "GREEN",
    "KUNING": "YELLOW",
    "MERAH": "RED",
}

# State machine states
STATES = ["READY", "BUSY", "PICKING", "MOVING", "PLACING", "RETURNING", "COMPLETED"]

# Counter tracking (in-memory for demo)
counters = {"GREEN": 0, "YELLOW": 0, "RED": 0}

current_state = "READY"
current_color = None
current_detection_id = None
start_time = None


def pick_destination(color_name):
    """Map competition color to destination bowl."""
    return f"BOWL_{color_name.upper()}"


def color_name_to_enum(color_name):
    """Convert color name to enum value."""
    mapping = {"GREEN": "HIJAU", "YELLOW": "KUNING", "RED": "MERAH"}
    return mapping.get(color_name, "HIJAU")


async def publish_command(client, color_name, destination, confidence=1.0):
    """Publish sorting command to arm/command topic."""
    command = {
        "action": "sort",
        "color": color_name,
        "destination": destination,
        "confidence": confidence,
        "timestamp": datetime.utcnow().isoformat() + "Z",
    }
    result = client.publish(MQTT_TOPIC_COMMAND, json.dumps(command), qos=1)
    return result.rc == mqtt.MQTT_ERR_SUCCESS


async def publish_status(client, state, color=None, detection_id=None, confidence=0.0):
    """Publish arm status to arm/status topic."""
    status = {
        "state": state,
        "color": color,
        "detection_id": detection_id,
        "confidence": confidence,
        "timestamp": datetime.utcnow().isoformat() + "Z",
    }
    result = client.publish(MQTT_TOPIC_STATUS, json.dumps(status), qos=1)
    return result.rc == mqtt.MQTT_ERR_SUCCESS


def on_command(client, userdata, message):
    """Callback for incoming command messages on arm/command."""
    global current_state, current_color, current_detection_id, start_time

    try:
        payload = json.loads(message.payload.decode())
        action = payload.get("action")

        if action == "sort" and COMPETITION_MODE:
            current_color = payload.get("color")
            destination = payload.get("destination", pick_destination(current_color))
            confidence = payload.get("confidence", 1.0)
            event_uuid = payload.get("event_uuid")
            detection_id = payload.get("detection_id")

            current_state = "BUSY"
            current_detection_id = detection_id
            start_time = datetime.utcnow()

            # Execute sort sequence
            asyncio.create_task(_sort_sequence(client, destination, confidence))

        elif action == "reset_session":
            current_state = "READY"
            current_color = None
            current_detection_id = None
            counters = {"GREEN": 0, "YELLOW": 0, "RED": 0}
            asyncio.create_task(publish_status(client, "READY"))
            print("=== Demo session reset ===")

    except (json.JSONDecodeError, KeyError) as e:
        print(f"Error processing command: {e}")


async def _sort_sequence(client, destination, confidence):
    """Execute the full sort sequence: BUSY → PICKING → MOVING → PLACING → RETURNING → COMPLETED → READY."""
    global current_state, current_color, current_detection_id, start_time

    delay = float(os.getenv("MOCK_DELAY_MS", 300)) / 1000.0  # Convert ms to seconds

    color_display = current_color or "HIJAU"
    destination_display = destination or f"BOWL_{color_display.upper()}"

    # Step 1: BUSY → PICKING
    current_state = "PICKING"
    await publish_status(client, "PICKING", current_color, current_detection_id, confidence)
    print(f"  [MOCK] PICKING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 2: PICKING → MOVING
    current_state = "MOVING"
    await publish_status(client, "MOVING", current_color, current_detection_id, confidence)
    print(f"  [MOCK] MOVING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 3: MOVING → PLACING
    current_state = "PLACING"
    await publish_status(client, "PLACING", current_color, current_detection_id, confidence)
    print(f"  [MOCK] PLACING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 4: PLACING → RETURNING
    current_state = "RETURNING"
    await publish_status(client, "RETURNING", current_color, current_detection_id, confidence)
    print(f"  [MOCK] RETURNING - color={color_display}, dest={destination_display}")
    await asyncio.sleep(delay)

    # Step 5: RETURNING → COMPLETED
    current_state = "COMPLETED"
    await publish_status(client, "COMPLETED", current_color, current_detection_id, confidence)

    # Update counters
    color_key = color_display.upper()
    if color_key in counters:
        counters[color_key] += 1
        print(f"  [MOCK] Counter updated: {color_key} = {counters[color_key]}/3")

    # COMPLETED → READY (with small delay)
    await asyncio.sleep(0.5)

    # COMPLETED → READY
    current_state = "READY"
    current_color = None
    current_detection_id = None
    await publish_status(client, "READY")
    print(f"  [MOCK] Sequence complete - {color_display} sorted to {destination_display}")


def start_mqtt_client():
    """Initialize and start the MQTT client for the mock hardware."""
    client = mqtt.Client()
    client.on_message = on_command
    client.connect(MQTT_BROKER, MQTT_PORT, 60)
    client.subscribe(MQTT_TOPIC_COMMAND)
    client.loop_start()
    return client


async def run_mock_server():
    """Run the mock hardware server with automatic MQTT connection."""
    client = start_mqtt_client()

    try:
        # Publish initial Ready status
        await publish_status(client, "READY")

        # Print initial state
        print(f"Mock Hardware Server running...")
        print(f"  Competition Mode: {COMPETITION_MODE}")
        print(f"  Initial state: READY")
        print(f"  Counters: {counters}")
        print(f"  Topics: subscribed={MQTT_TOPIC_COMMAND}, published={MQTT_TOPIC_STATUS}")
        print(f"  Delay: {os.getenv('MOCK_DELAY_MS', 300)}ms per step")
        print()
        print("=== Available demo controls ===")
        print("  SIMULATE COMPLETED - trigger green sort completion")
        print("  SIMULATE ERROR - trigger error state")
        print("  RESET SESSION - reset all counters")
        print()

        # Keep running
        while True:
            await asyncio.sleep(1)
    except KeyboardInterrupt:
        client.loop_stop()
        client.disconnect()


if __name__ == "__main__":
    print(f"Mock Hardware Server starting...")
    print(f"  Competition Mode: {COMPETITION_MODE}")
    print(f"  MQTT Broker: {MQTT_BROKER}:{MQTT_PORT}")
    print(f"  Model Path: not required (mock mode)")
    print(f"  Topics: {MQTT_TOPIC_COMMAND}, {MQTT_TOPIC_STATUS}")
    print(f"  Delay: {os.getenv('MOCK_DELAY_MS', 300)}ms per step")
    print()

    if COMPETITION_MODE:
        print("=== COMPETITION MODE ENABLED ===")
        print("Color mapping: HIJAU→BOWL_GREEN, KUNING→BOWL_YELLOW, MERAH→BOWL_RED")
        print("State sequence: READY → BUSY → PICKING → MOVING → PLACING → RETURNING → COMPLETED → READY")
        print("Counters track completed events per color (need 3/3 for SORTING_COMPLETE)")
        print()

    asyncio.run(run_mock_server())