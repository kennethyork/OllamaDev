document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;

            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            contents.forEach(c => {
                c.classList.remove('active');
                if (c.id === target) c.classList.add('active');
            });

            if (target === 'calculator' && window.calc) window.calc.updateDisplay();
            if (target === 'timer' && window.timer) window.timer.updateDisplay();
            if (target === 'stopwatch' && window.stopwatch) window.stopwatch.updateDisplay();
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'c') switchTab('calculator');
        else if (e.key === 't') switchTab('timer');
        else if (e.key === 's') switchTab('stopwatch');
    });

    function switchTab(name) {
        tabs.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === name);
        });
        contents.forEach(c => {
            c.classList.toggle('active', c.id === name);
        });
    }
});