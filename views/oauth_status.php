<?php

/**
 * @param Plugin_Abstract $plugin
 * @return array
 */
function getOauthConfigErrors(Plugin_Abstract $plugin)
{
    $errors = [];
    $requiredFields = $plugin->getPluginInfo('info/oauth/required_config');
    if ($requiredFields) {
        foreach (explode(',', $requiredFields) as $field) {
            if ( ! $plugin->getConfig($field)) {
                $errors[] = sprintf('The \'%s\' field is required for OAuth connection setup.', $field);
            }
        }
    }
    return $errors;
}

/**
 * @param Plugin_Abstract $plugin
 * @return null|string
 */
function getOauthValidationErrors(Plugin_Abstract $plugin)
{
    try {
        $plugin->oauthValidateConfig();
        return NULL;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

/**
 * @param Plugin_Abstract $plugin
 * @return bool
 */
function getOauthConnectionActive(Plugin_Abstract $plugin)
{
    return !! $plugin->oauthGetTokenData();
}

/**
 * @param Plugin_Abstract $plugin
 * @return null|string
 */
function getOauthTestErrors(Plugin_Abstract $plugin)
{
    try {
        getOauthTestResults($plugin);
        return NULL;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

/**
 * @param Plugin_Abstract $plugin
 * @return bool|array
 */
function getOauthTestResults(Plugin_Abstract $plugin)
{
    static $result;
    if ($result === NULL) {
        $result = FALSE;
        $result = $plugin->oauthTest();
    }
    return $result;
}

/**
 * @param Plugin_Abstract $plugin
 * @return string
 */
function renderOauthTestData(Plugin_Abstract $plugin)
{
    $result = getOauthTestResults($plugin);
    return is_array($result) ? implode("<br />", $result) : (string)$result;
}

/**
 * @param Plugin_Abstract $plugin
 * @return string
 */
function getOauthDisconnectButton(Plugin_Abstract $plugin)
{
    $url = $plugin->oauthGetUrl(['action' => 'disconnect']);
    return '<a href="'.$url.'">Disconnect</a>';
}