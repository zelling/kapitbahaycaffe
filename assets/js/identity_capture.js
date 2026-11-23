(() => {
    'use strict';

    const configElement = document.getElementById('identity-capture-config');
    const video = document.getElementById('identity-video');
    const canvas = document.getElementById('identity-canvas');
    const prompt = document.getElementById('identity-prompt');
    const status = document.getElementById('identity-status');
    const button = document.getElementById('identity-camera-button');
    const debug = document.getElementById('identity-debug');
    if (!configElement || !video || !canvas || !prompt || !status || !button) {
        return;
    }

    const config = JSON.parse(configElement.textContent);
    const poses = ['straight', 'left', 'right', 'closer', 'farther'];
    const labels = { straight: 'Look straight', left: 'Turn left', right: 'Turn right', closer: 'Move closer', farther: 'Move farther' };
    // Only this centered portrait area (width / height) is sent to the service.
    const CROP_ASPECT = 0.75;
    // The oval fills this share of the crop area.
    const OVAL_WIDTH = 0.84;
    const OVAL_HEIGHT = 0.88;
    let poseIndex = 0;
    let stream = null;
    let timer = null;
    let busy = false;
    let straightBlob = null;

     // Put the oval inside the existing camera box.
    const frame = video.parentElement;
    const guide = document.createElement('div');
    guide.className = 'app-id-guide';
    guide.setAttribute('aria-hidden', 'true');
    guide.hidden = true;
    frame.appendChild(guide);   

    const cropRect = () => {
        const vw = video.videoWidth || 640;
        const vh = video.videoHeight || 480;
        let ch = vh;
        let cw = Math.round(vh * CROP_ASPECT);
        if (cw > vw) {
            cw = vw;
            ch = Math.round(vw / CROP_ASPECT);
        }
        return { sx: Math.round((vw - cw) / 2), sy: Math.round((vh - ch) / 2), cw, ch, vw, vh };
    };

    const placeGuide = () => {
        const rect = cropRect();
        const shownWidth = video.clientWidth;
        const shownHeight = video.clientHeight;
        guide.style.width = Math.round(rect.cw / rect.vw * shownWidth * OVAL_WIDTH) + 'px';
        guide.style.height = Math.round(rect.ch / rect.vh * shownHeight * OVAL_HEIGHT) + 'px';
    };

    const stopCamera = () => {
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        guide.hidden = true;
    };

    const showPose = () => {
        const pose = poses[poseIndex];
        prompt.textContent = labels[pose];
        document.querySelectorAll('#identity-progress li').forEach((item) => {
            item.classList.toggle('app-id-progress-current', item.dataset.pose === pose);
        });
    };

    const wait = () => {
        timer = window.setTimeout(sendFrame, 1200);
    };

    // Cut out the oval area only and shrink it before sending.
    const frameBlob = () => new Promise((resolve) => {
        const rect = cropRect();
        const scale = Math.min(1, 640 / rect.ch);
        canvas.width = Math.round(rect.cw * scale);
        canvas.height = Math.round(rect.ch * scale);
        canvas.getContext('2d').drawImage(video, rect.sx, rect.sy, rect.cw, rect.ch, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(resolve, 'image/jpeg', 0.8);
    });

    const request = async (url, form, timeout) => {
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, { method: 'POST', body: form, signal: controller.signal, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'The request could not be completed.');
            }
            return data;
        } finally {
            window.clearTimeout(timeoutId);
        }
    };

    const match = async () => {
        if (!straightBlob) {
            status.textContent = 'Please retake the camera step.';
            return;
        }
        status.textContent = 'Matching, please wait.';
        const form = new FormData();
        form.append('csrf_token', config.csrfToken);
        form.append('selfie', straightBlob, 'selfie.jpg');
        try {
            const data = await request(`${config.baseUrl}/identity/match_api.php`, form, 150000);
            stopCamera();
            window.location.assign(data.redirect);
          } catch (error) {
            stopCamera();
            prompt.textContent = 'Matching failed';
            status.textContent = (error.name === 'AbortError' ? 'Matching timed out.' : error.message) + ' Reload this page to try again.';
        }
    };

    const flashGuide = () => {
        guide.classList.add('app-id-guide-ok');
        window.setTimeout(() => guide.classList.remove('app-id-guide-ok'), 600);
    };

    async function sendFrame() {
        if (busy || !stream) {
            return;
        }
        busy = true;
        const pose = poses[poseIndex];
        try {
            const blob = await frameBlob();
            if (!blob) {
                throw new Error('The camera frame could not be created.');
            }
            const form = new FormData();
            form.append('csrf_token', config.csrfToken);
            form.append('pose', pose);
            form.append('frame', blob, 'frame.jpg');
            const data = await request(`${config.baseUrl}/identity/capture_api.php`, form, 30000);
            if (debug && typeof data.yaw === 'number' && typeof data.face_size === 'number') {
                debug.textContent = `Yaw: ${data.yaw} · Face size: ${data.face_size}`;
            }
            status.textContent = data.message;
            if (data.passed) {
                flashGuide();
                document.querySelector(`[data-pose="${pose}"]`)?.classList.add('app-id-progress-passed');
                if (pose === 'straight') {
                    straightBlob = blob;
                }
                poseIndex += 1;
                if (data.done) {
                    prompt.textContent = 'Photo check complete';
                    await match();
                    return;
                }
                showPose();
            }
            wait();
        } catch (error) {
            stopCamera();
            status.textContent = error.name === 'AbortError' ? 'The camera check timed out. Please try again.' : error.message;
            button.textContent = 'Retry camera';
            button.disabled = false;
        } finally {
            busy = false;
        }
    }

    button.addEventListener('click', async () => {
        stopCamera();
        try {
            if (!window.isSecureContext && location.hostname !== 'localhost') {
                throw new Error('Camera access needs a secure connection.');
            }
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false });
            video.srcObject = stream;
            await video.play();
            button.disabled = true;
            placeGuide();
            guide.hidden = false;
            status.textContent = 'Camera ready. Fit your face inside the oval.';
            showPose();
            wait();
        } catch (error) {
            const message = error.name === 'NotAllowedError' ? 'Camera permission was denied. Allow access and try again.' : error.name === 'NotFoundError' ? 'No camera was found. Connect a camera and try again.' : error.message || 'Camera access is unavailable.';
            status.textContent = message;
        }
    });

    window.addEventListener('resize', placeGuide);
    window.addEventListener('beforeunload', stopCamera);
})();