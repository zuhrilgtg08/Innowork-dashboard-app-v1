import sys
sys.path.insert(0, 'F:/New-Innowrok/Dashboard-app-v1')

from fastapi.testclient import TestClient
from tools.bonding_controller import app
import uvicorn
import threading
import time
import json
import urllib.request

# Start server in background thread
print("Starting uvicorn server on port 5000...")
thread = threading.Thread(target=uvicorn.run, args=(app,), kwargs={'host': '127.0.0.1', 'port': 5000, 'log_level': 'error'})
thread.daemon = True
thread.start()

# Wait a moment for server to start
time.sleep(2)

print("Server started, testing endpoints...")

# Test 1: POST /bonding/calculate
print("\n1. Testing POST /bonding/calculate with x=100, y=50")
try:
    req_data = json.dumps({'x': 100.0, 'y': 50.0, 'source': 'icam'}).encode()
    req = urllib.request.Request('http://127.0.0.1:5000/bonding/calculate', data=req_data, method='POST')
    req.add_header('Content-Type', 'application/json')
    with urllib.request.urlopen(req, timeout=5) as response:
        result = json.loads(response.read().decode())
        print(f"   Status: {response.status}")
        print(f"   Priority: {result['priority']}, Length: {result['length']}, Width: {result['width']}")
except Exception as e:
    print(f"   Error: {e}")

# Test 2: GET /bonding/status
print("\n2. Testing GET /bonding/status")
try:
    with urllib.request.urlopen('http://127.0.0.1:5000/bonding/status', timeout=5) as response:
        result = json.loads(response.read().decode())
        print(f"   Status: {response.status}")
        print(f"   Connected: {result['connected']}")
except Exception as e:
    print(f"   Error: {e}")

print("\n=== Server test completed ===")