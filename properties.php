<p>
    51Degrees Pipeline plugin can be used in your theme or plugin development.
    To setup, take the text from the <code>Usage in Content</code> column and
    insert it into <code>code</code> snippets into your pages that will be
    replaced with the corresponding values. Below is the list of properties
    available with your Resource Key.
</p>

<?php
/*
    This Original Work is copyright of 51 Degrees Mobile Experts Limited.
    Copyright 2019 51 Degrees Mobile Experts Limited, 5 Charlotte Close,
    Caversham, Reading, Berkshire, United Kingdom RG4 7BY.

    This Original Work is licensed under the European Union Public Licence (EUPL) 
    v.1.2 and is subject to its terms as set out below.

    If a copy of the EUPL was not distributed with this file, You can obtain
    one at https://opensource.org/licenses/EUPL-1.2.

    The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
    amended by the European Commission) shall be deemed incompatible for
    the purposes of the Work and the provisions of the compatibility
    clause in Article 5 of the EUPL shall not apply.
*/

// Properties table

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/screen.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Properties_List_table extends WP_List_Table
{
    public function get_columns() {
        return $columns= array(
           'col_property_name' => __('Name'),
           'col_property_type' => __('Type'),
           'col_property_category' => __('Category'),
           'col_property_engine' => __('Engine'),
           'col_property_content_usage' => __('Usage in Content'),
           'col_property_php_usage' => __('Usage in PHP')
        );
    }

    public function get_sortable_columns() {
        return $sortable = array(
           'col_property_name' => array('col_property_name', true),
           'col_property_engine' => array('col_property_engine', true)
        );
    }

    public function prepare_items() {
        // Read from the cloud's authoritative entitlement map cached at
        // resource-key save time, not from runtime Pipeline::$data. The
        // runtime map can be empty (pipeline disabled, error, or
        // exception) and excludes optional engines (e.g. fodid) when their
        // feature toggle is off, even though the key entitles them.
        $cachedPipeline = get_option(Options::PIPELINE);

        if (!$cachedPipeline || empty($cachedPipeline['engine_properties'])) {
            return;
        }

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns());

        $results = array();

        foreach ($cachedPipeline['engine_properties'] as $dataKey => $properties) {
            foreach ($properties as $property) {
                $results[] = array(
                    "col_property_name" => strtolower($property["name"]),
                    "col_property_category" => $property["type"],
                    "col_property_type" => $property["category"],
                    "col_property_engine" => $dataKey,
                    "col_property_content_usage" => '{Pipeline::get("' .
                        $dataKey . '"' . ', "' .
                        strtolower($property["name"]) . '")}',
                    "col_property_php_usage" => 'Pipeline::get("' . $dataKey .
                        '"' . ', "' . strtolower($property["name"]) . '")'
                );
            }
        }

        usort($results, function ($a, $b) {
            $orderby = (!empty($_GET['orderby'])) ?
                sanitize_text_field($_GET['orderby']) : 'col_property_name';
            $order = (!empty($_GET['order'])) ?
                sanitize_text_field($_GET['order']) : 'asc';
            $result = strcmp($a[$orderby], $b[$orderby]);
            return ($order === 'asc') ? $result : -$result;
        });

        $this->items = $results;
    }

    public function display_rows() {
        $records = $this->items;

        foreach ($records as $i => $rec) {
            echo '<tr id="record_' . esc_html( $i ). '">';

            echo "<td>" . esc_html(strtolower($rec["col_property_name"])). "</td>";
            echo "<td>" . esc_html($rec["col_property_category"]). "</td>";
            echo "<td>" . esc_html($rec["col_property_type"]) . "</td>";
            echo "<td>" . esc_html($rec["col_property_engine"]). "</td>";
            echo "<td>" . esc_html($rec["col_property_content_usage"]). "</td>";
            echo "<td>" . esc_html($rec["col_property_php_usage"]). "</td>";

            echo'</tr>';
        }
    }
}

 //Prepare Table of elements
$propertyTable = new Properties_List_table();

$propertyTable->prepare_items();

$propertyTable->display();
