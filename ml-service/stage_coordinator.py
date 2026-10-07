"""One active pick/place/HOME stage. Pure coordinator: no network or motor commands."""
import copy
import math
import time
import uuid


class StageCoordinator:
    def __init__(self, min_confidence=0.6, max_frame_age=1.0, timeout=180.0, clock=time.monotonic):
        for value in (min_confidence, max_frame_age, timeout):
            if isinstance(value, bool) or not math.isfinite(value) or value <= 0:
                raise ValueError('Confidence, frame age, timeout harus positif dan finite')
        if min_confidence > 1:
            raise ValueError('Confidence maksimal 1')
        self.min_confidence = min_confidence
        self.max_frame_age = max_frame_age
        self.timeout = timeout
        self.clock = clock
        self.state = 'WAIT_READY'
        self.fresh_after = None
        self._active = None
        self._deadline = None
        self.reason = None

    @property
    def active(self):
        return copy.deepcopy(self._active)

    def fail(self, reason):
        # No automatic reconnect, replay, or retry: physical outcome may be unknown.
        self.state = 'FAULT'
        self.reason = str(reason)

    def tick(self):
        if self.state in ('WAIT_ACCEPTED', 'BUSY') and self.clock() >= self._deadline:
            self.fail('Timeout menunggu stage selesai di HOME; jangan kirim ulang otomatis')
        return self.state

    def on_status(self, message):
        """Future Arduino status contract. Legacy 'OK' never completes a stage."""
        self.tick()
        if self.state == 'FAULT' or not isinstance(message, dict):
            return False
        event = message.get('event')
        if event == 'RESET':
            self.fail('ESP32 reset; status fisik robot perlu diperiksa')
            return False
        if event == 'READY':
            if self.state != 'WAIT_READY' or message.get('home') is not True:
                return False
            self.state = 'IDLE'
            self.fresh_after = self.clock()
            return True
        if self._active is None or message.get('stage_id') != self._active['stage_id']:
            return False
        if event == 'ERROR':
            self.fail(message.get('reason', 'Firmware melaporkan stage gagal'))
            return False
        if event == 'ACCEPTED' and self.state == 'WAIT_ACCEPTED':
            self.state = 'BUSY'
            return True
        if event == 'DONE':
            if self.state != 'BUSY' or message.get('home') is not True:
                self.fail('DONE sebelum ACCEPTED atau belum kembali HOME')
                return False
            self.state = 'IDLE'
            self.fresh_after = self.clock()
            self._active = None
            self._deadline = None
            return True
        return False

    def offer(self, detections, captured_at):
        """Pick from one fresh frame only. Drop all candidates offered while busy."""
        self.tick()
        if self.state != 'IDLE':
            return None
        now = self.clock()
        if (isinstance(captured_at, bool) or not isinstance(captured_at, (int, float))
                or not math.isfinite(captured_at) or captured_at <= self.fresh_after
                or not 0 <= now-captured_at <= self.max_frame_age):
            return None
        candidates = []
        for detection in detections:
            if detection.get('inside_work_area') is not True or detection.get('center_inside_mask') is not True:
                continue
            color, xy, confidence = (detection.get(k) for k in ('color', 'xy_mm', 'confidence'))
            if color not in {'GREEN', 'RED', 'YELLOW'} or not isinstance(xy, (list, tuple)) or len(xy) != 2:
                continue
            values = [*xy, confidence]
            if any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) for v in values):
                continue
            if not self.min_confidence <= confidence <= 1:
                continue
            candidates.append((confidence, color, float(xy[0]), float(xy[1])))
        if not candidates:
            return None
        # One target, highest confidence; deterministic tie break by color/XY.
        _, color, x, y = max(candidates)
        self._active = dict(stage_id=uuid.uuid4().hex, x=x, y=y,
                            G=int(color == 'GREEN'), R=int(color == 'RED'), Y=int(color == 'YELLOW'))
        self.state = 'WAIT_ACCEPTED'  # Lock BEFORE the caller transmits this payload.
        self._deadline = now+self.timeout
        return self.active
