class Timer {
    constructor() {
        this.display = document.getElementById('timer-display');
        this.startBtn = document.getElementById('timer-start');
        this.pauseBtn = document.getElementById('timer-pause');
        this.resetBtn = document.getElementById('timer-reset');
        
        this.totalSeconds = 0;
        this.remainingSeconds = 0;
        this.interval = null;
        this.isRunning = false;
        
        this.init();
    }

    init() {
        this.startBtn.addEventListener('click', () => this.start());
        this.pauseBtn.addEventListener('click', () => this.pause());
        this.resetBtn.addEventListener('click', () => this.reset());
        
        document.querySelectorAll('.preset').forEach(btn => {
            btn.addEventListener('click', () => {
                const minutes = parseInt(btn.dataset.minutes);
                this.setTime(minutes * 60);
            });
        });
    }

    setTime(seconds) {
        this.totalSeconds = seconds;
        this.remainingSeconds = seconds;
        this.updateDisplay();
        this.stop();
    }

    start() {
        if (this.remainingSeconds <= 0) return;
        if (this.isRunning) return;
        
        this.isRunning = true;
        this.startBtn.classList.add('running');
        
        this.interval = setInterval(() => {
            this.remainingSeconds--;
            this.updateDisplay();
            
            if (this.remainingSeconds <= 0) {
                this.complete();
            }
        }, 1000);
    }

    pause() {
        if (!this.isRunning) return;
        
        this.isRunning = false;
        this.startBtn.classList.remove('running');
        clearInterval(this.interval);
    }

    reset() {
        this.stop();
        this.remainingSeconds = this.totalSeconds;
        this.updateDisplay();
    }

    stop() {
        this.isRunning = false;
        this.startBtn.classList.remove('running');
        clearInterval(this.interval);
    }

    complete() {
        this.stop();
        this.remainingSeconds = 0;
        this.updateDisplay();
        this.playSound();
        this.flashScreen();
    }

    playSound() {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        
        gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);
        
        oscillator.start(audioCtx.currentTime);
        oscillator.stop(audioCtx.currentTime + 0.5);
    }

    flashScreen() {
        const container = document.querySelector('.cli-container');
        container.style.background = '#00ff8833';
        setTimeout(() => {
            container.style.background = '';
        }, 200);
    }

    updateDisplay() {
        const h = Math.floor(this.remainingSeconds / 3600);
        const m = Math.floor((this.remainingSeconds % 3600) / 60);
        const s = this.remainingSeconds % 60;
        
        this.display.textContent = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
}

window.addEventListener('DOMContentLoaded', () => {
    window.timer = new Timer();
});