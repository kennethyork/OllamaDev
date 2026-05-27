class Calculator {
    constructor() {
        this.display = document.getElementById('calc-display');
        this.current = '0';
        this.previous = '';
        this.operator = null;
        this.waitForSecondOperand = false;
        this.init();
    }

    init() {
        document.querySelectorAll('.btn.num').forEach(btn => {
            btn.addEventListener('click', () => this.inputDigit(btn.dataset.value));
        });

        document.querySelectorAll('.btn.op').forEach(btn => {
            btn.addEventListener('click', () => this.handleOperator(btn.dataset.action));
        });

        document.querySelector('[data-action="clear"]').addEventListener('click', () => this.clear());
        document.querySelector('[data-action="negate"]').addEventListener('click', () => this.negate());
        document.querySelector('[data-action="percent"]').addEventListener('click', () => this.percent());
        document.querySelector('[data-action="decimal"]').addEventListener('click', () => this.decimal());
        document.querySelector('[data-action="equals"]').addEventListener('click', () => this.calculate());

        document.addEventListener('keydown', (e) => this.handleKeyboard(e));
    }

    inputDigit(digit) {
        if (this.waitForSecondOperand) {
            this.current = digit;
            this.waitForSecondOperand = false;
        } else {
            this.current = this.current === '0' ? digit : this.current + digit;
        }
        this.updateDisplay();
    }

    decimal() {
        if (this.waitForSecondOperand) {
            this.current = '0.';
            this.waitForSecondOperand = false;
        } else if (!this.current.includes('.')) {
            this.current += '.';
        }
        this.updateDisplay();
    }

    handleOperator(op) {
        const inputValue = parseFloat(this.current);

        if (this.operator && this.waitForSecondOperand) {
            this.operator = op;
            return;
        }

        if (this.previous === '') {
            this.previous = this.current;
        } else if (this.operator) {
            const result = this.calculateResult();
            this.current = String(result);
            this.previous = this.current;
        }

        this.operator = op;
        this.waitForSecondOperand = true;
        this.updateDisplay();
    }

    calculate() {
        if (this.operator === null || this.waitForSecondOperand) return;
        const result = this.calculateResult();
        this.current = String(result);
        this.previous = '';
        this.operator = null;
        this.waitForSecondOperand = true;
        this.updateDisplay();
    }

    calculateResult() {
        const prev = parseFloat(this.previous);
        const current = parseFloat(this.current);
        let result;

        switch (this.operator) {
            case 'add': result = prev + current; break;
            case 'subtract': result = prev - current; break;
            case 'multiply': result = prev * current; break;
            case 'divide': result = current !== 0 ? prev / current : 'Error'; break;
            default: result = current;
        }

        return result;
    }

    clear() {
        this.current = '0';
        this.previous = '';
        this.operator = null;
        this.waitForSecondOperand = false;
        this.updateDisplay();
    }

    negate() {
        this.current = String(-parseFloat(this.current));
        this.updateDisplay();
    }

    percent() {
        this.current = String(parseFloat(this.current) / 100);
        this.updateDisplay();
    }

    updateDisplay() {
        let display = this.current;
        if (display.length > 12) {
            display = parseFloat(display).toExponential(6);
        }
        this.display.textContent = display;
    }

    handleKeyboard(e) {
        if (e.key >= '0' && e.key <= '9') this.inputDigit(e.key);
        else if (e.key === '.') this.decimal();
        else if (e.key === '+') this.handleOperator('add');
        else if (e.key === '-') this.handleOperator('subtract');
        else if (e.key === '*') this.handleOperator('multiply');
        else if (e.key === '/') this.handleOperator('divide');
        else if (e.key === 'Enter' || e.key === '=') this.calculate();
        else if (e.key === 'Escape' || e.key === 'c' || e.key === 'C') this.clear();
        else if (e.key === 'Backspace') {
            if (this.current.length > 1) {
                this.current = this.current.slice(0, -1);
            } else {
                this.current = '0';
            }
            this.updateDisplay();
        }
    }
}

window.addEventListener('DOMContentLoaded', () => {
    window.calc = new Calculator();
});