// js/admin.js
jQuery(document).ready(function ($) {
    // --- Settings Page Tabs ---
    var $settingsWrapper = $('.edel-stripe-settings'); // Settings page container

    if ($settingsWrapper.length > 0) {
        var $navTabs = $settingsWrapper.find('.nav-tab-wrapper a.nav-tab');
        var $tabContents = $settingsWrapper.find('.tab-content');

        // Function to activate a tab
        function activateTab(target) {
            // Deactivate all tabs and content
            $navTabs.removeClass('nav-tab-active');
            $tabContents.removeClass('active-tab').hide(); // Hide inactive tabs

            // Activate the clicked tab and corresponding content
            $navTabs.filter('[href="' + target + '"]').addClass('nav-tab-active');
            $(target).addClass('active-tab').show(); // Show active tab

            // Optional: Store the active tab's hash in localStorage
            if (window.localStorage) {
                localStorage.setItem('edelStripeActiveTab', target);
            }
        }

        // Handle tab clicks
        $navTabs.on('click', function (e) {
            e.preventDefault();
            var target = $(this).attr('href');
            activateTab(target);
        });

        // Activate tab on page load (check localStorage first, then hash, then default to first)
        var activeTab = '#tab-common'; // Default tab
        if (window.localStorage) {
            var storedTab = localStorage.getItem('edelStripeActiveTab');
            if (storedTab && $navTabs.filter('[href="' + storedTab + '"]').length) {
                activeTab = storedTab;
            }
        }
        // Check URL hash as well (overrides localStorage if present)
        if (window.location.hash && $navTabs.filter('[href="' + window.location.hash + '"]').length) {
            activeTab = window.location.hash;
        }

        // Ensure the nav tab link also gets the active class
        $navTabs.filter('[href="' + activeTab + '"]').addClass('nav-tab-active');
        // Activate the content div
        $(activeTab).addClass('active-tab').show();
    } // end if settings wrapper exists

    // --- Copy to Clipboard for Price IDs ---
    // Uses the Clipboard API (requires HTTPS or localhost)
    $('.copy-clipboard').on('click', function (e) {
        e.preventDefault();
        var textToCopy = $(this).data('clipboard-text');
        var $button = $(this); // Store button element

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(
                () => {
                    // Success feedback
                    const originalText = $button.text();
                    $button.text('コピー完了!');
                    setTimeout(() => {
                        $button.text(originalText);
                    }, 1500);
                },
                (err) => {
                    // Error feedback (e.g., browser doesn't support it well)
                    console.error('Clipboard API copy failed: ', err);
                    alert('クリップボードへのコピーに失敗しました。');
                }
            );
        } else {
            // Fallback for older browsers or insecure contexts (less reliable)
            try {
                var tempInput = document.createElement('input');
                tempInput.style = 'position: absolute; left: -1000px; top: -1000px';
                tempInput.value = textToCopy;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                // Success feedback (fallback)
                const originalText = $button.text();
                $button.text('コピー完了!');
                setTimeout(() => {
                    $button.text(originalText);
                }, 1500);
            } catch (err) {
                console.error('Fallback copy failed: ', err);
                alert('クリップボードへのコピーに失敗しました。');
            }
        }
    });
}); // End document ready
