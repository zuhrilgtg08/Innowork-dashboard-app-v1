import json, sys
sys.stdout.reconfigure(encoding='utf-8')
nb = json.load(open('ml-service/colab-artifacts/ultra-milk-training.ipynb'))
print('Valid JSON | cells:', len(nb['cells']))
for i, c in enumerate(nb['cells']):
    src = ''.join(c['source']).strip().replace('\n', ' ')
    print(f'Cell {i}: {c["cell_type"]} | {src[:90]}')
