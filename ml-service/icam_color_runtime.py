"""YOLO11-seg ONNX CPU / CAMNavi2. Detection and mapping previews only."""
import argparse
import ast
import json
import hashlib
import queue
import sys
import re
import time
from pathlib import Path

import cv2
import numpy as np

COLORS = {"RED": (0, 0, 255), "GREEN": (0, 200, 0), "YELLOW": (0, 255, 255)}


def decode(prediction, prototypes, frame_shape, input_hw, names, conf=0.6, iou=0.45):
    """Decode raw YOLO11-seg, batch=1, nms=False; input image uses stretch resize."""
    if not 0 < conf <= 1 or not 0 < iou <= 1:
        raise ValueError("conf/iou harus 0 < nilai <= 1")
    if prediction.ndim != 3 or prototypes.ndim != 4 or prediction.shape[0] != 1 or prototypes.shape[0] != 1:
        raise ValueError("Butuh output YOLO11-seg raw, batch=1")
    nc, nm = len(names), prototypes.shape[1]
    if prediction.shape[1] != 4 + nc + nm:
        raise ValueError("Layout output harus [1, 4+nc+nm, anchors]; ekspor nms=False")
    if not np.isfinite(prototypes).all():
        raise ValueError('Output proto mask tidak finite')
    rows = prediction[0].T
    rows = rows[np.isfinite(rows).all(axis=1)]
    cls = rows[:, 4:4+nc].argmax(axis=1)
    scores = rows[np.arange(len(rows)), 4+cls]
    keep = (scores >= conf) & (rows[:, 2] > 0) & (rows[:, 3] > 0)
    rows, cls, scores = rows[keep], cls[keep], scores[keep]
    boxes = np.column_stack((rows[:, :2] - rows[:, 2:4]/2, rows[:, 2:4]))
    selected = []
    for class_id in np.unique(cls):
        indices = np.flatnonzero(cls == class_id)
        local = cv2.dnn.NMSBoxes(boxes[indices].tolist(), scores[indices].tolist(), conf, iou)
        selected.extend(indices[np.asarray(local, dtype=int).reshape(-1)].tolist())
    h, w = frame_shape[:2]
    ih, iw = input_hw
    proto = prototypes[0]
    detections = []
    for index in sorted(selected, key=lambda k: -scores[k])[:20]:
        x, y, bw, bh = boxes[index]
        x1, x2 = np.clip([x*w/iw, (x+bw)*w/iw], 0, w).astype(int)
        y1, y2 = np.clip([y*h/ih, (y+bh)*h/ih], 0, h).astype(int)
        if x2 <= x1 or y2 <= y1:
            continue
        logits = (rows[index, 4+nc:] @ proto.reshape(nm, -1)).reshape(proto.shape[1:])
        mask = (cv2.resize(logits, (w, h))[y1:y2, x1:x2] > 0).astype(np.uint8)
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not contours:
            continue
        contour = max(contours, key=cv2.contourArea)
        moments = cv2.moments(contour)
        if moments['m00'] <= 0:
            continue
        center = [x1 + moments['m10']/moments['m00'], y1 + moments['m01']/moments['m00']]
        # An irregular mask centroid can lie outside the object: do not offer it for motion.
        center_inside = cv2.pointPolygonTest(contour, (center[0]-x1, center[1]-y1), False) >= 0
        detections.append(dict(class_id=int(cls[index]), color=names[int(cls[index])],
                               confidence=float(scores[index]), center_px=center,
                               center_inside_mask=center_inside, bbox_xyxy=[int(x1), int(y1), int(x2), int(y2)],
                               contour=(contour.reshape(-1, 2)+[x1, y1]).tolist()))
    return detections


