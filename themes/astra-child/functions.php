<?php
/**
 * Astra Child Theme functions and definitions
 *
 * @package Astra Child
 */

// Enqueue parent & child styles
function astra_child_enqueue_styles() {
    $theme = wp_get_theme(); // Define $theme to avoid undefined variable error

    // Parent theme stylesheet
    wp_enqueue_style(
        'astra-parent-style',
        get_template_directory_uri() . '/style.css'
    );

    // Child theme stylesheet
    wp_enqueue_style(
        'astra-child-style',
        get_stylesheet_uri(),
        array('astra-parent-style')
    );

    // Font Awesome for footer icons
    wp_enqueue_style(
        'font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
        array(),
        '5.15.4'
    );
    
    // Google Fonts - Poppins for consistent typography
    wp_enqueue_style(
        'google-fonts',
        'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        array(),
        null
    );
    
    // Enqueue Sticky Header Script
    wp_enqueue_script( 'sticky-header-script', get_stylesheet_directory_uri() . '/js/sticky-header.js', array(), $theme->get('Version'), true );
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles', 99); // Priority of 99.

/**
 * Replace Astra's default footer with custom footer.
 */
function vin_verify_replace_astra_footer() {
    // Remove Astra's default footer markup
    remove_action('astra_footer', 'astra_footer_markup', 10);

    // Add custom footer
    vin_verify_custom_footer_markup();
}
add_action('astra_footer', 'vin_verify_replace_astra_footer', 5);




/**
 * Register the shortcode [processing_ui] to load the HTML structure.
 */
function render_processing_ui_shortcode() {
  // Construct the path to the HTML file within the child theme
  $html_file_path = get_stylesheet_directory() . '/processing_ui/processing_ui.html';

  // Check if the file exists
  if ( file_exists( $html_file_path ) ) {
      // Use output buffering to capture the file content
      ob_start();
      include $html_file_path;
      return ob_get_clean();
  } else {
      // Return an error message or empty string if the file is missing
      return '<p style="color: red;">Error: Processing UI HTML file not found.</p>';
  }
}
add_shortcode( 'processing_ui', 'render_processing_ui_shortcode' );

/**
* Enqueue CSS and JS assets specifically for the processing UI.
* Only loads assets if the [processing_ui] shortcode is present on the page.
*/
function enqueue_processing_ui_assets() {
  global $post; // Access the global post object

  // Check if we are viewing a single post/page and if the shortcode exists in its content
  // This prevents loading assets on archive pages or pages without the shortcode
  if ( is_singular() && has_shortcode( $post->post_content, 'processing_ui' ) ) {

      // --- Enqueue CSS ---
      $css_file_path = get_stylesheet_directory() . '/processing_ui/processing_ui.css';
      $css_file_uri = get_stylesheet_directory_uri() . '/processing_ui/processing_ui.css';
      // Use file modification time for cache busting during development
      // Replace with a fixed version number for production if preferred
      $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0';

      wp_enqueue_style(
          'processing-ui-styles', // Handle
          $css_file_uri,          // URL
          array(),                // Dependencies (e.g., parent theme style if needed)
          $css_version            // Version
      );

      // --- Enqueue JS ---
      $js_file_path = get_stylesheet_directory() . '/processing_ui/processing_ui.js';
      $js_file_uri = get_stylesheet_directory_uri() . '/processing_ui/processing_ui.js';
      $js_version = file_exists($js_file_path) ? filemtime($js_file_path) : '1.0';

      wp_enqueue_script(
          'processing-ui-script', // Handle
          $js_file_uri,           // URL
          array(),                // Dependencies (e.g., 'jquery' if you were using it)
          $js_version,            // Version
          true                    // Load in footer
      );

      // Optional: If your JS needs to interact with WordPress backend via AJAX
      // You might want to localize script data here using wp_localize_script
      /*
      wp_localize_script( 'processing-ui-script', 'processingUiData', array(
          'ajax_url' => admin_url( 'admin-ajax.php' ),
          'nonce'    => wp_create_nonce( 'processing_ui_nonce' ) // Example nonce
      ));
      */
  }
}
// Hook into wp_enqueue_scripts with a priority (e.g., 20) to ensure it runs after theme/plugin scripts if needed
add_action( 'wp_enqueue_scripts', 'enqueue_processing_ui_assets', 20 );



/**
 * Inject custom footer markup into Astra's footer.
 */
function vin_verify_custom_footer_markup() {
    ?>
    <footer class="site-footer" id="colophon" role="contentinfo">
        <div class="vin-verify-footer-wrapper">
            <div class="footer-container">
                <!-- Footer Info -->
                <div class="footer-info">
                    <h2 class="footer-logo">
                        <i class="fas fa-shield-alt footer-logo-icon"></i>
                        Vin<span>Verify</span>
                    </h2>
                    <p class="footer-description">
                        VinVerify is your trusted partner for comprehensive Vehicle Identification Number verification services.
                        We provide detailed and accurate reports for cars, motorcycles, and boats.
                    </p>
                    <div class="service-icons">
                        <div class="service-icon"><i class="fas fa-car"></i> <span>Cars</span></div>
                        <div class="service-icon"><i class="fas fa-motorcycle"></i> <span>Motorcycles</span></div>
                        <div class="service-icon"><i class="fas fa-ship"></i> <span>Boats</span></div>
                    </div>
                    <div class="trust-badge">Trusted by 640K+ Verifications</div>
                </div>

                <!-- Footer Links -->
                <div class="footer-links">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="<?php echo esc_url(home_url()); ?>">Home</a></li>
                        <li><a href="<?php echo esc_url(home_url('/about')); ?>">About Us</a></li>
                        <li><a href="<?php echo esc_url(home_url('/services')); ?>">Services</a></li>
                        <li><a href="<?php echo esc_url(home_url('/pricing')); ?>">Pricing</a></li>
                        <li><a href="<?php echo esc_url(home_url('/contact')); ?>">Contact</a></li>
                        <li><a href="<?php echo esc_url(home_url('/faq')); ?>">FAQ</a></li>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="footer-contact">
                    <h4>Contact Us</h4>
                    <ul>
                        <li><i class="fas fa-envelope"></i> <a href="mailto:support@vinverify.com">support@vinverify.com</a></li>
                        <li><i class="fas fa-phone-alt"></i> +1 800-123-4567</li>
                        <li><i class="fas fa-map-marker-alt"></i> 1234 VIN Street, Auto City, CA, USA</li>
                        <li><i class="fas fa-clock"></i> Mon - Fri: 9:00 AM - 6:00 PM</li>
                    </ul>
                    <a href="<?php echo esc_url(home_url('/contact')); ?>" class="footer-cta">Contact Us</a>
                </div>

                <!-- Newsletter -->
                <div class="footer-newsletter">
                    <h4>Newsletter</h4>
                    <p>Subscribe to receive updates on vehicle verification news and special offers.</p>
                    <form class="newsletter-form" action="#" method="post">
                        <input type="email" name="newsletter_email" placeholder="Your email address" required>
                        <button type="submit">Subscribe</button>
                    </form>
                    <p class="privacy-note">
                        By subscribing, you agree to our
                        <a href="<?php echo esc_url(home_url('/privacy-policy')); ?>">Privacy Policy</a>.
                    </p>
                </div>
                
                <!-- Policy Links - NEW -->
                <div class="footer-policy">
                    <h4>Legal Information</h4>
                    <ul>
                        <li><a href="<?php echo esc_url(home_url('/privacy-policy')); ?>">Privacy Policy</a></li>
                        <li><a href="<?php echo esc_url(home_url('/terms-of-service')); ?>">Terms of Service</a></li>
                        <li><a href="<?php echo esc_url(home_url('/refund-policy')); ?>">Refund Policy</a></li>
                        <li><a href="<?php echo esc_url(home_url('/data-processing')); ?>">Data Processing</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-social">
                    <ul>
                        <li><a href="https://www.facebook.com/vinverify" target="_blank" rel="noopener"><i class="fab fa-facebook-f"></i></a></li>
                        <li><a href="https://www.twitter.com/vinverify" target="_blank" rel="noopener"><i class="fab fa-twitter"></i></a></li>
                        <li><a href="https://www.instagram.com/vinverify" target="_blank" rel="noopener"><i class="fab fa-instagram"></i></a></li>
                        <li><a href="https://www.linkedin.com/company/vinverify" target="_blank" rel="noopener"><i class="fab fa-linkedin-in"></i></a></li>
                    </ul>
                </div>
                <div class="footer-copy">
                    <p>© <?php echo date('Y'); ?> VinVerify. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>
    <?php
}

/**
 * Social Proof Notification System
 * Displays notifications about recent purchases to build customer trust.
 */
function vin_verify_social_proof_notifier() {
    // Only add this to front-end, not admin area
    if (is_admin()) {
        return;
    }
    ?>
    <!-- Social Proof Notifier -->
    <div class="social-proof-container" id="social-proof">
      <div class="social-proof-notification" style="display:none;">
        <div class="notification-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
          </svg>
        </div>
        <div class="notification-content">
          <p class="notification-message">
            <span class="customer-name"></span> from <span class="customer-location"></span> just purchased 
            <span class="product-name"></span>
          </p>
          <p class="notification-time"></p>
        </div>
        <button class="notification-close" aria-label="Close notification">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
      </div>
    </div>
    <script>
      (function(){
        // Get or set widget cookie state
        function getCookie(name) {
          const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
          return match ? match[2] : null;
        }
        
        function setCookie(name, value, days) {
          let expires = "";
          if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
          }
          document.cookie = name + "=" + value + expires + "; path=/";
        }

        // Check if widget was dismissed globally
        if (getCookie('social_proof_dismissed') === 'true') {
          return; // Exit if user dismissed the widget
        }

        const customers = [
          { name: "John D.", location: "New York", product: "Silver Car Report" },
          { name: "Sarah L.", location: "Chicago", product: "Gold Motorcycle Report" },
          { name: "Michael R.", location: "Los Angeles", product: "Platinum Boat Report" },
          { name: "Emma J.", location: "Austin", product: "Gold Car Report" },
          { name: "David M.", location: "Boston", product: "Silver Motorcycle Report" },
          { name: "Olivia P.", location: "Seattle", product: "Platinum Car Report" },
          { name: "Liam T.", location: "Denver", product: "Gold Boat Report" },
          { name: "Sophia K.", location: "Miami", product: "Silver Car Report" },
          { name: "Robert N.", location: "Phoenix", product: "Platinum Motorcycle Report" },
          { name: "Ava G.", location: "Atlanta", product: "Gold Car Report" }
        ];
        
        const container = document.getElementById('social-proof');
        if (!container) return; // Safety check
        
        const notification = container.querySelector('.social-proof-notification');
        const closeBtn = notification.querySelector('.notification-close');
        const nameEl = notification.querySelector('.customer-name');
        const locationEl = notification.querySelector('.customer-location');
        const productEl = notification.querySelector('.product-name');
        const timeEl = notification.querySelector('.notification-time');

        function showNotification() {
          const customer = customers[Math.floor(Math.random() * customers.length)];
          nameEl.textContent = customer.name;
          locationEl.textContent = customer.location;
          productEl.textContent = customer.product;
          timeEl.textContent = `${Math.floor(Math.random() * 60) + 1} minutes ago`;
          
          notification.style.display = 'flex';
          setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateY(0)';
          }, 10);
          
          // Auto-hide after 5 seconds
          setTimeout(hideNotification, 5000);
        }

        function hideNotification() {
          notification.style.opacity = '0';
          notification.style.transform = 'translateY(20px)';
          setTimeout(() => {
            notification.style.display = 'none';
          }, 400); // Match CSS transition duration
          
          // Show next notification after a random delay (5-15s)
          const nextDelay = (Math.floor(Math.random() * 10) + 5) * 1000;
          setTimeout(showNotification, nextDelay);
        }

        // Handle close button click
        closeBtn.addEventListener('click', (e) => {
          hideNotification();
          setCookie('social_proof_dismissed', 'true', 7); // Dismiss for 7 days
        });

        // Initial delay before first notification (5-15s)
        const initialDelay = (Math.floor(Math.random() * 10) + 5) * 1000;
        setTimeout(showNotification, initialDelay);
      })();
    </script>
    <?php
}
add_action('wp_footer', 'vin_verify_social_proof_notifier', 20);
