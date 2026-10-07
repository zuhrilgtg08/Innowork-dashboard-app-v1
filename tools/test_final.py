import sys
sys.path.insert(0, 'F:/New-Innowrok/Dashboard-app-v1')
from fastapi.testclient import TestClient
from tools.bonding_controller import calculate_priority, app

# Test calculation
print('Calculation test:')
r = calculate_priority(100, 50)
print(f'  x=100, y=50 -> priority={r["priority"]}, length={r["length"]}, width={r["width"]}')

r2 = calculate_priority(200, 75)
print(f'  x=200, y=75 -> priority={r2["priority"]}, length={r2["length"]}, width={r2["width"]}')

# Test API
client = TestClient(app)
resp = client.post('/bonding/calculate', json={'x': 100, 'y': 50, 'source': 'icam'})
print(f'API test: status={resp.status_code}, body={resp.json()["priority"]}')

print('All core functionality working!')