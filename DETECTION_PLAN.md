# Real-Time Object Detection — Client-Side Plan

## Overview
Implement a browser-native, real-time object detection page using the **MediaDevices API** for camera access and **TensorFlow.js + COCO-SSD** for in-browser inference. The page renders a live video feed with a responsive canvas overlay for bounding boxes, labels, and live stats — all processed client-side with no backend round-trips.

## Architecture

### Stack
- **Laravel 11** + **Livewire** (SPA layout, routing, auth)
- **Alpine.js** (reactive UI state)
- **Tailwind CSS** (styling)
- **TensorFlow.js 4.22** + **COCO-SSD 2.2** (model inference)
- **Vite** (bundling)

### File Map
```
resources/js/
  detection.js          → Alpine data factory: camera, model, loop, canvas drawing
  app.js                → Entry point; exposes `window.detection`

resources/views/livewire/detection/
  index.blade.php       → Video + canvas overlay UI, controls, sidebar

app/Livewire/Detection/
  Index.php             → Livewire page shell with props (camera, conveyor, stats)

routes/web.php          → /detection route (auth protected)
```

## Core Mechanics

### 1. Camera Access (`resources/js/detection.js:51–90`)
- Calls `navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'environment' }, audio: false })`
- Permissions flow:
  - `NotAllowedError` → "Akses kamera ditolak"
  - `NotFoundError` → "Kamera tidak ditemukan"
  - Generic error → message fallback
- Stores stream track label for UI display
- Releases tracks on `stop()` and Livewire navigation (`livewire:navigating`)

### 2. Model Loading (`resources/js/detection.js:38–48`)
- Awaits `tf.ready()` for WebGL/CPU backend initialization
- Loads `cocoSsd.load({ base: 'lite_mobilenet_v2' })`
- Lite MobileNet V2 chosen for real-time browser performance (lower latency than base models)

### 3. Efficient Animation Loop (`resources/js/detection.js:119–132`)
- Uses `requestAnimationFrame` for smooth frame pacing
- Throttles inference to max ~12 FPS (`now - this.lastFrameTime < 80` ms guard) to keep UI responsive
- Calculates FPS every 1 second using a rolling frame counter
- Skips inference when video `readyState < HAVE_CURRENT_DATA` to avoid blank frames

### 4. Detection & Drawing (`resources/js/detection.js:134–179`)
- Calls `model.detect(video, 20, minConfidence)` — max 20 predictions, configurable confidence threshold
- Draws on `<canvas>` absolutely positioned over `<video>`:
  - Green bounding boxes (`strokeRect`, line width 3)
  - Filled label badge above each box with class name + confidence %
  - White text for readability
- Stats updated reactively: `stats.fps`, `stats.inferenceMs`, `stats.objects`

### 5. Responsive Overlay (`resources/views/livewire/detection/index.blade.php`)
- Wrapper div applies `-scale-x-100` when mirrored, flipping both video and canvas together
- Canvas sized to intrinsic video dimensions (`videoWidth`, `videoHeight`)
- Sidebar template iterates `x-for="(d, i) in detections"` showing class + score
- Controls: confidence slider, Flip (mirror), Stop

### 6. Styling
- Tailwind CSS utilities matching existing app design system (`card`, `brand-600`, dark mode variants)
- Dark mode compatible throughout
- `[x-cloak]` handled by existing `app.css`

## User Flow
1. User visits `/detection` (authenticated)
2. Alpine `init()` fires → starts loading COCO-SSD model in background
3. UI shows idle state with "Memuat model..." or "Aktifkan Kamera"
4. User clicks **Aktifkan Kamera** → browser permission prompt
5. On grant: video stream starts, canvas resizes, `requestAnimationFrame` loop begins
6. Every ~80 ms a frame is sent to TF.js → bounding boxes drawn in real-time
7. Stats bar updates: FPS, inference latency, object count
8. Sidebar lists detected classes with confidence
9. User can adjust confidence threshold, flip mirror, or stop

## Performance Notes
- Bundle size increase: ~1.9 MB JS (TF.js + COCO-SSD) — gzipped ~315 KB
- For production, consider:
  - Dynamic `import()` code-splitting to load TF.js only on the detection page
  - `manualChunks` in Vite config to isolate TF.js into a separate chunk
  - Model caching via `tf.io.registerSaveHandler` / IndexedDB for repeat visits

## Run
```bash
npm install
npm run build
php artisan serve
# Visit /detection
```
