<?php

class Plugin_Source_Yesno implements Plugin_Source_Interface
{
    /**
     * Return yes/no options
     *
     * @param Plugin_Abstract $plugin
     * @return array
     */
    public function getOptions(Plugin_Abstract $plugin)
    {
        return [
            ['value' => 1, 'label' => 'Yes'],
            ['value' => 0, 'label' => 'No'],
        ];
    }
}
