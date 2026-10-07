ICAM-300 / QCS6490 / SmoothingPitch.ino mapping preview
1. ICAM Python 3.10: use CAMNavi2 and OpenCV from the Advantech BSP.
   Optional venv: python3 -m venv --system-site-packages .venv
   pip install -r requirements-runtime.txt
   These pins are for ICAM/BSP, NOT Colab Python 3.13.
   Use a matching aarch64/Python/glibc ONNX Runtime wheel or vendor build.
2. Verify SHA256SUMS.json before copying parts from another bundle.
   runtime_config.json must match best.onnx SHA256 (checked by ColorModel).
3. Image: python3 icam_color_runtime.py --model best.onnx --source meja.jpg
4. Camera: release camera from vendor web utility per BSP guide:
   sudo systemctl stop web.service
   python3 icam_color_runtime.py --source camnavi --device-name iCam500 --frames 100 --threads 2
   Use actual enumerated device name/resolution. --show needs GUI OpenCV.
   After closing SDK: sudo systemctl start web.service
5. robot_mapping.json records color REFERENCE poses, not confirmed pick/drop locations.
   Firmware accepts six numeric fields for PTP, seven for parabolic; newline terminated.
   HOME moves; ZERO sets the physical upright reference. No XY:/SAVE/POINTS protocol here.
   Servo is written after motion. Firmware has blocking moves and no STOP command.
   This bundle never opens serial. Preview does not certify physical collision clearance.
6. Fill robot_plane.example.json with measured pixel/robot XY correspondences at fixed Z.
   Save as robot_plane.json. Validate independent points in mm with the fixed camera mount.
   python3 icam_color_runtime.py --source camnavi --calibration robot_plane.json
   Output is XY preview only; no automatic motion or inferred depth.
   stage_coordinator.py provides a tested single-stage gate, not a TCP/motor adapter.
   See stage_protocol.json: ACCEPTED means received; matching DONE + home=true ends the stage.
   Terima_data_icamv2.ino currently replies only OK and has no connected motion cycle.
   Do not treat OK as completion. Discard busy-period detections and read a new frame after DONE.
7. reports/onnx_quality.json measures the deployed decoder at the chosen confidence.
   Confidence selection uses valid, not test. Crops without negatives cannot measure table false alarms.
   Colab CPU timing is not ICAM FPS. Test full scenes, empty table, hands, shadows and glare.
   ONNX is FP32 CPU. NPU requires separate conversion and accuracy/latency validation.
