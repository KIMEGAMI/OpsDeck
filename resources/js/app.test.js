import assert from 'node:assert/strict';
import test from 'node:test';
import { initializePasswordToggles, initializeStorageAddonPreview } from './app.js';

class TestInput {
    constructor(type = 'password') {
        this.type = type;
    }
}

class TestToggle {
    constructor(inputId, passwordLabel = null) {
        this.attributes = new Map([['aria-controls', inputId]]);
        if (passwordLabel !== null) {
            this.attributes.set('data-password-toggle-label', passwordLabel);
        }
        this.hidden = true;
        this.textContent = '表示';
        this.clickHandler = null;
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    addEventListener(eventName, handler) {
        if (eventName === 'click') {
            this.clickHandler = handler;
        }
    }

    click() {
        this.clickHandler?.();
    }
}

class TestRoot {
    constructor(toggles, inputs) {
        this.toggles = toggles;
        this.inputs = inputs;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '[data-password-toggle]');

        return this.toggles;
    }

    getElementById(id) {
        return this.inputs.get(id) ?? null;
    }
}

class TestPreviewElement {
    constructor(attributes = new Map(), value = '') {
        this.attributes = attributes;
        this.value = value;
        this.textContent = '';
        this.hidden = true;
        this.inputHandler = null;
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    addEventListener(eventName, handler) {
        if (eventName === 'input') {
            this.inputHandler = handler;
        }
    }

    dispatchInput(value) {
        this.value = value;
        this.inputHandler?.();
    }
}

class TestPreviewRoot {
    constructor(elements) {
        this.elements = elements;
    }

    querySelector(selector) {
        return this.elements.get(selector) ?? null;
    }
}

test('password toggle reveals and masks only its associated input with an updated accessible label', () => {
    const passwordInput = new TestInput;
    const confirmationInput = new TestInput;
    const passwordToggle = new TestToggle('password');
    const confirmationToggle = new TestToggle('password_confirmation', '確認用パスワード');
    const root = new TestRoot(
        [passwordToggle, confirmationToggle],
        new Map([
            ['password', passwordInput],
            ['password_confirmation', confirmationInput],
        ]),
    );

    initializePasswordToggles(root, TestInput);

    assert.equal(passwordToggle.hidden, false);
    assert.equal(confirmationToggle.hidden, false);
    confirmationToggle.click();
    assert.equal(confirmationInput.type, 'text');
    assert.equal(confirmationToggle.textContent, '隠す');
    assert.equal(confirmationToggle.getAttribute('aria-label'), '確認用パスワードを隠す');
    assert.equal(passwordInput.type, 'password');

    confirmationToggle.click();
    assert.equal(confirmationInput.type, 'password');
    assert.equal(confirmationToggle.textContent, '表示');
    assert.equal(confirmationToggle.getAttribute('aria-label'), '確認用パスワードを表示');
});

test('password toggle stays hidden when its target is missing or is not a password input', () => {
    const missingTargetToggle = new TestToggle('missing-password');
    const textInput = new TestInput('text');
    const textInputToggle = new TestToggle('already-visible');
    const root = new TestRoot(
        [missingTargetToggle, textInputToggle],
        new Map([['already-visible', textInput]]),
    );

    initializePasswordToggles(root, TestInput);

    assert.equal(missingTargetToggle.hidden, true);
    assert.equal(missingTargetToggle.clickHandler, null);
    assert.equal(textInputToggle.hidden, true);
    assert.equal(textInputToggle.clickHandler, null);
    assert.equal(textInput.type, 'text');
});

test('storage addon preview updates monthly price, next limit, and over-capacity warning', () => {
    const quantityInput = new TestPreviewElement(new Map([
        ['data-addon-price-jpy', '100'],
        ['data-base-limit-mib', '2048'],
        ['data-mib-per-gib', '1024'],
        ['data-used-bytes', '5368709120'],
        ['data-bytes-per-mib', '1048576'],
    ]), '2');
    const monthlyTotalOutput = new TestPreviewElement;
    const capacityOutput = new TestPreviewElement;
    const capacityWarning = new TestPreviewElement;
    const root = new TestPreviewRoot(new Map([
        ['[data-storage-addon-quantity]', quantityInput],
        ['[data-storage-addon-monthly-total]', monthlyTotalOutput],
        ['[data-storage-addon-limit-after]', capacityOutput],
        ['[data-storage-addon-capacity-warning]', capacityWarning],
    ]));

    initializeStorageAddonPreview(root, TestPreviewElement);

    assert.equal(monthlyTotalOutput.textContent, '月額200円（税別）');
    assert.equal(capacityOutput.textContent, '4GiB');
    assert.equal(capacityWarning.hidden, false);

    quantityInput.dispatchInput('3');

    assert.equal(monthlyTotalOutput.textContent, '月額300円（税別）');
    assert.equal(capacityOutput.textContent, '5GiB');
    assert.equal(capacityWarning.hidden, true);
});

test('storage addon preview stays unchanged when its configuration data is invalid', () => {
    const quantityInput = new TestPreviewElement(new Map([
        ['data-addon-price-jpy', 'invalid'],
        ['data-base-limit-mib', '2048'],
        ['data-mib-per-gib', '1024'],
        ['data-used-bytes', '0'],
        ['data-bytes-per-mib', '1048576'],
    ]), '1');
    const monthlyTotalOutput = new TestPreviewElement;
    const capacityOutput = new TestPreviewElement;
    const capacityWarning = new TestPreviewElement;
    const root = new TestPreviewRoot(new Map([
        ['[data-storage-addon-quantity]', quantityInput],
        ['[data-storage-addon-monthly-total]', monthlyTotalOutput],
        ['[data-storage-addon-limit-after]', capacityOutput],
        ['[data-storage-addon-capacity-warning]', capacityWarning],
    ]));

    initializeStorageAddonPreview(root, TestPreviewElement);

    assert.equal(monthlyTotalOutput.textContent, '');
    assert.equal(capacityOutput.textContent, '');
    assert.equal(capacityWarning.hidden, true);
    assert.equal(quantityInput.inputHandler, null);
});
