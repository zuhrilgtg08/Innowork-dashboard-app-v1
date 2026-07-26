import * as tf from '@tensorflow/tfjs';
import * as cocoSsd from '@tensorflow-models/coco-ssd';

export default function detection() {
    return {
        active: false,
        loading: false,
        error: '',
        modelReady: false,
        mirrored: true,
        deviceLabel: '',
        stats: {
            fps: 0,
            inferenceMs: 0,
            objects: 0,
        },
        detections: [],
        stream: null,
        rafId: null,
        lastFrameTime: 0,
        frameCount: 0,
        fpsUpdateTime: 0,
        minConfidence: 0.5,
        model: null,
        video: null,
        canvas: null,
        ctx: null,
        boundResize: null,

        init() {
            this.video = this.$refs.video;
            this.canvas = this.$refs.canvas;
            this.ctx = this.canvas.getContext('2d');
            this.loadModel();
            document.addEventListener('livewire:navigating', () => this.stop(), { once: true });
        },

        async loadModel() {
            this.loading = true;
            try {
                await tf.ready();
                this.model = await cocoSsd.load({ base: 'lite_mobilenet_v2' });
                this.modelReady = true;
            } catch (e) {
                this.error = 'Gagal memuat model deteksi. Periksa koneksi internet.';
            } finally {
                this.loading = false;
            }
        },

        async start() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) {
                this.error = 'Browser tidak mendukung akses kamera';
                return;
            }
            if (!this.modelReady) {
                this.error = 'Model deteksi belum siap';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'environment',
                    },
                    audio: false,
                });
                this.video.srcObject = this.stream;
                this.deviceLabel = this.stream.getVideoTracks()[0]?.label || 'Kamera';
                await this.video.play();
                this.active = true;
                this.lastFrameTime = performance.now();
                this.fpsUpdateTime = this.lastFrameTime;
                this.frameCount = 0;
                this.resizeCanvas();
                this.loop();
                this.boundResize = this.resizeCanvas.bind(this);
                window.addEventListener('resize', this.boundResize);
            } catch (e) {
                if (e.name === 'NotAllowedError') {
                    this.error = 'Akses kamera ditolak. Izinkan akses kamera di browser lalu coba lagi.';
                } else if (e.name === 'NotFoundError') {
                    this.error = 'Kamera tidak ditemukan pada perangkat ini.';
                } else {
                    this.error = 'Gagal mengakses kamera: ' + e.message;
                }
                this.active = false;
            }
        },

        stop() {
            if (this.rafId) {
                cancelAnimationFrame(this.rafId);
                this.rafId = null;
            }
            if (this.boundResize) {
                window.removeEventListener('resize', this.boundResize);
                this.boundResize = null;
            }
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null;
            this.active = false;
            this.detections = [];
            if (this.ctx) {
                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            }
        },

        resizeCanvas() {
            const v = this.video;
            const c = this.canvas;
            if (!v || !c) return;
            c.width = v.videoWidth || 640;
            c.height = v.videoHeight || 480;
        },

        loop() {
            if (!this.active) return;
            this.rafId = requestAnimationFrame(() => this.loop());
            const now = performance.now();
            this.frameCount++;
            if (now - this.fpsUpdateTime >= 1000) {
                this.stats.fps = this.frameCount;
                this.frameCount = 0;
                this.fpsUpdateTime = now;
            }
            if (now - this.lastFrameTime < 80) return;
            this.lastFrameTime = now;
            this.detect();
        },

        async detect() {
            if (!this.active || !this.model || this.video.readyState < 2) return;
            try {
                const t0 = performance.now();
                const predictions = await this.model.detect(this.video, 20, this.minConfidence);
                this.stats.inferenceMs = Math.round(performance.now() - t0);
                this.stats.objects = predictions.length;
                this.detections = predictions;
                this.draw(predictions);
            } catch (e) {
                console.error('Detection error:', e);
            }
        },

        draw(predictions) {
            const ctx = this.ctx;
            const canvas = this.canvas;
            const video = this.video;
            if (!ctx || !canvas || !video) return;

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const scaleX = canvas.width / (this.mirrored ? video.videoHeight : video.videoWidth);
            const scaleY = canvas.height / video.videoHeight;

            predictions.forEach(pred => {
                const [x, y, w, h] = pred.bbox;
                const label = pred.class;
                const score = Math.round(pred.score * 100);

                ctx.strokeStyle = '#10b981';
                ctx.lineWidth = 3;
                ctx.strokeRect(x, y, w, h);

                ctx.fillStyle = '#10b981';
                const text = `${label} ${score}%`;
                const tm = ctx.measureText(text);
                const tw = tm.width + 10;
                const th = 22;
                ctx.fillRect(x, y > th ? y - th : y, tw, th);

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 12px ui-sans-serif, system-ui, sans-serif';
                ctx.fillText(text, x + 5, y > th ? y - 6 : y + 15);
            });
        },

        toggleMirror() {
            this.mirrored = !this.mirrored;
        },
    };
}
