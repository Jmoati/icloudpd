<?php

namespace Foundation\Argparse;

class _MutuallyExclusiveGroup extends _ArgumentGroup
{
    public bool $required;
    public _ActionsContainer $_container;

    public function __construct(_ActionsContainer $container, bool $required = false)
    {
        parent::__construct($container);
        $this->required = $required;
        $this->_container = $container;
    }

    public function _add_action(Action $action): Action
    {
        if ($action->required) {
            throw new \ValueError('mutually exclusive arguments must be optional');
        }
        $action = $this->_container->_add_action($action);
        $this->_group_actions[] = $action;
        return $action;
    }
}
