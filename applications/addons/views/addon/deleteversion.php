<?php if (!defined('APPLICATION')) exit();

echo $this->Form->open();

echo '<h2>Delete Version</h2>';
echo '<p>', t('Delete this version of the addon?'), '</p>';

echo '<p style="text-align: center">',
    $this->Form->button('Yes'),
    ' ',
    $this->Form->button('No'),
    '</p>';

echo $this->Form->close();