import serial
import math
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import uvicorn
import os
import json

app = FastAPI(title="Robot Arm Control - ICAM Bonding")

# Serial configuration - adjust port based on your system
# Windows: 'COM3', 'COM4', etc.
# Linux/Mac: '/dev/ttyUSB0', '/dev/ttyACM0', etc.
SERIAL_PORT = os.environ.get("ROBOT_SERIAL_PORT", "COM3")
BAUD_RATE = int(os.environ.get("ROBOT_BAUD_RATE", "115200"))

# Attempt to open serial connection
try:
    ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
    print(f"[Serial] Terbuka: {SERIAL_PORT} @ {BAUD_RATE}")
except Exception as e:
    ser = None
    print(f"[Serial] Tidak bisa membuka port: {e}")


class CoordinateInput(BaseModel):
    x: float
    y: float
    source: str = "icam"  # icam, manual, simulator


def calculate_priority(x: float, y: float) -> dict:
    """Calculate priority/length from x,y coordinates.
    
    Priority formula based on bonding point analysis:
    - radius = sqrt(x^2 + y^2) distance from origin
    - ratio = x/y or y/x determines priority level
    - Returns priority score and dimension info
    """
    if x == 0 and y == 0:
        return {"priority": 0, "length": 0, "width": 0, "distance": 0}

    distance = math.sqrt(x**2 + y**2)
    # Priority based on ratio of x to y (larger dimension gets higher priority)
    if y != 0:
        ratio = abs(x / y)
    else:
        ratio = 0

    # Normalize priority 0-100 based on distance and ratio
    priority = min(100, int(distance * 2 + ratio * 10))

    # Calculate "length" and "width" from x,y
    # Assuming x = width, y = length (or vice versa based on convention)
    length = max(x, y)
    width = min(x, y)

    return {
        "priority": priority,
        "length": length,
        "width": width,
        "distance": round(distance, 2),
        "ratio": round(ratio, 2),
    }


def send_to_esp(x: float, y: float, priority_data: dict) -> bool:
    """Send bonding data to ESP32 via serial UART."""
    if ser is None or not ser.is_open:
        print("[Serial] Port tidak tersedia, melewati pengiriman ke ESP32")
        return False

    # Format data: BONDING|x|y|priority|length|width
    message = f"BONDING|{x:.2f}|{y:.2f}|{priority_data['priority']}|{priority_data['length']:.2f}|{priority_data['width']:.2f}\n"

    try:
        ser.write(message.encode('utf-8'))
        ser.flush()

        # Wait for response from ESP32
        import time
        time.sleep(0.1)

        if ser.in_waiting:
            response = ser.read(ser.in_waiting).decode('utf-8').strip()
            print(f"[ESP32] Response: {response}")
            return True
        else:
            print("[ESP32] No response received")
            return True  # Still consider sent successfully
    except Exception as e:
        print(f"[Serial Error] {e}")
        return False


@app.post("/bonding/calculate")
async def calculate_bonding(coord: CoordinateInput):
    """Endpoint untuk menerima koordinat bonding dan mengirim ke ESP32."""
    priority_data = calculate_priority(coord.x, coord.y)

    # Send to ESP32
    sent = send_to_esp(coord.x, coord.y, priority_data)

    result = {
        "x": coord.x,
        "y": coord.y,
        "priority": priority_data["priority"],
        "length": priority_data["length"],
        "width": priority_data["width"],
        "distance": priority_data["distance"],
        "ratio": priority_data["ratio"],
        "sent_to_esp32": sent,
        "source": coord.source,
    }

    return result


@app.get("/bonding/status")
async def bonding_status():
    """Cek status koneksi serial ke ESP32."""
    if ser and ser.is_open:
        return {"serial_port": SERIAL_PORT, "baud_rate": BAUD_RATE, "connected": True}
    return {"serial_port": SERIAL_PORT, "baud_rate": BAUD_RATE, "connected": False, "error": "Port closed"}


@app.on_event("shutdown")
def shutdown_event():
    if ser and ser.is_open:
        ser.close()
        print("[Serial] Port tertutup")


if __name__ == "__main__":
    print("=== Robot Arm Control Server ===")
    print(f"Serial Port: {SERIAL_PORT} @ {BAUD_RATE}")
    print(f"API running on: http://127.0.0.1:5000")
    print("Endpoint: POST /bonding/calculate")
    print("---")
    uvicorn.run(app, host="127.0.0.1", port=5000)