import sys
sys.path.insert(0, 'F:/New-Innowrok/Dashboard-app-v1')

from fastapi.testclient import TestClient
from tools.bonding_controller import app
import json

client = TestClient(app)

print('=== Testing Bonding Controller ===')
print()

# Test 1: Calculate bonding with x=100, y=50
print('Test 1: POST /bonding/calculate with x=100, y=50')
response = client.post('/bonding/calculate', json={'x': 100.0, 'y': 50.0, 'source': 'icam'})
print(f'Status: {response.status_code}')
data = response.json()
print(f'Priority: {data["priority"]}, Length: {data["length"]}, Width: {data["width"]}, Distance: {data["distance"]}')
print()

# Test 2: Calculate with x=200, y=200
print('Test 2: POST /bonding/calculate with x=200, y=200')
response2 = client.post('/bonding/calculate', json={'x': 200.0, 'y': 200.0, 'source': 'manual'})
print(f'Status: {response2.status_code}')
data2 = response2.json()
print(f'Priority: {data2["priority"]}, Length: {data2["length"]}, Width: {data2["width"]}, Distance: {data2["distance"]}')
print()

# Test 3: Status endpoint
print('Test 3: GET /bonding/status')
response3 = client.get('/bonding/status')
print(f'Status: {response3.status_code}')
data3 = response3.json()
print(f'Response: {json.dumps(data3, indent=2)}')

print()
print('=== All tests completed ===')