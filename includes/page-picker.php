<?php
/* *********************************************************************
 * This Original Work is copyright of 51 Degrees Mobile Experts Limited.
 * Copyright 2026 51 Degrees Mobile Experts Limited, Davidson House,
 * Forbury Square, Reading, Berkshire, United Kingdom RG1 3EU.
 *
 * This Original Work is licensed under the European Union Public Licence
 * (EUPL) v.1.2 and is subject to its terms as set out below.
 *
 * If a copy of the EUPL was not distributed with this file, You can obtain
 * one at https://opensource.org/licenses/EUPL-1.2.
 *
 * The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
 * amended by the European Commission) shall be deemed incompatible for
 * the purposes of the Work and the provisions of the compatibility
 * clause in Article 5 of the EUPL shall not apply.
 *
 * If using the Work as, or as part of, a network application, by
 * including the attribution notice(s) required under Article 5 of the EUPL
 * in the end user terms of the application under an appropriate heading,
 * such notice(s) shall fulfill the requirements of that article.
 * ********************************************************************* */



/**
 * Renders a "pick a WP page" dropdown that populates the URL <input>
 * identified by $target_input_id when the admin selects a page.
 *
 * Outputs nothing if no WP pages exist. Stored value is the input's
 * value (a URL string) — the dropdown is purely a typing aid.
 *
 * @param string $target_input_id DOM id of the <input> to populate.
 * @param string $placeholder     Localized "-- Select a page --" label.
 */
function fiftyonedegrees_render_page_picker(string $target_input_id, string $placeholder): void {
    $pages = get_pages();
    if (empty($pages)) {
        return;
    }

    $picker_id = $target_input_id . '_page_picker';

    echo '<p>';
    echo '<select id="' . esc_attr($picker_id) . '">';
    echo '<option value="">' . esc_html($placeholder) . '</option>';
    foreach ($pages as $page) {
        echo '<option value="' . esc_url(get_permalink($page->ID)) . '">'
            . esc_html($page->post_title) . '</option>';
    }
    echo '</select>';
    echo '</p>';
    ?>
    <script>
    (function () {
        var sel   = document.getElementById( echo wp_json_encode($picker_id); ?>);
        var input = document.getElementById( echo wp_json_encode($target_input_id); ?>);
        if (sel && input) {
            sel.addEventListener('change', function () {
                if (this.value) {
                    input.value = this.value;
                    this.selectedIndex = 0;
                }
            });
        }
    })();
    </script>
    
}
