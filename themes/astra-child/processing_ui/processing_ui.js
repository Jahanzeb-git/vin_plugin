/**
 * VIN Processor Widget Interaction Logic
 * Version: 1.1.0
 * Handles step navigation, animations, scrolling, PayPal JS SDK integration, and AJAX calls.
 */
document.addEventListener('DOMContentLoaded', () => {
    const widget = document.getElementById('vin-process-widget');
    if (!widget) {
        // console.log('VIN Process Widget not found on this page.');
        return; // Exit if widget isn't on the page
    }

    // --- Configurable Settings (from wp_localize_script) ---
    const ajaxUrl = window.processingUiData?.ajax_url || '/wp-admin/admin-ajax.php';
    const nonce = window.processingUiData?.nonce || ''; // Security nonce
    const paypalClientId = window.processingUiData?.paypal_client_id || '';
    const paypalMode = window.processingUiData?.paypal_mode || 'sandbox';
    const errorMessages = window.processingUiData?.error_messages || { // Default messages
        generic: 'An unexpected error occurred. Please try again.',
        vin_invalid: 'Please enter a valid 17-digit VIN.',
        api_error: 'Could not retrieve vehicle data at this time.',
        payment_create_failed: 'Could not initiate payment. Please try again.',
        payment_capture_failed: 'Payment was approved, but finalizing it failed. Please contact support.',
        fulfillment_failed: 'Payment successful, but report generation failed. Please contact support. Your payment may be refunded.',
        retrieval_failed: 'Could not find a previous report for this VIN and email.',
        retrieval_file_missing: 'Report record found, but the file is unavailable. Please contact support.',
    };

    // Check if essential localized data is missing
    if (!nonce) {
        console.error('VIN Processor Error: Security nonce not found. AJAX calls will fail.');
        // Optionally disable the widget or show a general error
        widget.innerHTML = '<p style="color: red;">Initialization failed: Security token missing.</p>';
        return;
    }
     if (!paypalClientId && widget.querySelector('.vp-option-button[data-option="full"]')) {
         console.warn('VIN Processor Warning: PayPal Client ID not found. Payment option will be disabled or hidden.');
         // Optionally hide or disable the payment option button here
     }


    // --- DOM Elements ---
    const steps = Array.from(widget.querySelectorAll('.vp-step'));
    const progressSteps = Array.from(widget.querySelectorAll('.vp-progress-step'));
    const lineFill = widget.getElementById('vp-line-fill');

    // Step 1 Elements
    const step1 = widget.getElementById('vp-step-1');
    const vinInput = widget.getElementById('vin-input');
    const step1ContinueBtn = widget.getElementById('vp-step-1-continue');
    const step1Error = widget.getElementById('vp-step-1-error');
    const step1Summary = widget.getElementById('vp-step-1-summary');

    // Step 2 Elements
    const step2 = widget.getElementById('vp-step-2');
    const optionButtons = widget.querySelectorAll('.vp-option-button');
    const step2Error = widget.getElementById('vp-step-2-error');
    const step2Summary = widget.getElementById('vp-step-2-summary');

    // Step 3 Elements
    const step3 = widget.getElementById('vp-step-3');
    const step3Title = widget.getElementById('vp-step-3-title');
    const step3Error = widget.getElementById('vp-step-3-error'); // General step 3 error
    const step3Summary = widget.getElementById('vp-step-3-summary');

    // Step 3 Content Variants
    const step3BasicResults = widget.getElementById('vp-step-3-basic-results');
    const step3FullPayment = widget.getElementById('vp-step-3-full-payment');
    const step3Retrieve = widget.getElementById('vp-step-3-retrieve');
    const step3Confirmation = widget.getElementById('vp-step-3-confirmation');
    // Ensure the PayPal button container exists in the HTML for Step 3 Full Payment variant
    const paypalButtonContainer = widget.querySelector('#paypal-button-container') || (() => {
        // Create if missing (though it should be in the HTML)
        const container = document.createElement('div');
        container.id = 'paypal-button-container';
        if(step3FullPayment) step3FullPayment.appendChild(container); // Append to payment section
        return container;
    })();


    // Step 3 Action Elements
    const planSelection = widget.getElementById('plan-selection');
    const retrieveEmailInput = widget.getElementById('retrieve-email-input');
    const getPreviousReportBtn = widget.getElementById('vp-get-previous-report');
    const downloadReportLink = widget.getElementById('download-report-link');
    const restartButtons = widget.querySelectorAll('#vp-restart-basic, #vp-restart-final');
    const backToStep2Buttons = widget.querySelectorAll('#vp-back-step2-payment, #vp-back-step2-retrieve');


    // --- State ---
    let currentStep = 1;
    const totalSteps = steps.length;
    let selectedVin = '';
    let selectedPlan = null;
    let selectedOption = null;
    let currentPaypalOrderId = null;
    let isProcessing = false; // Prevent double submissions/clicks

    // --- Utility Functions ---

    /** Debounce function */
    function debounce(func, wait, immediate) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    };

    /** Check if mobile viewport */
     function isMobileViewport() {
        return window.matchMedia("(max-width: 768px)").matches;
    }

    /** Smooth scroll to step header */
    const smoothScrollToStep = debounce((stepNumber) => {
        const targetStepElement = widget.querySelector(`.vp-step[data-step="${stepNumber}"] .vp-step-header`); // Target header
        if (targetStepElement) {
            const stickyHeader = document.querySelector('#masthead.site-header.sticky-header') || document.querySelector('.elementor-sticky--active');
            const headerOffset = stickyHeader ? stickyHeader.offsetHeight : 0;
            const elementPosition = targetStepElement.getBoundingClientRect().top;
            const offsetPosition = window.pageYOffset + elementPosition - headerOffset - 20; // 20px margin

            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
        }
    }, 150); // Slightly longer debounce for scroll


    /** Update progress indicator UI */
    function updateProgressIndicator(completedStepNum) {
         const isMobile = isMobileViewport();
         progressSteps.forEach(pStep => {
             const stepNum = parseInt(pStep.dataset.step, 10);
             const iconElement = pStep.querySelector('.vp-step-icon');
             const labelText = pStep.querySelector('.vp-step-label').textContent;
             pStep.classList.remove('is-active', 'is-complete');
             iconElement.setAttribute('aria-label', `Step ${stepNum}: ${labelText} - Pending`);

             if (stepNum <= completedStepNum) {
                 pStep.classList.add('is-complete');
                  iconElement.setAttribute('aria-label', `Step ${stepNum}: ${labelText} - Complete`);
             }
             if (stepNum === completedStepNum + 1 && stepNum <= totalSteps) {
                 pStep.classList.add('is-active');
                  iconElement.setAttribute('aria-label', `Step ${stepNum}: ${labelText} - Active`);
             }
         });
         if (lineFill) {
              const percentage = completedStepNum >= totalSteps ? 100 : ((completedStepNum) / (totalSteps - 1)) * 100;
              const cappedPercentage = Math.min(percentage, 100);
              const property = isMobile ? 'width' : 'height';
              const otherProperty = isMobile ? 'height' : 'width';
              lineFill.style[property] = `${cappedPercentage}%`;
              lineFill.style[otherProperty] = '100%';
         }
     }

    /** Show content for target step */
     function showStepContent(targetStepNum) {
         steps.forEach(step => {
             const stepNum = parseInt(step.dataset.step, 10);
             const stepContent = step.querySelector('.vp-step-content');
             const stepHeader = step.querySelector('.vp-step-header');

             if (stepNum === targetStepNum) {
                 step.classList.add('is-active', 'is-visible');
                 step.classList.remove('is-complete');
                 step.setAttribute('aria-hidden', 'false');
                 if(stepContent) stepContent.style.display = '';
                 if(stepHeader) stepHeader.style.display = '';
             } else if (stepNum < targetStepNum) {
                 step.classList.remove('is-active');
                 step.classList.add('is-complete','is-visible');
                 step.setAttribute('aria-hidden', 'false');
                 // Optional: Hide content of completed steps
                 // if(stepContent) stepContent.style.display = 'none';
             } else {
                 step.classList.remove('is-active', 'is-complete', 'is-visible');
                 step.setAttribute('aria-hidden', 'true');
             }
         });
     }

    /** Display error message */
    function showError(stepNumber, message) {
        const errorContainers = [];
        const generalError = widget.querySelector(`#vp-step-${stepNumber}-error`);
        if (generalError) errorContainers.push(generalError);
        if (stepNumber === 3) {
            const paymentError = widget.querySelector('#vp-step-3-payment-error');
            const retrieveError = widget.querySelector('#vp-step-3-retrieve-error');
            if (selectedOption === 'full' && paymentError) errorContainers.push(paymentError);
            if (selectedOption === 'retrieve' && retrieveError) errorContainers.push(retrieveError);
        }
        if (errorContainers.length > 0) {
             const targetErrorElement = errorContainers[errorContainers.length - 1];
             targetErrorElement.textContent = message || errorMessages.generic;
             targetErrorElement.style.display = 'block';
             targetErrorElement.classList.add('is-visible');
             targetErrorElement.setAttribute('aria-hidden', 'false');
             setTimeout(() => targetErrorElement.focus(), 100);
        } else {
            console.warn(`Error element container for step ${stepNumber} not found.`);
            alert(`Error in Step ${stepNumber}: ${message || errorMessages.generic}`);
        }
        isProcessing = false; // Re-enable after showing error
    }

    /** Clear error messages */
    function clearError(stepNumber) {
        const errorElements = widget.querySelectorAll(
            `#vp-step-${stepNumber}-error, #vp-step-3-payment-error, #vp-step-3-retrieve-error`
        );
        errorElements.forEach(el => {
            if (el) {
                el.textContent = '';
                el.classList.remove('is-visible');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
            }
        });
    }

    /** Advance to next step */
    function advanceToStep(nextStepNumber) {
        const completedStep = nextStepNumber - 1;
        if (completedStep >= totalSteps) {
             updateProgressIndicator(totalSteps);
             const lastStep = widget.querySelector(`.vp-step[data-step="${totalSteps}"]`);
             if(lastStep) {
                lastStep.classList.add('is-complete', 'is-visible');
                lastStep.classList.remove('is-active');
                lastStep.setAttribute('aria-hidden', 'false');
             }
             return;
        }
        currentStep = nextStepNumber;
        updateProgressIndicator(completedStep);
        showStepContent(currentStep);
        setTimeout(() => smoothScrollToStep(currentStep), 150); // Scroll after transition starts
    }

    /** Go back to a previous step */
    function returnToStep(targetStepNumber) {
        if (targetStepNumber < 1 || isProcessing) return;
        currentStep = targetStepNumber;
        const lastCompletedStep = targetStepNumber - 1;
        // Reset state for future steps
        for (let i = targetStepNumber; i <= totalSteps; i++) {
            clearError(i);
            const stepSummary = widget.querySelector(`#vp-step-${i}-summary`);
            if (stepSummary) { stepSummary.textContent = ''; stepSummary.style.display = 'none'; }
             const stepElement = widget.querySelector(`.vp-step[data-step="${i}"]`);
             if(stepElement) {
                 stepElement.classList.remove('is-complete');
                 if (i === 1) { if(vinInput) vinInput.disabled = false; if(step1ContinueBtn) step1ContinueBtn.disabled = false; }
                 if (i === 2) { optionButtons.forEach(btn => { btn.disabled = false; btn.classList.remove('is-selected'); btn.setAttribute('aria-pressed', 'false'); }); selectedOption = null; }
                 if (i === 3) { widget.querySelectorAll('.step-3-content').forEach(el => el.style.display = 'none'); if (paypalButtonContainer) paypalButtonContainer.innerHTML = ''; }
             }
        }
        updateProgressIndicator(lastCompletedStep);
        showStepContent(currentStep);
        setTimeout(() => smoothScrollToStep(currentStep), 150);
    }

    /** Reset the entire widget */
    function resetWidget() {
        selectedVin = ''; selectedOption = null; currentPaypalOrderId = null; isProcessing = false;
        if(vinInput) vinInput.value = ''; if(retrieveEmailInput) retrieveEmailInput.value = ''; if(planSelection) planSelection.selectedIndex = 0;
        for (let i = 1; i <= totalSteps; i++) { clearError(i); const s = widget.querySelector(`#vp-step-${i}-summary`); if(s){s.textContent='';s.style.display='none';}}
        widget.querySelectorAll('.step-3-content').forEach(el => el.style.display = 'none');
        optionButtons.forEach(btn => { btn.classList.remove('is-selected'); btn.disabled = false; btn.setAttribute('aria-pressed', 'false'); });
        if (paypalButtonContainer) paypalButtonContainer.innerHTML = '';
        if(step1ContinueBtn) step1ContinueBtn.disabled = false; if(vinInput) vinInput.disabled = false;
        returnToStep(1);
        // Don't auto-scroll on initial reset
        // widget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /** Show final confirmation step */
     function showConfirmation(title, message, downloadUrl = null) {
         isProcessing = false;
         widget.querySelectorAll('.step-3-content').forEach(el => { if (!el.classList.contains('final-confirmation')) { el.style.display = 'none'; }});
         widget.querySelector('#confirmation-title').textContent = title;
         widget.querySelector('#confirmation-message').textContent = message;
         if (downloadUrl && downloadUrl !== '#') {
             downloadReportLink.href = downloadUrl;
             downloadReportLink.style.display = 'inline-block';
         } else {
             downloadReportLink.style.display = 'none';
         }
         step3Confirmation.style.display = 'block';
         step3Summary.textContent = "Completed"; step3Summary.style.display = 'inline';
         advanceToStep(totalSteps + 1);
     }

    // --- AJAX Helper ---
    async function makeAjaxRequest(action, data = {}) {
        if (!nonce || !ajaxUrl) {
            console.error("AJAX config missing (nonce or ajaxUrl)");
            throw new Error(errorMessages.generic);
        }
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', nonce); // Add nonce
        for (const key in data) {
            formData.append(key, data[key]);
        }

        try {
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            if (!response.ok) { throw new Error(`HTTP error! status: ${response.status}`); }
            const result = await response.json();
            if (result.success) { return result.data; }
            else { throw new Error(result.data?.message || errorMessages.generic); }
        } catch (error) {
            console.error(`AJAX Error (${action}):`, error);
            throw new Error(error.message || errorMessages.generic); // Re-throw processed error message
        }
    }

    // --- Event Handlers ---

    /** Handles Step 1 submission */
    async function handleStep1Submit() {
        if (isProcessing) return;
        clearError(1);
        selectedVin = vinInput.value.trim().toUpperCase();

        if (!selectedVin || selectedVin.length !== 17 || !/^[A-HJ-NPR-Z0-9]{17}$/.test(selectedVin)) {
             showError(1, errorMessages.vin_invalid); vinInput.focus(); return;
        }
        isProcessing = true; step1ContinueBtn.disabled = true; step1ContinueBtn.textContent = 'Validating...';
        try {
            await makeAjaxRequest('validate_vin_backend', { vin: selectedVin });
            step1Summary.textContent = `VIN: ${selectedVin}`; step1Summary.style.display = 'inline'; vinInput.disabled = true;
            advanceToStep(2);
        } catch (error) {
            showError(1, error.message); step1ContinueBtn.disabled = false; step1ContinueBtn.textContent = 'Continue';
        } finally {
             isProcessing = false; if (currentStep === 1) step1ContinueBtn.textContent = 'Continue';
        }
    }

    /** Handles Step 2 option selection */
    async function handleStep2OptionSelect(option) {
         if (isProcessing) return;
         clearError(2); selectedOption = option; isProcessing = true;
         optionButtons.forEach(btn => { const isSelected = btn.dataset.option === option; btn.classList.toggle('is-selected', isSelected); btn.disabled = true; btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false'); });
         const selectedBtn = widget.querySelector(`.vp-option-button[data-option="${option}"] h4`);
         step2Summary.textContent = selectedBtn ? selectedBtn.textContent : option.charAt(0).toUpperCase() + option.slice(1); step2Summary.style.display = 'inline';
         widget.querySelectorAll('.step-3-content').forEach(el => el.style.display = 'none'); clearError(3);

         let step3TitleText = "Step 3: Action & Results"; let nextStepUiReady = false;
         try {
             if (option === 'basic') {
                 step3TitleText = "Step 3: Basic Vehicle Specs"; step3BasicResults.style.display = 'block';
                 const basicData = await makeAjaxRequest('get_basic_specs', { vin: selectedVin });
                 widget.querySelector('#result-year').textContent = basicData.year || 'N/A'; widget.querySelector('#result-make').textContent = basicData.make || 'N/A'; widget.querySelector('#result-model').textContent = basicData.model || 'N/A'; widget.querySelector('#result-engine').textContent = basicData.engine || 'N/A';
                 nextStepUiReady = true;
             } else if (option === 'full') {
                 step3TitleText = "Step 3: Purchase Full Report"; step3FullPayment.style.display = 'block';
                 renderPayPalButtons(); // Render buttons now
                 nextStepUiReady = true;
             } else if (option === 'retrieve') {
                 step3TitleText = "Step 3: Retrieve Previous Report"; step3Retrieve.style.display = 'block';
                 nextStepUiReady = true;
             }
             step3Title.textContent = step3TitleText;
             if (nextStepUiReady) { advanceToStep(3); }
         } catch (error) {
             showError(2, error.message);
             optionButtons.forEach(btn => { btn.disabled = false; btn.classList.remove('is-selected'); btn.setAttribute('aria-pressed', 'false'); });
             step2Summary.style.display = 'none'; selectedOption = null;
         } finally { isProcessing = false; }
    }

     /** Handles Step 3 'Retrieve Previous Report' action */
     async function handleRetrieveReport() {
         if (isProcessing) return; clearError(3);
         const email = retrieveEmailInput.value.trim();
         if (!email || !/\S+@\S+\.\S+/.test(email)) { showError(3, 'Please enter a valid email address.'); retrieveEmailInput.focus(); return; }
         isProcessing = true; getPreviousReportBtn.disabled = true; getPreviousReportBtn.textContent = 'Verifying...';
         try {
             const result = await makeAjaxRequest('retrieve_report', { vin: selectedVin, email: email });
             showConfirmation("Report Found!", "Your previously generated report is ready.", result.downloadUrl);
         } catch (error) {
             showError(3, error.message); getPreviousReportBtn.disabled = false;
         } finally { isProcessing = false; if (currentStep === 3 && selectedOption === 'retrieve') getPreviousReportBtn.textContent = 'Verify & Get Report'; }
     }

     /** Renders PayPal buttons using the JS SDK */
     function renderPayPalButtons() {
         if (!window.paypal || !paypalClientId || !paypalButtonContainer) {
             showError(3, 'PayPal payment option is currently unavailable.'); return;
         }
         paypalButtonContainer.innerHTML = '<div id="paypal-spinner" style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin fa-2x"></i> Loading Payment Options...</div>'; // Add spinner

         const plan = planSelection.value;
         // Price is determined server-side, but we might need it for display? Not strictly needed for SDK calls.

         paypal.Buttons({
             style: { layout: 'vertical', color: 'blue', shape: 'rect', label: 'pay' },
             createOrder: async () => {
                 if (isProcessing) return; clearError(3); isProcessing = true;
                 console.log('Creating PayPal order for plan:', plan);
                 try {
                     const orderData = await makeAjaxRequest('create_paypal_order', { vin: selectedVin, plan: plan });
                     currentPaypalOrderId = orderData.orderID; console.log('PayPal Order ID:', currentPaypalOrderId);
                     isProcessing = false; return currentPaypalOrderId;
                 } catch (error) { showError(3, error.message); isProcessing = false; throw error; }
             },
             onApprove: async (data, actions) => {
                 if (isProcessing) return; clearError(3); isProcessing = true;
                 console.log('PayPal order approved. Capturing payment for Order ID:', data.orderID);
                 // Show processing indicator on UI
                 paypalButtonContainer.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin fa-2x"></i> Processing Payment...</div>';
                 try {
                     const captureData = await makeAjaxRequest('capture_paypal_order', { orderID: data.orderID });
                     console.log('Capture successful:', captureData);
                     showConfirmation( "Payment Successful!", captureData.message || "Your report is being generated.", captureData.downloadUrl || null );
                 } catch (error) {
                     showError(3, error.message); // Show capture error
                     renderPayPalButtons(); // Re-render buttons on capture error to allow retry? Or guide user?
                 } finally { isProcessing = false; /* Confirmation step handles final state */ }
             },
             onError: (err) => { console.error('PayPal Button Error:', err); showError(3, `PayPal Error: ${err.message || errorMessages.generic}`); isProcessing = false; },
             onCancel: () => { console.log('PayPal payment cancelled.'); showError(3, 'Payment process cancelled.'); isProcessing = false; }
         }).render('#paypal-button-container').then(() => {
             // Remove spinner once buttons are rendered
             const spinner = widget.querySelector('#paypal-spinner');
             if(spinner) spinner.remove();
         }).catch((err) => {
              console.error("Failed to render PayPal Buttons", err);
              showError(3, "Could not display PayPal payment options.");
              const spinner = widget.querySelector('#paypal-spinner');
              if(spinner) spinner.innerHTML = '<p style="color: red;">Error loading payment options.</p>';
         });
     }

    // --- Event Listeners Setup ---
    if (step1ContinueBtn) step1ContinueBtn.addEventListener('click', handleStep1Submit);
    if (vinInput) vinInput.addEventListener('keypress', (e) => { if (e.key === 'Enter' && currentStep === 1 && !isProcessing) { e.preventDefault(); handleStep1Submit(); } });
    optionButtons.forEach(button => button.addEventListener('click', () => { if (currentStep === 2 && !isProcessing && !button.disabled) { handleStep2OptionSelect(button.dataset.option); } }));
    if (getPreviousReportBtn) getPreviousReportBtn.addEventListener('click', handleRetrieveReport);
    if (retrieveEmailInput) retrieveEmailInput.addEventListener('keypress', (e) => { if (e.key === 'Enter' && currentStep === 3 && selectedOption === 'retrieve' && !isProcessing) { e.preventDefault(); handleRetrieveReport(); } });
    if (planSelection) planSelection.addEventListener('change', () => { if (currentStep === 3 && selectedOption === 'full' && paypalButtonContainer?.hasChildNodes()) { renderPayPalButtons(); } });
    restartButtons.forEach(button => button.addEventListener('click', resetWidget));
    backToStep2Buttons.forEach(button => button.addEventListener('click', () => returnToStep(2)));

    // --- Initialization ---
    resetWidget(); // Initialize to step 1 state

});
