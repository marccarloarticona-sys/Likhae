import '../address/forms.js';
import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';

document.addEventListener('DOMContentLoaded', () => {
    const select = (selector, root = document) => root.querySelector(selector);
    const selectAll = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const sidebar = select('[data-sl-sidebar]');
    const overlay = select('[data-sl-overlay]');
    const mobileButton = select('[data-sl-mobile-menu]');
    const collapseButton = select('[data-sl-sidebar-toggle]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const storageKey = 'likhae-seller-sidebar-collapsed';
    let toastTimer;

    const readStorage = (key) => {
        try { return window.localStorage.getItem(key); } catch (error) { return null; }
    };

    const writeStorage = (key, value) => {
        try { window.localStorage.setItem(key, value); } catch (error) { /* State remains session-only. */ }
    };

    const showToast = (message) => {
        const toast = select('[data-sl-toast]');
        if (!toast || !message) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };
    window.slShowToast = showToast;

    const setMobileSidebar = (open) => {
        if (!sidebar) return;
        sidebar.classList.toggle('is-open', open);
        overlay?.classList.toggle('is-open', open);
        document.body.classList.toggle('sl-mobile-open', open && !desktop.matches);
        mobileButton?.setAttribute('aria-expanded', String(open));
    };

    const setCollapsed = (collapsed, persist = true) => {
        if (!sidebar) return;
        if (!desktop.matches) collapsed = false;
        document.body.classList.toggle('sl-sidebar-collapsed', collapsed);
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        collapseButton?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        if (persist) writeStorage(storageKey, String(collapsed));
    };

    if (desktop.matches && readStorage(storageKey) === 'true') setCollapsed(true, false);
    mobileButton?.addEventListener('click', () => setMobileSidebar(!sidebar?.classList.contains('is-open')));
    overlay?.addEventListener('click', () => setMobileSidebar(false));
    collapseButton?.addEventListener('click', () => setCollapsed(!document.body.classList.contains('sl-sidebar-collapsed')));

    desktop.addEventListener('change', (event) => {
        setMobileSidebar(false);
        setCollapsed(event.matches && readStorage(storageKey) === 'true', false);
    });

    selectAll('.sl-sidebar a').forEach((link) => link.addEventListener('click', () => {
        if (!desktop.matches) setMobileSidebar(false);
    }));

    selectAll('[data-nav-toggle]').forEach((button) => {
        const submenu = button.nextElementSibling;
        if (!submenu?.matches('[data-nav-submenu]')) return;

        button.addEventListener('click', () => {
            const open = !button.classList.contains('is-open');
            selectAll('[data-nav-toggle]').forEach((other) => {
                if (other === button) return;
                const otherMenu = other.nextElementSibling;
                other.classList.remove('is-open');
                other.setAttribute('aria-expanded', 'false');
                if (otherMenu?.matches('[data-nav-submenu]')) {
                    otherMenu.classList.remove('is-open');
                    window.setTimeout(() => { if (!otherMenu.classList.contains('is-open')) otherMenu.hidden = true; }, 200);
                }
            });

            button.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', String(open));
            if (open) {
                submenu.hidden = false;
                window.requestAnimationFrame(() => submenu.classList.add('is-open'));
            } else {
                submenu.classList.remove('is-open');
                window.setTimeout(() => { if (!submenu.classList.contains('is-open')) submenu.hidden = true; }, 200);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            const search = select('.sl-header-search input');
            if (search) { event.preventDefault(); search.focus(); }
        }
        if (event.key === 'Escape') {
            setMobileSidebar(false);
            closeModal(select('.sl-modal:not([hidden])'));
        }
    });

    const openModal = (modal) => {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        select('input, select, textarea, button', modal)?.focus();
    };

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    selectAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => {
        openModal(select(`[data-modal="${button.dataset.modalOpen}"]`));
    }));
    selectAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('[data-modal]'))));
    selectAll('[data-modal]').forEach((modal) => modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    }));

    const characterField = select('[data-character-count]');
    if (characterField) {
        const textarea = characterField.closest('.sl-field')?.querySelector('textarea');
        const updateCount = () => { characterField.textContent = String(textarea?.value.length || 0); };
        textarea?.addEventListener('input', updateCount);
        updateCount();
    }

    const filesFrom = (selector) => Array.from(select(selector)?.files || []);
    const imageLibraryItems = () => selectAll('[data-library-image]:not(.is-removing), [data-new-library-image]');

    const clearVariantImageAssignments = (imageRef) => {
        if (!imageRef) return;
        variationRows().forEach((row) => {
            const ref = rowField(row, 'product_image_ref');
            if (ref?.value !== imageRef) return;
            ref.value = '';
            syncVariantImageButton(row);
        });
    };

    const renderFilePreview = (file, alt, className = '') => {
        const image = document.createElement('img');
        image.className = className;
        image.src = URL.createObjectURL(file);
        image.alt = alt;
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        return image;
    };

    const syncProductImageLibrary = () => {
        const primaryFiles = filesFrom('[data-image-input]');
        const additionalFiles = filesFrom('[data-images-input]');
        const newLibrary = select('[data-new-product-image-library]');
        const primaryPreview = select('[data-image-preview]');
        if (!newLibrary) return;

        if (primaryPreview) {
            if (primaryFiles[0]) {
                primaryPreview.innerHTML = '';
                primaryPreview.appendChild(renderFilePreview(primaryFiles[0], primaryFiles[0].name));
            }
        }
        newLibrary.innerHTML = '';
        const existingCount = selectAll('[data-library-image]:not(.is-removing)').length;
        const renderNewImage = (file, ref, number, primary = false, index = 0) => {
            const item = document.createElement('span');
            item.className = 'sl-saved-image sl-product-image-library__item';
            item.dataset.newLibraryImage = 'true';
            item.dataset.imageRef = ref;
            item.dataset.imageUrl = URL.createObjectURL(file);
            item.dataset.imageLabel = `#${number}`;
            item.appendChild(Object.assign(document.createElement('span'), { className: 'sl-image-number', textContent: `#${number}${primary ? ' · Primary' : ''}` }));
            item.appendChild(renderFilePreview(file, file.name));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'sl-image-remove';
            remove.dataset.removeNewProductImage = 'true';
            remove.dataset.uploadKind = primary ? 'primary' : 'additional';
            remove.dataset.uploadIndex = String(index);
            remove.textContent = 'Remove';
            item.appendChild(remove);
            newLibrary.appendChild(item);
        };

        if (primaryFiles[0]) renderNewImage(primaryFiles[0], 'upload:primary', existingCount + 1, true);
        additionalFiles.forEach((file, index) => renderNewImage(file, `upload:additional:${index}`, existingCount + (primaryFiles[0] ? 2 : 1) + index, false, index));
        syncAllVariantImageButtons();
        syncBulkImageButton();
    };

    const removeFileFromInput = (selector, index) => {
        const input = select(selector);
        if (!input?.files?.length || !window.DataTransfer) return;
        const transfer = new DataTransfer();
        Array.from(input.files).forEach((file, fileIndex) => {
            if (fileIndex !== index) transfer.items.add(file);
        });
        input.files = transfer.files;
        syncProductImageLibrary();
        select('.sl-editor-layout')?.dispatchEvent(new Event('input', { bubbles: true }));
    };

    select('[data-image-input]')?.addEventListener('change', syncProductImageLibrary);
    select('[data-images-input]')?.addEventListener('change', syncProductImageLibrary);

    const productTypeEditor = select('[data-product-type-editor]');
    if (productTypeEditor) {
        const singleFields = select('[data-single-product-fields]');
        const variationFields = select('[data-variation-product-fields]');
        const syncProductType = () => {
            const variations = select('[name="product_type"]:checked')?.value === 'variations';
            if (singleFields) singleFields.hidden = variations;
            if (variationFields) variationFields.hidden = !variations;
            selectAll('input, select, textarea', singleFields || document).forEach((field) => { field.disabled = variations; });
            selectAll('input, select, textarea', variationFields || document).forEach((field) => { field.disabled = !variations; });
        };
        selectAll('[name="product_type"]', productTypeEditor).forEach((radio) => radio.addEventListener('change', syncProductType));
        syncProductType();
    }

    const normalizeDiscountType = (value, fallback = 'none') => {
        const type = String(value || '').toLowerCase();
        return ['none', 'percentage', 'fixed'].includes(type) ? type : fallback;
    };

    const discountAmountFor = (price, type, value) => {
        const basePrice = Math.max(0, Number(price) || 0);
        const discountValue = Math.max(0, Number(value) || 0);
        const normalizedType = normalizeDiscountType(type);
        if (normalizedType === 'fixed') return Math.min(basePrice, discountValue);
        if (normalizedType === 'percentage') return Math.min(basePrice, basePrice * Math.min(100, discountValue) / 100);
        return 0;
    };

    const discountLabelFor = (type, value, money) => normalizeDiscountType(type) === 'fixed'
        ? `${money(value)} OFF`
        : `${Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits: 2 })}% OFF`;

    const syncDiscountControl = (typeField, valueField, label = null, prefix = null) => {
        if (!typeField || !valueField) return 'none';
        const type = normalizeDiscountType(typeField.value);
        const fixed = type === 'fixed';
        const percentage = type === 'percentage';
        valueField.max = percentage ? '100' : '999999.99';
        valueField.placeholder = fixed ? '0.00' : '0';
        valueField.disabled = type === 'none';
        if (type === 'none') valueField.value = '0';
        valueField.setAttribute('aria-label', fixed ? 'Fixed discount amount' : percentage ? 'Discount percentage' : 'Discount value');
        if (label) label.textContent = fixed ? 'Discount amount (₱)' : percentage ? 'Discount (%)' : 'Discount value';
        if (prefix) prefix.hidden = !fixed;
        return type;
    };

    const productForm = select('.sl-editor-layout');
    if (productForm) {
        const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const updateEditorPreview = () => {
            const variations = select('[name="product_type"]:checked', productForm)?.value === 'variations';
            const name = select('[name="name"]', productForm)?.value.trim() || 'Your product name';
            const category = select('[name="category_id"] option:checked', productForm)?.textContent.trim() || 'Subcategory';
            const singleDiscountTypeField = select('[data-single-discount-type]', productForm);
            const singleDiscountValueField = select('[data-single-discount-value]', productForm);
            const singleDiscountType = syncDiscountControl(
                singleDiscountTypeField,
                singleDiscountValueField,
                select('[data-single-discount-label]', productForm),
                select('[data-single-discount-prefix]', productForm),
            );
            const bulkDiscountTypeField = select('[data-bulk-discount-type]', productForm);
            const bulkDiscountValueField = select('[data-bulk-discount-value]', productForm);
            syncDiscountControl(
                bulkDiscountTypeField,
                bulkDiscountValueField,
                select('[data-bulk-discount-label]', productForm),
            );

            const variantPricing = selectAll('[name^="variants"][name$="[price]"]:not(:disabled)', productForm).map((field) => {
                const price = Number(field.value || 0);
                const row = field.closest('.sl-variation-row');
                const typeField = row?.querySelector('[name$="[discount_type]"]');
                const valueField = row?.querySelector('[name$="[discount_value]"]');
                const type = syncDiscountControl(typeField, valueField);
                const value = Number(valueField?.value || 0);
                const discountAmount = discountAmountFor(price, type, value);
                return {
                    price,
                    type,
                    value,
                    discountAmount,
                    finalPrice: Math.max(0, price - discountAmount),
                };
            }).filter((value) => Number.isFinite(value.price) && value.price >= 0);
            const variantPrices = variantPricing.map((item) => item.finalPrice);
            const variantStocks = selectAll('[name^="variants"][name$="[stock]"]:not(:disabled)', productForm).map((field) => Number(field.value || 0));
            const singleBasePrice = Number(select('[name="price"]:not(:disabled)', productForm)?.value || 0);
            const singleDiscountValue = Number(singleDiscountValueField?.value || 0);
            const singleDiscountAmount = discountAmountFor(singleBasePrice, singleDiscountType, singleDiscountValue);
            const singlePrice = Math.max(0, singleBasePrice - singleDiscountAmount);
            const singleStock = Number(select('[name="stock"]:not(:disabled)', productForm)?.value || 0);
            const prices = variations ? variantPrices : [singlePrice];
            const stock = variations ? variantStocks.reduce((sum, value) => sum + value, 0) : singleStock;
            const priceText = prices.length && Math.min(...prices) !== Math.max(...prices) ? `${money(Math.min(...prices))} - ${money(Math.max(...prices))}` : money(prices[0] || 0);

            if (select('[data-preview-name]')) select('[data-preview-name]').textContent = name;
            if (select('[data-preview-category]')) select('[data-preview-category]').textContent = category === 'Select subcategory' ? 'Subcategory' : category;
            if (select('[data-preview-price]')) select('[data-preview-price]').textContent = priceText;
            if (select('[data-preview-stock]')) select('[data-preview-stock]').textContent = `${stock} available`;
            const previewDiscount = select('[data-preview-discount]', productForm);
            const previewDiscounts = variations
                ? variantPricing.filter((item) => item.discountAmount > 0)
                : (singleDiscountAmount > 0 ? [{ type: singleDiscountType, value: singleDiscountValue, discountAmount: singleDiscountAmount }] : []);
            if (previewDiscount) {
                const sameDiscount = previewDiscounts.length > 0 && previewDiscounts.every((item) => item.type === previewDiscounts[0].type && item.value === previewDiscounts[0].value);
                previewDiscount.hidden = previewDiscounts.length === 0;
                previewDiscount.textContent = sameDiscount
                    ? discountLabelFor(previewDiscounts[0].type, previewDiscounts[0].value, money)
                    : 'Discount varies by variation';
            }

            const previewImage = select('[data-preview-image]', productForm);
            const previewGallery = select('[data-preview-gallery]', productForm);
            const previewOptions = select('[data-preview-options]', productForm);
            const imageItems = imageLibraryItems().filter((item) => item.dataset.imageUrl);
            const previewItems = previewGallery
                ? selectAll('[data-preview-saved-image]', previewGallery).filter((item) => item.dataset.imageUrl)
                : [];
            const galleryItems = imageItems.length ? imageItems : previewItems;
            const primaryFile = filesFrom('[data-image-input]')[0];

            if (previewImage && !primaryFile && previewImage.dataset.previewFromUpload === 'true') {
                previewImage.src = previewImage.dataset.previewDefaultSrc || previewImage.src;
                delete previewImage.dataset.previewFromUpload;
            }

            if (previewGallery) {
                const selectedPreviewUrl = previewImage?.dataset.previewSelected && galleryItems.some((item) => item.dataset.imageUrl === previewImage.dataset.previewSelected)
                    ? previewImage.dataset.previewSelected
                    : galleryItems[0]?.dataset.imageUrl;
                previewGallery.innerHTML = '';
                previewGallery.hidden = galleryItems.length === 0;
                galleryItems.forEach((item, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'sl-preview-gallery-button';
                    const selected = item.dataset.imageUrl === selectedPreviewUrl || (!selectedPreviewUrl && index === 0);
                    button.classList.toggle('is-selected', selected);
                    button.setAttribute('aria-pressed', String(selected));
                    button.setAttribute('aria-label', `Preview ${item.dataset.imageLabel || 'product image'}`);
                    button.addEventListener('click', () => {
                        if (!previewImage) return;
                        previewImage.src = item.dataset.imageUrl;
                        previewImage.dataset.previewSelected = item.dataset.imageUrl;
                        selectAll('.sl-preview-gallery-button', previewGallery).forEach((thumbnail) => thumbnail.classList.remove('is-selected'));
                        selectAll('.sl-preview-gallery-button', previewGallery).forEach((thumbnail) => thumbnail.setAttribute('aria-pressed', String(thumbnail === button)));
                        button.classList.add('is-selected');
                    });

                    const image = document.createElement('img');
                    image.src = item.dataset.imageUrl;
                    image.alt = item.dataset.imageLabel || 'Product image';
                    button.appendChild(image);
                    previewGallery.appendChild(button);
                });
            }

            if (previewOptions) {
                previewOptions.innerHTML = '';
                const optionRows = selectAll('[data-option-list] .sl-option-row');
                optionRows.forEach((row) => {
                    const optionName = select('input[name$="[name]"]', row)?.value.trim();
                    const optionValues = (select('input[name$="[values]"]', row)?.value || '')
                        .split(',')
                        .map((value) => value.trim())
                        .filter(Boolean);
                    if (!optionName && !optionValues.length) return;

                    const option = document.createElement('div');
                    option.className = 'sl-preview-option';
                    const label = document.createElement('strong');
                    label.textContent = optionName || 'Option';
                    const values = document.createElement('span');
                    values.textContent = optionValues.join(' · ');
                    option.append(label, values);
                    previewOptions.appendChild(option);
                });
                previewOptions.hidden = !variations || !previewOptions.children.length;
            }

            const checks = {
                name: name !== 'Your product name',
                category: Boolean(select('[name="category_id"]', productForm)?.value),
                image: Boolean(select('[name="image"]', productForm)?.files?.length || select('[data-image-preview] img')),
                pricing: prices.length > 0 && prices.every((value) => value >= 0),
                inventory: variations ? variantStocks.length > 0 && variantStocks.every((value) => value >= 0) : singleStock >= 0,
                variations: !variations || (variantPrices.length > 0 && selectAll('[name^="variants"][name$="[values]"]:not(:disabled)', productForm).every((field) => field.value.trim())),
            };
            Object.entries(checks).forEach(([key, complete]) => {
                const row = select(`[data-check="${key}"]`);
                if (!row) return;
                row.classList.toggle('is-complete', complete);
                const icon = select('span', row);
                if (icon) icon.textContent = complete ? '✓' : '!';
                if (key === 'variations') row.hidden = !variations;
            });
            const publish = select('[data-publish-product]');
            const applicableChecks = Object.entries(checks).filter(([key]) => variations || key !== 'variations');
            if (publish) publish.disabled = !applicableChecks.every(([, value]) => value);
        };
        productForm.addEventListener('input', updateEditorPreview);
        productForm.addEventListener('change', updateEditorPreview);
        select('[data-image-input]', productForm)?.addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            const preview = select('[data-preview-image]');
            if (file && preview) {
                preview.src = URL.createObjectURL(file);
                preview.dataset.previewFromUpload = 'true';
                delete preview.dataset.previewSelected;
            }
        });
        productForm.addEventListener('submit', (event) => {
            const button = event.submitter;
            const submittedStatus = select('[data-listing-status-field]', productForm);
            if (submittedStatus && button?.dataset.listingStatus) {
                submittedStatus.value = button.dataset.listingStatus;
            }
            if (!button) return;
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = button.dataset.listingStatus === 'draft' ? 'Saving Draft...' : 'Publishing...';
        });
        updateEditorPreview();
    }

    const optionList = select('[data-option-list]');
    const addOptionButton = select('[data-add-option]');
    const maxOptionTypes = Number(optionList?.dataset.maxOptions || 3);
    const syncOptionTypeLimit = () => {
        if (!optionList || !addOptionButton) return;
        const atLimit = selectAll('.sl-option-row', optionList).length >= maxOptionTypes;
        addOptionButton.disabled = atLimit;
        addOptionButton.title = atLimit ? `Only ${maxOptionTypes} variation types are allowed.` : '';
        addOptionButton.setAttribute('aria-disabled', String(atLimit));
    };

    addOptionButton?.addEventListener('click', () => {
        const list = optionList;
        if (!list) return;
        if (selectAll('.sl-option-row', list).length >= maxOptionTypes) {
            syncOptionTypeLimit();
            showToast(`You can add up to ${maxOptionTypes} variation types.`);
            return;
        }
        const indexes = selectAll('input[name^="options["][name$="[name]"]', list)
            .map((field) => Number(field.name.match(/^options\[(\d+)\]/)?.[1]))
            .filter((index) => Number.isInteger(index));
        const index = indexes.length ? Math.max(...indexes) + 1 : 0;
        const row = document.createElement('div');
        row.className = 'sl-option-row';
        row.innerHTML = `<label class="sl-field"><span>Variation type</span><input name="options[${index}][name]" placeholder="e.g. Color"></label><label class="sl-field"><span>Options</span><input name="options[${index}][values]" placeholder="Red, Blue, Natural"></label><button type="button" class="sl-icon-btn" data-remove-option aria-label="Remove variation type">&times;</button>`;
        list.appendChild(row);
        select('input', row)?.focus();
        syncOptionTypeLimit();
    });
    syncOptionTypeLimit();

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[character]));

    const variationRows = () => selectAll('[data-variation-list] .sl-variation-row:not(.sl-variation-labels)');
    const rowField = (row, suffix) => select(`[name$="[${suffix}]"]`, row);
    const rowValuesKey = (value) => (value || '').split(/[,|]+/).map((item) => item.trim().toLowerCase()).filter(Boolean).join('|');

    let activeImagePickerTarget = null;
    const imagePickerDialog = select('[data-image-assignment-dialog]');

    const syncVariantImageButton = (row) => {
        const ref = rowField(row, 'product_image_ref')?.value || '';
        const trigger = select('[data-variant-image-trigger]', row);
        const thumb = select('[data-variant-image-thumb]', row);
        const label = select('[data-variant-image-label]', row);
        const image = imageLibraryItems().find((item) => item.dataset.imageRef === ref);
        if (thumb) {
            thumb.innerHTML = '';
            if (image?.dataset.imageUrl) {
                const img = document.createElement('img');
                img.src = image.dataset.imageUrl;
                img.alt = image.dataset.imageLabel || 'Assigned product image';
                thumb.appendChild(img);
            }
        }
        if (label) label.textContent = image?.dataset.imageLabel || 'Choose image';
        if (trigger) trigger.dataset.imageRef = ref;
    };

    const syncAllVariantImageButtons = () => variationRows().forEach(syncVariantImageButton);

    const syncBulkImageButton = () => {
        const ref = select('[data-bulk-image-ref]')?.value || '';
        const image = imageLibraryItems().find((item) => item.dataset.imageRef === ref);
        const thumb = select('[data-bulk-image-thumb]');
        const label = select('[data-bulk-image-label]');
        if (thumb) {
            thumb.innerHTML = '';
            if (image?.dataset.imageUrl) {
                const img = document.createElement('img');
                img.src = image.dataset.imageUrl;
                img.alt = image.dataset.imageLabel || 'Selected product image';
                thumb.appendChild(img);
            }
        }
        if (label) label.textContent = image?.dataset.imageLabel || 'Choose image';
    };

    const openImagePicker = (target) => {
        if (!imagePickerDialog) return;
        activeImagePickerTarget = target;
        const currentRef = target === 'bulk'
            ? select('[data-bulk-image-ref]')?.value || ''
            : rowField(target, 'product_image_ref')?.value || '';
        const context = select('[data-image-assignment-context]', imagePickerDialog);
        if (context) context.textContent = target === 'bulk'
            ? 'Choose the existing image to apply to the selected scope.'
            : `Choose an image for ${rowField(target, 'values')?.value || 'this variant'}.`;
        const options = select('[data-image-picker-options]', imagePickerDialog);
        if (!options) return;
        options.innerHTML = '';
        const items = imageLibraryItems();
        if (!items.length) {
            options.innerHTML = '<p class="sl-image-picker-empty">Upload a primary or additional product image above first.</p>';
        }
        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `sl-image-picker-option${item.dataset.imageRef === currentRef ? ' is-selected' : ''}`;
            button.dataset.imagePickerRef = item.dataset.imageRef || '';
            const image = document.createElement('img');
            image.src = item.dataset.imageUrl || '';
            image.alt = item.dataset.imageLabel || 'Product image';
            const label = document.createElement('span');
            label.textContent = item.dataset.imageLabel || 'Product image';
            button.append(image, label);
            options.appendChild(button);
        });
        if (typeof imagePickerDialog.showModal === 'function') imagePickerDialog.showModal();
        else imagePickerDialog.hidden = false;
    };

    const closeImagePicker = () => {
        if (!imagePickerDialog) return;
        if (typeof imagePickerDialog.close === 'function' && imagePickerDialog.open) imagePickerDialog.close();
        else imagePickerDialog.hidden = true;
        activeImagePickerTarget = null;
    };

    const applyImageReference = (reference) => {
        if (!activeImagePickerTarget) return;
        if (activeImagePickerTarget === 'bulk') {
            const field = select('[data-bulk-image-ref]');
            if (field) field.value = reference || '';
            syncBulkImageButton();
        } else {
            const field = rowField(activeImagePickerTarget, 'product_image_ref');
            if (field) field.value = reference || '';
            syncVariantImageButton(activeImagePickerTarget);
        }
        closeImagePicker();
    };

    select('[data-bulk-image-trigger]')?.addEventListener('click', () => openImagePicker('bulk'));
    select('[data-image-picker-close]')?.addEventListener('click', closeImagePicker);
    select('[data-image-picker-clear]')?.addEventListener('click', () => applyImageReference(''));
    select('[data-image-picker-options]')?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-image-picker-ref]');
        if (option) applyImageReference(option.dataset.imagePickerRef || '');
    });
    syncProductImageLibrary();
    syncAllVariantImageButtons();
    syncBulkImageButton();

    select('[data-generate-variants]')?.addEventListener('click', () => {
        const optionRows = selectAll('[data-option-list] .sl-option-row');
        if (optionRows.length > maxOptionTypes) {
            showToast(`You can generate variations from up to ${maxOptionTypes} variation types.`);
            return;
        }
        const sets = optionRows.map((row) => (select('input[name$="[values]"]', row)?.value || '').split(',').map((value) => value.trim()).filter(Boolean)).filter((set) => set.length);
        if (!sets.length) return;
        const combinations = sets.reduce((rows, set) => rows.flatMap((row) => set.map((value) => [...row, value])), [[]]);
        const list = select('[data-variation-list]');
        if (!list) return;
        const labels = select('.sl-variation-labels', list);
        const previous = new Map(variationRows().map((row) => [rowValuesKey(rowField(row, 'values')?.value), row]));
        list.replaceChildren();
        if (labels) list.appendChild(labels);
        combinations.forEach((values, index) => {
            const previousRow = previous.get(rowValuesKey(values.join(', ')));
            const previousValue = (suffix, fallback = '') => rowField(previousRow, suffix)?.value || fallback;
            const isActive = previousRow ? previousValue('is_active', '1') : '1';
            const previousDiscountType = normalizeDiscountType(previousValue('discount_type', 'none'), previousValue('discount_value') ? 'percentage' : 'none');
            const row = document.createElement('div');
            row.className = 'sl-variation-row';
            row.innerHTML = `<label class="sl-variation-check"><input type="checkbox" data-variant-select aria-label="Select variation"></label><input name="variants[${index}][id]" type="hidden" value="${escapeHtml(previousValue('id'))}"><input name="variants[${index}][values]" value="${escapeHtml(values.join(', '))}" aria-label="Option values"><input name="variants[${index}][product_image_ref]" type="hidden" value="${escapeHtml(previousValue('product_image_ref'))}" data-variant-image-ref><button type="button" class="sl-variant-image-button" data-variant-image-trigger aria-label="Choose image for ${escapeHtml(values.join(' / '))}"><span class="sl-variant-image-thumb" data-variant-image-thumb></span><span data-variant-image-label>Choose image</span></button><input name="variants[${index}][sku]" value="${escapeHtml(previousValue('sku'))}" placeholder="Auto-generated SKU" aria-label="Variant SKU"><input name="variants[${index}][price]" type="number" min="0" step="0.01" value="${escapeHtml(previousValue('price'))}" placeholder="Price" aria-label="Variant price"><input name="variants[${index}][stock]" type="number" min="0" value="${escapeHtml(previousValue('stock'))}" placeholder="Stock" aria-label="Variant stock"><select name="variants[${index}][discount_type]" data-discount-type aria-label="Discount type"><option value="none" ${previousDiscountType === 'none' ? 'selected' : ''}>None</option><option value="percentage" ${previousDiscountType === 'percentage' ? 'selected' : ''}>Percentage</option><option value="fixed" ${previousDiscountType === 'fixed' ? 'selected' : ''}>Fixed</option></select><input name="variants[${index}][discount_value]" data-discount-value type="number" min="0" max="999999.99" step="0.01" value="${escapeHtml(previousValue('discount_value', '0'))}" placeholder="0" aria-label="Discount value"><select name="variants[${index}][payment_method]" aria-label="Payment method"><option value="cod_online" ${previousValue('payment_method', 'cod_online') === 'cod_online' ? 'selected' : ''}>COD + Online</option><option value="cod" ${previousValue('payment_method') === 'cod' ? 'selected' : ''}>COD</option><option value="online" ${previousValue('payment_method') === 'online' ? 'selected' : ''}>Online</option></select><select name="variants[${index}][is_active]" aria-label="Availability"><option value="1" ${isActive === '1' ? 'selected' : ''}>Available</option><option value="0" ${isActive === '0' ? 'selected' : ''}>Unavailable</option></select><button type="button" class="sl-icon-btn" data-remove-variant>&times;</button>`;
            list.appendChild(row);
        });
        syncAllVariantImageButtons();
        productForm?.dispatchEvent(new Event('input', { bubbles: true }));
    });

    const applyBulkChanges = () => {
        const scope = select('[data-bulk-scope]')?.value || 'all';
        const rows = variationRows().filter((row) => scope === 'all' || select('[data-variant-select]', row)?.checked);
        const discountType = normalizeDiscountType(select('[data-bulk-discount-type]')?.value || 'none');
        const discountValue = select('[data-bulk-discount-value]')?.value || '0';
        const payment = select('[data-bulk-payment]')?.value || 'cod_online';
        const active = select('[data-bulk-active]')?.value || '1';
        const imageRef = select('[data-bulk-image-ref]')?.value || '';
        rows.forEach((row) => {
            const value = rowField(row, 'discount_value');
            const type = rowField(row, 'discount_type');
            const method = rowField(row, 'payment_method');
            const availability = rowField(row, 'is_active');
            const image = rowField(row, 'product_image_ref');
            if (value) value.value = discountValue;
            if (type) type.value = discountType;
            if (method) method.value = payment;
            if (availability) availability.value = active;
            if (image && imageRef) image.value = imageRef;
            syncVariantImageButton(row);
        });
        productForm?.dispatchEvent(new Event('input', { bubbles: true }));
        window.slShowToast?.(`${rows.length} variation${rows.length === 1 ? '' : 's'} updated.`);
    };

    select('[data-apply-bulk]')?.addEventListener('click', applyBulkChanges);
    select('[data-clear-bulk-discount]')?.addEventListener('click', () => {
        const type = select('[data-bulk-discount-type]');
        const field = select('[data-bulk-discount-value]');
        if (type) type.value = 'none';
        if (field) field.value = '0';
        applyBulkChanges();
    });

    select('[data-add-variation]')?.addEventListener('click', () => {
        const list = select('[data-variation-list]');
        if (!list) return;
        const row = document.createElement('div');
        row.className = 'sl-variation-row';
        const defaultWeight = document.querySelector('[name="weight_grams"]')?.value || '';
        row.innerHTML = `<input name="variation_name[]" placeholder="e.g. Color" aria-label="Variation name"><input name="variation_value[]" placeholder="e.g. Navy Blue" aria-label="Variation option"><input name="variation_sku[]" placeholder="SKU" aria-label="Variation SKU"><input name="variation_price[]" type="number" min="0" step="0.01" placeholder="Price" aria-label="Variation price"><input name="variation_stock[]" type="number" min="0" aria-label="Variation stock"><input name="variation_weight_grams[]" type="number" min="1" value="${defaultWeight}" placeholder="Weight (g)" aria-label="Weight of one unit in grams"><button type="button" class="sl-icon-btn" data-remove-row aria-label="Remove variation">&times;</button>`;
        list.appendChild(row);
        select('input', row)?.focus();
    });

    document.addEventListener('click', (event) => {
        const variantImageTrigger = event.target.closest('[data-variant-image-trigger]');
        if (variantImageTrigger) {
            openImagePicker(variantImageTrigger.closest('.sl-variation-row'));
            return;
        }
        const removeButton = event.target.closest('[data-remove-row]');
        if (removeButton) removeButton.closest('.sl-variation-row')?.remove();
        const removeOption = event.target.closest('[data-remove-option]');
        if (removeOption) {
            removeOption.closest('.sl-option-row')?.remove();
            syncOptionTypeLimit();
        }
        const removeVariant = event.target.closest('[data-remove-variant]');
        if (removeVariant) removeVariant.closest('.sl-variation-row')?.remove();
        const removeSavedImage = event.target.closest('[data-remove-saved-image]');
        if (removeSavedImage) {
            const image = removeSavedImage.closest('[data-saved-image]');
            const imageId = removeSavedImage.dataset.imageId;
            if (!image || !imageId) return;
            const existing = image.querySelector('input[name="delete_image_ids[]"]');
            if (existing) {
                existing.remove();
                image.classList.remove('is-removing');
                removeSavedImage.textContent = 'Remove';
            } else {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'delete_image_ids[]';
                hidden.value = imageId;
                image.appendChild(hidden);
                image.classList.add('is-removing');
                removeSavedImage.textContent = 'Keep';
                clearVariantImageAssignments(`image:${imageId}`);
                showToast('Assignments using this image were cleared. Choose another image if needed.');
            }
            select('.sl-editor-layout')?.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const removeNewImage = event.target.closest('[data-remove-new-product-image]');
        if (removeNewImage) {
            const kind = removeNewImage.dataset.uploadKind;
            const index = Number(removeNewImage.dataset.uploadIndex || 0);
            const ref = kind === 'primary' ? 'upload:primary' : `upload:additional:${index}`;
            if (kind === 'additional') {
                variationRows().forEach((row) => {
                    const field = rowField(row, 'product_image_ref');
                    if (!field) return;
                    if (field.value === ref) field.value = '';
                    else if (field.value.startsWith('upload:additional:')) {
                        const assignedIndex = Number(field.value.split(':').pop());
                        if (assignedIndex > index) field.value = `upload:additional:${assignedIndex - 1}`;
                    }
                    syncVariantImageButton(row);
                });
            } else {
                clearVariantImageAssignments(ref);
            }
            removeFileFromInput(kind === 'primary' ? '[data-image-input]' : '[data-images-input]', index);
        }
    });

    const filterProductRows = () => {
        const query = (select('[data-product-search]')?.value || '').trim().toLowerCase();
        const status = select('[data-product-status-filter]')?.value || 'all';
        const rows = selectAll('[data-product-row]');
        let visible = 0;
        rows.forEach((row) => {
            const matchesSearch = !query || (row.dataset.productName || '').includes(query);
            const matchesStatus = status === 'all' || row.dataset.productStatus === status;
            const show = matchesSearch && matchesStatus;
            row.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-product-empty]');
        if (empty) empty.hidden = visible > 0;
    };

    select('[data-product-search]')?.addEventListener('input', filterProductRows);
    select('[data-product-status-filter]')?.addEventListener('change', filterProductRows);

    const orderSearch = select('[data-order-search]');
    orderSearch?.addEventListener('input', () => {
        const query = orderSearch.value.trim().toLowerCase();
        const cards = selectAll('[data-order-card]');
        let visible = 0;
        cards.forEach((card) => {
            const show = !query || (card.dataset.search || '').includes(query);
            card.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-order-empty]');
        if (empty) empty.hidden = visible > 0;
    });
    if (orderSearch?.value.trim()) orderSearch.dispatchEvent(new Event('input'));

    const filterConversations = () => {
        const query = (select('[data-conversation-search]')?.value || '').trim().toLowerCase();
        const activeFilter = select('[data-conversation-filter].is-active')?.dataset.conversationFilter || 'all';
        selectAll('[data-conversation]').forEach((item) => {
            const matchesQuery = !query || (item.dataset.search || '').includes(query);
            const matchesFilter = activeFilter === 'all' || (activeFilter === 'unread' && item.dataset.unread === 'true') || (activeFilter === 'orders' && item.dataset.order === 'true');
            item.hidden = !(matchesQuery && matchesFilter);
        });
    };

    select('[data-conversation-search]')?.addEventListener('input', filterConversations);
    selectAll('[data-conversation-filter]').forEach((button) => button.addEventListener('click', () => {
        selectAll('[data-conversation-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
        filterConversations();
    }));

    const filterNotifications = (type) => {
        let visible = 0;
        selectAll('[data-notification]').forEach((item) => {
            const show = type === 'all' || item.dataset.type === type;
            item.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-notification-empty]');
        if (empty) empty.hidden = visible > 0;
    };

    selectAll('[data-notification-filter]').forEach((button) => button.addEventListener('click', () => {
        selectAll('[data-notification-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
        filterNotifications(button.dataset.notificationFilter);
    }));

    select('[data-mark-all-read]')?.addEventListener('click', () => {
        selectAll('[data-notification].is-unread').forEach((item) => {
            item.classList.remove('is-unread');
            Array.from(item.children).find((child) => child.tagName === 'I')?.remove();
        });
        showToast('All notifications marked as read.');
    });
});
