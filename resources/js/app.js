export function initializePasswordToggles(root, InputElementClass) {
    root.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
        const passwordInputId = toggle.getAttribute('aria-controls');
        const passwordInput = passwordInputId ? root.getElementById(passwordInputId) : null;

        if (!(passwordInput instanceof InputElementClass) || passwordInput.type !== 'password') {
            return;
        }

        const passwordLabel = toggle.getAttribute('data-password-toggle-label') || 'パスワード';
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'text';
            passwordInput.type = isVisible ? 'password' : 'text';
            toggle.setAttribute('aria-label', `${passwordLabel}を${isVisible ? '表示' : '隠す'}`);
            toggle.textContent = isVisible ? '表示' : '隠す';
        });
    });
}

export function initializeStorageAddonPreview(root, InputElementClass) {
    const quantityInput = root.querySelector('[data-storage-addon-quantity]');
    const monthlyTotalOutput = root.querySelector('[data-storage-addon-monthly-total]');
    const capacityOutput = root.querySelector('[data-storage-addon-limit-after]');
    const capacityWarning = root.querySelector('[data-storage-addon-capacity-warning]');

    if (!(quantityInput instanceof InputElementClass) || !monthlyTotalOutput || !capacityOutput || !capacityWarning) {
        return;
    }

    const rawValues = [
        quantityInput.getAttribute('data-addon-price-jpy'),
        quantityInput.getAttribute('data-base-limit-mib'),
        quantityInput.getAttribute('data-mib-per-gib'),
        quantityInput.getAttribute('data-used-bytes'),
        quantityInput.getAttribute('data-bytes-per-mib'),
    ];
    if (rawValues.some((value) => value === null || value.trim() === '')) {
        return;
    }

    const [unitPrice, baseLimitMib, mibPerGib, usedBytes, bytesPerMib] = rawValues.map(Number);
    if (![unitPrice, baseLimitMib, mibPerGib, usedBytes, bytesPerMib].every(Number.isSafeInteger)
        || unitPrice < 0
        || baseLimitMib < 0
        || mibPerGib <= 0
        || usedBytes < 0
        || bytesPerMib <= 0) {
        return;
    }

    const formatNumber = (value) => new Intl.NumberFormat('ja-JP').format(value);
    const updatePreview = () => {
        const quantity = Number(quantityInput.value);
        if (!Number.isSafeInteger(quantity) || quantity < 0) {
            return;
        }

        const monthlyTotal = quantity * unitPrice;
        const projectedLimitMib = baseLimitMib + (quantity * mibPerGib);
        const projectedLimitBytes = projectedLimitMib * bytesPerMib;
        if (!Number.isSafeInteger(monthlyTotal) || !Number.isSafeInteger(projectedLimitBytes)) {
            return;
        }

        monthlyTotalOutput.textContent = `月額${formatNumber(monthlyTotal)}円（税別）`;
        capacityOutput.textContent = `${new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 1 }).format(projectedLimitMib / mibPerGib)}GiB`;
        capacityWarning.hidden = usedBytes <= projectedLimitBytes;
        if (!capacityWarning.hidden) {
            capacityWarning.textContent = 'この容量に変更すると現在の使用量が上限を超えます。次回更新日以降、新しいファイルを保存できません。既存ファイルは削除されません。';
        }
    };

    quantityInput.addEventListener('input', updatePreview);
    updatePreview();
}

if (typeof document !== 'undefined' && typeof HTMLInputElement !== 'undefined') {
    initializePasswordToggles(document, HTMLInputElement);
    initializeStorageAddonPreview(document, HTMLInputElement);
}
