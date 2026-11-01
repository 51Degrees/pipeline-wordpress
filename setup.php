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

     settings_fields(Options::GROUP_KEY); ?>

    

        $cachedPipeline  = get_option(Options::PIPELINE);
        $validationError = get_option(Options::PIPELINE_VALIDATION_ERROR, '');
        $resourceKey     = get_option(Options::RESOURCE_KEY, '');

        // Defense-in-depth: only render any status box when a key is actually
        // configured. Stale state can never bleed through to a fresh UI.
        if (!empty($resourceKey)) {
            if (isset($cachedPipeline['error'])) {
                echo '<p></p><span class="fod-pipeline-status error"><b>' .
                    esc_html($cachedPipeline['error']) . '</b></span>';
            } elseif (!empty($validationError)) {
                echo '<p></p><span class="fod-pipeline-status error"><b>' .
                    esc_html($validationError) . '</b></span>';
            } elseif (isset($cachedPipeline['pipeline'])) {
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
        }

    ?>

    <p>
        To get started visit
        <a href="https://configure.51degrees.com/zHPMyDk6?utm_source=code&utm_medium=comment&utm_campaign=pipeline-wordpress&utm_content=setup.php&utm_term=body" target="_blank">the Configurator</a>
        to get a 51Degrees Resource Key for the device detection properties you
        want to get access to.
        </br>
        If you plan to use the Google Analytics feature, make sure the resource
        key is restricted to this site's domain in the Configurator — Google
        Analytics access is only authorized for resource keys whose registered
        domains include this site.
        </br>
        For more information on how to use our Configurator, view our explainer
        video
        <a href="https://51degrees.com/documentation/_concepts__configurator.html?utm_source=code&utm_medium=comment&utm_campaign=pipeline-wordpress&utm_content=setup.php&utm_term=body" target="_blank">
            here
        </a>.
    </p>
    
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <label for=" echo Options::RESOURCE_KEY; ?>">Resource Key</label>
                </th>
                <td>
                    <input name=" echo Options::RESOURCE_KEY; ?>" type="text" id=" echo Options::RESOURCE_KEY; ?>" value=" echo esc_attr(get_option(Options::RESOURCE_KEY));?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for=" echo Options::PIPELINE_ENABLE; ?>">Device Detection</label>
                </th>
                <td>
                    <input type="hidden" name=" echo Options::PIPELINE_ENABLE; ?>" value="off">
                    <label>
                        <input name=" echo Options::PIPELINE_ENABLE; ?>" type="checkbox" id=" echo Options::PIPELINE_ENABLE; ?>" value="on"  checked(get_option(Options::PIPELINE_ENABLE, 'on'), 'on'); ?>>
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