class MatROI:
    """Fixed mat polygon in native camera pixels; independent of robot XY/Z calibration."""
    def __init__(self, config, margin_px=2.0):
        size = config.get('frame_size_wh')
        if (not isinstance(size, (list, tuple)) or len(size) != 2
                or any(isinstance(v, bool) or not isinstance(v, int) or v <= 0 for v in size)):
            raise ValueError('Isi frame_size_wh=[lebar, tinggi] frame ICAM asli')
        self.size = tuple(size)
        self.corners = np.asarray(config.get('points_px'), dtype=np.float32)
        if (self.corners.shape != (4, 2) or not np.isfinite(self.corners).all()
                or not cv2.isContourConvex(self.corners) or abs(cv2.contourArea(self.corners)) < 100):
            raise ValueError('Isi points_px: empat sudut matras berurutan, cembung, tanpa silang')
        if (self.corners < 0).any() or (self.corners >= self.size).any():
            raise ValueError('Sudut matras di luar resolusi frame')
        if not np.isfinite(margin_px) or margin_px < 0:
            raise ValueError('Margin matras tidak valid')
        self.margin = float(margin_px)
        mask = np.zeros((self.size[1], self.size[0]), dtype=np.uint8)
        cv2.fillConvexPoly(mask, np.round(self.corners).astype(np.int32), 1)
        self.mask = mask.astype(bool)

    def apply(self, frame):
        if (frame is None or frame.ndim != 3 or frame.shape[2] != 3
                or (frame.shape[1], frame.shape[0]) != self.size):
            raise ValueError('Resolusi frame berbeda dari ROI matras; ukur ulang points_px')
        masked = np.full_like(frame, 114)  # Neutral gray, no RED/GREEN/YELLOW background outside.
        masked[self.mask] = frame[self.mask]
        return masked

    def filter(self, detections):
        accepted = []
        for detection in detections:
            if detection.get('center_inside_mask') is not True:
                continue
            center = np.asarray(detection.get('center_px'), dtype=np.float32)
            contour = np.asarray(detection.get('contour'), dtype=np.float32)
            if (center.shape != (2,) or not np.isfinite(center).all() or contour.ndim != 2
                    or contour.shape[1] != 2 or len(contour) < 3 or not np.isfinite(contour).all()):
                continue
            # Convex ROI: every contour vertex inside implies every edge remains inside too.
            # Small inset rejects masks cut by the mat boundary; keep original pixel coordinates.
            points = np.vstack([center, contour])
            if all(cv2.pointPolygonTest(self.corners, tuple(map(float, p)), True) >= self.margin for p in points):
                accepted.append(detection)
        return accepted



def predict_mat(model, frame, roi=None, conf=None, mode='roi-crop', tile_size=640, overlap=0.25):
    """Infer at mat scale; return ALL geometry in original camera pixels.

    Tiled mode adds overlapping square views to the whole-mat pass. Reject
    detections cut by an internal tile edge before class-aware merging.
    """
    if mode not in {'full', 'roi-crop', 'tiled'}:
        raise ValueError('Mode inference tidak valid')
    if isinstance(tile_size, bool) or not isinstance(tile_size, int) or tile_size < 64:
        raise ValueError('tile_size harus integer >= 64 piksel kamera')
    if not np.isfinite(overlap) or not 0 <= overlap < 0.75:
        raise ValueError('tile overlap harus 0 <= nilai < 0.75')
    masked = roi.apply(frame) if roi is not None else frame
    height, width = masked.shape[:2]
    if mode == 'full':
        detections = model.predict(masked, conf)
        return roi.filter(detections) if roi is not None else detections
    if roi is None:
        raise ValueError('roi-crop/tiled memerlukan poligon matras')
    low = np.floor(roi.corners.min(axis=0)).astype(int)
    high = np.minimum(np.ceil(roi.corners.max(axis=0)).astype(int)+1, [width, height])
    left, top = map(int, low)
    right, bottom = map(int, high)
    windows = [(left, top, right, bottom)]
    if mode == 'tiled':
        def starts(lo, hi):
            last = max(lo, hi-tile_size)
            step = max(1, int(tile_size*(1-overlap)))
            return sorted(set(list(range(lo, last+1, step))+[last]))
        for y in starts(top, bottom):
            for x in starts(left, right):
                window = (x, y, min(x+tile_size, right), min(y+tile_size, bottom))
                if window not in windows:
                    windows.append(window)
    detections = []
    for x1, y1, x2, y2 in windows:
        for original in model.predict(masked[y1:y2, x1:x2], conf):
            box = np.asarray(original['bbox_xyxy'], dtype=float)
            # A partial instance has an unreliable centroid. An overlapping tile
            # or the whole-mat pass must observe the complete object instead.
            if ((x1 > left and box[0] <= 2) or (y1 > top and box[1] <= 2)
                    or (x2 < right and box[2] >= x2-x1-2)
                    or (y2 < bottom and box[3] >= y2-y1-2)):
                continue
            detection = dict(original)
            detection['bbox_xyxy'] = (box+[x1, y1, x1, y1]).tolist()
            detection['center_px'] = (np.asarray(original['center_px'])+[x1, y1]).tolist()
            detection['contour'] = (np.asarray(original['contour'])+[x1, y1]).tolist()
            detections.append(detection)
    # Filter before merging so an invalid outside mask cannot suppress an inside one.
    detections = roi.filter(detections)
    merged = []
    for detection in sorted(detections, key=lambda d: -d['confidence']):
        a = np.asarray(detection['bbox_xyxy'], dtype=float)
        duplicate = False
        for previous in merged:
            if previous['class_id'] != detection['class_id']:
                continue
            b = np.asarray(previous['bbox_xyxy'], dtype=float)
            intersection = np.maximum(0, np.minimum(a[2:], b[2:])-np.maximum(a[:2], b[:2])).prod()
            union = np.maximum(0, a[2:]-a[:2]).prod()+np.maximum(0, b[2:]-b[:2]).prod()-intersection
            if union > 0 and intersection/union > 0.45:
                duplicate = True
                break
        if not duplicate:
            merged.append(detection)
    return merged[:20]


