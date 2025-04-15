/**
 * VIN Processor Widget Interaction Logic
 * Version: 1.0
 * Handles step navigation, animations, scrolling, and placeholder interactions.
 */
document.addEventListener('DOMContentLoaded', () => {
    const widget = document.getElementById('vin-process-widget');
    if (!widget) {
        // console.error('VIN Process Widget container (#vin-process-widget) not found.');
        return; // Exit if widget isn't on the page
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

    // Step 3 Action Elements
    const proceedPaymentBtn = widget.getElementById('vp-proceed-payment');
    const retrieveEmailInput = widget.getElementById('retrieve-email-input');
    const getPreviousReportBtn = widget.getElementById('vp-get-previous-report');
    const downloadReportLink = widget.getElementById('download-report-link');
    const restartButtons = widget.querySelectorAll('#vp-restart-basic, #vp-restart-final');
    const backToStep2Buttons = widget.querySelectorAll('#vp-back-step2-payment, #vp-back-step2-retrieve'); // Back buttons


    // --- State ---
    let currentStep = 1;
    const totalSteps = steps.length;
    let selectedVin = '';
    let selectedOption = null; // 'basic', 'full', 'retrieve'

    // --- Utility Functions ---

    /**
     * Debounce function to limit how often a function can run.
     */
    function debounce(func, wait, immediate) {
        let timeout;
        return function() {
            const context = this, args = arguments;
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

    /**
     * Checks if the viewport is mobile based on CSS breakpoint.
     * Uses matchMedia for reliability.
     */
    function isMobileViewport() {
        return window.matchMedia("(max-width: 768px)").matches;
    }

    /**
     * Scrolls the target step's header into view smoothly.
     * Adjusts for potential sticky headers.
     * @param {number} stepNumber The step number to scroll to.
     */
    const smoothScrollToStep = debounce((stepNumber) => {
        const targetStepElement = widget.querySelector(`.vp-step[data-step="${stepNumber}"]`);
        if (targetStepElement) {
            // Try to find common sticky header selectors
            const stickyHeader = document.querySelector('#masthead.site-header.sticky-header') || document.querySelector('.elementor-sticky--active');
            const headerOffset = stickyHeader ? stickyHeader.offsetHeight : 0;
            const elementPosition = targetStepElement.getBoundingClientRect().top;
            // Calculate target scroll position, accounting for sticky header and adding a small top margin
            const offsetPosition = window.pageYOffset + elementPosition - headerOffset - 20; // 20px margin from top

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    }, 100); // Debounce scrolling calls slightly


    /**
     * Updates the visual state of the progress indicator (line and icons).
     * @param {number} completedStepNum The step number that was just completed (0 if none).
     */
    function updateProgressIndicator(completedStepNum) {
        const isMobile = isMobileViewport();
        progressSteps.forEach(pStep => {
            const stepNum = parseInt(pStep.dataset.step, 10);
            const iconElement = pStep.querySelector('.vp-step-icon');
            pStep.classList.remove('is-active', 'is-complete');
            iconElement.setAttribute('aria-label', `Step ${stepNum}: ${pStep.querySelector('.vp-step-label').textContent} - Pending`); // Reset ARIA

            if (stepNum <= completedStepNum) {
                pStep.classList.add('is-complete');
                 iconElement.setAttribute('aria-label', `Step ${stepNum}: ${pStep.querySelector('.vp-step-label').textContent} - Complete`);
            }
            // Current step is the one *after* the completed one
            if (stepNum === completedStepNum + 1 && stepNum <= totalSteps) {
                pStep.classList.add('is-active');
                 iconElement.setAttribute('aria-label', `Step ${stepNum}: ${pStep.querySelector('.vp-step-label').textContent} - Active`);
            }
        });

        // Update line fill percentage
        if (lineFill) {
             const percentage = completedStepNum >= totalSteps ? 100 : ((completedStepNum) / (totalSteps - 1)) * 100;
             const cappedPercentage = Math.min(percentage, 100); // Ensure it doesn't exceed 100%

             if (isMobile) {
                 lineFill.style.width = `${cappedPercentage}%`; // Animate width on mobile
                 lineFill.style.height = '100%';
             } else {
                 lineFill.style.height = `${cappedPercentage}%`; // Animate height on desktop
                 lineFill.style.width = '100%';
             }
        }
    }

     /**
      * Shows the content for the target step and updates visibility/completion states.
      * @param {number} targetStepNum The step number to make active.
      */
     function showStepContent(targetStepNum) {
         steps.forEach(step => {
             const stepNum = parseInt(step.dataset.step, 10);
             const stepContent = step.querySelector('.vp-step-content');
             const stepHeader = step.querySelector('.vp-step-header');

             if (stepNum === targetStepNum) {
                 step.classList.add('is-active', 'is-visible');
                 step.classList.remove('is-complete');
                 step.setAttribute('aria-hidden', 'false');
                 // Ensure content is visible if it was hidden
                 if(stepContent) stepContent.style.display = '';
                 if(stepHeader) stepHeader.style.display = '';
             } else if (stepNum < targetStepNum) {
                 // Mark previous steps as complete, keep visible
                 step.classList.remove('is-active');
                 step.classList.add('is-complete','is-visible'); // Keep visible to show summary
                 step.setAttribute('aria-hidden', 'false'); // Still visible
                 // Optional: Hide content of completed steps for cleaner look
                 // if(stepContent) stepContent.style.display = 'none';
             } else {
                 // Hide future steps completely
                 step.classList.remove('is-active', 'is-complete');
                 step.classList.remove('is-visible'); // Ensure it's hidden
                 step.setAttribute('aria-hidden', 'true');
             }
         });
     }


    /**
     * Displays an error message for a specific step, making it visible to screen readers.
     * @param {number} stepNumber The step number where the error occurred.
     * @param {string} message The error message to display.
     */
    function showError(stepNumber, message) {
        const errorContainers = [];
        const generalError = widget.querySelector(`#vp-step-${stepNumber}-error`);
        if (generalError) errorContainers.push(generalError);

        // Add specific step 3 error containers if stepNumber is 3
        if (stepNumber === 3) {
            const paymentError = widget.querySelector('#vp-step-3-payment-error');
            const retrieveError = widget.querySelector('#vp-step-3-retrieve-error');
            if (selectedOption === 'full' && paymentError) errorContainers.push(paymentError);
            if (selectedOption === 'retrieve' && retrieveError) errorContainers.push(retrieveError);
        }

        if (errorContainers.length > 0) {
             // Show error in the most relevant container found
             const targetErrorElement = errorContainers[errorContainers.length - 1]; // Use the most specific one
             targetErrorElement.textContent = message;
             targetErrorElement.style.display = 'block'; // Use style for transition
             targetErrorElement.classList.add('is-visible');
             targetErrorElement.setAttribute('aria-hidden', 'false'); // Make visible to SR

             // Focus the error message for screen reader announcement
             setTimeout(() => targetErrorElement.focus(), 100); // Slight delay for rendering

        } else {
            console.warn(`Error element container for step ${stepNumber} (option: ${selectedOption}) not found.`);
            // Fallback: Alert if no container found
            alert(`Error in Step ${stepNumber}: ${message}`);
        }
    }

    /**
     * Clears the error message for a specific step and hides it from screen readers.
     * @param {number} stepNumber The step number to clear errors for.
     */
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

    /**
     * Moves the process to the next step, updating UI and scrolling.
     * @param {number} nextStepNumber The step number to advance to.
     */
    function advanceToStep(nextStepNumber) {
        const completedStep = nextStepNumber - 1;

        // Handle final step completion
        if (completedStep >= totalSteps) {
             console.log("Process complete");
             updateProgressIndicator(totalSteps); // Mark final step complete visually
             // Ensure last step content is fully visible and marked complete
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
        // Scroll slightly after the transition starts
        setTimeout(() => smoothScrollToStep(currentStep), 100);
    }

    /**
     * Go back to a previous step (e.g., from step 3 back to step 2).
     * @param {number} targetStepNumber The step number to go back to.
     */
    function returnToStep(targetStepNumber) {
        if (targetStepNumber < 1) return;
        currentStep = targetStepNumber;
        const lastCompletedStep = targetStepNumber - 1;

        // Reset state for future steps
        for (let i = targetStepNumber; i <= totalSteps; i++) {
            clearError(i);
            const stepSummary = widget.querySelector(`#vp-step-${i}-summary`);
            if (stepSummary) {
                stepSummary.textContent = '';
                stepSummary.style.display = 'none';
            }
             const stepElement = widget.querySelector(`.vp-step[data-step="${i}"]`);
             if(stepElement) {
                 stepElement.classList.remove('is-complete');
                 // Re-enable inputs/buttons if needed (example for step 1)
                 if (i === 1) {
                    if(vinInput) vinInput.disabled = false;
                    if(step1ContinueBtn) step1ContinueBtn.disabled = false;
                 }
                 // Re-enable option buttons for step 2
                 if (i === 2) {
                    optionButtons.forEach(btn => {
                        btn.disabled = false;
                        btn.classList.remove('is-selected');
                        btn.setAttribute('aria-pressed', 'false');
                    });
                    selectedOption = null;
                 }
                 // Hide step 3 variants
                 if (i === 3) {
                    widget.querySelectorAll('.step-3-content').forEach(el => el.style.display = 'none');
                 }
             }
        }


        updateProgressIndicator(lastCompletedStep);
        showStepContent(currentStep);
        // Scroll slightly after the transition starts
        setTimeout(() => smoothScrollToStep(currentStep), 100);
    }


    /**
     * Resets the entire widget to the initial state (Step 1).
     */
    function resetWidget() {
        // Reset state variables
        selectedVin = '';
        selectedOption = null;

        // Reset inputs and selections
        if(vinInput) {
            vinInput.value = '';
            vinInput.disabled = false;
        }
        if(retrieveEmailInput) retrieveEmailInput.value = '';
        const planSelect = widget.querySelector('#plan-selection');
        if(planSelect) planSelect.selectedIndex = 0;

        // Clear summaries and errors for all steps
        for (let i = 1; i <= totalSteps; i++) {
            clearError(i);
            const stepSummary = widget.querySelector(`#vp-step-${i}-summary`);
            if (stepSummary) {
                stepSummary.textContent = '';
                stepSummary.style.display = 'none';
            }
        }

        // Hide step 3 content variants
        widget.querySelectorAll('.step-3-content').forEach(el => {
            el.style.display = 'none';
        });

        // Reset option button selection and state
        optionButtons.forEach(btn => {
            btn.classList.remove('is-selected');
            btn.disabled = false;
            btn.setAttribute('aria-pressed', 'false');
        });

        // Re-enable step 1 button
        if(step1ContinueBtn) step1ContinueBtn.disabled = false;

        // Go back to step 1 view
        returnToStep(1);

        // Scroll to top of widget smoothly
        widget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }


    // --- Placeholder Handlers (Replace comments with actual AJAX/Backend calls) ---

    /** Handles Step 1 submission: Validates VIN and advances. */
    function handleStep1Submit() {
        clearError(1);
        selectedVin = vinInput.value.trim().toUpperCase();

        // Basic client-side validation
        if (!selectedVin || selectedVin.length !== 17 || !/^[A-HJ-NPR-Z0-9]{17}$/.test(selectedVin)) {
             showError(1, 'Please enter a valid 17-digit VIN (letters and numbers, no I, O, Q).');
             vinInput.focus();
             return;
        }

        console.log(`Step 1 Submitted. VIN: ${selectedVin}. Simulating Server Validation...`);
        // --- TODO: Replace with AJAX call to your WordPress backend ---
        // Example structure:
        // fakeApiCall({ action: 'validate_vin', vin: selectedVin })
        //   .then(response => {
        //      if (response.success) {
        //          step1Summary.textContent = `VIN: ${selectedVin}`;
        //          step1Summary.style.display = 'inline';
        //          vinInput.disabled = true;
        //          step1ContinueBtn.disabled = true;
        //          advanceToStep(2);
        //      } else {
        //          showError(1, response.message || 'VIN validation failed.');
        //          vinInput.focus();
        //      }
        //   })
        //   .catch(error => {
        //      showError(1, 'An error occurred during validation. Please try again.');
        //      console.error("Validation Error:", error);
        //   });
        // --- Simulation ---
        setTimeout(() => { // Simulate network delay
            // Simulate success:
            step1Summary.textContent = `VIN: ${selectedVin}`;
            step1Summary.style.display = 'inline';
            vinInput.disabled = true;
            step1ContinueBtn.disabled = true;
            advanceToStep(2);
            // Simulate failure:
            // showError(1, 'Simulated Error: VIN not found in database.');
            // vinInput.focus();
        }, 500);
    }

    /** Handles Step 2 option selection: Updates UI and prepares Step 3. */
    function handleStep2OptionSelect(option) {
         clearError(2);
         selectedOption = option;
         console.log(`Step 2 Submitted. Option: ${selectedOption}`);

         // Update button styles and ARIA state
         optionButtons.forEach(btn => {
             const isSelected = btn.dataset.option === option;
             btn.classList.toggle('is-selected', isSelected);
             btn.disabled = true; // Disable all options after selection
             btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
         });

         // Update summary
         const selectedBtn = widget.querySelector(`.vp-option-button[data-option="${option}"] h4`);
         step2Summary.textContent = selectedBtn ? selectedBtn.textContent : option.charAt(0).toUpperCase() + option.slice(1);
         step2Summary.style.display = 'inline';

         // Prepare Step 3 UI based on selection
         widget.querySelectorAll('.step-3-content').forEach(el => el.style.display = 'none'); // Hide all variants first
         clearError(3); // Clear any previous step 3 errors

         let step3TitleText = "Step 3: Action & Results"; // Default title
         if (option === 'basic') {
             step3TitleText = "Step 3: Basic Vehicle Specs";
             step3BasicResults.style.display = 'block';
             console.log("Fetching basic specs...");
             // --- TODO: Replace with AJAX call to fetch basic specs ---
             // fakeApiCall({ action: 'get_basic_specs', vin: selectedVin })
             //   .then(response => {
             //      if (response.success) {
             //           widget.querySelector('#result-year').textContent = response.data.year || 'N/A';
             //           widget.querySelector('#result-make').textContent = response.data.make || 'N/A';
             //           widget.querySelector('#result-model').textContent = response.data.model || 'N/A';
             //           widget.querySelector('#result-engine').textContent = response.data.engine || 'N/A';
             //           advanceToStep(3);
             //      } else {
             //           showError(3, response.message || 'Failed to fetch basic specs.');
             //           returnToStep(2); // Allow user to choose another option maybe?
             //      }
             //   })
             //   .catch(error => { showError(3, 'Error fetching specs.'); console.error(error); returnToStep(2); });
             // --- Simulation ---
             setTimeout(() => {
                 widget.querySelector('#result-year').textContent = '2021';
                 widget.querySelector('#result-make').textContent = 'SimuMake';
                 widget.querySelector('#result-model').textContent = 'SimuModel';
                 widget.querySelector('#result-engine').textContent = 'SimuEngine';
                 advanceToStep(3);
             }, 800);

         } else if (option === 'full') {
             step3TitleText = "Step 3: Purchase Full Report";
             step3FullPayment.style.display = 'block';
             advanceToStep(3);
         } else if (option === 'retrieve') {
             step3TitleText = "Step 3: Retrieve Previous Report";
             step3Retrieve.style.display = 'block';
             advanceToStep(3);
         }
         step3Title.textContent = step3TitleText;
    }

     /** Handles Step 3 actions (Payment, Retrieve, etc.) */
     function handleStep3Action(actionType) {
         clearError(3); // Clear previous errors in step 3
         console.log(`Step 3 Action: ${actionType}`);

         if (actionType === 'payment') {
             const selectedPlanEl = widget.querySelector('#plan-selection');
             const plan = selectedPlanEl.value;
             const price = selectedPlanEl.options[selectedPlanEl.selectedIndex].dataset.price;
             console.log(`Initiating payment process for plan: ${plan}, price: ${price}, VIN: ${selectedVin}`);
             // --- TODO: Implement actual payment initiation ---
             // 1. Potentially make an AJAX call to backend to create an order ID.
             // 2. Redirect to PayPal with order details (VIN, Plan, Price, OrderID, Callback URLs).
             // Example: window.location.href = `/start-payment?plan=${plan}&vin=${selectedVin}`;
             // --- Simulation ---
             alert(`Simulating Payment Gateway Redirect for ${plan} ($${price}).\n\n--- IMPORTANT ---\nOn your actual site, you would redirect to PayPal here.\nAfter payment, PayPal redirects back to a success/cancel URL on your site.\nYour backend needs a webhook/IPN listener to get payment confirmation from PayPal.\nONLY after backend confirmation should you call the VinAudit API and generate the report.\n\nClick OK to simulate a SUCCESSFUL payment callback and report generation.`);
             // --- Simulate successful callback and fulfillment ---
             // This part would normally happen on a separate page load or via AJAX triggered by webhook
             setTimeout(() => {
                console.log("Simulating successful payment callback & report generation...");
                // --- TODO: Backend would verify payment, call VinAudit, generate PDF, return URL ---
                const fakeDownloadUrl = '#download-simulated-report.pdf'; // Replace with actual URL from backend
                showConfirmation("Payment Successful!", "Your full history report has been generated.", fakeDownloadUrl);
             }, 1500); // Simulate delay

         } else if (actionType === 'retrieve') {
             const email = retrieveEmailInput.value.trim();
             if (!email || !/\S+@\S+\.\S+/.test(email)) {
                 showError(3, 'Please enter a valid email address.');
                 retrieveEmailInput.focus();
                 return;
             }
             console.log(`Attempting to retrieve report for VIN ${selectedVin} with email ${email}`);
             // --- TODO: Replace with AJAX call to backend ---
             // Example:
             // fakeApiCall({ action: 'retrieve_report', vin: selectedVin, email: email })
             //  .then(response => {
             //      if (response.success) {
             //          showConfirmation("Report Found!", "Your previous report is ready.", response.downloadUrl);
             //      } else {
             //          showError(3, response.message || 'Could not find a previous report for this VIN and email.');
             //      }
             //  })
             //  .catch(error => { showError(3, 'An error occurred during retrieval.'); console.error(error); });
             // --- Simulation ---
             alert(`Simulating verification for ${email}. Click OK to simulate success.`);
             setTimeout(() => {
                 // Simulate success:
                 const fakeDownloadUrl = '#download-retrieved-report.pdf';
                 showConfirmation("Report Found!", "Your previously generated report is ready.", fakeDownloadUrl);
                 // Simulate failure:
                 // showError(3, 'No previous report found for this VIN and email combination.');
             }, 1000);
         }
     }

     /** Shows the final confirmation/download step UI */
     function showConfirmation(title, message, downloadUrl = null) {
         // Hide other step 3 content variants
         const step3Contents = widget.querySelectorAll('.step-3-content');
         step3Contents.forEach(el => {
             if (!el.classList.contains('final-confirmation')) {
                 el.style.display = 'none';
             }
         });

         // Update and show confirmation content
         widget.querySelector('#confirmation-title').textContent = title;
         widget.querySelector('#confirmation-message').textContent = message;
         if (downloadUrl && downloadUrl !== '#') {
             downloadReportLink.href = downloadUrl;
             downloadReportLink.style.display = 'inline-block';
         } else {
             downloadReportLink.style.display = 'none';
         }
         step3Confirmation.style.display = 'block';
         step3Summary.textContent = "Completed"; // Update summary
         step3Summary.style.display = 'inline';

         // Mark step 3 as complete on progress indicator
         advanceToStep(totalSteps + 1); // Advance logic handles marking last step complete
     }


    // --- Event Listeners ---

    // Step 1 Continue Button
    if (step1ContinueBtn) {
        step1ContinueBtn.addEventListener('click', handleStep1Submit);
    }
    // Allow Enter key submission in VIN input
    if (vinInput) {
        vinInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Only submit if step 1 is active
                if (currentStep === 1) {
                    handleStep1Submit();
                }
            }
        });
    }

    // Step 2 Option Buttons
    optionButtons.forEach(button => {
        button.addEventListener('click', () => {
             // Only allow selection if step 2 is active and button isn't already disabled
             if (currentStep === 2 && !button.disabled) {
                handleStep2OptionSelect(button.dataset.option);
             }
        });
    });

    // Step 3 Action Buttons
    if (proceedPaymentBtn) {
        proceedPaymentBtn.addEventListener('click', () => handleStep3Action('payment'));
    }
    if (getPreviousReportBtn) {
        getPreviousReportBtn.addEventListener('click', () => handleStep3Action('retrieve'));
    }
     // Allow Enter key submission in retrieve email input
     if (retrieveEmailInput) {
         retrieveEmailInput.addEventListener('keypress', (e) => {
             if (e.key === 'Enter') {
                 e.preventDefault();
                 // Only submit if retrieve section is visible
                 if (window.getComputedStyle(step3Retrieve).display === 'block') {
                     handleStep3Action('retrieve');
                 }
             }
         });
     }

     // Restart Buttons
     restartButtons.forEach(button => {
         button.addEventListener('click', resetWidget);
     });

     // Back Buttons (Step 3 to Step 2)
     backToStep2Buttons.forEach(button => {
         button.addEventListener('click', () => returnToStep(2));
     });


    // --- Initialization ---
    resetWidget(); // Initialize to step 1 state

});

// Example Fake API Call function (Replace with your actual AJAX implementation)
// function fakeApiCall(data) {
//     console.log("Simulating API Call with data:", data);
//     return new Promise((resolve, reject) => {
//         setTimeout(() => {
//             // Simulate different responses based on action
//             if (data.action === 'validate_vin') {
//                 if (data.vin && data.vin.length === 17) {
//                     resolve({ success: true });
//                 } else {
//                     resolve({ success: false, message: 'Invalid VIN provided.' });
//                 }
//             } else if (data.action === 'get_basic_specs') {
//                  resolve({ success: true, data: { year: '2022', make: 'API Make', model: 'API Model', engine: 'API Engine' } });
//             } else if (data.action === 'retrieve_report') {
//                 if (data.email === 'test@example.com') {
//                      resolve({ success: true, downloadUrl: '#retrieved-report-link' });
//                 } else {
//                      resolve({ success: false, message: 'No report found for this email.' });
//                 }
//             }
//             else {
//                 reject(new Error("Unknown API action simulation"));
//             }
//         }, 1000); // Simulate 1 second delay
//     });
// }

