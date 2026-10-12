import { postalCodeFromAddress } from '../address/postal-code.js';

document.addEventListener('DOMContentLoaded', () => {

    /*
    |--------------------------------------------------------------------------
    | ELEMENTS
    |--------------------------------------------------------------------------
    */

    const form =
        document.getElementById(
            'registrationForm'
        );

    const emailInput =
        document.getElementById(
            'email'
        );
    const contactNumberInput = document.getElementById('contact_number');
    contactNumberInput?.addEventListener('input', () => {
        let digits = contactNumberInput.value.replace(/\D/g, '');
        if (digits.startsWith('63')) digits = digits.slice(2);
        if (digits.startsWith('0')) digits = digits.slice(1);
        contactNumberInput.value = digits.slice(0, 10);
    });

    document.querySelectorAll('[data-name-format]').forEach((field) => {
        const validateName = () => {
            const value = field.value.trim();
            const valid = value.length >= 2 && value.length <= 50 && /^[\p{L}][\p{L}\s'.-]*$/u.test(value);
            field.setCustomValidity(valid || value === '' ? '' : 'Use 2 to 50 letters, spaces, hyphens, apostrophes, or periods.');
        };
        field.addEventListener('input', validateName);
        field.addEventListener('blur', validateName);
        validateName();
    });

    const emailVerification =
        document.querySelector(
            '[data-registration-email-verification]'
        );

    const emailCodeInput =
        document.querySelector(
            '[data-email-verification-code]'
        );

    const sendEmailCodeButton =
        document.querySelector(
            '[data-send-email-code]'
        );

    const verifyEmailCodeButton =
        document.querySelector(
            '[data-verify-email-code]'
        );

    const emailVerificationStatus =
        document.querySelector(
            '[data-email-verification-status]'
        );

    let emailVerified =
        emailInput?.readOnly === true;

    emailInput?.addEventListener('input', () => {
        emailVerified = false;

        if (emailCodeInput) {
            emailCodeInput.value = '';
            emailCodeInput.disabled = true;
        }

        if (verifyEmailCodeButton) {
            verifyEmailCodeButton.disabled = true;
        }

        setEmailVerificationStatus('Verify your email address before continuing.');
    });


    const accountTypeInput =
        document.getElementById(
            'accountType'
        );


    const accountButtons =
        document.querySelectorAll(
            '[data-account-type]'
        );


    const sellerStep =
        document.querySelector(
            '[data-seller-step]'
        );


    const sellerProgressStep =
        document.querySelector(
            '[data-seller-progress-step]'
        );


    const businessPermitUpload =
        document.getElementById(
            'businessPermitUpload'
        );


    const sellerRequiredFields =
        document.querySelectorAll(
            '[data-seller-required]'
        );


    const verificationNumber =
        document.querySelector(
            '[data-verification-number]'
        );


    const verificationStepNumber =
        document.querySelector(
            '[data-verification-step-number]'
        );


    const submitLabel =
        document.querySelector(
            '[data-submit-label]'
        );


    const nextButton =
        document.querySelector(
            '[data-step-next]'
        );


    const backButton =
        document.querySelector(
            '[data-step-back]'
        );


    const submitButton =
        document.querySelector(
            '[data-submit-button]'
        );


    const loginLink =
        document.querySelector(
            '[data-login-link]'
        );


    const progressTitle =
        document.querySelector(
            '[data-progress-title]'
        );


    const progressCounter =
        document.querySelector(
            '[data-progress-counter]'
        );


    const progressFill =
        document.querySelector(
            '[data-progress-fill]'
        );


    const stepError =
        document.querySelector(
            '[data-step-error]'
        );


    let currentStep = 0;


    /*
    |--------------------------------------------------------------------------
    | BUILD ACTIVE STEPS
    |--------------------------------------------------------------------------
    */

    function getSteps() {

        const seller =
            accountTypeInput?.value === 'seller';

        const logistics =
            accountTypeInput?.value === 'logistics';

        const rider =
            accountTypeInput?.value === 'rider';

        const steps = [
            {
                name:
                    'personal',

                title:
                    'Personal Information',
            },

            {
                name:
                    'contact',

                title:
                    'Contact Information',
            },

            {
                name:
                    'address',

                title:
                    'Address',
            },
        ];


        if (seller || logistics) {
            steps.push({
                name:
                    'business',

                title:
                    'Business Information',
            });

        }





        if (rider) {

            steps.push({
                name:
                    'rider-vehicle',

                title:
                    'Vehicle Information',
            });

        }

        steps.push({
            name:
                'profile-picture',

            title:
                'Profile Picture',
        });

        steps.push({
            name:
                'verification',

            title:
                'Account Verification',
        });


        return steps;

    }


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT TYPE
    |--------------------------------------------------------------------------
    */

    function setAccountType(type) {

        const seller =
            type === 'seller';

        const logistics =
            type === 'logistics';

        const rider =
            type === 'rider';

        if (accountTypeInput) {

            accountTypeInput.value =
                type;

        }

        const sellerBranding = document.querySelector('[data-seller-branding]');
        if (sellerBranding) sellerBranding.hidden = !seller;
        const sellerTypeField = document.querySelector('[data-seller-type-field]');
        const sellerTypeInput = document.getElementById('seller_type');
        if (sellerTypeField) sellerTypeField.hidden = !seller;
        if (sellerTypeInput) {
            sellerTypeInput.required = seller;
            sellerTypeInput.disabled = !seller;
        }
        const businessIdField = document.querySelector('[data-business-id-field]');
        if (businessIdField) businessIdField.hidden = !(seller || logistics);
        const businessNameInput = document.getElementById('business_name');
        if (businessNameInput) businessNameInput.maxLength = seller ? 30 : 200;
        const sellerDocuments = document.querySelector('[data-seller-documents]');
        if (sellerDocuments) sellerDocuments.hidden = !(seller || logistics);
        document.querySelectorAll('[data-logistics-field]').forEach((field) => { field.hidden = !logistics; });
        const sellerTypeValue = sellerTypeInput?.value;
        document.querySelectorAll('[data-seller-document]').forEach((wrapper) => {
            const input = wrapper.querySelector('input[type="file"]');
            const documentType = wrapper.dataset.sellerDocument;
            const required = (seller && (
                (documentType === 'dti_certificate' && sellerTypeValue === 'sole_proprietorship')
                || (documentType === 'sec_certificate' && sellerTypeValue === 'corporation')
                || (documentType === 'bir_form_2303' && ['sole_proprietorship', 'corporation'].includes(sellerTypeValue))
            )) || (logistics && ['sec_certificate', 'bir_form_2303'].includes(documentType));
            wrapper.hidden = !required;
            if (input) {
                input.required = required;
                input.disabled = !required;
            }
        });


        accountButtons.forEach(
            (button) => {

                const active =
                    button.dataset.accountType ===
                    type;


                button.classList.toggle(
                    'is-active',
                    active
                );


                button.setAttribute(
                    'aria-pressed',
                    active
                        ? 'true'
                        : 'false'
                );

            }
        );


        /*
        |--------------------------------------------------------------
        | Seller step
        |--------------------------------------------------------------
        */

        if (sellerStep) {

            sellerStep.hidden =
                !(seller || logistics);

        }


        if (sellerProgressStep) {

            sellerProgressStep.hidden =
                !(seller || logistics);

        }


        if (businessPermitUpload) {

            businessPermitUpload.hidden =
                !logistics;

            const permitInput = businessPermitUpload.querySelector('input[type="file"]');
            if (permitInput) {
                permitInput.required = logistics;
                permitInput.disabled = !logistics;
            }

        }


        sellerRequiredFields.forEach(
            (field) => {

                field.required = seller || (logistics && field.name !== 'line_of_business');
                field.disabled = !field.required;

            }
        );

        /*
        |--------------------------------------------------------------
        | Logistics specific
        |--------------------------------------------------------------
        */

        const logisticsStep =
            document.querySelector(
                '[data-logistics-step]'
            );

        const logisticsProgressStep =
            document.querySelector(
                '[data-logistics-progress-step]'
            );

        const logisticsBusinessPermitUpload =
            document.getElementById(
                'logisticsBusinessPermitUpload'
            );

        if (logisticsStep) {

            logisticsStep.hidden =
                !logistics;

        }


        if (logisticsProgressStep) {

            logisticsProgressStep.hidden =
                !logistics;

        }


        if (logisticsBusinessPermitUpload) {

            logisticsBusinessPermitUpload.hidden =
                !logistics;

        }


        const logisticsRequiredFields =
            document.querySelectorAll(
                '[data-logistics-required]'
            );

        logisticsRequiredFields.forEach(
            (field) => {

                field.required =
                    logistics;
                field.disabled =
                    !logistics;

            }
        );

        /*
        |--------------------------------------------------------------
        | Rider specific
        |--------------------------------------------------------------
        */

        const riderStep =
            document.querySelector(
                '[data-rider-step]'
            );

        const riderProgressStep =
            document.querySelector(
                '[data-rider-progress-step]'
            );

        const riderVehicleSection =
            document.getElementById(
                'riderVehicleSection'
            );

        if (riderStep) {

            riderStep.hidden =
                !rider;

        }


        if (riderProgressStep) {

            riderProgressStep.hidden =
                !rider;

        }


        if (riderVehicleSection) {

            riderVehicleSection.hidden =
                !rider;

        }


        const riderRequiredFields =
            document.querySelectorAll(
                '[data-rider-required]'
            );

        riderRequiredFields.forEach(
            (field) => {

                field.required = rider;
                field.disabled = !rider;

            }
        );

        /*
        |--------------------------------------------------------------
        | Step numbering
        |--------------------------------------------------------------
        */

        if (verificationNumber) {

            verificationNumber.textContent =
                seller || logistics || rider
                    ? '06'
                    : '05';

        }


        if (verificationStepNumber) {

            verificationStepNumber.textContent =
                seller || logistics || rider
                    ? '6'
                    : '5';

        }


        /*
        |--------------------------------------------------------------
        | Submit text
        |--------------------------------------------------------------
        */

        if (submitLabel) {

            if (seller) {

                submitLabel.textContent =
                    'Submit Seller Application';

            } else if (logistics) {

                submitLabel.textContent =
                    'Submit Logistics Application';

            } else if (rider) {

                submitLabel.textContent =
                    'Submit Rider Application';

            } else {

                submitLabel.textContent =
                    'Submit Buyer Application';

            }

        }


        /*
        |--------------------------------------------------------------
        | Reset step when changing role
        |--------------------------------------------------------------
        */

        const businessLine = document.getElementById('line_of_business');
        if (businessLine) businessLine.closest('.register-field').hidden = !seller;
        if (sellerProgressStep) {
            sellerProgressStep.hidden = !(seller || logistics || rider);
            sellerProgressStep.lastElementChild.textContent = rider ? 'Vehicle' : 'Business';
        }
        currentStep = 0;


        showStep(
            currentStep
        );

    }


    accountButtons.forEach(
        (button) => {

            button.addEventListener(
                'click',
                () => {

                    setAccountType(
                        button.dataset.accountType
                    );

                }
            );

        }
    );

    document.getElementById('seller_type')?.addEventListener('change', () => {
        setAccountType(accountTypeInput?.value || 'buyer');
    });


    /*
    |--------------------------------------------------------------------------
    | STEP ELEMENT
    |--------------------------------------------------------------------------
    */

    function findStepElement(name) {

        return document.querySelector(
            `[data-form-step][data-step="${name}"]`
        );

    }


    /*
    |--------------------------------------------------------------------------
    | DISPLAY STEP
    |--------------------------------------------------------------------------
    */

    function showStep(index) {

        const steps =
            getSteps();


        if (index < 0) {
            index = 0;
        }


        if (
            index >
            steps.length - 1
        ) {

            index =
                steps.length - 1;

        }


        currentStep =
            index;


        /*
        |--------------------------------------------------------------
        | Hide everything
        |--------------------------------------------------------------
        */

        document
            .querySelectorAll(
                '[data-form-step]'
            )
            .forEach(
                (section) => {

                    section.hidden =
                        true;


                    section.classList.remove(
                        'is-active'
                    );

                }
            );


        /*
        |--------------------------------------------------------------
        | Show active section
        |--------------------------------------------------------------
        */

        const current =
            steps[currentStep];


        const currentElement =
            findStepElement(
                current.name
            );


        if (currentElement) {

            currentElement.hidden =
                false;


            currentElement.classList.add(
                'is-active'
            );

        }


        /*
        |--------------------------------------------------------------
        | Progress header
        |--------------------------------------------------------------
        */

        if (progressTitle) {

            progressTitle.textContent =
                current.title;

        }


        if (progressCounter) {

            progressCounter.textContent =
                `Step ${currentStep + 1} of ${steps.length}`;

        }


        if (progressFill) {

            const percent =
                (
                    (
                        currentStep + 1
                    )
                    /
                    steps.length
                )
                *
                100;


            progressFill.style.width =
                `${percent}%`;

        }


        /*
        |--------------------------------------------------------------
        | Progress dots
        |--------------------------------------------------------------
        */

        updateProgressSteps();


        /*
        |--------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------
        */

        const lastStep =
            currentStep ===
            steps.length - 1;


        if (backButton) {

            backButton.hidden =
                currentStep === 0;

        }


        if (loginLink) {

            loginLink.hidden =
                currentStep !== 0;

        }


        if (nextButton) {

            nextButton.hidden =
                lastStep;

        }


        if (submitButton) {

            submitButton.hidden =
                !lastStep;

        }


        /*
        |--------------------------------------------------------------
        | Error message
        |--------------------------------------------------------------
        */

        hideStepError();


        /*
        |--------------------------------------------------------------
        | Scroll
        |--------------------------------------------------------------
        */

        document
            .querySelector(
                '.registration-progress'
            )
            ?.scrollIntoView({
                behavior:
                    'smooth',

                block:
                    'start',
            });

    }


    /*
    |--------------------------------------------------------------------------
    | PROGRESS DOTS
    |--------------------------------------------------------------------------
    */

    function updateProgressSteps() {

        const steps =
            getSteps();


        const progressButtons =
            document.querySelectorAll(
                '[data-progress-step]'
            );


        progressButtons.forEach(
            (button) => {

                button.classList.remove(
                    'is-active',
                    'is-complete'
                );

            }
        );


        steps.forEach(
            (step, index) => {

                let button;


                if (
                    step.name ===
                    'verification'
                ) {

                    button =
                        document.querySelector(
                            '[data-verification-progress-step]'
                        );

                } else {

                    button =
                        document.querySelector(
                            `[data-progress-step="${index}"]`
                        );

                }


                if (!button) {
                    return;
                }


                if (
                    index <
                    currentStep
                ) {

                    button.classList.add(
                        'is-complete'
                    );

                }


                if (
                    index ===
                    currentStep
                ) {

                    button.classList.add(
                        'is-active'
                    );

                }

            });

    }


    /*
    |--------------------------------------------------------------------------
    | CURRENT STEP VALIDATION
    |--------------------------------------------------------------------------
    */

    function validateCurrentStep() {

        const steps =
            getSteps();


        const current =
            steps[currentStep];


        const section =
            findStepElement(
                current.name
            );


        if (!section) {
            return true;
        }


        const fields =
            section.querySelectorAll(
                'input, select, textarea'
            );


        let valid =
            true;


        let firstInvalid =
            null;


        fields.forEach(
            (field) => {

                /*
                |----------------------------------------------------------
                | Ignore disabled / non-required fields
                |----------------------------------------------------------
                */

                if (
                    field.disabled
                    ||
                    !field.required
                ) {

                    field.classList.remove(
                        'is-invalid'
                    );


                    return;

                }


                /*
                |----------------------------------------------------------
                | Native browser validation
                |----------------------------------------------------------
                */

                if (
                    !field.checkValidity()
                ) {

                    valid =
                        false;


                    field.classList.add(
                        'is-invalid'
                    );


                    if (!firstInvalid) {

                        firstInvalid =
                            field;

                    }

                } else {

                    field.classList.remove(
                        'is-invalid'
                    );

                }

            }
        );


        if (!valid) {

            showStepError('Please correct the highlighted field(s) before continuing.');


            firstInvalid?.focus();
            firstInvalid?.reportValidity();


            return false;

        }

        if (
            current.name === 'contact'
            && !emailVerified
        ) {
            showStepError('Verify your email before continuing.');
            sendEmailCodeButton?.focus();
            return false;
        }


        hideStepError();


        return true;

    }

    function setEmailVerificationStatus(message, success = false) {
        if (!emailVerificationStatus) {
            return;
        }

        emailVerificationStatus.textContent = message;
        emailVerificationStatus.classList.toggle('is-success', success);
        emailVerificationStatus.classList.toggle('is-error', !success);
    }

    async function postEmailVerification(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(payload),
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(
                result.errors?.email?.[0]
                || result.errors?.code?.[0]
                || result.message
                || 'Email verification failed. Please try again.'
            );
        }

        return result;
    }

    sendEmailCodeButton?.addEventListener('click', async () => {
        if (!emailInput?.reportValidity()) {
            return;
        }

        sendEmailCodeButton.disabled = true;
        setEmailVerificationStatus('Sending verification code...');

        try {
            const result = await postEmailVerification(
                emailVerification.dataset.sendUrl,
                { email: emailInput.value }
            );

            emailCodeInput.disabled = false;
            verifyEmailCodeButton.disabled = false;
            emailCodeInput.focus();
            setEmailVerificationStatus(result.message);
        } catch (error) {
            setEmailVerificationStatus(error.message);
        } finally {
            sendEmailCodeButton.disabled = false;
        }
    });

    verifyEmailCodeButton?.addEventListener('click', async () => {
        if (!emailCodeInput?.reportValidity()) {
            return;
        }

        verifyEmailCodeButton.disabled = true;
        setEmailVerificationStatus('Checking verification code...');

        try {
            const result = await postEmailVerification(
                emailVerification.dataset.verifyUrl,
                { email: emailInput.value, code: emailCodeInput.value }
            );

            emailVerified = true;
            emailInput.readOnly = true;
            emailCodeInput.disabled = true;
            sendEmailCodeButton.hidden = true;
            verifyEmailCodeButton.hidden = true;
            setEmailVerificationStatus(result.message, true);
        } catch (error) {
            setEmailVerificationStatus(error.message);
            verifyEmailCodeButton.disabled = false;
        }
    });


    /*
    |--------------------------------------------------------------------------
    | ERROR
    |--------------------------------------------------------------------------
    */

    function showStepError(message = 'Please complete the required fields before continuing.') {

        if (stepError) {

            stepError.textContent = message;
            stepError.hidden =
                false;

        }

    }


    function hideStepError() {

        if (stepError) {

            stepError.hidden =
                true;

        }


        document
            .querySelectorAll(
                '.is-invalid'
            )
            .forEach(
                (field) => {

                    field.classList.remove(
                        'is-invalid'
                    );

                }
            );

    }


    /*
    |--------------------------------------------------------------------------
    | NEXT
    |--------------------------------------------------------------------------
    */

    nextButton?.addEventListener(
        'click',
        () => {

            if (
                !validateCurrentStep()
            ) {

                return;

            }


            const steps =
                getSteps();


            if (
                currentStep <
                steps.length - 1
            ) {

                currentStep++;


                showStep(
                    currentStep
                );

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | BACK
    |--------------------------------------------------------------------------
    */

    backButton?.addEventListener(
        'click',
        () => {

            if (
                currentStep > 0
            ) {

                currentStep--;


                showStep(
                    currentStep
                );

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | PREVENT ACCIDENTAL PROGRESS STEP SKIPPING
    |--------------------------------------------------------------------------
    |
    | Completed steps may be clicked to go back.
    | Users cannot jump ahead and bypass validation.
    |
    */

    document
        .querySelectorAll(
            '[data-progress-step]'
        )
        .forEach(
            (button) => {

                button.addEventListener(
                    'click',
                    () => {

                        const steps =
                            getSteps();


                        let targetIndex =
                            -1;


                        if (
                            button.hasAttribute(
                                'data-verification-progress-step'
                            )
                        ) {

                            targetIndex =
                                steps.findIndex(
                                    (step) =>
                                        step.name ===
                                        'verification'
                                );

                        } else {

                            const raw =
                                Number(
                                    button.dataset.progressStep
                                );


                            if (
                                Number.isInteger(
                                    raw
                                )
                            ) {

                                targetIndex =
                                    raw;

                            }

                        }


                        if (
                            targetIndex >= 0
                            &&
                            targetIndex <
                            currentStep
                        ) {

                            showStep(
                                targetIndex
                            );

                        }

                    }
                );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | FINAL SUBMIT VALIDATION
    |--------------------------------------------------------------------------
    */

    form?.addEventListener(
        'submit',
        (event) => {

            const steps = getSteps();
            if (currentStep < steps.length - 1) {
                event.preventDefault();
                nextButton?.click();
                return;
            }
            const password = document.getElementById('password');
            const confirmation = document.getElementById('password_confirmation');
            confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords must match.');
            if (!validateCurrentStep()) {
                event.preventDefault();
                confirmation.reportValidity();
                return;
            }
            submitButton.disabled = true;
            submitLabel.textContent = 'Submitting application...';

        }
    );


    /*
    |--------------------------------------------------------------------------
    | BIRTHDAY -> AGE
    |--------------------------------------------------------------------------
    */

    const birthday =
        document.getElementById(
            'birthday'
        );


    const age =
        document.getElementById(
            'age'
        );


    function calculateAge() {

        if (
            !birthday
            ||
            !age
            ||
            !birthday.value
        ) {

            if (age) {

                age.value =
                    '';

            }


            return;

        }


        const birthDate =
            new Date(
                `${birthday.value}T00:00:00`
            );


        const today =
            new Date();


        let calculatedAge =
            today.getFullYear()
            -
            birthDate.getFullYear();


        const monthDifference =
            today.getMonth()
            -
            birthDate.getMonth();


        if (
            monthDifference < 0
            ||
            (
                monthDifference === 0
                &&
                today.getDate()
                <
                birthDate.getDate()
            )
        ) {

            calculatedAge--;

        }


        age.value =
            Math.max(
                0,
                calculatedAge
            );

    }


    birthday?.addEventListener(
        'change',
        calculateAge
    );


    birthday?.addEventListener(
        'input',
        calculateAge
    );


    calculateAge();


    /*
    |--------------------------------------------------------------------------
    | FILE DISPLAY
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-file-input]'
        )
        .forEach(
            (input) => {

                input.addEventListener(
                    'change',
                    () => {

                        const uploadBox =
                            input.closest(
                                '.upload-box'
                            );


                        const fileName =
                            uploadBox?.querySelector(
                                '[data-file-name]'
                            );


                        if (!fileName) {
                            return;
                        }


                        const file =
                            input.files?.[0];


                        if (!file) {

                            fileName.textContent =
                                'No file selected';
                            input.setCustomValidity('');


                            return;

                        }

                        const maxBytes = 5 * 1024 * 1024;
                        const acceptedTypes = (input.accept || '').toLowerCase().split(',').map((type) => type.trim()).filter(Boolean);
                        const fileExtension = `.${file.name.split('.').pop().toLowerCase()}`;
                        const accepted = !acceptedTypes.length || acceptedTypes.some((type) => (
                            type === file.type.toLowerCase()
                            || type === fileExtension
                            || (type.endsWith('/*') && file.type.toLowerCase().startsWith(type.slice(0, -1)))
                        ));
                        const error = file.size > maxBytes
                            ? 'File must be 5 MB or smaller.'
                            : (!accepted ? 'Choose a file in one of the accepted formats.' : '');
                        input.setCustomValidity(error);
                        if (error) {
                            input.reportValidity();
                            input.value = '';
                            fileName.textContent = 'No file selected';
                            return;
                        }


                        const fileSize =
                            (
                                file.size
                                /
                                1024
                                /
                                1024
                            )
                            .toFixed(2);


                        fileName.textContent =
                            `${file.name} · ${fileSize} MB`;

                        // Handle profile picture preview
                        if (input.id === 'profile_picture') {
                            const reader = new FileReader();
                            reader.onload = (e) => {
                                const preview = document.getElementById('profilePicturePreview');
                                const defaultIcon = document.getElementById('profilePictureDefault');
                                if (preview && defaultIcon) {
                                    preview.src = e.target.result;
                                    preview.style.display = 'block';
                                    defaultIcon.style.display = 'none';
                                }
                            };
                            reader.readAsDataURL(file);
                        }

                    }
                );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | PHILIPPINE ADDRESS
    |--------------------------------------------------------------------------
    */

    const region =
        document.querySelector(
            '[data-address-region]'
        );

    const oldRegion = region?.dataset.oldValue ?? '';
    const oldRegionCode = region?.dataset.oldCode ?? '';
    const regionCode = document.querySelector('[data-address-region-code]');

    const province =
        document.querySelector(
            '[data-address-province]'
        );


    const municipality =
        document.querySelector(
            '[data-address-municipality]'
        );


    const barangay =
        document.querySelector(
            '[data-address-barangay]'
        );

    const postalCode =
        document.querySelector(
            '[data-address-postal]'
        );

    const oldProvince = province?.dataset.oldValue ?? '';
    const oldProvinceCode = province?.dataset.oldCode ?? '';
    const provinceCode = document.querySelector('[data-address-province-code]');

    const oldMunicipality = municipality?.dataset.oldValue ?? '';
    const oldMunicipalityCode = municipality?.dataset.oldCode ?? '';
    const municipalityCode = document.querySelector('[data-address-municipality-code]');

    const oldBarangay = barangay?.dataset.oldValue ?? '';
    const oldBarangayCode = barangay?.dataset.oldCode ?? '';
    const barangayCode = document.querySelector('[data-address-barangay-code]');

    const oldPostalCode =
        postalCode?.dataset.oldValue
        ?? '';

    const logisticsCenterSelect = document.getElementById('target_logistics_center_id');
    const logisticsCenterStatus = document.querySelector('[data-logistics-center-status]');

    function filterEligibleLogisticsCenters() {
        if (!logisticsCenterSelect) return;

        const selectedProvinceCode = String(provinceCode?.value || province?.selectedOptions?.[0]?.dataset.code || '').trim();
        const selectedMunicipalityCode = String(municipalityCode?.value || municipality?.selectedOptions?.[0]?.dataset.code || '').trim();
        const hasCanonicalMunicipality = selectedProvinceCode !== '' && selectedMunicipalityCode !== '';
        let matches = 0;

        [...logisticsCenterSelect.options]
            .filter((option) => option.dataset.logisticsCenterOption === 'true')
            .forEach((option) => {
                const eligible = hasCanonicalMunicipality
                    && option.dataset.provinceCode === selectedProvinceCode
                    && option.dataset.municipalityCode === selectedMunicipalityCode;
                option.hidden = !eligible;
                option.disabled = !eligible;
                if (eligible) matches += 1;
            });

        const selectedOption = logisticsCenterSelect.selectedOptions[0];
        if (selectedOption?.dataset.logisticsCenterOption === 'true' && selectedOption.disabled) {
            logisticsCenterSelect.value = '';
        }

        logisticsCenterSelect.setCustomValidity(
            logisticsCenterSelect.value && logisticsCenterSelect.selectedOptions[0]?.disabled
                ? 'Choose a logistics center that serves your selected municipality.'
                : ''
        );

        if (!logisticsCenterStatus) return;
        if (!hasCanonicalMunicipality) {
            logisticsCenterStatus.textContent = 'Select your province and municipality to see eligible active logistics centers.';
        } else if (matches === 0) {
            logisticsCenterStatus.textContent = 'No eligible active logistics center is available for this municipality yet.';
        } else {
            logisticsCenterStatus.textContent = `${matches} eligible active logistics center${matches === 1 ? '' : 's'} available for this municipality.`;
        }
    }

    const addressRequests = {
        regions: null,
        provinces: null,
        municipalities: null,
        barangays: null,
        postalCode: null,
    };

    function abortAddressRequest(key) {

        addressRequests[key]?.abort();

        addressRequests[key] =
            new AbortController();

        return addressRequests[key];

    }

    async function fetchAddressJson(key, url) {

        const controller =
            abortAddressRequest(key);

        const response =
            await fetch(
                url,
                {
                    headers: {
                        Accept:
                            'application/json'
                    },
                    signal:
                        controller.signal,
                }
            );

        if (!response.ok) {
            throw new Error(`Address request failed: ${response.status}`);
        }

        return response.json();

    }

    function resetPostalCode(placeholder = '') {

        if (!postalCode) {
            return;
        }

        postalCode.value =
            placeholder;

    }


    function resetSelect(
        select,
        placeholder
    ) {

        if (!select) {
            return;
        }


        select.innerHTML =
            `<option value="">${placeholder}</option>`;


        select.disabled =
            true;

    }

    function selectedLabel(select) {

        return select?.selectedOptions?.[0]?.textContent?.trim()
            ?? '';

    }


    function addOptions(
        select,
        records
    ) {

        records.forEach(
            (record) => {

                const option =
                    document.createElement(
                        'option'
                    );


                const code = record.code ?? record.id ?? record.value ?? '';
                const label = record.name ?? record.label ?? record.description ?? code;

                option.value = label;
                option.dataset.code = code;


                option.textContent =
                    label;

                option.dataset.prv =
                    record.prv ?? '';

                option.dataset.mun =
                    record.mun ?? '';

                option.dataset.level =
                    record.level ?? '';


                select.appendChild(
                    option
                );

            }
        );

    }


    async function loadRegions() {

        if (!region) {
            return;
        }


        try {

            region.innerHTML =
                '<option value="">Loading regions...</option>';


            const result =
                await fetchAddressJson(
                    'regions',
                    form.dataset.addressBase + '/regions'
                );


            const records =
                Array.isArray(result)
                    ? result
                    : (
                        result.data
                        ??
                        result.provinces
                        ??
                        []
                    );


            region.innerHTML =
                '<option value="">Select region</option>';


            addOptions(
                region,
                records
            );


            region.disabled =
                false;

            delete region.dataset.loadFailed;

        } catch (error) {

            if (error.name === 'AbortError') {
                return;
            }

            console.error('Region API:', error);


            region.innerHTML =
                '<option value="">Address service unavailable — refresh to retry</option>';


            region.disabled =
                false;

            region.dataset.loadFailed =
                'true';

        }

    }

    function retryRegionsIfUnavailable() {

        if (region?.dataset.loadFailed === 'true') {
            loadRegions();
        }

    }

    region?.addEventListener(
        'focus',
        retryRegionsIfUnavailable
    );

    region?.addEventListener(
        'click',
        retryRegionsIfUnavailable
    );


    region?.addEventListener(
        'change',
        async () => {

            resetSelect(province, 'Select province');
            if (regionCode) regionCode.value = region.selectedOptions[0]?.dataset.code ?? '';
            if (provinceCode) provinceCode.value = '';
            if (municipalityCode) municipalityCode.value = '';
            if (barangayCode) barangayCode.value = '';

            resetSelect(municipality, 'Select municipality / city');
            if (provinceCode) provinceCode.value = province.selectedOptions[0]?.dataset.code ?? '';
            if (municipalityCode) municipalityCode.value = '';
            if (barangayCode) barangayCode.value = '';

            resetSelect(barangay, 'Select barangay');
            if (municipalityCode) municipalityCode.value = municipality.selectedOptions[0]?.dataset.code ?? '';
            if (barangayCode) barangayCode.value = '';

            resetPostalCode();
            filterEligibleLogisticsCenters();


            if (!region.value) {
                return;
            }


            try {

                province.innerHTML =
                    '<option value="">Loading provinces...</option>';

                const result = await fetchAddressJson(
                    'provinces',
                    `${form.dataset.addressBase}/regions/${encodeURIComponent(
                        region.selectedOptions[0]?.dataset.code || region.value
                    )}/provinces`
                );
                const records = Array.isArray(result)
                    ? result
                    : (result.data ?? result.provinces ?? []);


                province.innerHTML =
                    '<option value="">Select province</option>';

                addOptions(province, records);
                province.disabled = false;

                if (oldProvince) {
                    province.value = oldProvince;
                    if (provinceCode) provinceCode.value = oldProvinceCode || province.selectedOptions[0]?.dataset.code || '';
                    province.dispatchEvent(new Event('change'));
                }

            } catch (error) {

                if (error.name === 'AbortError') {
                    return;
                }

                console.error('Province API:', error);
                province.innerHTML =
                    '<option value="">Address service unavailable — refresh to retry</option>';
                province.disabled = false;

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | PROVINCE -> MUNICIPALITY
    |--------------------------------------------------------------------------
    */

    province?.addEventListener(
        'change',
        async () => {

            if (provinceCode) provinceCode.value = province.selectedOptions[0]?.dataset.code ?? '';
            if (municipalityCode) municipalityCode.value = '';
            if (barangayCode) barangayCode.value = '';

            resetSelect(
                municipality,
                'Select municipality / city'
            );


            resetSelect(
                barangay,
                'Select barangay'
            );

            resetPostalCode();
            filterEligibleLogisticsCenters();


            if (!province.value) {
                return;
            }


            try {

                const selectedProvince =
                    province.selectedOptions[0];

                if (selectedProvince?.dataset.level === 'City') {
                    municipality.innerHTML =
                        '<option value="">Select municipality / city</option>';

                    const cityOption = new Option(selectedProvince.textContent, selectedProvince.value);
                    cityOption.dataset.code = selectedProvince.dataset.code ?? '';

                    cityOption.dataset.prv =
                        selectedProvince.dataset.prv ?? '';

                    cityOption.dataset.mun =
                        selectedProvince.dataset.mun ?? '';
                    municipality.appendChild(cityOption);
                    municipality.disabled = false;

                    municipality.value = oldMunicipality || selectedProvince.value;
                    municipality.dispatchEvent(new Event('change'));
                    return;
                }

                municipality.innerHTML =
                    '<option value="">Loading municipalities...</option>';


                const result =
                    await fetchAddressJson(
                        'municipalities',
                        `${form.dataset.addressBase}/provinces/${encodeURIComponent(
                            province.selectedOptions[0]?.dataset.code || province.value
                        )}/municipalities?prv=${encodeURIComponent(
                            province.selectedOptions[0]?.dataset.prv
                            || province.selectedOptions[0]?.dataset.code
                            || province.value
                        )}`
                    );


                const records =
                    Array.isArray(result)
                        ? result
                        : (
                            result.data
                            ??
                            result.municipalities
                            ??
                            []
                        );


                municipality.innerHTML =
                    '<option value="">Select municipality / city</option>';


                addOptions(
                    municipality,
                    records
                );


                municipality.disabled =
                    false;

                if (oldMunicipality) {
                    municipality.value = oldMunicipality;
                    if (municipalityCode) municipalityCode.value = oldMunicipalityCode || municipality.selectedOptions[0]?.dataset.code || '';
                    municipality.dispatchEvent(new Event('change'));
                }

            } catch (error) {

                if (error.name === 'AbortError') {
                    return;
                }

                console.error('Municipality API:', error);


                municipality.innerHTML =
                    '<option value="">Address service unavailable</option>';

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | MUNICIPALITY -> BARANGAY
    |--------------------------------------------------------------------------
    */

    municipality?.addEventListener(
        'change',
        async () => {

            if (municipalityCode) municipalityCode.value = municipality.selectedOptions[0]?.dataset.code ?? '';
            if (barangayCode) barangayCode.value = '';

            resetSelect(
                barangay,
                'Select barangay'
            );

            resetPostalCode();
            filterEligibleLogisticsCenters();


            if (!municipality.value) {
                return;
            }


            try {

                barangay.innerHTML =
                    '<option value="">Loading barangays...</option>';


                const result =
                    await fetchAddressJson(
                        'barangays',
                        `${form.dataset.addressBase}/municipalities/${encodeURIComponent(
                            municipality.selectedOptions[0]?.dataset.code || municipality.value
                        )}/barangays?mun=${encodeURIComponent(
                            municipality.selectedOptions[0]?.dataset.mun
                            || municipality.value
                        )}&prv=${encodeURIComponent(
                            municipality.selectedOptions[0]?.dataset.prv
                            || province.selectedOptions[0]?.dataset.prv
                            || ''
                        )}`
                    );


                const records =
                    Array.isArray(result)
                        ? result
                        : (
                            result.data
                            ??
                            result.barangays
                            ??
                            []
                        );


                barangay.innerHTML =
                    '<option value="">Select barangay</option>';


                addOptions(
                    barangay,
                    records
                );


                barangay.disabled =
                    false;

                if (oldBarangay) {
                    barangay.value = oldBarangay;
                    if (barangayCode) barangayCode.value = oldBarangayCode || barangay.selectedOptions[0]?.dataset.code || '';
                    barangay.dispatchEvent(new Event('change'));
                }

            } catch (error) {

                if (error.name === 'AbortError') {
                    return;
                }

                console.error('Barangay API:', error);


                barangay.innerHTML =
                    '<option value="">Address service unavailable</option>';

            }

        }
    );

    barangay?.addEventListener(
        'change',
        async () => {

            abortAddressRequest('postalCode');
            resetPostalCode();
            if (barangayCode) barangayCode.value = barangay.selectedOptions[0]?.dataset.code ?? '';

            if (!barangay.value || !municipality.value || !province.value) {
                return;
            }

            try {

                resetPostalCode('Loading...');

                const packagePostalCode = postalCodeFromAddress({
                    region: selectedLabel(region),
                    province: selectedLabel(province),
                    municipality: selectedLabel(municipality),
                    barangay: selectedLabel(barangay),
                });

                if (packagePostalCode) {
                    postalCode.value = packagePostalCode;
                    return;
                }

                const params =
                    new URLSearchParams({
                        province: provinceCode?.value || province.selectedOptions[0]?.dataset.code || '',
                        municipality: municipalityCode?.value || municipality.selectedOptions[0]?.dataset.code || '',
                        barangay: barangayCode?.value || barangay.selectedOptions[0]?.dataset.code || '',
                        province_name:
                            selectedLabel(province),
                        municipality_name:
                            selectedLabel(municipality),
                        barangay_name:
                            selectedLabel(barangay),
                    });

                const result =
                    await fetchAddressJson(
                        'postalCode',
                        `${form.dataset.addressBase}/postal-code?${params.toString()}`
                    );

                postalCode.value =
                    result.postal_code ?? '';

            } catch (error) {

                if (error.name === 'AbortError') {
                    return;
                }

                console.error('Postal Code API:', error);
                resetPostalCode('');

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | INITIALIZE
    |--------------------------------------------------------------------------
    */

    loadRegions().then(() => {
        if (oldRegion) {
            region.value = oldRegion;
            if (regionCode) regionCode.value = oldRegionCode || region.selectedOptions[0]?.dataset.code || '';
            region.dispatchEvent(new Event('change'));
        }

        if (oldPostalCode && postalCode) {
            postalCode.value = oldPostalCode;
        }

        filterEligibleLogisticsCenters();
    });

    logisticsCenterSelect?.addEventListener('change', filterEligibleLogisticsCenters);


    window.addEventListener('pageshow', () => {
        submitButton.disabled = false;
    });
    const initialAccountType = accountTypeInput?.value;

    setAccountType(
        ['buyer', 'seller', 'logistics', 'rider'].includes(initialAccountType)
            ? initialAccountType
            : 'buyer'
    );

    const serverErrors = JSON.parse(document.getElementById('registration-validation-errors')?.textContent || '{}');
    let firstInvalid = null;
    Object.entries(serverErrors).forEach(([name, messages]) => {
        const fields = [...form.querySelectorAll('[name]')].filter((field) => field.name === name && field.type !== 'hidden');
        if (!fields.length) return;
        const message = Array.isArray(messages) ? messages[0] : String(messages);
        fields.forEach((field) => {
            field.setAttribute('aria-invalid', 'true');
            const wrapper = field.closest('.register-field');
            if (!wrapper || wrapper.querySelector(`[data-inline-error="${name}"]`)) return;
            const inline = document.createElement('small');
            inline.className = 'register-inline-error';
            inline.dataset.inlineError = name;
            inline.setAttribute('role', 'alert');
            inline.textContent = message;
            wrapper.append(inline);
            firstInvalid ||= field;
        });
    });
    if (firstInvalid) {
        const steps = getSteps();
        const invalidStep = steps.findIndex((step) => step.contains(firstInvalid));
        if (invalidStep >= 0) showStep(invalidStep);
        firstInvalid.focus({preventScroll: true});
        firstInvalid.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    document.querySelectorAll('[data-store-photo]').forEach((input) => input.addEventListener('change', () => {
        const file = input.files?.[0];
        const preview = document.querySelector(`[data-store-photo-preview="${input.dataset.storePhoto}"]`);
        const maxBytes = input.dataset.storePhoto === 'avatar' ? 5 * 1024 * 1024 : 8 * 1024 * 1024;
        if (!file || !preview) return;
        if (file.size > maxBytes) {
            input.value = '';
            input.setCustomValidity(`Choose an image smaller than ${input.dataset.storePhoto === 'avatar' ? '5' : '8'} MB.`);
            input.reportValidity();
            return;
        }
        input.setCustomValidity('');
        const oldUrl = preview.dataset.previewUrl;
        if (oldUrl) URL.revokeObjectURL(oldUrl);
        const url = URL.createObjectURL(file);
        preview.dataset.previewUrl = url;
        preview.src = url;
        preview.hidden = false;
        updateStorePhotoPreview(input.dataset.storePhoto);
    }));

    function updateStorePhotoPreview(key) {
        const preview = document.querySelector(`[data-store-photo-preview="${key}"]`);
        if (!preview) return;
        const x = document.querySelector(`[data-store-photo-x="${key}"]`)?.value ?? 50;
        const y = document.querySelector(`[data-store-photo-y="${key}"]`)?.value ?? 50;
        const zoom = document.querySelector(`[data-store-photo-zoom="${key}"]`)?.value ?? 100;
        preview.style.objectPosition = `${x}% ${y}%`;
        preview.style.transform = `scale(${Number(zoom) / 100})`;
    }

    document.querySelectorAll('[data-store-photo-zoom], [data-store-photo-x], [data-store-photo-y]').forEach((control) => {
        control.addEventListener('input', () => updateStorePhotoPreview(control.dataset.storePhotoZoom || control.dataset.storePhotoX || control.dataset.storePhotoY));
    });

    let storePhotoCropInProgress = false;
    form?.addEventListener('submit', async (event) => {
        if (event.defaultPrevented || storePhotoCropInProgress) return;
        const selected = [...document.querySelectorAll('[data-store-photo]')]
            .map((input) => ({input, file: input.files?.[0], key: input.dataset.storePhoto}))
            .filter((item) => item.file);
        if (!selected.length) return;
        event.preventDefault();
        storePhotoCropInProgress = true;
        try {
            for (const {input, file, key} of selected) {
                const image = await createImageBitmap(file);
                const width = 1200;
                const height = key === 'avatar' ? 1200 : 400;
                const zoom = Number(document.querySelector(`[data-store-photo-zoom="${key}"]`).value) / 100;
                const scale = Math.max(width / image.width, height / image.height) * zoom;
                const drawWidth = image.width * scale;
                const drawHeight = image.height * scale;
                const x = (width - drawWidth) * Number(document.querySelector(`[data-store-photo-x="${key}"]`).value) / 100;
                const y = (height - drawHeight) * Number(document.querySelector(`[data-store-photo-y="${key}"]`).value) / 100;
                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                canvas.getContext('2d').drawImage(image, x, y, drawWidth, drawHeight);
                image.close();
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', .88));
                if (blob?.type === 'image/webp') {
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([blob], `${key}.webp`, {type: 'image/webp'}));
                    input.files = transfer.files;
                }
            }
        } catch (error) {
            console.warn('Shop photo crop failed; sending the selected original image.', error);
        }
        form.submit();
    });

});