class ColorModel:
    def __init__(self, model_path, threads=2):
        import onnxruntime as ort
        if not isinstance(threads, int) or threads < 1:
            raise ValueError('threads harus integer positif')
        options = ort.SessionOptions()
        options.intra_op_num_threads = threads
        self.session = ort.InferenceSession(str(model_path), sess_options=options, providers=['CPUExecutionProvider'])
        self.default_conf = 0.60
        config_path = Path(model_path).with_name('runtime_config.json')
        if config_path.is_file():
            config = json.loads(config_path.read_text())
            digest = hashlib.sha256(Path(model_path).read_bytes()).hexdigest()
            if config['model_sha256'] != digest or config['preprocessing'] != 'stretch_rgb_nchw_fp32':
                raise ValueError('runtime_config tidak cocok dengan model/preprocessing')
            self.default_conf = float(config['confidence'])
            if not 0 < self.default_conf <= 1:
                raise ValueError('Confidence runtime tidak valid')
        tensor = self.session.get_inputs()[0]
        if tensor.type != 'tensor(float)' or len(tensor.shape) != 4 or tensor.shape[:2] != [1, 3]:
            raise ValueError('Ekspor ONNX FP32 NCHW batch=1 diperlukan')
        if not all(isinstance(v, int) and v > 0 for v in tensor.shape):
            raise ValueError('Ekspor dynamic=False diperlukan')
        self.input_name, self.hw = tensor.name, tuple(tensor.shape[2:])
        meta = self.session.get_modelmeta().custom_metadata_map
        names = ast.literal_eval(meta.get('names', '{}'))
        self.names = {int(k): str(v) for k, v in names.items()}
        if sorted(self.names) != [0, 1, 2] or set(self.names.values()) != set(COLORS):
            raise ValueError('Metadata names harus tepat GREEN, YELLOW, RED dengan ID 0..2')

    def predict(self, frame, conf=None):
        conf = self.default_conf if conf is None else conf
        if frame is None or frame.ndim != 3 or frame.shape[2] != 3:
            raise ValueError('Frame harus BGR 3 channel; kamera mono tidak dapat mendeteksi warna')
        rgb = cv2.cvtColor(cv2.resize(frame, (self.hw[1], self.hw[0])), cv2.COLOR_BGR2RGB)
        tensor = np.ascontiguousarray(rgb.transpose(2, 0, 1)[None], dtype=np.float32)/255.0
        outputs = self.session.run(None, {self.input_name: tensor})
        predictions = [v for v in outputs if v.ndim == 3]
        prototypes = [v for v in outputs if v.ndim == 4]
        if len(predictions) != 1 or len(prototypes) != 1:
            raise ValueError('Model harus YOLO11 instance segmentation dengan dua output raw')
        return decode(predictions[0], prototypes[0], frame.shape, self.hw, self.names, conf)


