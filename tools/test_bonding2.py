import sys
import json
sys.path.insert(0, 'F:/New-Innowrok/Dashboard-app-v1')

from fastapi.testclient import TestClient
from tools.bonding_controller import calculate_priority, app

# Override serial to None to avoid port issues
import tools.bonding_controller as bc
bc.ser = None

client = TestClient(app)

print('=== Testing Bonding Controller (Serial disabled) ===')
print()

# Test calculation function
print('Test A: calculate_priority(100, 50)')
result = calculate_priority(100.0, 50.0)
print(f'  Priority: {result["priority"]}, Length: {result["length"]}, Width: {result["width"]}, Distance: {result["distance"]}, Ratio: {result["ratio"]}')
print()

print('Test B: calculate_priority(200, 200)')
result2 = calculate_priority(200.0, 200.0)
print(f'  Priority: {result2["priority"]}, Length: {result2["length"]}, Width: {result2["width"]}, Distance: {result2["distance"]}, Ratio: {result2["ratio"]}')
print()

print('Test C: calculate_priority(0, 0)')
result3 = calculate_priority(0.0, 0.0)
print(f'  Priority: {result3["priority"]}, Length: {result3["length"]}, Width: {result3["width"]}, Distance: {result3["distance"]}')
print()

# Test FastAPI endpoints
print('Test D: POST /bonding/calculate with x=100, y=50')
response = client.post('/bonding/calculate', json={'x': 100.0, 'y': 50.0, 'source': 'icam'})
print(f'  Status: {response.status_code}')
data = response.json()
print(f'  Priority: {data["priority"]}, Length: {data["length"]}, Width: {data["width"]}, Distance: {data["distance"]}, Sent to ESP32: {data["sent_to_esp32"]}')
print()

print('Test E: POST /bonding/calculate with x=150, y=75')
response2 = client.post('/bonding/calculate', json={'x': 150.0, 'y': 75.0, 'source': 'simulator'})
print(f'  Status: {response2.status_code}')
data2 = response2.json()
print(f'  Priority: {data2["priority"]}, Length: {data2["length"]}, Width: {data2["width"]}, Distance: {data2["distance"]}, Sent to ESP32: {data2["sent_to_esp32"]}')
print()

print('Test F: GET /bonding/status')
response3 = client.get('/bonding/status')
print(f'  Status: {response3.status_code}')
data3 = response3.json()
print(f'  Connected: {data3["connected"]}')
print()

print('=== All tests completed successfully ===')