<!--
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
-->

<form method="post" action="options.php">

    <?php settings_fields(Options::GROUP_KEY); ?>

    <?php

        $cachedPipeline = get_option(Options::PIPELINE);
        $validationError = get_option(Options::PIPELINE_VALIDATION_ERROR, '');

        if (isset($cachedPipeline['error'])) {
            echo '<p></p><span class="fod-pipeline-status error"><b>' .
                esc_html($cachedPipeline['error']) . '</b></span>';
        } elseif (!empty($validationError)) {
            echo '<p></p><span class="fod-pipeline-status error"><b>' .
                esc_html($validationError) . '</b></span>';
        }

        $runtimeError = Pipeline::get_runtime_error();
        if ($runtimeError !== null) {
            echo '<p></p><span class="fod-pipeline-status error"><b>'
                . esc_html($runtimeError['context']) . '</b><br>'
                . esc_html($runtimeError['class']) . ': '
                . esc_html($runtimeError['message']);
            if (!empty($runtimeError['file'])) {
                echo '<br><small>at ' . esc_html($runtimeError['file'])
                    . ':' . (int) $runtimeError['line'] . '</small>';
            }
            echo '<br><small>occurred ' . esc_html(human_time_diff((int) $runtimeError['occurred']))
                . ' ago</small>';
            if (!empty($runtimeError['trace'])) {
                echo '<details><summary>Stack trace</summary><pre style="white-space:pre-wrap;font-size:11px;">'
                    . esc_html($runtimeError['trace']) . '</pre></details>';
            }
            echo '</span>';
        }

        if (isset($cachedPipeline['pipeline'])) {
            echo '<p></p><span class="fod-pipeline-status good"><b>This ' .
                'Resource Key is valid and allows access to the custom ' .
                'properties selected in the following categories: ' .
                esc_html(json_encode($cachedPipeline['available_engines'])) .
                ' </br>To continue, connect to Google Analytics via the ' .
                '<a href="options-general.php?page=51Degrees&tab=google-analytics">' .
                'Google Analytics</a> tab. See the ' .
                '<a href="options-general.php?page=51Degrees&tab=properties">Properties</a>' .
                ' tab for a list of all the custom properties.</b></span>';
        }

    ?>

    <p>
        To get started visit
        <a href="https://configure.51degrees.com/zHPMyDk6" target="_blank">the Configurator</a>
        to get a 51Degrees Resource Key for the device detection properties you
        want to get access to.
        </br>
        For more information on how to use our Configurator, view our explainer
        video
        <a href="https://51degrees.com/documentation/_concepts__configurator.html" target="_blank">
            here
        </a>.
    </p>
    
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <label for="<?php echo Options::RESOURCE_KEY; ?>">Resource Key</label>
                </th>
                <td>
                    <input name="<?php echo Options::RESOURCE_KEY; ?>" type="text" id="<?php echo Options::RESOURCE_KEY; ?>" value="<?php echo esc_attr(get_option(Options::RESOURCE_KEY));?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo Options::PIPELINE_ENABLE; ?>">Device Detection</label>
                </th>
                <td>
                    <input type="hidden" name="<?php echo Options::PIPELINE_ENABLE; ?>" value="off">
                    <label>
                        <input name="<?php echo Options::PIPELINE_ENABLE; ?>" type="checkbox" id="<?php echo Options::PIPELINE_ENABLE; ?>" value="on" <?php checked(get_option(Options::PIPELINE_ENABLE, 'on'), 'on'); ?>>
                        Enable 51Degrees device detection on every request
                    </label>
                    <p class="description">
                        Disable to stop device detection calls entirely. This will also disable Robots Enforce. Automatically re-enabled when any feature that requires it is turned on.
                    </p>
                </td>
            </tr>
        </tbody>
    </table>

    <input type="submit" class="button-primary" value="Save Changes"/>

</form>