class CamNaviSource:
    """CAMNavi2 JPEG callback, latest frame only. Requires vendor BSP SDK."""
    def __init__(self, device_name, width=640, height=480):
        from CamNavi2 import CamNavi2
        self.sdk = CamNavi2.CamNavi2() if hasattr(CamNavi2, 'CamNavi2') else CamNavi2()
        print('CAMNavi devices:', self.sdk.enum_camera_list(), file=sys.stderr)
        self.camera = self.sdk.get_device_by_name(device_name)
        self.frames = queue.Queue(maxsize=1)
        if self.camera is None:
            raise RuntimeError('Nama device tidak ditemukan; gunakan hasil enum_camera_list()')
        if int(self.sdk.advcam_query_fw_sku(self.camera)) != 1:
            raise RuntimeError('SDK tidak melaporkan kamera color (SKU=1); verifikasi BSP')
        try:
            self.sdk.advcam_config_pipeline(self.camera, acq_mode=0, width=width, height=height,
                                           enable_infer=0, pipeline_mode='default')
            self.sdk.advcam_open(self.camera)
            self.sdk.advcam_register_new_image_handler(self.camera, self._sample)
            self.sdk.advcam_play(self.camera)
        except BaseException:
            self.close()
            raise

    def _sample(self, sample):
        if sample is None:
            return
        received_at = time.monotonic()  # Stamp callback entry before JPEG decoding.
        buf = sample.get_buffer()
        frame = cv2.imdecode(np.frombuffer(buf.extract_dup(0, buf.get_size()), np.uint8), cv2.IMREAD_UNCHANGED)
        if frame is None or frame.ndim != 3 or frame.shape[2] != 3:
            return
        if self.frames.full():
            try:
                self.frames.get_nowait()
            except queue.Empty:
                pass
        try:
            self.frames.put_nowait((received_at, frame))
        except queue.Full:
            pass

    def read_sample(self, timeout=5, after=None):
        deadline = time.monotonic()+timeout
        while time.monotonic() < deadline:
            try:
                stamp, frame = self.frames.get(timeout=max(0.001, deadline-time.monotonic()))
            except queue.Empty:
                break
            if time.monotonic()-stamp < 1 and (after is None or stamp > after):
                return stamp, frame
        raise TimeoutError('Tidak ada frame JPEG berwarna baru dari CAMNavi2')

    def read(self, timeout=5):
        return self.read_sample(timeout=timeout)[1]

    def close(self):
        try:
            self.sdk.advcam_register_new_image_handler(self.camera, None)
        finally:
            self.sdk.advcam_close(self.camera)


def annotate(frame, detections):
    out = frame.copy()
    for d in detections:
        color = COLORS[d['color']]
        cv2.polylines(out, [np.asarray(d['contour'], np.int32)], True, color, 2)
        p = tuple(np.round(d['center_px']).astype(int))
        cv2.circle(out, p, 4, color, -1)
        cv2.putText(out, f"{d['color']} {d['confidence']:.2f}", p,
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, color, 1)
    return out


def xy_payload(detection):
    """Serialize calibrated robot millimeters, never raw pixel coordinates."""
    if detection.get('inside_work_area') is not True or detection.get('center_inside_mask') is not True:
        return None
    color = detection.get('color')
    xy = detection.get('xy_mm')
    if color not in COLORS or not isinstance(xy, (list, tuple)) or len(xy) != 2:
        return None
    try:
        if any(isinstance(v, (str, bool)) for v in xy):
            return None
        x, y = (float(v) for v in xy)
    except (TypeError, ValueError, OverflowError):
        return None
    if not np.isfinite([x, y]).all():
        return None
    return dict(x=round(x, 2), y=round(y, 2),
                G=int(color == 'GREEN'), R=int(color == 'RED'), Y=int(color == 'YELLOW'))


def xy_line(detection):
    payload = xy_payload(detection)
    return None if payload is None else json.dumps(payload, separators=(',', ':'), allow_nan=False)+'\n'



def terminal_line(detection):
    """Human-readable observation; robot mm and image pixels are explicitly distinct."""
    color = detection['color']
    confidence = float(detection['confidence'])
    payload = xy_payload(detection)
    if payload is not None:
        return f"{color} | X={payload['x']:.2f} mm | Y={payload['y']:.2f} mm | conf={confidence:.2f}\n"
    u, v = detection['center_px']
    if not detection.get('center_inside_mask', False):
        reason = 'PUSAT_MASK_DI_LUAR_OBJEK'
    elif detection.get('inside_work_area') is False:
        reason = 'DI_LUAR_MATRAS'
    elif detection.get('inside_work_area') is None:
        reason = 'BELUM_KALIBRASI'
    else:
        reason = 'KOORDINAT_TIDAK_VALID'
    return f'{color} | U={u:.1f} px | V={v:.1f} px | XY(mm)={reason} | conf={confidence:.2f}\n'



