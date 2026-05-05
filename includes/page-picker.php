<?php

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
        var sel   = document.getElementById(<?php echo wp_json_encode($picker_id); ?>);
        var input = document.getElementById(<?php echo wp_json_encode($target_input_id); ?>);
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
    <?php
}
