"""SmoothingPitch.ino mapping previews only. Does not open serial or move a robot."""
import math
import numpy as np
import cv2

L1, L2, L3, L_TOOL, R_BASE = 231.5, 221.124, 223.22, 175.0, 80.0
HOME_POSE = [0., 0., L1+L2+L3+L_TOOL, 0., 0., 0.]


def firmware_ik(pose):
    """Mirror firmware workspace/IK checks, not a collision or joint-limit proof."""
    values = np.asarray(pose, dtype=float)
    if values.shape != (6,) or not np.isfinite(values).all():
        raise ValueError('Pose harus enam angka finite: X Y Z Pitch DOF4 Servo')
    x, y, z, pitch, dof4, servo = values
    if not (0 <= x <= 600 and -600 <= y <= 600 and 0 <= z <= HOME_POSE[2] and 0 <= servo <= 180):
        raise ValueError('Di luar workspace/servo firmware')
    radius = math.hypot(x, y)
    if z <= L1 and radius < R_BASE:
        raise ValueError('Target menabrak base robot')
    pitch_rad = math.radians(pitch)
    rw, zw = radius-L_TOOL*math.sin(pitch_rad), z-L1-L_TOOL*math.cos(pitch_rad)
    distance = math.hypot(rw, zw)
    if distance > L2+L3+.01 or distance < abs(L2-L3)-.01:
        raise ValueError('Target di luar jangkauan IK')
    theta3 = math.acos(np.clip((distance**2-L2**2-L3**2)/(2*L2*L3), -1, 1))
    theta2 = math.atan2(rw, zw)-math.atan2(L3*math.sin(theta3), L2+L3*math.cos(theta3))
    r2, z2 = L2*math.sin(theta2), L1+L2*math.cos(theta2)
    r3, z3 = r2+L3*math.sin(theta2+theta3), z2+L3*math.cos(theta2+theta3)
    if min(z2, z3) < 0 or (z2 <= L1 and abs(r2) < R_BASE) or (z3 <= L1 and abs(r3) < R_BASE):
        raise ValueError('IK melanggar base/lantai menurut firmware')
    j2, j3 = math.degrees(theta2), math.degrees(theta3)
    return [math.degrees(math.atan2(y, x)) if x or y else 0., j2, j3, dof4, pitch-j2-j3, servo]


def command_preview(pose, mode='PTP', travel_h=100.):
    firmware_ik(pose)
    if mode not in {'PTP', 'PARABOLIC'}:
        raise ValueError('Mode harus PTP atau PARABOLIC')
    values = list(pose)
    if mode == 'PARABOLIC':
        if not math.isfinite(travel_h) or travel_h <= 0:
            raise ValueError('Travel H harus positif untuk mode PARABOLIC')
        values.append(travel_h)
    return ' '.join(f'{v:.3f}'.rstrip('0').rstrip('.') if v else '0' for v in values)


def check_parabolic(start_pose, target_pose, travel_h):
    """Check all 40 waypoints BEFORE sending anything (no sending here)."""
    command_preview(target_pose, 'PARABOLIC', travel_h)
    firmware_ik(start_pose)
    start, target = np.asarray(start_pose, float), np.asarray(target_pose, float)
    for i in range(1, 41):
        t = i/40
        pose = start+(target-start)*t
        pose[2] += 4*travel_h*t*(1-t)
        try:
            firmware_ik(pose)
        except ValueError as error:
            return dict(passed=False, waypoint=i, t=t, reason=str(error))
    return dict(passed=True, waypoints_checked=40,
                limitation='IK/workspace saja; belum memeriksa joint limit dan tabrakan sepanjang lintasan fisik')


class RobotPlane:
    """Four corresponding pixels and robot XY points on one fixed-height plane."""
    def __init__(self, config):
        self.size = tuple(config['frame_size_wh'])
        self.z = float(config['plane_z_mm'])
        self.pixels = np.asarray(config['points_px'], np.float32)
        self.robot = np.asarray(config['points_robot_xy_mm'], np.float32)
        if len(self.size) != 2 or min(self.size) <= 0 or not math.isfinite(self.z):
            raise ValueError('Resolusi/Z kalibrasi tidak valid')
        for points in (self.pixels, self.robot):
            if points.shape != (4, 2) or not np.isfinite(points).all() or not cv2.isContourConvex(points) or abs(cv2.contourArea(points)) < 1:
                raise ValueError('Isi 4 pasangan titik cembung berurutan pada satu bidang')
        if (self.pixels < 0).any() or (self.pixels >= self.size).any():
            raise ValueError('Piksel kalibrasi di luar frame')
        self.matrix = cv2.getPerspectiveTransform(self.pixels, self.robot)
        if not np.isfinite(self.matrix).all() or np.linalg.matrix_rank(self.matrix) < 3:
            raise ValueError('Homography degenerat')

    def xy(self, pixel, frame_shape):
        if (frame_shape[1], frame_shape[0]) != self.size:
            raise ValueError('Resolusi berubah, ulangi kalibrasi')
        point = np.asarray(pixel, np.float32)
        if point.shape != (2,) or not np.isfinite(point).all():
            raise ValueError('Piksel tidak valid')
        if cv2.pointPolygonTest(self.pixels, tuple(map(float, point)), False) < 0:
            return None
        xy = cv2.perspectiveTransform(point.reshape(1, 1, 2), self.matrix)[0, 0]
        if not np.isfinite(xy).all() or cv2.pointPolygonTest(self.robot, tuple(map(float, xy)), True) < -0.01:
            return None
        return xy.tolist()