def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', choices=['full', 'xy-json', 'terminal'], default='full',
                        help='full: detection details; xy-json: calibrated XY + one-hot color per line; terminal: readable XY or pixels')
    parser.add_argument('--model', default='best.onnx')
    parser.add_argument('--source', default='camnavi', help='camnavi, image path, video, RTSP, or webcam index')
    parser.add_argument('--device-name', default='iCam500', help='Use actual CAMNavi enum name; manual uses iCam500')
    parser.add_argument('--width', type=int, default=640)
    parser.add_argument('--height', type=int, default=480)
    parser.add_argument('--conf', type=float, default=None, help='Override runtime_config confidence')
    parser.add_argument('--threads', type=int, default=2)
    parser.add_argument('--inference-mode', choices=['full', 'roi-crop', 'tiled'], default=None,
                        help='Default roi-crop with a mat; full for --full-frame diagnostics')
    parser.add_argument('--tile-size', type=int, default=640, help='Tile side in native camera pixels')
    parser.add_argument('--tile-overlap', type=float, default=0.25)
    area = parser.add_mutually_exclusive_group()
    area.add_argument('--roi', help='mat_roi.json: mat pixel polygon only, no robot XY/Z needed')
    area.add_argument('--calibration', help='robot_plane.json: mat polygon and calibrated robot XY')
    area.add_argument('--full-frame', action='store_true', help='Explicit diagnostic mode without mat restriction')
    parser.add_argument('--show', action='store_true', help='Requires GUI OpenCV and local display')
    parser.add_argument('--frames', type=int, default=100)
    args = parser.parse_args()
    if args.output == 'xy-json' and not args.calibration:
        parser.error('--output xy-json memerlukan --calibration robot_plane.json; XY harus dalam mm')
    calibration = None
    if args.calibration:
        from robot_mapping import RobotPlane
        calibration_config = json.loads(Path(args.calibration).read_text())
        missing = [key for key in ('frame_size_wh', 'points_px', 'points_robot_xy_mm', 'plane_z_mm')
                   if calibration_config.get(key) is None]
        if missing:
            parser.error('Kalibrasi belum lengkap: '+', '.join(missing))
        calibration = RobotPlane(calibration_config)
    roi = None
    if args.calibration:
        roi = MatROI(calibration_config)
    elif args.roi:
        roi = MatROI(json.loads(Path(args.roi).read_text()))
    elif not args.full_frame:
        parser.error('Batas matras belum diisi: gunakan --roi mat_roi.json atau --calibration robot_plane.json. '
                     '--full-frame hanya untuk diagnosis tanpa pembatas matras.')
    mode = args.inference_mode or ('roi-crop' if roi is not None else 'full')
    if mode != 'full' and roi is None:
        parser.error('roi-crop/tiled memerlukan --roi atau --calibration')
    if args.tile_size < 64 or not 0 <= args.tile_overlap < 0.75:
        parser.error('--tile-size harus >= 64 dan 0 <= --tile-overlap < 0.75')
    model = ColorModel(args.model, threads=args.threads)
    source = None
    try:
        still = cv2.imread(args.source) if Path(args.source).suffix.lower() in {'.jpg', '.jpeg', '.png'} else None
        if args.source == 'camnavi':
            source = CamNaviSource(args.device_name, args.width, args.height)
        elif still is None:
            source = cv2.VideoCapture(int(args.source) if args.source.isdigit() else args.source)
            if not source.isOpened():
                raise RuntimeError('Sumber kamera/video tidak dapat dibuka')
        for _ in range(args.frames):
            start = time.monotonic()
            if still is not None:
                frame = still
            elif isinstance(source, CamNaviSource):
                frame = source.read()
            else:
                ok, frame = source.read()
                if not ok:
                    break
            inference_frame = roi.apply(frame) if roi is not None else frame
            detections = predict_mat(model, frame, roi, args.conf, mode,
                                     args.tile_size, args.tile_overlap)
            for detection in detections:
                detection['inside_mat'] = True if roi is not None else None
                detection['inside_work_area'] = None  # Unknown until mat calibration is supplied.
                if calibration and detection['center_inside_mask']:
                    xy = calibration.xy(detection['center_px'], frame.shape)
                    detection['inside_work_area'] = xy is not None
                    detection['xy_mm'] = xy
                    detection['plane_z_mm'] = calibration.z  # Not measured depth, no motion command.
            if args.output == 'terminal':
                if not detections:
                    print('[DETEKSI] Tidak ada objek', flush=True)
                for detection in detections:
                    print(terminal_line(detection), end='', flush=True)
            elif args.output == 'xy-json':
                for detection in detections:
                    line = xy_line(detection)
                    if line is not None:
                        print(line, end='', flush=True)
            else:
                print(json.dumps(dict(detections=detections, inference_mode=mode, elapsed_ms=round((time.monotonic()-start)*1000, 1))))
            overlay = annotate(inference_frame, detections)
            if roi is not None:
                cv2.polylines(overlay, [np.round(roi.corners).astype(np.int32)], True, (255,255,255), 1)
            if still is not None:
                cv2.imwrite('prediction.jpg', overlay)
                break
            if args.show:
                cv2.imshow('RED GREEN YELLOW', overlay)
                if cv2.waitKey(1) & 0xff in (27, ord('q')):
                    break
    finally:
        if isinstance(source, CamNaviSource):
            source.close()
        elif source is not None:
            source.release()
        if args.show:
            cv2.destroyAllWindows()


if __name__ == '__main__':
    main()
