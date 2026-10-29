import usePostalPH from 'use-postal-ph';

const { fetchDataLists } = usePostalPH();

const normalize = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/\b(city|municipality|municipalities|province|of)\b/g, ' ')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();

const regionCode = (value) => {
    const region = normalize(value);
    const aliases = {
        'national capital region': 'ncr',
        'cordillera administrative region': 'car',
        'central luzon': 'iii',
        'calabarzon': 'iv a',
        'mimaropa': 'iv b',
        'bicol region': 'v',
        'western visayas': 'vi',
        'central visayas': 'vii',
        'eastern visayas': 'viii',
        'zamboanga peninsula': 'ix',
        'northern mindanao': 'x',
        'davao region': 'xi',
        'soccsksargen': 'xii',
        'caraga': 'xiii',
        'bangsamoro autonomous region in muslim mindanao': 'barmm',
    };

    return aliases[region] ?? region;
};

/** Return a postal code only when use-postal-ph identifies one unambiguous place. */
export function postalCodeFromAddress({ region, province, municipality, barangay }) {
    const rows = fetchDataLists().data ?? [];
    const wantedRegion = regionCode(region);
    const wantedProvince = normalize(province);
    const wantedMunicipality = normalize(municipality);
    const wantedBarangay = normalize(barangay);

    const uniqueCode = (candidates) => {
        const codes = [...new Set(candidates.map((row) => String(row.post_code ?? '')).filter(Boolean))];
        if (codes.length === 1) return codes[0];
        if (!wantedRegion) return '';
        const regionalCodes = [...new Set(candidates
            .filter((row) => regionCode(row.region) === wantedRegion)
            .map((row) => String(row.post_code ?? ''))
            .filter(Boolean))];
        return regionalCodes.length === 1 ? regionalCodes[0] : '';
    };

    const provinceMunicipality = rows.filter((row) =>
        normalize(row.location) === wantedProvince
        && normalize(row.municipality) === wantedMunicipality
    );
    const byProvinceAndMunicipality = uniqueCode(provinceMunicipality);
    if (byProvinceAndMunicipality) return byProvinceAndMunicipality;

    const cityAndBarangay = rows.filter((row) =>
        normalize(row.location) === wantedMunicipality
        && normalize(row.municipality) === wantedBarangay
    );

    return uniqueCode(cityAndBarangay);
}

