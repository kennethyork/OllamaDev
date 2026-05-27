class Stopwatch {
    constructor() {
        this.display = document.getElementById('stopwatch-display');
        this.startBtn = document.getElementById('sw-start');
        this.pauseBtn = document.getElementById('sw-pause');
        this.resetBtn = document.getElementById('sw-reset');
        this.lapsContainer = document.getElementById('laps');
        
        this.elapsedSeconds = 0;
        this.interval = null;
        this.isRunning = false;
        this.lapCount = 0;
        
        this.init();
    }

    init() {
        this.startBtn.addEventListener('click', () => this.start());
        this.pauseBtn.addEventListener('click', () => this.pause());
        this.resetBtn.addEventListener('click', () => this.reset());
        
        this.startBtn.addEventListener('click', () => this.recordLap());
    }

    start() {
        if (this.isRunning) return;
        
        this.isRunning = true;
        this.startBtn.classList.add('running');
        
        const startTime = Date.now() - (this.elapsedSeconds * 1000);
        
        this.interval = setInterval(() => {
            this.elapsedSeconds = Math.floor((Date.now() - startTime) / 1000);
            this.updateDisplay();
        }, 100);
    }

    pause() {
        if (!this.isRunning) return;
        
        this.isRunning = false;
        this.startBtn.classList.remove('running');
        clearInterval(this.interval);
    }

    reset() {
        this.stop();
        this.elapsedSeconds = 0;
        this.lapCount = 0;
        this.lapsContainer.innerHTML = '';
        this.updateDisplay();
    }

    stop() {
        this.isRunning = false;
        this.startBtn.classList.remove('running');
        clearInterval(this.interval);
    }

    recordLap() {
        if (this.elapsedSeconds === 0) return;
        
        this.lapCount++;
        
        const lapItems = this.lapsContainer.querySelectorAll('.lap-item');
        let previousTime = 0;
        
        if (lapItems.length > 0) {
            const lastLapText = lapItems[lapItems.length - 1].querySelector('span:last-child').textContent;
            previousTime = this.parseTime(lastLapText);
        }
        
        const lapTime = this.elapsedSeconds - previousTime;
        
        const lap = document.createElement('div');
        lap.className = 'lap-item';
        lap.innerHTML = `
            <span>Lap ${this.lapCount}</span>
            <span>${this.formatTime(lapTime)}</span>
        `;
        
        this.lapsContainer.insertBefore(lap, this.lapsContainer.firstChild);
        
        while (this.lapsContainer.children.length > 10) {
            this.lapsContainer.removeChild(this.lapsContainer.lastChild);
        }
    }

    parseTime(timeStr) {
        const parts = timeStr.split(':');
        return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60 + parseInt(parts[2]);
    }

    formatTime(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    updateDisplay() {
        this.display.textContent = this.formatTime(this.elapsedSeconds);
    }
}

window.addEventListener('DOMContentLoaded', () => {
    window.stopwatch = new Stopwatch();
});