/** Auto-fill postal codes on free-text address forms when a complete match is found. */
export function initPostalAddressForm(form) {
    const province = form.querySelector('[data-postal-province]');
    const municipality = form.querySelector('[data-postal-municipality]');
    const barangay = form.querySelector('[data-postal-barangay]');
    const region = form.querySelector('[data-postal-region]');
    const postal = form.querySelector('[data-postal-code]');
    const status = form.querySelector('[data-postal-status]');
    const regionCodeField = form.querySelector('[data-postal-region-code]');
    const provinceCodeField = form.querySelector('[data-postal-province-code]');
    const municipalityCodeField = form.querySelector('[data-postal-municipality-code]');
    const barangayCodeField = form.querySelector('[data-postal-barangay-code]');

    if (!municipality) return;

    if ([region, province, municipality, barangay].some((field) => field?.tagName === 'SELECT')) {
        initPostalAddressDropdowns({
            form, region, province, municipality, barangay, postal, status,
            regionCodeField, provinceCodeField, municipalityCodeField, barangayCodeField,
        });
        return;
    }

    if (!postal) return;

    let requestVersion = 0;
    let fallbackTimer;
    const update = () => {
        const currentRequest = ++requestVersion;
        window.clearTimeout(fallbackTimer);
        const code = postalCodeFromAddress({
            region: region?.value,
            province: province?.value,
            municipality: municipality.value,
            barangay: barangay?.value,
        });

        if (code) {
            postal.value = code;
            postal.dataset.autofilled = 'true';
            return;
        }

        postal.value = '';
        postal.dataset.autofilled = 'false';
        const base = form.dataset.postalBase;
        if (!base || !municipality.value.trim() || !province?.value.trim()) return;

        const params = new URLSearchParams({
            province_name: province.value.trim(),
            municipality_name: municipality.value.trim(),
        });

        fallbackTimer = window.setTimeout(async () => {
            try {
                const response = await fetch(`${base}/postal-code?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) return;
                const result = await response.json();
                if (currentRequest !== requestVersion) return;
                postal.value = result.postal_code ?? '';
                postal.dataset.autofilled = postal.value ? 'true' : 'false';
            } catch (_) {
                if (currentRequest === requestVersion) postal.value = '';
            }
        }, 250);
    };

    [region, province, municipality, barangay].filter(Boolean).forEach((input) => {
        input.addEventListener('input', update);
        input.addEventListener('change', update);
    });
}

function initPostalAddressDropdowns({
    form, region, province, municipality, barangay, postal, status,
    regionCodeField, provinceCodeField, municipalityCodeField, barangayCodeField,
}) {
    if (!region || !province || !municipality || !barangay) return;

    const base = form.dataset.postalBase;
    const old = {
        region: [region.dataset.oldValue, region.dataset.oldCode],
        province: [province.dataset.oldValue, province.dataset.oldCode],
        municipality: [municipality.dataset.oldValue, municipality.dataset.oldCode],
        barangay: [barangay.dataset.oldValue, barangay.dataset.oldCode],
    };
    const allowedProvinceCode = String(form.dataset.postalAllowedProvinceCode ?? '');
    const allowedMunicipalityCode = String(form.dataset.postalAllowedMunicipalityCode ?? '');
    const provinceField = province.closest('label');
    const requests = new Map();
    let postalRequest = 0;

    const setProvinceFieldVisible = (visible) => {
        if (provinceField) provinceField.hidden = !visible;
    };

    const setStatus = (message, error = false) => {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('text-red-700', error);
        status.classList.toggle('text-stone-500', !error);
    };

    const clearPostal = (message = 'Postal code will fill after you select a barangay.') => {
        postalRequest += 1;
        if (postal) postal.value = '';
        setStatus(message);
    };

    const resetSelect = (select, label) => {
        select.replaceChildren(new Option(label, ''));
        select.disabled = true;
    };

    const recordsFrom = (payload) => Array.isArray(payload)
        ? payload
        : (payload?.data ?? payload?.regions ?? payload?.provinces ?? payload?.municipalities ?? payload?.barangays ?? []);

    const fetchRecords = async (key, url, select, placeholder) => {
        requests.get(key)?.abort();
        const controller = new AbortController();
        requests.set(key, controller);
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Address lookup failed (${response.status})`);
        let records = recordsFrom(await response.json());
        if (requests.get(key) !== controller) return [];

        if (key === 'provinces' && allowedProvinceCode) {
            records = records.filter((record) => String(record.code ?? record.id ?? record.value ?? '') === allowedProvinceCode);
        }
        if (key === 'municipalities' && allowedMunicipalityCode) {
            records = records.filter((record) => String(record.code ?? record.id ?? record.value ?? '') === allowedMunicipalityCode);
        }

        resetSelect(select, placeholder);
        records.forEach((record) => {
            const code = record.code ?? record.id ?? record.value ?? '';
            const label = record.name ?? record.label ?? record.description ?? code;
            const option = new Option(label, label);
            option.dataset.code = code;
            option.dataset.prv = record.prv ?? '';
            option.dataset.mun = record.mun ?? '';
            option.dataset.level = record.level ?? '';
            select.add(option);
        });
        select.disabled = select.options.length <= 1;
        return records;
    };

    const pickOldValue = (select, key) => {
        const [name, code] = old[key];
        old[key] = ['', ''];
        const allowedCode = key === 'province' ? allowedProvinceCode : key === 'municipality' ? allowedMunicipalityCode : '';
        if (allowedCode) {
            const allowed = [...select.options].find((option) => option.dataset.code === allowedCode);
            if (allowed) return allowed.value;
        }
        if (code) {
            const byCode = [...select.options].find((option) => option.dataset.code === code);
            if (byCode) return byCode.value;
        }
        return [...select.options].find((option) => option.value === name)?.value ?? '';
    };

    region.addEventListener('change', async () => {
        const selected = region.selectedOptions[0];
        if (regionCodeField) regionCodeField.value = selected?.dataset.code ?? '';
        if (provinceCodeField) provinceCodeField.value = '';
        if (municipalityCodeField) municipalityCodeField.value = '';
        if (barangayCodeField) barangayCodeField.value = '';
        setProvinceFieldVisible(true);
        resetSelect(province, 'Select province');
        resetSelect(municipality, 'Select municipality / city');
        resetSelect(barangay, 'Select barangay');
        clearPostal();
        if (!selected?.value) return;

        try {
            const regionCode = selected.dataset.code || selected.value;
            const records = await fetchRecords('provinces', `${base}/regions/${encodeURIComponent(regionCode)}/provinces`, province, 'Select province');

            const regionParent = records.find((record) =>
                record.level === 'Region' && String(record.code ?? record.id ?? record.value ?? '') === String(regionCode)
            );
            if (regionParent) {
                province.value = [...province.options].find((option) => option.dataset.code === String(regionCode))?.value ?? '';
                old.province = ['', ''];
                setProvinceFieldVisible(false);
                if (province.value) province.dispatchEvent(new Event('change'));
                return;
            }

            const value = pickOldValue(province, 'province');
            if (value) {
                province.value = value;
                province.dispatchEvent(new Event('change'));
            }
        } catch (error) {
            if (error.name !== 'AbortError') setStatus('Could not load provinces. Change the region or try again.', true);
        }
    });

    province.addEventListener('change', async () => {
        const selected = province.selectedOptions[0];
        setProvinceFieldVisible(!selected?.value || selected.dataset.level !== 'Region');
        if (provinceCodeField) provinceCodeField.value = selected?.dataset.code ?? '';
        if (municipalityCodeField) municipalityCodeField.value = '';
        if (barangayCodeField) barangayCodeField.value = '';
        resetSelect(municipality, 'Select municipality / city');
        resetSelect(barangay, 'Select barangay');
        clearPostal();
        if (!selected?.value) return;

        try {
            if (selected.dataset.level === 'City') {
                const option = new Option(selected.value, selected.value);
                option.dataset.code = selected.dataset.code ?? '';
                option.dataset.prv = selected.dataset.prv ?? '';
                option.dataset.mun = selected.dataset.mun ?? '';
                municipality.add(option);
                municipality.disabled = false;
                municipality.value = option.value;
                old.municipality = ['', ''];
                old.barangay = ['', ''];
                municipality.dispatchEvent(new Event('change'));
                return;
            } else {
                await fetchRecords(
                    'municipalities',
                    `${base}/provinces/${encodeURIComponent(selected.dataset.code || selected.value)}/municipalities?prv=${encodeURIComponent(selected.dataset.prv || selected.dataset.code || '')}`,
                    municipality,
                    'Select municipality / city',
                );
            }

            const value = pickOldValue(municipality, 'municipality');
            if (value) {
                municipality.value = value;
                municipality.dispatchEvent(new Event('change'));
            }
        } catch (error) {
            if (error.name !== 'AbortError') setStatus('Could not load municipalities. Choose the province again to retry.', true);
        }
    });

    municipality.addEventListener('change', async () => {
        const selected = municipality.selectedOptions[0];
        if (municipalityCodeField) municipalityCodeField.value = selected?.dataset.code ?? '';
        if (barangayCodeField) barangayCodeField.value = '';
        resetSelect(barangay, 'Select barangay');
        clearPostal();
        if (!selected?.value) return;

        try {
            await fetchRecords(
                'barangays',
                `${base}/municipalities/${encodeURIComponent(selected.dataset.code || selected.value)}/barangays?mun=${encodeURIComponent(selected.dataset.mun || selected.value)}&prv=${encodeURIComponent(selected.dataset.prv || province.selectedOptions[0]?.dataset.prv || '')}`,
                barangay,
                'Select barangay',
            );
            const value = pickOldValue(barangay, 'barangay');
            if (value) {
                barangay.value = value;
                barangay.dispatchEvent(new Event('change'));
            }
        } catch (error) {
            if (error.name !== 'AbortError') setStatus('Could not load barangays. Choose the municipality again to retry.', true);
        }
    });

    barangay.addEventListener('change', async () => {
        const selectedBarangay = barangay.selectedOptions[0];
        if (barangayCodeField) barangayCodeField.value = selectedBarangay?.dataset.code ?? '';
        clearPostal(selectedBarangay?.value ? 'Finding postal code…' : 'Postal code will fill after you select a barangay.');
        if (!selectedBarangay?.value) return;

        const currentRequest = postalRequest;
        const packageCode = postalCodeFromAddress({
            region: region.selectedOptions[0]?.textContent,
            province: province.selectedOptions[0]?.textContent,
            municipality: municipality.selectedOptions[0]?.textContent,
            barangay: selectedBarangay.textContent,
        });
        if (packageCode) {
            if (postal) postal.value = packageCode;
            setStatus(`Postal code: ${packageCode}`);
            return;
        }

        const selectedProvince = province.selectedOptions[0];
        const selectedMunicipality = municipality.selectedOptions[0];
        const params = new URLSearchParams({
            province: selectedProvince?.dataset.code ?? '',
            municipality: selectedMunicipality?.dataset.code ?? '',
            barangay: selectedBarangay.dataset.code ?? '',
            province_name: selectedProvince?.textContent?.trim() ?? '',
            municipality_name: selectedMunicipality?.textContent?.trim() ?? '',
            barangay_name: selectedBarangay.textContent.trim(),
        });
        try {
            const response = await fetch(`${base}/postal-code?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const result = response.ok ? await response.json() : null;
            if (currentRequest !== postalRequest) return;
            if (result?.postal_code) {
                if (postal) postal.value = result.postal_code;
                setStatus(`Postal code: ${result.postal_code}`);
            } else {
                setStatus('Postal code was not found for this city. Try selecting the address again.', true);
            }
        } catch (_) {
            if (currentRequest === postalRequest) setStatus('Could not look up the postal code. Try selecting the barangay again.', true);
        }
    });

    resetSelect(province, 'Select province');
    resetSelect(municipality, 'Select municipality / city');
    resetSelect(barangay, 'Select barangay');
    region.replaceChildren(new Option('Loading regions…', ''));
    region.disabled = true;
    fetchRecords('regions', `${base}/regions`, region, 'Select region')
        .then(() => {
            region.disabled = false;
            const value = pickOldValue(region, 'region');
            if (value) {
                region.value = value;
                region.dispatchEvent(new Event('change'));
            }
        })
        .catch((error) => {
            if (error.name !== 'AbortError') {
                resetSelect(region, 'Address service unavailable. Refresh to retry.');
                region.disabled = false;
                setStatus('Could not load Philippine locations. Refresh the page to retry.', true);
            }
        });
}
